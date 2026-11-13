<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

final class InvoiceConfigurationException extends \DomainException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
