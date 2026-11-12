<?php
declare(strict_types=1);
namespace App\Modules\Inventory;
final class NirNotFoundException extends \RuntimeException
{
    public function __construct(string $message = 'Documentul nu există.') { parent::__construct($message, 404); }
}
