<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Modules\Inventory\NirRepository;
use App\Modules\Inventory\ReturnStockLinks;

/** Keeps the operational return and its fiscal/physical documents in one lifecycle. */
final class OrderReturnWorkflow
{
    public function __construct(private readonly InvoiceRepository $repository) {}

    /** @param array<string,mixed> $credit @param array<string,mixed> $actor */
    public function linkCredit(array $credit,string $beforeStatus,string $reason,array $actor,?int $returnId=null): void
    {
        if($credit['kind']!=='return') { return; }
        $lines=array_values(array_filter($credit['lines'],static fn(array $line):bool=>$line['kind']==='product' && $line['order_item_id']!==null));
        if($lines===[]) { return; }
        if($returnId===null) {
            $remaining=$this->repository->hasUnreturnedProducts((int)$credit['order_id']);
            $returnId=$this->repository->insert('order_returns',['order_id'=>$credit['order_id'],'return_type'=>$remaining?'partial':'full','reason'=>$reason,'notes'=>'','restock'=>0,'refund_mode'=>'none','refund_bani'=>0,'actor_user_id'=>$actor['id'],'created_at'=>$this->repository->now(),'status_before'=>$beforeStatus]);
            $quantities=[];
            foreach($lines as $line) {
                if(abs((int)$line['quantity_units'])%10000!==0) { throw new InvoiceException('Produsele se returnează în bucăți întregi.'); }
                $id=(int)$line['order_item_id'];$quantities[$id]=($quantities[$id] ?? 0)+intdiv(abs((int)$line['quantity_units']),10000);
            }
            foreach($quantities as $itemId=>$quantity) { $this->repository->insert('order_return_items',['return_id'=>$returnId,'order_item_id'=>$itemId,'quantity'=>$quantity,'restocked_quantity'=>0,'line_refund_bani'=>0]); }
        }
        $this->repository->insert('order_return_documents',['return_id'=>$returnId,'invoice_id'=>$credit['id']]);
    }

    /** @return list<array<string,mixed>> */
    public function missingDocuments(int $orderId): array
    {
        $links=new ReturnStockLinks($this->repository->pdo());$missing=[];
        foreach($this->repository->all('order_returns',['order_id'=>$orderId,'voided_at'=>null]) as $return) {
            if($links->invoicesForReturn((int)$return['id'])!==[]) { continue; }
            $return['items']=$this->repository->all('order_return_items',['return_id'=>$return['id']]);
            $return['restore_blocker']=$this->restoreBlocker($return);
            $missing[]=$return;
        }
        return $missing;
    }

    /** Call after soft-deleting the invoice, in the same transaction. @param array<string,mixed> $actor */
    public function invoiceDeleted(int $invoiceId,array $actor,string $reason): void
    {
        $link=$this->repository->one('order_return_documents',['invoice_id'=>$invoiceId]);
        if(!$link) { return; }
        $return=$this->repository->one('order_returns',['id'=>$link['return_id']]);
        if(!$return || $return['voided_at']!==null || (new ReturnStockLinks($this->repository->pdo()))->invoicesForReturn((int)$return['id'])!==[]) { return; }
        $this->void($return,$actor,$reason);
    }

    /** @param array<string,mixed> $return @param array<string,mixed> $actor */
    public function void(array $return,array $actor,string $reason): void
    {
        if(!$this->repository->pdo()->inTransaction()) { throw new \LogicException('Return reversal requires a transaction.'); }
        $blocker=$this->restoreBlocker($return);if($blocker!=='') { throw new InvoiceException($blocker,409); }
        $this->repository->update('order_returns',['voided_at'=>$this->repository->now(),'voided_by'=>$actor['id'],'void_reason'=>$reason],['id'=>$return['id']]);
        $order=$this->repository->one('orders',['id'=>$return['order_id']]);
        $target=in_array($return['status_before'],['new','confirmed','preparing','shipped','delivered'],true)?$return['status_before']:'delivered';
        if(in_array($order['status'],['returned','return_confirmed','return_requested','return_refused'],true)) {
            $remaining=$this->repository->all('order_returns',['order_id'=>$order['id'],'voided_at'=>null]);
            $stillFull=in_array('full',array_column($remaining,'return_type'),true);
            $this->repository->update('orders',['status'=>$stillFull?'returned':$target,'updated_at'=>$this->repository->now()],['id'=>$order['id']]);
        }
        $this->repository->insert('audit_log',['actor_user_id'=>$actor['id'],'action'=>'order.return_reversed','entity_type'=>'order','entity_id'=>$return['order_id'],'context_json'=>InvoiceSnapshot::json(['return_id'=>$return['id'],'reason'=>$reason,'status_before'=>$order['status'],'status_after'=>$this->repository->one('orders',['id'=>$return['order_id']])['status']]),'created_at'=>$this->repository->now()]);
    }

    /** @param array<string,mixed> $return */
    public function restoreBlocker(array $return): string
    {
        $links=new ReturnStockLinks($this->repository->pdo());
        if($links->invoicesForReturn((int)$return['id'])!==[]) { return 'Returul are încă o factură activă. Gestionează mai întâi documentul fiscal.'; }
        if((new NirRepository($this->repository->pdo()))->one('nir_documents',['return_id'=>$return['id']]) || $links->hasInvoiceReceipt((int)$return['id'])) { return 'Produsele au o recepție înregistrată. Verifică documentul de stoc înainte de revenire.'; }
        if((int)$return['refund_bani']>0 || array_sum(array_column($this->repository->all('order_return_items',['return_id'=>$return['id']]),'restocked_quantity'))>0) { return 'Returul are stoc sau bani restituiți. Necesită verificarea operațiunilor existente.'; }
        return '';
    }
}
