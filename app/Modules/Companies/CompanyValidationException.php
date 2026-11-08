<?php

declare(strict_types=1);

namespace App\Modules\Companies;

final class CompanyValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(reset($errors) ?: 'Verifică datele completate.');
    }
}
