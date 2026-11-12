<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Modules\Admin\AdminNotificationService;
use App\Modules\Suppliers\SupplierAdminService;
use App\Shared\Config\Config;
use App\Shared\Http\View;
use App\Shared\Security\Auth;
use App\Shared\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response;

/** HTTP adapter; stock rules and all authorization remain in NirService. */
final class NirAdminController
{
    public function __construct(
        private readonly NirService $nirs,
        private readonly NirDocumentStorage $storage,
        private readonly NirImportService $imports,
        private readonly NirExportService $exports,
        private readonly NirExchangeRateService $rates,
        private readonly SupplierAdminService $suppliers,
        private readonly AdminNotificationService $notifications,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly Config $config,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $filters = $request->getQueryParams();
            $filters['search']=(string) ($filters['cautare'] ?? $filters['search'] ?? '');
            $registry = $this->nirs->registry($filters, $this->actor());
            $page = (int) ($registry['page'] ?? 1);
            $pages = (int) ($registry['total_pages'] ?? 1);
            $pagination = ($registry['pagination'] ?? $registry) + ['page'=>$page,'pages'=>$pages];
            $pagination['previous_url'] = $page > 1 ? $this->url('?' . http_build_query(array_replace($filters, ['page'=>$page-1]))) : null;
            $pagination['next_url'] = $page < $pages ? $this->url('?' . http_build_query(array_replace($filters, ['page'=>$page+1]))) : null;
            return $this->render('admin/pages/nir-index.twig', $this->nirs->defaults($this->actor()) + [
                'registry'=>$registry,'documents'=>array_map($this->presentDocument(...),$registry['items'] ?? []), 'summary'=>$registry['summary'] ?? [],
                'filters'=>$filters, 'pagination'=>$pagination,
            ]);
        } catch (\Throwable $e) { return $this->failure($request, $e); }
    }

    /** @param array<string,string> $args */
    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            return $this->render('admin/pages/nir-detail.twig', ['document'=>$this->presentDocument($this->nirs->document((int) $args['id'], $this->actor()))]);
        } catch (\Throwable $e) { return $this->failure($request, $e); }
    }

    /** @param array<string,string> $args */
    public function form(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try { return $this->formResponse($request, isset($args['id']) ? (int) $args['id'] : null); }
        catch (\Throwable $e) { return $this->failure($request, $e); }
    }

    /** @param array<string,string> $args */
    public function actionForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            if ($this->action($request)==='export' && isset($request->getQueryParams()['format'])) {
                return $this->export($request,$response,['format'=>(string) $request->getQueryParams()['format']]);
            }
            $doc = isset($args['id']) ? $this->presentDocument($this->nirs->document((int) $args['id'], $this->actor())) : null;
            $kind = $this->action($request);
            $permissions = $this->nirs->permissions($this->actor());
            $permission = match ($kind) { 'delete'=>'edit', 'attachments'=>'edit', default=>$kind };
            if (empty($permissions[$permission])) { throw new \RuntimeException('Nu ai permisiunea pentru această acțiune.', 403); }
            return $this->render($this->modal($request) ? 'admin/partials/nir-action-content.twig' : 'admin/pages/nir-action.twig', array_replace($this->nirs->defaults($this->actor()), [
                'action_kind'=>$kind,'document'=>$doc,'values'=>[], 'errors'=>[], 'warnings'=>[],
                'products'=>$kind==='opening'?$this->nirs->selectProducts($this->actor()):[],
                'form_action'=>preg_replace('~/modal/$~', '/', $request->getUri()->getPath()),
                'idempotency_key'=>bin2hex(random_bytes(24)), 'back_url'=>$this->url($doc ? $doc['id'].'/' : ''),
                'is_modal'=>$this->modal($request),
            ]));
        } catch (\Throwable $e) { return $this->failure($request, $e); }
    }

    /** @param array<string,string> $args */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = (array) $request->getParsedBody();
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $stored = [];
        try {
            $this->requirePermission('edit');
            if (!is_array($input['lines'] ?? [])) { throw new NirValidationException(['lines'=>'Lista produselor nu este validă. Reîncarcă formularul și încearcă din nou.']); }
            foreach ($input['lines'] ?? [] as $index=>$line) {
                if (!is_array($line)) { throw new NirValidationException(['lines'=>'Una dintre pozițiile recepției nu este validă.']); }
                foreach ($line as $value) {
                    if (!is_scalar($value) && $value !== null) { throw new NirValidationException(['lines'=>'Verifică valorile pozițiilor din recepție.']); }
                }
                if (!empty($line['variant_id_fallback'])) { $input['lines'][$index]['variant_id']=(int) $line['variant_id_fallback']; $input['lines'][$index]['product_id']=0; }
            }
            if (($input['intent'] ?? '') === 'add_line') {
                $input['lines'] = array_values((array) ($input['lines'] ?? []));
                $input['lines'][] = $this->blankLine();
                return $this->formResponse($request, $id, $input);
            }
            if (($input['intent'] ?? '') === 'add_supplier') {
                $input['supplier_id'] = $this->saveSupplier((array) ($input['new_supplier'] ?? []));
                unset($input['new_supplier']);
                return $this->formResponse($request, $id, $input);
            }
            if (($input['intent'] ?? '') === 'add_location') {
                $location=$this->nirs->saveLocation((int) ($input['warehouse_id'] ?? 0),(array) ($input['new_location'] ?? []),$this->actor());
                $input['location_id']=$location['id'];
                unset($input['new_location']);
                return $this->formResponse($request,$id,$input);
            }
            $stored = $this->uploads($request);
            $input['attachments'] = $stored;
            $doc = str_contains($request->getUri()->getPath(), '/corectie/')
                ? $this->nirs->correct($id ?? 0, (int) ($input['row_version'] ?? 0), $input, (string) ($input['idempotency_key'] ?? ''), !empty($input['accept_warnings']), $this->actor())
                : $this->nirs->save($id, $input, $this->actor());
            $this->removeUnusedUploads($stored,$doc);
            return $this->success($request, 'Recepția a fost salvată.', $this->url($doc['id'].'/'), $doc);
        } catch (\Throwable $e) {
            foreach ($stored as $file) { $this->storage->remove((string) $file['stored_name']); }
            if (!$this->jsonWanted($request) && $e instanceof NirValidationException) {
                return $this->formResponse($request, $id, $input, $e->errors, 422, $e->warnings);
            }
            return $this->failure($request, $e);
        }
    }

    /** @param array<string,string> $args */
    public function mutate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = (array) $request->getParsedBody();
        $id = (int) ($args['id'] ?? 0);
        $stored = [];
        try {
            $version = (int) ($input['row_version'] ?? 0);
            $key = (string) ($input['idempotency_key'] ?? '');
            $kind = $this->action($request);
            if ($kind === 'delete') {
                $doc = $this->nirs->document($id, $this->actor());
                $this->nirs->delete($id, $version, $this->actor());
                foreach ($doc['attachments'] ?? [] as $file) { $this->storage->remove((string) $file['stored_name']); }
                return $this->success($request, 'Ciorna a fost ștearsă.', $this->url());
            }
            if ($kind === 'reverse' || $kind === 'attachments') { $this->requirePermission($kind === 'reverse' ? 'reverse' : 'edit'); $stored = $this->uploads($request); $input['attachments'] = $stored; }
            $doc = match ($kind) {
                'confirm'=>$this->nirs->confirm($id, $version, $key, !empty($input['accept_warnings']), $this->actor()),
                'reverse'=>$this->nirs->reverse($id, $version, $input, $key, $this->actor()),
                'attachments'=>$this->nirs->attach($id, $stored, $version, $this->actor()),
                default=>throw new \InvalidArgumentException('Acțiune necunoscută.'),
            };
            $this->removeUnusedUploads($stored,$doc);
            return $this->success($request, match ($kind) { 'confirm'=>'Recepția a fost confirmată. Stocul contabil și loturile sunt actualizate.', 'reverse'=>'Stornarea a fost înregistrată.', default=>'Documentele au fost adăugate.' }, $this->url($doc['id'].'/'), $doc);
        } catch (\Throwable $e) {
            foreach ($stored as $file) { $this->storage->remove((string) $file['stored_name']); }
            if (!$this->jsonWanted($request) && $e instanceof NirValidationException) {
                return $this->render('admin/pages/nir-action.twig', [
                    'action_kind'=>$this->action($request),'document'=>$this->presentDocument($this->nirs->document($id,$this->actor())),
                    'values'=>$input,'errors'=>$e->errors,'warnings'=>$e->warnings,'form_action'=>$request->getUri()->getPath(),
                    'idempotency_key'=>$input['idempotency_key'] ?? '', 'back_url'=>$this->url($id.'/'),
                ], 422);
            }
            return $this->failure($request, $e);
        }
    }

    public function lookup(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $query = $request->getQueryParams();
            $items=$this->nirs->lookupProducts((string) ($query['q'] ?? ''), (int) ($query['supplier_id'] ?? 0), $this->actor());
            foreach ($items as &$item) { $item['image_url']=$this->imageUrl((string) ($item['image_path'] ?? '')); } unset($item);
            return $this->json(['items'=>$items]);
        } catch (\Throwable $e) { return $this->failure($request, $e); }
    }

    public function matchLine(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $input = (array) $request->getParsedBody();
            return $this->json($this->presentMatch($this->nirs->matchLine((int) ($input['supplier_id'] ?? 0), (array) ($input['line'] ?? []), $this->actor())));
        } catch (\Throwable $e) { return $this->failure($request, $e); }
    }

    public function supplier(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->requirePermission('edit');
            $id = $this->saveSupplier((array) $request->getParsedBody());
            return $this->json(['success'=>true,'supplier'=>$this->suppliers->find($id),'suppliers'=>$this->suppliers->allForSelect()]);
        } catch (\Throwable $e) { return $this->failure($request, $e); }
    }

    /** @param array<string,mixed> $input */
    private function saveSupplier(array $input): int
    {
        if (trim((string) ($input['company_name'] ?? ''))==='') { throw new NirValidationException(['supplier_id'=>'Completează denumirea juridică a furnizorului.']); }
        try { return $this->suppliers->save($input); }
        catch (\RuntimeException $e) {
            if (get_class($e) !== \RuntimeException::class) { throw $e; }
            throw new NirValidationException(['supplier_id'=>$e->getMessage()]);
        }
    }

    public function exchangeRate(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->requirePermission('edit');
            $query = $request->getQueryParams();
            return $this->json($this->rates->lookup((string) ($query['currency'] ?? 'EUR'), (string) ($query['date'] ?? date('Y-m-d'))));
        } catch (\Throwable $e) { return $this->failure($request, $e); }
    }

    public function location(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $input=(array) $request->getParsedBody();
            $location=$this->nirs->saveLocation((int) ($input['warehouse_id'] ?? 0),$input,$this->actor());
            return $this->json(['success'=>true,'location'=>$location,'locations'=>$this->nirs->defaults($this->actor())['locations'] ?? []]);
        } catch (\Throwable $e) { return $this->failure($request,$e); }
    }

    public function import(ServerRequestInterface $request): ResponseInterface
    {
        $stored = null;
        try {
            $this->requirePermission('edit');
            $file = $request->getUploadedFiles()['file'] ?? $request->getUploadedFiles()['import_file'] ?? null;
            if (!$file instanceof UploadedFileInterface) { throw new \InvalidArgumentException('Alege factura XLSX sau XML pentru import.'); }
            $stored = $this->storage->store($file);
            $result = $this->imports->parse($this->storage->path((string) $stored['stored_name']) ?? '', (string) $stored['extension']);
            $input = (array) $request->getParsedBody();
            $supplierId = (int) ($input['supplier_id'] ?? 0);
            $taxId=preg_replace('/\s+/', '', strtoupper((string) ($result['header']['supplier_tax_id'] ?? '')));
            if ($supplierId===0 && $taxId!=='') {
                $candidates=array_filter($this->nirs->defaults($this->actor())['suppliers'],static fn(array $supplier):bool=>preg_replace('/^(RO)?/','',preg_replace('/\s+/','',strtoupper((string) ($supplier['tax_id'] ?? ''))))===preg_replace('/^(RO)?/','',$taxId));
                if (count($candidates)===1) { $supplierId=(int) array_values($candidates)[0]['id']; $result['header']['supplier_id']=$supplierId; }
            }
            foreach ($result['lines'] as &$line) { $line['match'] = $this->presentMatch($this->nirs->matchLine($supplierId, $line, $this->actor())); }
            unset($line);
            return $this->json(['success'=>true] + $result);
        } catch (\Throwable $e) { return $this->failure($request, $e); }
        finally { if ($stored !== null) { $this->storage->remove((string) $stored['stored_name']); } }
    }

    public function legacyFifo(ServerRequestInterface $request,ResponseInterface $response): ResponseInterface
    {
        $query=$request->getUri()->getQuery();
        return $response->withStatus(301)->withHeader('Location',$this->url('stoc/').($query!==''?'?'.$query:''));
    }

    public function fifo(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $data=$this->nirs->fifo($request->getQueryParams(),$this->actor());
            $defaults=$this->nirs->defaults($this->actor());
            $warehouses=array_column($defaults['warehouses'] ?? [],'name','id');
            $products=$this->nirs->selectProducts($this->actor());
            $productNames=array_column($products,'product_name','product_id');
            $supplierRows=array_column($defaults['suppliers'] ?? [],null,'id');
            foreach ($data['reconciliation'] ?? [] as $index=>$row) {
                $data['reconciliation'][$index]+= ['product_name'=>$productNames[$row['product_id']] ?? 'Produs indisponibil','stock_quantity'=>NirDecimal::quantity((int) $row['balance_units']),'fifo_quantity'=>NirDecimal::quantity((int) $row['fifo_units']),'difference'=>NirDecimal::quantity((int) $row['difference_units']),'warehouse_name'=>$warehouses[$row['warehouse_id']] ?? 'Gestiune'];
            }
            foreach ($data['references'] ?? [] as $index=>$reference) {
                $supplierId=(int)$reference['supplier_id'];
                // Retained references also need readable names for inactive suppliers.
                $supplierRows[$supplierId] ??= $this->suppliers->find($supplierId) ?? [];
                $supplier=$supplierRows[$supplierId];
                $data['references'][$index]['supplier_alias']=trim((string)($supplier['alias'] ?? '')) ?: (trim((string)($supplier['company_name'] ?? '')) ?: 'Furnizor indisponibil');
                $data['references'][$index]['product_name']=$productNames[$reference['product_id']] ?? 'Produs indisponibil';
            }
            foreach ($data['layers'] ?? [] as $index=>$layer) {
                $data['layers'][$index]['display_received_at']=$this->date((string) $layer['received_at']);
                foreach ($layer['consumptions'] ?? [] as $position=>$consumption) {
                    $data['layers'][$index]['consumptions'][$position]['display_created_at']=$this->date((string) $consumption['created_at']);
                }
            }
            foreach ($data['pending'] ?? [] as $index=>$pending) { $data['pending'][$index]['display_created_at']=$this->date((string) $pending['created_at']); }
            return $this->render('admin/pages/nir-fifo.twig',array_replace($defaults,$data,[
                'fifo'=>$data,'filters'=>$request->getQueryParams(),'pending_returns'=>$this->nirs->pendingReturnReceipts($this->actor()),
                'products'=>$products,
            ]));
        } catch (\Throwable $e) { return $this->failure($request,$e); }
    }

    public function opening(ServerRequestInterface $request): ResponseInterface
    {
        $input=(array) $request->getParsedBody();
        try {
            $input['attachments']=[];
            $doc=$this->nirs->opening($input,(string) ($input['idempotency_key'] ?? ''),$this->actor());
            return $this->success($request,'Soldul inițial a fost înregistrat.',$this->url($doc['id'].'/'),$doc);
        } catch (\Throwable $e) {
            if (!$this->jsonWanted($request) && $e instanceof NirValidationException) {
                return $this->render('admin/pages/nir-action.twig',array_replace($this->nirs->defaults($this->actor()),[
                    'action_kind'=>'opening','values'=>$input,'errors'=>$e->errors,'warnings'=>$e->warnings,
                    'products'=>$this->nirs->selectProducts($this->actor()),'form_action'=>$request->getUri()->getPath(),
                    'idempotency_key'=>$input['idempotency_key'] ?? '', 'back_url'=>$this->url('stoc/'),
                ]),422);
            }
            return $this->failure($request,$e);
        }
    }

    /** @param array<string,string> $args */
    public function returnForm(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $this->requirePermission('confirm');
            $returns=$this->nirs->pendingReturnReceipts($this->actor());
            $selected=null;
            foreach ($returns as $return) { if ((int) ($return['return_id'] ?? $return['id'] ?? 0)===(int) $args['id']) { $selected=$return; break; } }
            if ($selected===null) { throw new NirNotFoundException('Returul nu este disponibil pentru recepție.'); }
            return $this->render($this->modal($request)?'admin/partials/nir-action-content.twig':'admin/pages/nir-action.twig',array_replace($this->nirs->defaults($this->actor()),[
                'action_kind'=>'customer_return','return'=>$selected,'return_document'=>$selected,'errors'=>[],'values'=>[],
                'form_title'=>'Recepționează returul',
                'form_action'=>$this->url('retururi/'.$args['id'].'/receptie/'),'back_url'=>$this->url('stoc/'),
                'idempotency_key'=>bin2hex(random_bytes(24)),'is_modal'=>$this->modal($request),
            ]));
        } catch (\Throwable $e) { return $this->failure($request,$e); }
    }

    /** @param array<string,string> $args */
    public function receiveReturn(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        $stored=[];
        $input=(array) $request->getParsedBody();
        try {
            $this->requirePermission('confirm');
            $stored=$this->uploads($request);
            $input['attachments']=$stored;
            $doc=$this->nirs->receiveReturn((int) $args['id'],$input,(string) ($input['idempotency_key'] ?? ''),$this->actor());
            $this->removeUnusedUploads($stored,$doc);
            return $this->success($request,'Returul fizic a fost recepționat.',$this->url($doc['id'].'/'),$doc);
        } catch (\Throwable $e) {
            foreach ($stored as $file) { $this->storage->remove((string) $file['stored_name']); }
            if (!$this->jsonWanted($request) && $e instanceof NirValidationException) {
                $returns=$this->nirs->pendingReturnReceipts($this->actor());
                $selected=null;
                foreach ($returns as $return) { if ((int) $return['return_id']===(int) $args['id']) { $selected=$return; break; } }
                return $this->render('admin/pages/nir-action.twig',array_replace($this->nirs->defaults($this->actor()),[
                    'action_kind'=>'customer_return','return_document'=>$selected,'form_title'=>'Recepționează returul',
                    'values'=>$input,'errors'=>$e->errors,'warnings'=>$e->warnings,'form_action'=>$request->getUri()->getPath(),
                    'idempotency_key'=>$input['idempotency_key'] ?? '', 'back_url'=>$this->url('stoc/'),
                ]),422);
            }
            return $this->failure($request,$e);
        }
    }

    /** @param array<string,string> $args */
    public function dismissReturn(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $this->nirs->dismissReturnReminder((int) $args['id'],$this->actor());
            return $this->success($request,'Reamintirea a fost ascunsă pentru astăzi.',$this->url('stoc/'));
        } catch (\Throwable $e) { return $this->failure($request,$e); }
    }

    /** @param array<string,string> $args */
    public function reassign(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $this->nirs->reassignReference((int) $args['id'],(array) $request->getParsedBody(),$this->actor());
            return $this->success($request,'Referința furnizorului a fost actualizată.',$this->url('stoc/'));
        } catch (\Throwable $e) { return $this->failure($request,$e); }
    }

    /** @param array<string,string> $args */
    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $doc = $this->nirs->document((int) $args['id'], $this->actor());
            // Supplier documents expose purchase costs, so require the same cost permission.
            $this->requirePermission('costs');
            foreach ($doc['attachments'] ?? [] as $file) {
                if ((int) $file['id'] === (int) $args['attachment']) {
                    $path = $this->storage->path((string) $file['stored_name']);
                    if ($path !== null) { return $this->file($path, (string) $file['original_name'], (string) $file['mime_type']); }
                }
            }
            throw new NirNotFoundException('Documentul nu mai este disponibil.');
        } catch (\Throwable $e) { return $this->failure($request, $e); }
    }

    /** @param array<string,string> $args */
    public function detach(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $id = (int) $args['id'];
            $before = $this->nirs->document($id, $this->actor());
            $doc = $this->nirs->detach($id,(int) $args['attachment'], (int) (((array) $request->getParsedBody())['row_version'] ?? 0),$this->actor());
            foreach ($before['attachments'] ?? [] as $file) { if ((int) $file['id'] === (int) $args['attachment']) { $this->storage->remove((string) $file['stored_name']); } }
            return $this->success($request,'Documentul a fost eliminat.',$this->url($id.'/'),$doc);
        } catch (\Throwable $e) { return $this->failure($request,$e); }
    }

    /** @param array<string,string> $args */
    public function detachForm(ServerRequestInterface $request,ResponseInterface $response,array $args): ResponseInterface
    {
        try {
            $this->requirePermission('edit');
            $doc=$this->presentDocument($this->nirs->document((int) $args['id'],$this->actor()));
            $attachment=null;
            foreach ($doc['attachments'] as $file) { if ((int) $file['id']===(int) $args['attachment']) { $attachment=$file; break; } }
            if ($attachment===null) { throw new NirNotFoundException('Documentul nu mai este disponibil.'); }
            return $this->render($this->modal($request)?'admin/partials/nir-action-content.twig':'admin/pages/nir-action.twig',[
                'action_kind'=>'delete_attachment','document'=>$doc,'attachment'=>$attachment,'errors'=>[],
                'form_action'=>$this->url($args['id'].'/documente/'.$args['attachment'].'/sterge/'),'back_url'=>$this->url($args['id'].'/'),
                'is_modal'=>$this->modal($request),
            ]);
        } catch (\Throwable $e) { return $this->failure($request,$e); }
    }

    /** @param array<string,string> $args */
    public function export(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = null;
        try {
            $this->requirePermission('export');
            $costs = !empty($this->nirs->permissions($this->actor())['costs']);
            $filters=$this->exportFilters($request->getQueryParams());
            $result = isset($args['id'])
                ? $this->exports->individual($this->nirs->document((int) $args['id'],$this->actor()), $args['format'], $costs)
                : $this->exports->registry($this->nirs->registryForExport($filters,$this->actor()),$args['format'],$filters,$costs);
            return $this->file($result['path'],$result['filename'],$result['mime']);
        } catch (\Throwable $e) { return $this->failure($request,$e); }
        finally { if ($result !== null) { $this->exports->cleanup($result['path']); } }
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function exportFilters(array $filters): array
    {
        $today=new \DateTimeImmutable('today',new \DateTimeZone('Europe/Bucharest'));
        $period=(string) ($filters['period'] ?? 'custom');
        [$from,$to]=match($period) {
            'today'=>[$today,$today],
            'month'=>[$today->modify('first day of this month'),$today],
            'previous_month'=>[$today->modify('first day of last month'),$today->modify('last day of last month')],
            '3months'=>[$today->modify('first day of this month')->modify('-2 months'),$today],
            '6months'=>[$today->modify('first day of this month')->modify('-5 months'),$today],
            'year'=>[$today->setDate((int) $today->format('Y'),1,1),$today],
            'all','custom'=>[null,null],
            default=>throw new \InvalidArgumentException('Perioada selectată nu este validă.'),
        };
        if($period==='all') { unset($filters['from'],$filters['to']); }
        elseif($from!==null && $to!==null) { $filters['from']=$from->format('Y-m-d'); $filters['to']=$to->format('Y-m-d'); }
        else {
            foreach(['from','to'] as $field) {
                $value=(string) ($filters[$field] ?? '');
                if($value==='') {
                    if (isset($filters['period']) && $period==='custom') { throw new NirValidationException([$field=>'Alege ambele date pentru intervalul personalizat.']); }
                    unset($filters[$field]); continue;
                }
                $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
                if($parsed===false || $parsed->format('Y-m-d')!==$value) { throw new NirValidationException([$field=>'Alege o dată validă.']); }
            }
            if(isset($filters['from'],$filters['to']) && $filters['from']>$filters['to']) { throw new NirValidationException(['to'=>'Data de sfârșit trebuie să fie după data de început.']); }
        }
        return $filters;
    }

    /** @param array<string,mixed>|null $submitted @param array<string,string> $errors @param list<string> $warnings */
    private function formResponse(ServerRequestInterface $request, ?int $id, ?array $submitted = null, array $errors = [], int $status = 200, array $warnings = []): ResponseInterface
    {
        $this->requirePermission('edit');
        $defaults = $this->nirs->defaults($this->actor());
        $doc = $id === null ? null : $this->presentDocument($this->nirs->document($id,$this->actor()));
        $correction = str_contains($request->getUri()->getPath(),'/corectie/');
        if ($doc !== null && ($doc['status'] ?? '') === 'confirmed' && !$correction) { return $this->view->redirect($this->url($id.'/')); }
        $values = $submitted ?? $doc ?? $defaults['values'] ?? [];
        // A malformed request must not make the validation page fail while rendering rows.
        if ($submitted !== null && isset($values['lines'])) {
            $values['lines']=is_array($values['lines']) ? array_values(array_filter($values['lines'],'is_array')) : [];
            foreach ($values['lines'] as &$line) {
                foreach ($line as $field=>$value) { if (!is_scalar($value) && $value !== null) { unset($line[$field]); } }
            }
            unset($line);
        }
        if ($submitted===null && $doc!==null) {
            foreach (['nir_at','received_at'] as $field) { $values[$field]=$this->date((string) ($doc[$field] ?? ''),'Y-m-d\TH:i'); }
        }
        if (empty($values['lines'])) { $values['lines'] = [$this->blankLine()]; }
        return $this->render($this->modal($request) ? 'admin/partials/nir-form-content.twig' : 'admin/pages/nir-form.twig', array_replace($defaults,[
            'products'=>$this->nirs->selectProducts($this->actor()),
            'values'=>$values,'document'=>$doc,'errors'=>$errors,'warnings'=>$warnings, 'is_edit'=>$id!==null, 'is_correction'=>$correction,
            'form_action'=>$this->url($id===null ? 'nou/' : $id.($correction ? '/corectie/' : '/editare/')),
            'back_url'=>$this->url($id===null?'':$id.'/'), 'is_modal'=>$this->modal($request), 'idempotency_key'=>bin2hex(random_bytes(24)),
        ]),$status);
    }

    /** @return array<string,mixed> */
    private function blankLine(): array
    {
        return ['invoice_quantity'=>'1','received_quantity'=>'1','accepted_quantity'=>'1','rejected_quantity'=>'0','conversion_factor'=>'1','purchase_unit'=>'buc','stock_unit'=>'buc','unit_price'=>'0','discount_percent'=>'0','vat_percent'=>'21','extra_cost_ron'=>'0','price_mode'=>'unit'];
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function presentDocument(array $document): array
    {
        $document['number']=$document['display_number'] ?? $document['number'] ?? $document['temp_number'] ?? '';
        $document['operation_type']=$document['type'] ?? 'supplier_receipt';
        $document['supplier_name']=$document['supplier']['alias'] ?? $document['supplier']['company_name'] ?? '';
        $document['warehouse_name']=$document['warehouse']['name'] ?? '';
        foreach (['nir_at','received_at','created_at','confirmed_at'] as $field) { $document['display_'.$field]=$this->date((string) ($document[$field] ?? '')); }
        $document['display_invoice_date']=$this->date((string) ($document['invoice_date'] ?? ''),'d.m.Y');
        foreach ($document['lines'] ?? [] as $i=>$line) {
            $document['lines'][$i]['image_url']=$this->imageUrl((string) ($line['image_path'] ?? ''));
            $available=\Brick\Math\BigDecimal::of((int) ($line['available_units'] ?? 0))->dividedBy(10000,4);
            $factor=\Brick\Math\BigDecimal::of((string) ($line['conversion_factor'] ?? '1'));
            $document['lines'][$i]['available_quantity']=(string) ($factor->isPositive()?$available->dividedBy($factor,4,\Brick\Math\RoundingMode::Down):$available);
        }
        $labels=[
            'draft_created'=>'Recepție salvată în lucru','draft_updated'=>'Document actualizat','draft_deleted'=>'Document în lucru șters',
            'confirmed'=>'Recepție confirmată','reconfirmed'=>'Recepție reconfirmată după corecție','corrected'=>'Corecție aplicată',
            'supplier_return'=>'Retur către furnizor înregistrat','reversed'=>'Retur către furnizor înregistrat',
            'attachment_added'=>'Document justificativ adăugat','attachments_added'=>'Documente justificative adăugate',
            'attachment_deleted'=>'Document justificativ eliminat','attachment_removed'=>'Document justificativ eliminat',
            'opening_balance'=>'Sold inițial înregistrat','customer_return_received'=>'Retur client recepționat','customer_return'=>'Retur client recepționat',
            'reference_saved'=>'Referință furnizor salvată','reference_reassigned'=>'Referință furnizor reasociată','location_created'=>'Locație de recepție adăugată',
        ];
        foreach ($document['audit'] ?? [] as $i=>$event) {
            $document['audit'][$i]['label']=$labels[$event['action']] ?? 'Actualizare document';
            $document['audit'][$i]['display_created_at']=$this->date((string) ($event['created_at'] ?? ''));
        }
        return $document;
    }
    private function date(string $value,string $format='d.m.Y, H:i'): string
    {
        if ($value==='') { return ''; }
        try { return (new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format($format); }
        catch (\Throwable) { return $value; }
    }
    private function imageUrl(string $path): string { return $path===''?'':$this->config->path(ltrim($path,'/')); }
    /** @param array<string,mixed> $match @return array<string,mixed> */
    private function presentMatch(array $match): array
    {
        if (is_array($match['product'] ?? null)) { $match['product']['image_url']=$this->imageUrl((string) ($match['product']['image_path'] ?? '')); }
        foreach ($match['suggestions'] ?? [] as $i=>$item) { $match['suggestions'][$i]['image_url']=$this->imageUrl((string) ($item['image_path'] ?? '')); }
        return $match;
    }

    /** @param list<array<string,mixed>> $uploaded @param array<string,mixed> $document */
    private function removeUnusedUploads(array $uploaded,array $document): void
    {
        $retained=array_column($document['attachments'] ?? [],'stored_name');
        foreach ($uploaded as $file) {
            if (!in_array($file['stored_name'],$retained,true)) { $this->storage->remove((string) $file['stored_name']); }
        }
    }

    /** @return list<array<string,mixed>> */
    private function uploads(ServerRequestInterface $request): array
    {
        $files = $request->getUploadedFiles()['attachments'] ?? $request->getUploadedFiles()['documents'] ?? [];
        if ($files instanceof UploadedFileInterface) { $files = [$files]; }
        if (!is_array($files) || count($files)>30) { throw new NirValidationException(['attachments'=>'Încarcă maximum 30 de documente odată.']); }
        $stored=[];
        try {
            foreach ($files as $file) {
                if ($file instanceof UploadedFileInterface && $file->getError()!==UPLOAD_ERR_NO_FILE) { $stored[]=$this->storage->store($file); }
            }
            return $stored;
        } catch (\Throwable $e) {
            foreach ($stored as $file) { $this->storage->remove((string) $file['stored_name']); }
            if ($e instanceof \InvalidArgumentException) { throw new NirValidationException(['attachments'=>$e->getMessage()]); }
            throw $e;
        }
    }

    private function action(ServerRequestInterface $request): string
    {
        foreach (['confirma'=>'confirm','sterge'=>'delete','storno'=>'reverse','documente'=>'attachments','sold-initial'=>'opening','export'=>'export'] as $segment=>$kind) {
            if (str_contains($request->getUri()->getPath(),'/'.$segment.'/')) { return $kind; }
        }
        throw new \InvalidArgumentException('Acțiune necunoscută.');
    }
    /** @return array<string,mixed> */
    private function actor(): array { return $this->auth->user() ?? throw new \RuntimeException('Autentifică-te în administrare.',403); }
    private function requirePermission(string $permission): void { if (empty($this->nirs->permissions($this->actor())[$permission])) { throw new \RuntimeException('Nu ai permisiunea pentru această acțiune.',403); } }
    private function url(string $suffix=''): string { return $this->config->path('admin/nir-uri/'.$suffix); }
    private function modal(ServerRequestInterface $request): bool { return str_ends_with($request->getUri()->getPath(),'/modal/'); }
    private function jsonWanted(ServerRequestInterface $request): bool { return str_contains($request->getHeaderLine('Accept'),'application/json') || strtolower($request->getHeaderLine('X-Requested-With'))==='xmlhttprequest'; }

    /** @param array<string,mixed> $data */
    private function render(string $template,array $data,int $status=200): ResponseInterface
    {
        return $this->view->render($template,array_replace([
            'admin_section'=>'nir','admin_context'=>'NIR-uri', 'permissions'=>$this->nirs->permissions($this->actor()),
            'today'=>(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Bucharest')))->format('Y-m-d'),
            'admin_unread_count'=>$this->notifications->unreadCount((int) $this->actor()['id']),
        ],$data),$status)->withHeader('Cache-Control','no-store');
    }
    /** @param array<string,mixed> $data */
    private function json(array $data,int $status=200): ResponseInterface
    {
        $response=new Response($status); $response->getBody()->write(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type','application/json; charset=utf-8')->withHeader('Cache-Control','no-store');
    }
    /** @param array<string,mixed>|null $document */
    private function success(ServerRequestInterface $request,string $message,string $redirect,?array $document=null): ResponseInterface
    {
        if ($this->jsonWanted($request)) { return $this->json(['success'=>true,'message'=>$message,'redirect'=>$redirect,'document'=>$document]); }
        Flash::set('success',$message); return $this->view->redirect($redirect);
    }
    private function failure(ServerRequestInterface $request,\Throwable $e): ResponseInterface
    {
        $code=(int) $e->getCode();
        $status=in_array($code,[403,404,409,422],true)?$code:($e instanceof \InvalidArgumentException?422:500);
        $message=$status===500?'Operațiunea nu a putut fi finalizată. Datele confirmate au rămas protejate.':$e->getMessage();
        if ($status===500) { error_log('NIR '.get_class($e).': '.$e->getMessage()); }
        $errors=$e instanceof NirValidationException?$e->errors:['_form'=>$message];
        $warnings=$e instanceof NirValidationException?$e->warnings:[];
        if ($this->jsonWanted($request)) { return $this->json(['success'=>false,'message'=>$message,'errors'=>$errors,'warnings'=>$warnings],$status); }
        return $this->render('admin/pages/nir-error.twig',['error_message'=>$message,'errors'=>$errors,'back_url'=>$this->url()],$status);
    }
    private function file(string $path,string $name,string $mime): ResponseInterface
    {
        $source=fopen($path,'rb');
        if ($source===false) { throw new NirNotFoundException('Fișierul nu mai este disponibil.'); }
        $buffer=tmpfile();
        if ($buffer===false) { fclose($source); throw new \RuntimeException('Fișierul nu poate fi pregătit.'); }
        stream_copy_to_stream($source,$buffer); fclose($source); rewind($buffer);
        $response=(new Response())->withBody(new \Slim\Psr7\Stream($buffer));
        $safe=preg_replace('/[^A-Za-z0-9._-]/','_', $name) ?: 'document';
        return $response->withHeader('Content-Type',$mime)->withHeader('Content-Disposition','attachment; filename="'.$safe.'"; filename*=UTF-8\'\''.rawurlencode($name))->withHeader('Cache-Control','private, no-store')->withHeader('X-Content-Type-Options','nosniff');
    }
}
