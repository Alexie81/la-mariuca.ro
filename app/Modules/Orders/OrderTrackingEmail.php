<?php

declare(strict_types=1);

namespace App\Modules\Orders;

final class OrderTrackingEmail
{
    public function __construct(private readonly OrderTrackingService $tracking) {}

    /** @return array{text:string,html:string} */
    public function withTracking(string $body, int $orderId, string $recipient): array
    {
        $url = $this->tracking->emailUrl($orderId, $recipient);
        $safeBody = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return [
            'text'=>$body . "\n\nUrmărește comanda:\n" . $url . "\n\nPăstrează acest link privat.",
            'html'=>'<!doctype html><html lang="ro"><body style="margin:0;background:#fcf7f3;font-family:Arial,sans-serif;color:#49372f"><div style="max-width:560px;margin:32px auto;padding:32px;background:#fff;border:1px solid #eadbd3;border-radius:24px"><p style="color:#a55364;letter-spacing:2px;font-size:12px">LA MĂRIUCA BOUTIQUE</p><div style="font-size:16px;line-height:1.8">' . $safeBody . '</div><p style="margin-top:28px"><a href="' . $safeUrl . '" style="display:inline-block;padding:16px 24px;background:#a55364;color:white;border-radius:14px;text-decoration:none;font-weight:bold">Urmărește comanda →</a></p><p style="font-size:13px;color:#88746a">Toate detaliile, într-un singur loc. Păstrează acest link privat.</p></div></body></html>',
        ];
    }
}
