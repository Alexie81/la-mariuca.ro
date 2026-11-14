<?php
declare(strict_types=1);

namespace App\Modules\Orders;

final class OrderNoteService
{
    public function __construct(private readonly OrderNoteRepository $notes) {}

    public function save(int $orderId, string $note, string $version): string
    {
        if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note, 'UTF-8') > 5000) {
            throw new \DomainException('Nota poate conține cel mult 5.000 de caractere.');
        }
        $current = $this->notes->find($orderId);
        if ($current === null) { throw new \OutOfBoundsException('Comanda nu mai este disponibilă.'); }
        if (!hash_equals(hash('sha256', $current), $version)) {
            throw new \UnexpectedValueException('Nota a fost modificată între timp. Copiază textul scris, apoi reîncarcă pagina pentru a vedea ultima versiune.');
        }
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
        if ($current !== $note && !$this->notes->replace($orderId, $current, $note)) {
            throw new \UnexpectedValueException('Nota a fost modificată între timp. Textul tău este păstrat; reîncarcă pagina înainte de a salva din nou.');
        }
        return $note;
    }
}
