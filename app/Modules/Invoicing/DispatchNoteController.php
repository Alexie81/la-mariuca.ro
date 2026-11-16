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
    public function __construct(private readonly DispatchNoteService $service,private readonly Auth $auth,private readonly Config $config,private readonly \App\Shared\Http\View $view) {}

    /** @param array<string,string> $args */
    public function issueForm(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try { return $this->renderIssueForm($request,(int)$args['id']); }
        catch(\Throwable $error) { return $this->json(['ok'=>false,'message'=>$error instanceof InvoiceException?$error->getMessage():'Formularul nu poate fi deschis.'],$error instanceof InvoiceException?$error->getCode():500); }
    }

    /** @param array<string,mixed> $input */
    private function renderIssueForm(ServerRequestInterface $request,int $orderId,array $input=[],string $error='',int $status=200): ResponseInterface
    {
        $snapshot=$this->service->issueFormData($orderId,$this->actor());
        foreach(is_array($input['dispatch'] ?? null)?$input['dispatch']:[] as $field=>$value) {
            if(array_key_exists($field,$snapshot['dispatch']) && is_string($value)) { $snapshot['dispatch'][$field]=$value; }
        }
        return $this->view->render($request->getHeaderLine('X-Invoice-Modal')==='1'?'admin/invoices/dispatch-issue.twig':'admin/pages/dispatch-issue.twig',[
            'admin_section'=>'invoices','admin_context'=>'Aviz de însoțire','snapshot'=>$snapshot,'error'=>$error,
            'request_key'=>is_string($input['request_key'] ?? null)?$input['request_key']:bin2hex(random_bytes(24)),
            'form_action'=>$this->config->path('admin/comenzi/'.$orderId.'/aviz/'),'back_url'=>$this->config->path('admin/comenzi/'.$orderId.'/').'#aviz',
        ],$status)->withHeader('Cache-Control','no-store');
    }

    /** @param array<string,string> $args */
    public function emailForm(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $orderId=(int)$args['id'];$note=$this->service->forOrder($orderId,$this->actor())??throw new InvoiceException('Avizul nu există.',404);
            return $this->view->render($request->getHeaderLine('X-Invoice-Modal')==='1'?'admin/invoices/dispatch-email.twig':'admin/pages/dispatch-email.twig',[
                'admin_section'=>'invoices','admin_context'=>'Aviz de însoțire','note'=>$note,'request_key'=>bin2hex(random_bytes(24)),
                'form_action'=>$this->config->path('admin/comenzi/'.$orderId.'/aviz/email/'),'back_url'=>$this->config->path('admin/comenzi/'.$orderId.'/').'#aviz',
            ])->withHeader('Cache-Control','no-store');
        } catch(\Throwable $error) { return $this->json(['ok'=>false,'message'=>$error instanceof InvoiceException?$error->getMessage():'Formularul nu poate fi deschis.'],$error instanceof InvoiceException?$error->getCode():500); }
    }

    /** @param array<string,string> $args */
    public function email(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        $orderId=(int)$args['id'];
        try {
            $this->service->queueEmail($orderId,(array)$request->getParsedBody(),$this->actor());
            $message='Emailul cu avizul PDF și linkul privat este pregătit pentru trimitere.';
            if(str_contains($request->getHeaderLine('Accept'),'application/json')) { return $this->json(['ok'=>true,'message'=>$message,'redirect'=>$this->config->path('admin/comenzi/'.$orderId.'/').'#aviz']); }
            Flash::set('success',$message);
        } catch(\Throwable $error) {
            $message=$error instanceof InvoiceException?$error->getMessage():'Emailul nu a putut fi pregătit.';
            if(str_contains($request->getHeaderLine('Accept'),'application/json')) { return $this->json(['ok'=>false,'message'=>$message],$error instanceof InvoiceException?$error->getCode():500); }
            Flash::set('error',$message);
        }
        return $this->redirect($orderId);
    }

    /** @param array<string,string> $args */
    public function publicPdf(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $note=$this->service->publicNote($args['token']);$path=$this->service->publicPdf($args['token']);$handle=fopen($path,'rb');
            if($handle===false) { throw new InvoiceException('Avizul nu este disponibil.',404); }
            return (new Response())->withBody(new Stream($handle))->withHeader('Content-Length',(string)(fstat($handle)['size'] ?? 0))->withHeader('Content-Type','application/pdf')->withHeader('Content-Disposition',(($request->getQueryParams()['view']??'')==='1'?'inline':'attachment').'; filename="Aviz-'.$note['series_code'].'-'.$note['number'].'.pdf"')->withHeader('Cache-Control','private, no-store')->withHeader('X-Content-Type-Options','nosniff')->withHeader('X-Robots-Tag','noindex, nofollow')->withHeader('Referrer-Policy','no-referrer');
        } catch(\Throwable) { $result=new Response(404);$result->getBody()->write('Linkul avizului nu mai este disponibil. Contactează magazinul pentru o copie.');return $result->withHeader('Content-Type','text/plain; charset=utf-8')->withHeader('Cache-Control','no-store'); }
    }

    /** @param array<string,string> $args */
    public function deleteForm(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $orderId=(int)$args['id'];$note=$this->service->forOrder($orderId,$this->actor())??throw new InvoiceException('Avizul nu există.',404);
            return $this->view->render($request->getHeaderLine('X-Invoice-Modal')==='1'?'admin/invoices/dispatch-delete.twig':'admin/pages/dispatch-delete.twig',[
                'admin_section'=>'invoices','admin_context'=>'Aviz de însoțire','note'=>$note,'request_key'=>bin2hex(random_bytes(24)),
                'form_action'=>$this->config->path('admin/comenzi/'.$orderId.'/aviz/sterge/'),'back_url'=>$this->config->path('admin/comenzi/'.$orderId.'/').'#aviz',
            ])->withHeader('Cache-Control','no-store');
        } catch(\Throwable $error) {
            $result=new Response($error instanceof InvoiceException?($error->getCode()?:422):500);$result->getBody()->write($error instanceof InvoiceException?$error->getMessage():'Confirmarea nu a putut fi pregătită.');return $result->withHeader('Content-Type','text/plain; charset=utf-8')->withHeader('Cache-Control','no-store');
        }
    }

    /** @param array<string,string> $args */
    public function issue(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        $input=(array)$request->getParsedBody();
        try {
            if(isset($input['dispatch']) && !is_array($input['dispatch'])) { throw new InvoiceException('Datele de expediție nu sunt valide.'); }
            $this->service->issue((int)$args['id'],(string)($input['request_key']??''),$this->actor(),$input['dispatch'] ?? []); Flash::set('success','Avizul a fost generat din datele comenzii.');
            if(str_contains($request->getHeaderLine('Accept'),'application/json')) { return $this->json(['ok'=>true,'message'=>'Avizul a fost generat. Îl poți deschide sau descărca din comandă.','redirect'=>$this->config->path('admin/comenzi/'.(int)$args['id'].'/').'#aviz']); }
        } catch(\Throwable $error) {
            $message=$error instanceof InvoiceException?$error->getMessage():'Avizul nu a putut fi generat.';
            if(str_contains($request->getHeaderLine('Accept'),'application/json')) { return $this->json(['ok'=>false,'message'=>$message],$error instanceof InvoiceException?($error->getCode()?:422):500); }
            if($error instanceof InvoiceException && $error->getCode()===422) { return $this->renderIssueForm($request,(int)$args['id'],$input,$message,422); }
            Flash::set('error',$message);
        }
        return $this->redirect((int)$args['id']);
    }

    /** @param array<string,string> $args */
    public function delete(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $input=(array)$request->getParsedBody();
            $noteId=filter_var($input['dispatch_note_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $version=filter_var($input['version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$noteId || !$version) { throw new InvoiceException('Reîncarcă pagina înainte de ștergerea avizului.',409); }
            $this->service->delete((int)$args['id'],(string)($input['request_key']??''),$this->actor(),$noteId,$version);
            $message='Avizul a fost șters. Istoricul operațiunii a rămas în audit.';
            if(str_contains($request->getHeaderLine('Accept'),'application/json')) { return $this->json(['ok'=>true,'message'=>$message,'redirect'=>$this->config->path('admin/comenzi/'.(int)$args['id'].'/').'#aviz']); }
            Flash::set('success',$message);
        } catch(\Throwable $error) {
            $message=$error instanceof InvoiceException?$error->getMessage():'Avizul nu a putut fi eliminat.';
            if(str_contains($request->getHeaderLine('Accept'),'application/json')) { return $this->json(['ok'=>false,'message'=>$message],$error instanceof InvoiceException?($error->getCode()?:422):500); }
            Flash::set('error',$message);
        }
        return $this->redirect((int)$args['id']);
    }

    /** @param array<string,string> $args */
    public function pdf(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $id=(int)$args['id']; $note=$this->service->forOrder($id,$this->actor())??throw new InvoiceException('Avizul nu există.',404);
            if (\App\Shared\Http\ExportSummary::requested($request)) { return \App\Shared\Http\ExportSummary::response(1,count($note['snapshot']['lines'] ?? []),1,'Avize'); }
            $path=$this->service->pdf($id,$this->actor()); $handle=fopen($path,'rb'); if($handle===false){throw new InvoiceException('PDF-ul avizului nu poate fi deschis.');}
            $inline=($request->getQueryParams()['deschide']??'')==='1'; return (new Response())->withBody(new Stream($handle))->withHeader('Content-Length',(string)(fstat($handle)['size'] ?? 0))->withHeader('Content-Type','application/pdf')->withHeader('Content-Disposition',($inline?'inline':'attachment').'; filename="Aviz-'.$note['series_code'].'-'.$note['number'].'.pdf"')->withHeader('Cache-Control','private, no-store')->withHeader('X-Content-Type-Options','nosniff')->withHeader('X-Robots-Tag','noindex, nofollow');
        } catch(\Throwable $error) {
            $status=$error instanceof InvoiceException?$error->getCode():500; $result=new Response($status?:500); $result->getBody()->write($error instanceof InvoiceException?$error->getMessage():'Avizul nu a putut fi pregătit.'); return $result->withHeader('Content-Type','text/plain; charset=utf-8')->withHeader('Cache-Control','no-store');
        }
    }

    /** @return array<string,mixed> */
    private function actor(): array {return $this->auth->user()??throw new InvoiceException('Autentifică-te în administrare.',403);}
    private function redirect(int $orderId): ResponseInterface {return (new Response(302))->withHeader('Location',$this->config->path('admin/comenzi/'.$orderId.'/').'#aviz');}
    /** @param array<string,mixed> $data */
    private function json(array $data,int $status=200): ResponseInterface {$response=new Response($status);$response->getBody()->write(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return $response->withHeader('Content-Type','application/json')->withHeader('Cache-Control','no-store');}
}
