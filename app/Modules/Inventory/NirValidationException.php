<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

final class NirValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors @param list<string> $warnings */
    public function __construct(public readonly array $errors = [], public readonly array $warnings = [])
    {
        parent::__construct($errors !== [] ? (string) reset($errors) : 'Verifică și acceptă avertismentele înainte de confirmare.', 422);
    }
}
