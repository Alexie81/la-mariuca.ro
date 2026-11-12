<?php
declare(strict_types=1);
namespace App\Modules\Inventory;
final class NirConflictException extends \RuntimeException
{
    public function __construct(string $message = 'Documentul a fost modificat pe alt dispozitiv. Reîncarcă fișa înainte de a continua.') { parent::__construct($message, 409); }
}
