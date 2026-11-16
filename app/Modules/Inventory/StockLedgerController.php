<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use App\Modules\Admin\AdminNotificationService;
use App\Modules\Invoicing\InvoiceException;
use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;

final class StockLedgerController
{
    public function __construct(private readonly StockLedgerService $service,private readonly StockLedgerRepository $repository,private readonly StockLedgerExportService $exports,private readonly AdminNotificationService $notifications,private readonly Auth $auth,private readonly View $view,private readonly Config $config) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $filters=$request->getQueryParams(); $data=$this->service->registry($filters,$this->actor());
            $data['previous_url']=$data['page']>1?$this->url('?'.http_build_query(array_replace($filters,['page'=>$data['page']-1]))):null;
            $data['next_url']=$data['page']<$data['pages']?$this->url('?'.http_build_query(array_replace($filters,['page'=>$data['page']+1]))):null;
            return $this->render('admin/pages/stock-ledger.twig',$data+['filters'=>$filters,'export_query'=>http_build_query(array_diff_key($filters,['page'=>true]))]);
        } catch(\Throwable $e) { return $this->failure($e); }
    }

    /** @param array<string,string> $args */
    public function detail(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $query=$request->getQueryParams(); $data=$this->service->detail((int)$args['id'],$query,$this->actor());
            $entries=array_values(array_filter($data['movements'],static fn(array $m):bool=>!empty($m['nir_id']) && $m['quantity_units']>0));
            $data['entries']=$this->pageRows(array_reverse($entries),'entries_page',$query,(int)$args['id']);
            $data['journal']=$this->pageRows(array_reverse($data['movements']),'journal_page',$query,(int)$args['id']);
            $data['adjustment_request_key']=bin2hex(random_bytes(24));
            return $this->render('admin/pages/stock-ledger-detail.twig',$data);
        }
        catch(\Throwable $e) { return $this->failure($e); }
    }

    /** @param array<string,string> $args */
    public function adjustOnline(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $data=(array)($request->getParsedBody() ?? []);
            $result=$this->service->adjustOnline(
                (int)$args['id'],(int)($data['variant_id'] ?? 0),(string)($data['direction'] ?? ''),
                (string)($data['quantity'] ?? ''),(string)($data['reason'] ?? ''),(string)($data['request_key'] ?? ''),$this->actor()
            );
            Flash::set('success',$result['duplicate']?'Ajustarea fusese deja înregistrată; stocul nu a fost modificat din nou.':'Disponibilitatea online a fost ajustată și operațiunea a fost auditată.');
        } catch(\Throwable $e) { Flash::set('error',$e->getMessage()); }
        return $this->view->redirect($this->url((int)$args['id'].'/'));
    }

    /** @param array<string,string> $args */
    public function threshold(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        $json=str_contains($request->getHeaderLine('Accept'),'application/json');
        try {
            $this->service->threshold((int)$args['id'],(string)(((array)$request->getParsedBody())['minimum'] ?? ''),$this->actor());
            if($json) { $response=new Response(); $response->getBody()->write(json_encode(['ok'=>true,'message'=>'Pragul a fost actualizat.'],JSON_THROW_ON_ERROR)); return $response->withHeader('Content-Type','application/json'); }
            Flash::set('success','Pragul de alertă a fost actualizat.');
        } catch(\Throwable $e) {
            if($json) { $response=new Response(422); $response->getBody()->write(json_encode(['ok'=>false,'message'=>$e instanceof InvoiceException?$e->getMessage():'Pragul nu a putut fi salvat. Reîncearcă.'],JSON_THROW_ON_ERROR)); return $response->withHeader('Content-Type','application/json'); }
            Flash::set('error',$e->getMessage());
        }
        return $this->view->redirect($this->url((int)$args['id'].'/'));
    }

    /** @param array<string,string> $args */
    public function export(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $actor=$this->actor(); $this->service->allow($actor,true); $company=$this->repository->companyProfile();
            $report=isset($args['id'])?$this->service->detail((int)$args['id'],array_replace($this->service->period($request->getQueryParams()),['documented_only'=>true]),$actor):$this->service->accountingReport($request->getQueryParams(),$actor);
            if(\App\Shared\Http\ExportSummary::requested($request)) {
                $details=isset($args['id'])?[$report]:$report['details'];
                return \App\Shared\Http\ExportSummary::response(count($details),array_sum(array_map(static fn(array $detail):int=>count($detail['movements']),$details)),1,'Produse');
            }
            $file=isset($args['id'])?$this->exports->product($report,$company):$this->exports->accounting($report,$company);
            $source=fopen($file['path'],'rb'); $buffer=tmpfile(); if(!$source||!$buffer) { throw new InvoiceException('Exportul nu a putut fi deschis.'); }
            stream_copy_to_stream($source,$buffer); fclose($source); rewind($buffer); $this->exports->cleanup($file['path']);
            return (new Response())->withBody(new Stream($buffer))->withHeader('Content-Length',(string)(fstat($buffer)['size'] ?? 0))->withHeader('Content-Type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->withHeader('Content-Disposition','attachment; filename="'.$file['name'].'"')->withHeader('Cache-Control','private, no-store')->withHeader('X-Content-Type-Options','nosniff');
        } catch(\Throwable $e) { return $this->failure($e); }
    }

    /** @return array<string,mixed> */
    private function actor(): array { return $this->auth->user() ?? throw new InvoiceException('Autentifică-te în administrare.',403); }
    private function url(string $suffix=''): string { return $this->config->path('admin/stoc/'.$suffix); }
    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $query @return array<string,mixed> */
    private function pageRows(array $rows,string $key,array $query,int $id): array
    {
        $total=count($rows); $pages=max(1,(int)ceil($total/5)); $page=max(1,min($pages,(int)($query[$key] ?? 1)));
        return ['items'=>array_slice($rows,($page-1)*5,5),'total'=>$total,'page'=>$page,'pages'=>$pages,'from'=>$total?($page-1)*5+1:0,'to'=>min($page*5,$total),
            'previous'=>$page>1?$this->url($id.'/?'.http_build_query(array_replace($query,[$key=>$page-1]))):null,
            'next'=>$page<$pages?$this->url($id.'/?'.http_build_query(array_replace($query,[$key=>$page+1]))):null];
    }
    /** @param array<string,mixed> $data */
    private function render(string $template,array $data,int $status=200): ResponseInterface
    {
        $actor=$this->actor(); return $this->view->render($template,$data+['admin_section'=>'stock','admin_context'=>'Stoc și fișa produsului','can_manage'=>in_array($actor['role'],['administrator','manager'],true),'admin_unread_count'=>$this->notifications->unreadCount((int)$actor['id'])],$status)->withHeader('Cache-Control','no-store');
    }
    private function failure(\Throwable $e): ResponseInterface
    {
        $status=$e instanceof InvoiceException?$e->getCode():500; if($status<400||$status>599) { $status=500; }
        if($status===500) { error_log('Stock ledger '.get_class($e).': '.$e->getMessage()); }
        return $this->render('admin/pages/stock-ledger-error.twig',['error'=>$status===500?'Evidența stocului nu a putut fi încărcată. Reîncearcă.':$e->getMessage(),'back_url'=>$this->url()],$status);
    }
}
