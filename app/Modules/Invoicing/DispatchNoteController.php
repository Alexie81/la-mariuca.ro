<?php

declare(strict_types=1);

namespace App\Modules\Invoicing;

use App\Shared\Config\Config;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;

final class DispatchNoteController
{
    public function __construct(private readonly DispatchNoteService $service,private readonly Auth $auth,private readonly Config $config) {}

    /** @param array<string,string> $args */
    public function issue(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $input=(array)$request->getParsedBody(); $this->service->issue((int)$args['id'],(string)($input['request_key']??''),$this->actor()); Flash::set('success','Avizul a fost generat din datele comenzii.');
        } catch(\Throwable $error) {Flash::set('error',$error instanceof InvoiceException?$error->getMessage():'Avizul nu a putut fi generat.');}
        return $this->redirect((int)$args['id']);
    }

    /** @param array<string,string> $args */
    public function delete(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $input=(array)$request->getParsedBody(); $this->service->delete((int)$args['id'],(string)($input['request_key']??''),$this->actor()); Flash::set('success','Avizul a fost eliminat. Istoricul operațiunii a rămas în audit.');
        } catch(\Throwable $error) {Flash::set('error',$error instanceof InvoiceException?$error->getMessage():'Avizul nu a putut fi eliminat.');}
        return $this->redirect((int)$args['id']);
    }

    /** @param array<string,string> $args */
    public function pdf(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $id=(int)$args['id']; $note=$this->service->forOrder($id,$this->actor())??throw new InvoiceException('Avizul nu există.',404); $path=$this->service->pdf($id,$this->actor()); $handle=fopen($path,'rb'); if($handle===false){throw new InvoiceException('PDF-ul avizului nu poate fi deschis.');}
            $inline=($request->getQueryParams()['deschide']??'')==='1'; return (new Response())->withBody(new Stream($handle))->withHeader('Content-Type','application/pdf')->withHeader('Content-Disposition',($inline?'inline':'attachment').'; filename="Aviz-'.$note['series_code'].'-'.$note['number'].'.pdf"')->withHeader('Cache-Control','private, no-store')->withHeader('X-Content-Type-Options','nosniff')->withHeader('X-Robots-Tag','noindex, nofollow');
        } catch(\Throwable $error) {
            $status=$error instanceof InvoiceException?$error->getCode():500; $result=new Response($status?:500); $result->getBody()->write($error instanceof InvoiceException?$error->getMessage():'Avizul nu a putut fi pregătit.'); return $result->withHeader('Content-Type','text/plain; charset=utf-8')->withHeader('Cache-Control','no-store');
        }
    }

    /** @return array<string,mixed> */
    private function actor(): array {return $this->auth->user()??throw new InvoiceException('Autentifică-te în administrare.',403);}
    private function redirect(int $orderId): ResponseInterface {return (new Response(302))->withHeader('Location',$this->config->path('admin/comenzi/'.$orderId.'/').'#aviz');}
}
