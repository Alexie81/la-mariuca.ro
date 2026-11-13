<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Shared\Config\Config;
use App\Shared\Http\{View,RateLimiter};
use Psr\Http\Message\{ResponseInterface,ServerRequestInterface};

final class InvoiceCustomerController
{
    public function __construct(private readonly InvoiceService $service,private readonly View $view,private readonly Config $config,private readonly RateLimiter $limiter) {}
    /** @param array<string,string> $args */
    public function form(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface { return $this->page($request,(int)$args['id']); }
    /** @param array<string,string> $args */
    public function submit(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        $id=(int)$args['id']; $input=(array)$request->getParsedBody();
        try {
            $order=$this->authorizedOrder();
            if(!$this->limiter->allow('invoice-return-customer',(string)$order,8,900)) { throw new InvoiceException('Ai trimis mai multe solicitări. Reîncearcă în câteva minute.',429); }
            $this->service->requestReturn($id,$input,null,$order);
            return $this->page($request,$id,'',true);
        } catch(InvoiceException $e) { return $this->page($request,$id,$e->getMessage(),false,$e->getCode()); }
    }
    private function authorizedOrder(): int
    {
        $access=$_SESSION['tracked_order'] ?? [];
        if(!isset($access['id']) || (int)($access['expires'] ?? 0)<=time()) { throw new InvoiceException('Verifică din nou numărul comenzii și emailul pentru acces.',401); }
        return (int)$access['id'];
    }
    private function page(ServerRequestInterface $request,int $id,string $error='',bool $success=false,int $status=200): ResponseInterface
    {
        try { $order=$this->authorizedOrder(); $invoice=$this->service->load($id); if((int)$invoice['order_id']!==$order) { throw new InvoiceException('Documentul nu este disponibil.',404); } }
        catch(InvoiceException) { return $this->view->redirect($this->config->path('urmarire-comanda/')); }
        return $this->view->render('storefront/pages/invoice-return.twig',['invoice'=>$invoice,'eligibility'=>$this->service->returnEligibility($invoice),'error'=>$error,'success'=>$success,'values'=>(array)$request->getParsedBody(),'request_key'=>bin2hex(random_bytes(24)),'meta'=>['title'=>'Solicită un retur · La Măriuca','noindex'=>true]],$status)
            ->withHeader('Cache-Control','private, no-store')->withHeader('Referrer-Policy','no-referrer')->withHeader('X-Robots-Tag','noindex, nofollow');
    }
}
