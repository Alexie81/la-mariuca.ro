<?php

declare(strict_types=1);

namespace App\Modules\Content;

use App\Shared\Config\Config;

final class ContactWhatsAppService
{
    /** @var array<string, string> */
    private const SUBJECTS = [
        'produs' => 'Întrebare despre un produs',
        'comanda' => 'Ajutor cu o comandă',
        'marime' => 'Alegerea mărimii',
        'botez' => 'Ținută sau trusou de botez',
        'cadou' => 'Alegerea unui cadou',
        'altceva' => 'Altceva',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /** @return array<string, string> */
    public function subjects(): array
    {
        return self::SUBJECTS;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{values:array<string, string>,errors:array<string, string>}
     */
    public function validate(array $input): array
    {
        $values = [
            'name' => $this->singleLine($input['name'] ?? ''),
            'email' => mb_strtolower($this->singleLine($input['email'] ?? '')),
            'phone' => $this->singleLine($input['phone'] ?? ''),
            'subject' => $this->singleLine($input['subject'] ?? ''),
            'message' => trim((string) ($input['message'] ?? '')),
            'consent' => (string) ($input['consent'] ?? ''),
        ];
        $errors = [];

        if (mb_strlen($values['name']) < 2 || mb_strlen($values['name']) > 80) {
            $errors['name'] = 'Scrie un nume între 2 și 80 de caractere.';
        }
        if ($values['email'] !== '' && (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($values['email']) > 254)) {
            $errors['email'] = 'Introdu o adresă de email validă.';
        }
        if ($values['phone'] !== '' && !preg_match('/^[0-9+().\s-]{6,30}$/u', $values['phone'])) {
            $errors['phone'] = 'Introdu un număr de telefon valid sau lasă câmpul liber.';
        }
        if (!isset(self::SUBJECTS[$values['subject']])) {
            $errors['subject'] = 'Alege subiectul mesajului.';
        }
        if (mb_strlen($values['message']) < 10 || mb_strlen($values['message']) > 1200) {
            $errors['message'] = 'Mesajul trebuie să aibă între 10 și 1200 de caractere.';
        }
        if ($values['consent'] !== '1') {
            $errors['consent'] = 'Confirmă că datele pot fi deschise în WhatsApp.';
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** @param array<string, string> $values */
    public function url(array $values): string
    {
        $subject = self::SUBJECTS[$values['subject']] ?? self::SUBJECTS['altceva'];
        $lines = [
            'Bună, echipa La Măriuca Boutique!',
            '',
            'Numele meu este: ' . $values['name'],
            'Subiect: ' . $subject,
        ];
        if ($values['email'] !== '') {
            $lines[] = 'Email: ' . $values['email'];
        }
        if ($values['phone'] !== '') {
            $lines[] = 'Telefon: ' . $values['phone'];
        }
        $lines[] = '';
        $lines[] = 'Mesaj:';
        $lines[] = $values['message'];
        $lines[] = '';
        $lines[] = 'Mesaj pregătit din formularul de contact La Măriuca Boutique.';

        return 'https://wa.me/' . $this->number() . '?text=' . rawurlencode(implode("\n", $lines));
    }

    public function hasDirectNumber(): bool
    {
        return $this->number() !== '';
    }

    public function directUrl(): string
    {
        return 'https://wa.me/' . $this->number();
    }

    public function phoneUrl(): string
    {
        $number = $this->number();
        return $number === '' ? '' : 'tel:+' . $number;
    }

    public function phoneDisplay(): string
    {
        $number = $this->number();
        if (preg_match('/^40(\d{3})(\d{3})(\d{3})$/', $number, $matches) === 1) {
            return '0' . $matches[1] . ' ' . $matches[2] . ' ' . $matches[3];
        }

        return $number;
    }

    public function contactEmail(): string
    {
        return trim($this->config->get('CONTACT_EMAIL', 'contact@la-mariuca.ro'));
    }

    private function singleLine(mixed $value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        $clean = preg_replace('/[\r\n\t]+/u', ' ', trim((string) $value)) ?? '';
        return preg_replace('/\s{2,}/u', ' ', $clean) ?? '';
    }

    private function number(): string
    {
        return preg_replace('/\D+/', '', $this->config->get('WHATSAPP_NUMBER')) ?? '';
    }
}
