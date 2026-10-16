<?php

declare(strict_types=1);

namespace App\Shared\Security;

use RobThree\Auth\Providers\Qr\IQRCodeProvider;

final class NullQrProvider implements IQRCodeProvider
{
    public function getQRCodeImage(string $qrText, int $size): string
    {
        return '';
    }

    public function getMimeType(): string
    {
        return 'image/png';
    }
}
