<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

final class SpvResponseException extends \RuntimeException
{
    public function __construct(public readonly string $state,string $message) { parent::__construct($message); }
}
