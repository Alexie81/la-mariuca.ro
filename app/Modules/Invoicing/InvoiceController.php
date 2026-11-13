<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Modules\Admin\AdminNotificationService;
use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;

final class InvoiceController
{
    public function __construct(private readonly InvoiceService $service,private readonly InvoiceRepository $repository,private readonly InvoiceExportService $exports,private readonly AdminNotificationService $notifications,private readonly Auth $auth,private readonly View $view,private readonly Config $config) {}
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $filters=$request->getQueryParams(); $data=$this->service->registry($filters,$this->actor());
            $data['previous_url']=$data['page']>1?$this->url('?'.http_build_query(array_replace($filters,['page'=>$data['page']-1]))):null;
            $data['next_url']=$data['page']<$data['pages']?$this->url('?'.http_build_query(array_replace($filters,['page'=>$data['page']+1]))):null;
            return $this->render('admin/pages/invoices.twig',$data+['filters'=>$filters,'export_query'=>http_build_query($filters)]);
        } catch(\Throwable $e) { return $this->failure($request,$e); }
    }
    /** @param array<string,string> $args */
    public function detail(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try { return $this->render('admin/pages/invoice-detail.twig',['invoice'=>$this->service->document((int)$args['id'],$this->actor())]); }
        catch(\Throwable $e) { return $this->failure($request,$e); }
    }
    /** @param array<string,string> $args */
    public function form(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $kind=$args['action'] ?? 'issue'; $this->service->allow($this->actor(),$kind);
            $invoice=(int)($args['id'] ?? 0)>0?$this->service->document((int)$args['id'],$this->actor()):null;
            $orderId=(int)($request->getQueryParams()['order'] ?? $request->getQueryParams()['order_id'] ?? 0);
            $preview=in_array($kind,['issue','order-edit'],true) && $orderId>0?$this->service->preview($orderId,$this->actor()):null;
            $editOrder=(in_array($kind,['edit','correct'],true) && $invoice)||$kind==='order-edit'?(new \App\Modules\Orders\OrderService($this->repository->pdo()))->findForAdmin($invoice['order_id'] ?? $orderId):null;
            $returnRequest=null;
            if($kind==='decide' && $invoice) { foreach($invoice['return_requests'] as $candidate) { if((int)$candidate['id']===(int)($request->getQueryParams()['request'] ?? 0)) { $returnRequest=$candidate; } } if(!$returnRequest) { throw new InvoiceException('Solicitarea nu există.',404); } }
            $data=['action'=>$kind,'invoice'=>$invoice,'preview'=>$preview,'order_id'=>$orderId,'orders'=>$kind==='issue'?$this->repository->eligibleOrders():[],
                'warehouses'=>$this->repository->all('company_warehouses',['is_active'=>1]),'request_key'=>bin2hex(random_bytes(24)),
                'form_action'=>$this->url($invoice?$invoice['id'].'/'.$kind.'/':($kind==='issue'?'emite/':'0/'.$kind.'/')),'back_url'=>$this->url($invoice?$invoice['id'].'/':''),'values'=>[],
                'edit_order'=>$editOrder,'return_request'=>$returnRequest,'return_policy'=>$this->service->returnPolicy(),
                'is_modal'=>$request->getHeaderLine('X-Invoice-Modal')==='1'];
            return $this->render($data['is_modal']?'admin/invoices/form.twig':'admin/pages/invoice-form.twig',$data);
        } catch(\Throwable $e) { return $this->failure($request,$e); }
    }
    /** @param array<string,string> $args */
    public function action(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $input=(array)$request->getParsedBody(); $kind=$args['action'] ?? 'issue'; $actor=$this->actor(); $id=(int)($args['id'] ?? 0); $result=null;
            if(!(new \App\Shared\Http\RateLimiter($this->repository->pdo()))->allow('invoice-action',(string)$actor['id'],90,300)) { throw new InvoiceException('Ai făcut multe operațiuni. Așteaptă puțin și reîncearcă.',429); }
            if($kind==='issue') { $result=$this->service->issue((int)($input['order_id'] ?? 0),$input,$actor); $id=(int)$result['id']; }
            elseif($kind==='delete') { $this->service->delete($id,$input,$actor); $id=0; }
            elseif($kind==='email') { $this->service->queueEmail($id,(string)($input['request_key'] ?? ''),$actor); }
            elseif($kind==='link') {
                $token=$this->service->publicLink($id,$actor,($input['revoke'] ?? '')==='1');
                $link=$token!==''?$this->config->rootUrl().'/factura/'.$token.'/':'';
                if($this->jsonWanted($request)) { return $this->json(['ok'=>true,'link'=>$link,'message'=>$link!==''?'Linkul este pregătit. Distribuie-l doar clientului.':'Linkurile anterioare au fost revocate.']); }
                return $this->render('admin/pages/invoice-link.twig',['public_link'=>$link,'back_url'=>$this->url($id.'/')]);
            } elseif($kind==='dismiss') { $this->service->dismissReminders($actor); }
            elseif($kind==='policy') { $this->service->saveReturnPolicy($input,$actor); }
            elseif($kind==='order-edit') { $this->service->editOrder((int)($input['order_id'] ?? 0),$input,$actor); }
            elseif($kind==='request') { $this->service->requestReturn($id,$input,$actor); }
            else {
                $result=match($kind) { 'return'=>$this->service->credit($id,$input,$actor),'receive'=>$this->service->receive($id,$input,$actor),
                    'edit'=>$this->service->edit($id,$input,$actor),'correct'=>$this->service->correct($id,$input,$actor),'payment'=>$this->service->payment($id,$input,$actor),'decide'=>$this->service->decideReturn($id,$input,$actor),default=>throw new InvoiceException('Acțiune necunoscută.',404) };
                $id=(int)$result['id'];
            }
            $message=match($kind) { 'issue'=>'Factura a fost emisă.','return'=>'Factura de retur a fost emisă.','receive'=>'Primirea produselor a fost înregistrată.','email'=>'Emailul cu factura a fost pus în coada de trimitere.','delete'=>'Factura a fost eliminată, iar stocul a fost recalculat.','payment'=>'Înregistrarea a fost salvată.','correct'=>'Documentele de corecție au fost emise.',default=>'Modificările au fost salvate.' };
            $redirect=$kind==='order-edit'?$this->config->path('admin/comenzi/'.(int)$input['order_id'].'/'):$this->url($id>0?$id.'/':'');
            if($this->jsonWanted($request)) { return $this->json(['ok'=>true,'message'=>$message,'redirect'=>$redirect]); }
            Flash::set('success',$message); return $this->view->redirect($redirect);
        } catch(\Throwable $e) { return $this->failure($request,$e); }
    }
    /** @param array<string,string> $args */
    public function export(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $this->service->allow($this->actor()); $format=$args['format']; $registry=!isset($args['id']);
            if($registry) {
                $rows=$this->repository->registry($request->getQueryParams(),true)['items']; $invoices=array_map(fn(array $row):array=>$this->service->load((int)$row['id']),$rows);
                $path=match($format) {'xlsx'=>$this->exports->registry($invoices),'zip'=>$this->exports->zip($invoices),default=>throw new InvoiceException('Format necunoscut.',404)}; $name='Registru-facturi-'.date('Y-m-d').'.'.$format;
            } else {
                $invoice=$this->service->document((int)$args['id'],$this->actor());
                $path=match($format) {'pdf'=>$this->exports->pdf($invoice),'xlsx'=>$this->exports->xlsx($invoice),'zip'=>$this->exports->zip([$invoice],false),default=>throw new InvoiceException('Format necunoscut.',404)};
                $name=$this->exports->filename($invoice).'.'.$format;
            }
            $result=$this->file($path,$name,$format); if($registry || $format==='zip') { @unlink($path); } return $result;
        } catch(\Throwable $e) { return $this->failure($request,$e); }
    }
    /** @param array<string,string> $args */
    public function publicPdf(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            if(!(new \App\Shared\Http\RateLimiter($this->repository->pdo()))->allow('invoice-public',(string)($request->getServerParams()['REMOTE_ADDR'] ?? ''),60,300)) { return new Response(429); }
            $invoice=$this->service->publicDocument($args['token']); return $this->file($this->exports->pdf($invoice),$this->exports->filename($invoice).'.pdf','pdf'); }
        catch(\Throwable) { $response=new Response(404); $response->getBody()->write('Linkul facturii nu este disponibil. Contactează magazinul pentru o copie.'); return $response->withHeader('Content-Type','text/plain; charset=utf-8')->withHeader('Cache-Control','no-store'); }
    }
    private function file(string $path,string $name,string $format): ResponseInterface
    {
        $source=fopen($path,'rb'); $buffer=tmpfile(); if(!$source || !$buffer) { throw new InvoiceException('Documentul nu a putut fi pregătit.'); }
        stream_copy_to_stream($source,$buffer); fclose($source); rewind($buffer);
        return (new Response())->withBody(new Stream($buffer))->withHeader('Content-Type',match($format){'pdf'=>'application/pdf','zip'=>'application/zip',default=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'})
            ->withHeader('Content-Disposition','attachment; filename="'.$name.'"')->withHeader('Cache-Control','private, no-store')->withHeader('X-Content-Type-Options','nosniff')->withHeader('X-Robots-Tag','noindex, nofollow')->withHeader('Referrer-Policy','no-referrer');
    }
    /** @return array<string,mixed> */
    private function actor(): array { return $this->auth->user() ?? throw new InvoiceException('Autentifică-te în administrare.',403); }
    private function url(string $suffix=''): string { return $this->config->path('admin/facturi/'.$suffix); }
    /** @param array<string,mixed> $data */
    private function render(string $template,array $data,int $status=200): ResponseInterface
    {
        return $this->view->render($template,$data+['admin_section'=>'invoices','admin_context'=>'Facturi de ieșire','can_manage'=>in_array($this->actor()['role'],['administrator','manager'],true),'admin_unread_count'=>$this->notifications->unreadCount((int)$this->actor()['id'])],$status)->withHeader('Cache-Control','no-store');
    }
    private function jsonWanted(ServerRequestInterface $request): bool { return str_contains($request->getHeaderLine('Accept'),'application/json'); }
    /** @param array<string,mixed> $data */
    private function json(array $data,int $status=200): ResponseInterface { $response=new Response($status); $response->getBody()->write(InvoiceSnapshot::json($data)); return $response->withHeader('Content-Type','application/json; charset=utf-8')->withHeader('Cache-Control','no-store'); }
    private function failure(ServerRequestInterface $request,\Throwable $e): ResponseInterface
    {
        $status=$e instanceof InvoiceException?$e->getCode():500;
        $message=$status===500?'Operațiunea nu a putut fi finalizată. Reîncearcă; documentele salvate sunt păstrate.':$e->getMessage();
        if($status===500) { error_log('Invoice '.get_class($e).': '.$e->getMessage()); }
        if($this->jsonWanted($request)) { return $this->json(['ok'=>false,'message'=>$message],$status); }
        return $this->render('admin/pages/invoice-error.twig',['error'=>$message,'back_url'=>$this->url()],$status);
    }
}
