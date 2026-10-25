<?php

declare(strict_types=1);

namespace App\Modules\Reviews;

use RuntimeException;

final class ProductReviewValidationException extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
