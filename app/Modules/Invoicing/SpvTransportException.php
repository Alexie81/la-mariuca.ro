<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

/** A missing response is not evidence that ANAF did not receive the invoice. */
final class SpvTransportException extends \RuntimeException {}
