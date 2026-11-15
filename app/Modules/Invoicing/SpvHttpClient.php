<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

interface SpvHttpClient
{
    /** @param list<string> $headers @return array{status:int,body:string} */
    public function request(string $method,string $url,array $headers=[],string $body=''): array;
}
