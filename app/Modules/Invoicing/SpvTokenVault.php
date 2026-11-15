<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

final class SpvTokenVault
{
    private readonly string $key;
    public function __construct(string $appKey)
    {
        if(strlen($appKey)<24) { throw new InvoiceException('Cheia privată a aplicației trebuie configurată înainte de conectarea SPV.'); }
        $this->key=hash_hkdf('sha256',$appKey,32,'lamariuca.spv.tokens.v1');
    }
    /** @param array<string,mixed> $tokens */
    public function seal(array $tokens): string
    {
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox(InvoiceSnapshot::json($tokens),$nonce,$this->key));
    }
    /** @return array<string,mixed> */
    public function open(string $cipher): array
    {
        $bytes=base64_decode($cipher,true);
        if($bytes===false || strlen($bytes)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { throw new InvoiceException('Conexiunea SPV trebuie reautorizată.'); }
        $plain=sodium_crypto_secretbox_open(substr($bytes,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($bytes,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->key);
        if($plain===false) { throw new InvoiceException('Cheia aplicației nu poate deschide conexiunea SPV. Reautorizează conexiunea.'); }
        return json_decode($plain,true,512,JSON_THROW_ON_ERROR);
    }
}
