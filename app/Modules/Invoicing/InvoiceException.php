<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

final class InvoiceException extends \RuntimeException
{
    public function __construct(string $message, int $status = 422) { parent::__construct($message, $status); }
}
