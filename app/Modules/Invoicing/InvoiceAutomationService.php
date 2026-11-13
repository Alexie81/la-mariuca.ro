<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

final class InvoiceAutomationService
{
    public const FIELDS = ['card_issue','card_email','cod_issue','cod_email'];

    public function __construct(private readonly InvoiceAutomationRepository $repository) {}

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $state = $this->repository->settings();
        foreach (self::FIELDS as $field) { $state[$field] = (bool) $state[$field]; }
        $state['revision'] = (int) $state['revision'];
        return $state;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(array $input, int $actorId): array
    {
        $revision = $input['revision'] ?? '';
        if (!is_scalar($revision) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string) $revision)) {
            throw new InvoiceConfigurationException('Reîncarcă pagina pentru a salva setările actuale.', 409);
        }
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $input[$field] ?? '0';
            if (!in_array($value, ['0','1',0,1,false,true], true)) {
                throw new InvoiceConfigurationException('Verifică opțiunile de automatizare și încearcă din nou.');
            }
            $values[$field] = in_array($value, ['1',1,true], true);
        }
        // Switching issuance off also switches email off; re-enabling never sends implicitly.
        $values['card_email'] = $values['card_issue'] && $values['card_email'];
        $values['cod_email'] = $values['cod_issue'] && $values['cod_email'];
        return $this->repository->transaction(function () use ($values, $revision, $actorId): array {
            $current = $this->settings();
            if (array_intersect_key($current, $values) === $values) { return $current; }
            if ($current['revision'] !== (int) $revision) {
                throw new InvoiceConfigurationException('Setările au fost schimbate în altă filă. Am încărcat varianta actuală; alege din nou modificarea dorită.', 409);
            }
            $this->repository->save($values, $actorId);
            return $this->settings();
        });
    }

    /**
     * Persist the idempotent invoice intent with the eligible order transition.
     * Dispatch only after the order transaction commits; email is independent.
     * @return array<string,mixed>|null
     */
    public function prepareForOrder(int $orderId): ?array
    {
        return $this->repository->transaction(function () use ($orderId): ?array {
            $existing = $this->repository->intent($orderId);
            if ($existing !== null) { return $existing; }
            $order = $this->repository->order($orderId);
            if ($order === null) { return null; }
            $method = match (true) {
                $order['payment_method'] === 'stripe' && $order['payment_status'] === 'paid' && $order['status'] === 'confirmed' => 'card',
                $order['payment_method'] === 'cod' && $order['status'] === 'new' && in_array($order['payment_status'], ['pending','paid'], true) => 'cod',
                default => null,
            };
            if ($method === null) { return null; }
            $settings = $this->settings();
            if (!$settings[$method . '_issue']) { return null; }
            $this->repository->insertIntent([
                'order_id'=>$orderId,'payment_method'=>$order['payment_method'],
                'send_email'=>(int) $settings[$method . '_email'],'settings_revision'=>$settings['revision'],
                'issue_key'=>'order:' . $orderId . ':invoice:issue','email_key'=>'order:' . $orderId . ':invoice:email','created_at'=>gmdate('c'),
            ]);
            return $this->repository->intent($orderId);
        });
    }
}
