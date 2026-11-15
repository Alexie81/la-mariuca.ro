<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;

final class SpvController
{
    public function __construct(private readonly SpvSettingsService $settings,private readonly SpvConnection $connection,private readonly SpvHttpClient $http,private readonly InvoiceRepository $repository,private readonly InvoiceService $invoices,private readonly Config $config,private readonly Auth $auth,private readonly View $view,private readonly string $root) {}
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try { $this->settings->allow($this->actor()); }
        catch(InvoiceException $error) { return $this->forbidden($response,$error); }
        $notice=$_SESSION['spv_notice'] ?? null; unset($_SESSION['spv_notice']);
        return $this->view->render('admin/pages/spv.twig',['admin_section'=>'spv_preview','admin_context'=>'SPV · RO e-Factura','settings'=>$this->settings->state(),'connection'=>$this->connection->state(),'jobs'=>$this->settings->jobs(),'notice'=>$notice,'callback'=>$this->connection->callback()])->withHeader('Cache-Control','no-store');
    }
    public function action(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $this->settings->allow($this->actor()); $input=(array)$request->getParsedBody(); $operation=$args['operation'];
            if($operation==='conecteaza') {
                $state=bin2hex(random_bytes(32)); $url=$this->connection->authorizationUrl($state);
                $_SESSION['spv_oauth']=['state'=>$state,'expires'=>time()+600,'actor'=>$this->actor()['id']];
                return $this->view->redirect($url);
            }
            match($operation) {
                'salveaza'=>$this->settings->save($input,$this->actor()),
                'testeaza'=>$this->connection->test(),
                'deconecteaza'=>$this->connection->disconnect(),
                default=>throw new InvoiceException('Acțiune indisponibilă.',404),
            };
            if($operation==='deconecteaza') { (new SpvSchedule($this->repository->pdo()))->rebuild(); }
            $this->audit($operation); $_SESSION['spv_notice']=['ok'=>true,'text'=>match($operation){'salveaza'=>'Setările au fost salvate. Coada netransmisă a fost recalculată.','testeaza'=>'Conexiunea ANAF răspunde. Dreptul de transmitere pentru firmă se verifică la încărcare.',default=>'Conexiunea ANAF a fost închisă.'}];
        } catch(\Throwable $error) { if($error instanceof InvoiceException && $error->getCode()===403) { return $this->forbidden($response,$error); } $this->error($error); }
        return $this->view->redirect($this->config->path('admin/spv-efactura/'));
    }
    public function callback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $this->settings->allow($this->actor()); $query=$request->getQueryParams(); $pending=$_SESSION['spv_oauth'] ?? []; unset($_SESSION['spv_oauth']);
            if(!is_string($query['state'] ?? null) || !isset($pending['state']) || !hash_equals($pending['state'],$query['state']) || ($pending['expires'] ?? 0)<time() || ($pending['actor'] ?? null)!==$this->actor()['id']) { throw new InvoiceException('Autorizarea a expirat sau nu aparține acestei sesiuni. Reia conectarea.'); }
            if(!is_string($query['code'] ?? null) || $query['code']==='' || strlen($query['code'])>8000) { throw new InvoiceException('Autorizarea ANAF nu a fost finalizată.'); }
            $this->connection->exchange($query['code']); (new SpvSchedule($this->repository->pdo()))->rebuild(); $this->audit('conectat');
            $_SESSION['spv_notice']=['ok'=>true,'text'=>'Conexiunea ANAF a fost salvată securizat.'];
        } catch(\Throwable $error) { if($error instanceof InvoiceException && $error->getCode()===403) { return $this->forbidden($response,$error); } $this->error($error); }
        return $this->view->redirect($this->config->path('admin/spv-efactura/'));
    }
    public function invoiceAction(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        $id=(int)$args['id'];
        try {
            $this->settings->allow($this->actor());
            $this->invoices->load($id); $job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]);
            $environment=$job['environment'] ?? $this->settings->state()['environment'];
            $gateway=new AnafFiscalGateway($this->connection,$this->http,$this->config,$environment);
            $service=new InvoiceFiscalService($this->repository,$this->invoices,$gateway);
            if($args['operation']==='trimite') { $service->queue($id,$this->actor()); $service->process($id); }
            elseif($args['operation']==='verifica') { $service->poll($id); }
            elseif($args['operation']==='reia') { $service->retry($id,$this->actor()); $service->process($id); }
            else { throw new InvoiceException('Acțiune indisponibilă.',404); }
            $_SESSION['spv_notice']=['ok'=>true,'text'=>'Operațiunea a fost procesată. Verifică starea documentului și răspunsul ANAF mai jos.'];
        } catch(\Throwable $error) { if($error instanceof InvoiceException && $error->getCode()===403) { return $this->forbidden($response,$error); } $this->error($error); }
        return $this->view->redirect($this->config->path('admin/spv-efactura/').'#document-'.$id);
    }
    public function archive(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try { $this->settings->allow($this->actor()); }
        catch(InvoiceException $error) { return $this->forbidden($response,$error); }
        $id=(int)$args['id']; $this->invoices->load($id);
        $job=$this->repository->one('invoice_fiscal_jobs',['invoice_id'=>$id]) ?? throw new InvoiceException('Arhiva nu este disponibilă.',404);
        $gateway=new AnafFiscalGateway($this->connection,$this->http,$this->config,$job['environment']);
        $path=(new SpvWorker($this->repository,$this->invoices,$this->connection,$this->http,$this->config,$this->root))->archive($id,$gateway);
        return (new Response())->withBody(new Stream(fopen($path,'rb')))->withHeader('Content-Type','application/zip')->withHeader('Content-Disposition','attachment; filename="ANAF-'.$id.'.zip"')->withHeader('Cache-Control','private, no-store')->withHeader('X-Content-Type-Options','nosniff');
    }
    private function error(\Throwable $error): void { $_SESSION['spv_notice']=['ok'=>false,'text'=>$error instanceof InvoiceException?$error->getMessage():'Operațiunea nu a reușit. Verifică setările conexiunii și reîncearcă.']; }
    private function forbidden(ResponseInterface $response,InvoiceException $error): ResponseInterface { $response->getBody()->write($error->getMessage()); return $response->withStatus(403)->withHeader('Content-Type','text/plain; charset=utf-8')->withHeader('Cache-Control','no-store'); }
    private function actor(): array { return $this->auth->user() ?? []; }
    private function audit(string $action): void { $this->repository->insert('audit_log',['actor_user_id'=>$this->actor()['id'],'action'=>'spv.'.$action,'entity_type'=>'spv','entity_id'=>1,'context_json'=>'{}','created_at'=>$this->repository->now()]); }
}
