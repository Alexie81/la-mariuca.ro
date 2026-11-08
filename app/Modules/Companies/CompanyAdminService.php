<?php

declare(strict_types=1);

namespace App\Modules\Companies;

use Psr\Http\Message\UploadedFileInterface;

final class CompanyAdminService
{
    private const FIELDS = [
        'legal_name'=>190,'trade_name'=>190,'tax_id'=>60,'trade_register'=>80,
        'registered_address'=>1000,'city'=>120,'county'=>120,'postal_code'=>20,'country'=>100,
        'email'=>254,'phone'=>50,'website'=>500,'physical_address'=>1000,'service_area'=>500,
        'bank_name'=>190,'iban'=>34,
    ];

    public function __construct(private readonly CompanyRepository $repository, private readonly CompanyStampStorage $stamps)
    {
    }

    /** @return list<array<string,mixed>> */
    public function companies(): array
    {
        return $this->repository->allCompanies();
    }

    /** @return array<string,mixed> */
    public function company(int $id): array
    {
        return $this->repository->company($id) ?? throw new CompanyNotFoundException('Firma nu mai există.');
    }

    /** @return list<array<string,mixed>> */
    public function warehouses(int $companyId): array
    {
        $this->company($companyId);
        return $this->repository->warehouses($companyId);
    }

    /** @return array<string,mixed> */
    public function warehouse(int $companyId, int $id): array
    {
        return $this->repository->warehouse($companyId, $id) ?? throw new CompanyNotFoundException('Gestiunea nu există în această firmă.');
    }

    /** @param array<string,mixed> $data */
    public function saveCompany(array $data, ?int $id = null, ?UploadedFileInterface $stamp = null): int
    {
        if ($id !== null) {
            $this->company($id);
        }
        $errors = [];
        $values = [];
        foreach (self::FIELDS as $field => $length) {
            $value = $data[$field] ?? '';
            if (!is_scalar($value)) {
                $errors[$field] = 'Completează acest câmp cu text.';
                $value = '';
            }
            $values[$field] = trim((string) $value);
            if ($field === 'iban') {
                $values[$field] = strtoupper(preg_replace('/\s+/u', '', $values[$field]) ?? '');
            }
            if (mb_strlen($values[$field]) > $length) {
                $errors[$field] = 'Folosește maximum ' . $length . ' de caractere.';
            }
        }
        if ($values['legal_name'] === '') {
            $errors['legal_name'] = 'Denumirea legală este obligatorie.';
        }
        $values['country'] = $values['country'] === '' ? 'România' : $values['country'];
        if ($values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Introdu o adresă de email validă.';
        }
        if ($values['website'] !== '') {
            if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $values['website'])) {
                $values['website'] = 'https://' . $values['website'];
            }
            $parts = parse_url($values['website']);
            if (filter_var($values['website'], FILTER_VALIDATE_URL) === false || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['https','http'], true) || isset($parts['user']) || isset($parts['pass']) || mb_strlen($values['website']) > 500) {
                $errors['website'] = 'Introdu un website valid, de forma https://exemplu.ro.';
            }
        }
        if ($values['iban'] !== '' && !$this->validIban($values['iban'])) {
            $errors['iban'] = 'IBAN-ul nu este valid. Verifică toate literele și cifrele.';
        }
        $rawCapital = $data['share_capital'] ?? '';
        $capital = is_scalar($rawCapital) ? trim((string) $rawCapital) : 'invalid';
        $capital = preg_replace('/\s+/u', '', $capital) ?? '';
        $values['share_capital_bani'] = null;
        if ($capital !== '') {
            if (preg_match('/^([0-9]{1,12})(?:[.,]([0-9]{1,2}))?$/D', $capital, $parts) !== 1) {
                $errors['share_capital'] = 'Introdu o sumă pozitivă în lei, cu maximum două zecimale.';
            } else {
                $values['share_capital_bani'] = ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
            }
        }
        if ($errors !== []) {
            throw new CompanyValidationException($errors);
        }
        $newStamp = null;
        $replaceStamp = $this->checked($data['remove_stamp'] ?? false);
        if ($stamp !== null && $stamp->getError() !== UPLOAD_ERR_NO_FILE) {
            $newStamp = $this->stamps->store($stamp);
            $replaceStamp = true;
        }
        try {
            $saved = $this->repository->saveCompany($values, $id, $this->checked($data['is_default'] ?? false), $replaceStamp, $newStamp);
        } catch (\Throwable $exception) {
            $this->stamps->remove($newStamp);
            throw $exception;
        }
        $this->stamps->remove($saved['previous_stamp']);
        return $saved['id'];
    }

    public function deleteCompany(int $id): void
    {
        $this->stamps->remove($this->repository->deleteCompany($id));
    }

    /** @param array<string,mixed> $data */
    public function saveWarehouse(int $companyId, array $data, ?int $id = null): int
    {
        $this->company($companyId);
        if ($id !== null) {
            $this->warehouse($companyId, $id);
        }
        $name = is_scalar($data['name'] ?? '') ? trim((string) ($data['name'] ?? '')) : '';
        if ($name === '') {
            throw new CompanyValidationException(['name'=>'Denumirea gestiunii este obligatorie.']);
        }
        if (mb_strlen($name) > 120) {
            throw new CompanyValidationException(['name'=>'Denumirea poate avea maximum 120 de caractere.']);
        }
        $nameKey = mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);
        return $this->repository->saveWarehouse($companyId, $id, $name, $nameKey, $this->checked($data['is_default'] ?? false));
    }

    public function deleteWarehouse(int $companyId, int $id): void
    {
        $this->repository->deleteWarehouse($companyId, $id);
    }

    public function stampPath(int $companyId): ?string
    {
        $company = $this->company($companyId);
        return $this->stamps->path(is_string($company['stamp_path']) ? $company['stamp_path'] : null);
    }

    private function checked(mixed $value): bool
    {
        return in_array($value, [true,1,'1','on','true'], true);
    }

    private function validIban(string $iban): bool
    {
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $iban) !== 1 || (str_starts_with($iban, 'RO') && strlen($iban) !== 24)) {
            return false;
        }
        $rotated = substr($iban, 4) . substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rotated) as $character) {
            $digits = ctype_digit($character) ? $character : (string) (ord($character) - 55);
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }
        return $remainder === 1;
    }
}
