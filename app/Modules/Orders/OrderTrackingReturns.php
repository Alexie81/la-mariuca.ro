<?php
declare(strict_types=1);
namespace App\Modules\Orders;

use App\Modules\Invoicing\InvoiceRepository;

/** Customer-facing projection shared by requests created in either interface. */
final class OrderTrackingReturns
{
    public function __construct(private readonly InvoiceRepository $repository) {}
    /** @return list<array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        $items=array_column($this->repository->all('order_items',['order_id'=>$orderId]),null,'id');$rows=[];
        foreach($this->repository->all('order_return_requests',['order_id'=>$orderId]) as $request) {
            $products=[];
            foreach(json_decode($request['quantities_json'],true,512,JSON_THROW_ON_ERROR) as $id=>$qty) { if(isset($items[$id])) { $products[]=['name'=>$items[$id]['product_name'],'quantity'=>$qty]; } }
            $return=$request['return_id']?$this->repository->one('order_returns',['id'=>$request['return_id'],'order_id'=>$orderId]):null;
            $rows[]=$this->project($request,$products,$return,json_decode($request['refund_details_json'] ?? '{}',true,512,JSON_THROW_ON_ERROR));
        }
        foreach($this->repository->all('invoice_return_requests',['order_id'=>$orderId]) as $request) {
            $payload=json_decode($request['request_json'],true,512,JSON_THROW_ON_ERROR);$products=[];
            $decisions=json_decode($request['decision_json'],true,512,JSON_THROW_ON_ERROR);$accepted=array_column($decisions,'accepted_units','line_id');
            foreach($payload['lines'] as $line) { $product=['name'=>$line['name'],'quantity'=>$line['quantity_units']/10000];if(array_key_exists($line['line_id'],$accepted)) { $product['accepted_quantity']=$accepted[$line['line_id']]/10000; }$products[]=$product; }
            $link=$request['credit_invoice_id']?$this->repository->one('order_return_documents',['invoice_id'=>$request['credit_invoice_id']]):null;
            $return=$link?$this->repository->one('order_returns',['id'=>$link['return_id'],'order_id'=>$orderId]):null;
            $request['decision_reason']=implode(' ',array_filter(array_column($decisions,'reason')));
            $rows[]=$this->project($request,$products,$return,$payload);
        }
        usort($rows,static fn(array $a,array $b):int=>strcmp($b['created_at'],$a['created_at']));
        return $rows;
    }
    /** @param array<string,mixed> $request @param list<array<string,mixed>> $products @param array<string,mixed>|null $return @param array<string,mixed> $destination @return array<string,mixed> */
    private function project(array $request,array $products,?array $return,array $destination): array
    {
        $state=$return && $return['voided_at']!==null?'reversed':$request['state'];
        $settlement=$return && $state!=='reversed'?json_decode($return['settlement_json'] ?? '{}',true,512,JSON_THROW_ON_ERROR):[];
        $method=$settlement['destination']['refund_method'] ?? $destination['refund_method'] ?? '';
        return ['state'=>$state,'label'=>match($state){'pending'=>'Cerere de retur în analiză','accepted'=>'Retur acceptat','partial'=>'Retur acceptat parțial','rejected'=>'Cerere de retur respinsă','reversed'=>'Înregistrarea returului a fost anulată',default=>'Cerere de retur'},
            'created_at'=>$request['created_at'],'decided_at'=>$request['decided_at'],'reason'=>$request['reason'],'decision_reason'=>$request['decision_reason'] ?? '',
            'products'=>$products,'refund_method'=>match($method){'bank'=>'În cont bancar','cash'=>'În numerar','original_payment'=>'Către metoda inițială de plată',default=>''},
            'settlement'=>array_intersect_key($settlement,array_flip(['base_bani','costs','costs_bani','refund_bani','adjustment_bani']))];
    }
}
