<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

/** Provider-neutral contract. No credentials, authority endpoints or legal protocol are assumed. */
interface FiscalGateway
{
    /** @param array<string,mixed> $invoice */
    public function build(array $invoice): string;
    /** @return list<string> */
    public function validate(string $document): array;
    /** The provider must deduplicate on documentKey.
     * @return array{reference:string,state:string,response:array<string,mixed>}
     */
    public function send(string $document,string $documentKey): array;
    /** @return array{state:string,response:array<string,mixed>} */
    public function status(string $reference): array;
}
