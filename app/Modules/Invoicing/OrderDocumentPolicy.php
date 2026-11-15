<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

/** One decision model for order controls and their server-side guards. */
final class OrderDocumentPolicy
{
    public function __construct(private readonly InvoiceRepository $repository) {}

    /** @param array<string,mixed> $order @return array<string,array<string,mixed>> */
    public function actions(array $order): array
    {
        $documents=$this->repository->all('invoices',['order_id'=>$order['id'],'deleted_at'=>null]);
        $positive=null; $blocked=''; $chain=false;
        foreach($documents as $document) {
            if($document['kind']==='sale') { $positive=$document; }
            else { $chain=true; }
            if($document['fiscal_attempted_at']!==null || $document['fiscal_reference']!==null || !in_array($document['fiscal_state'],['unconfigured','not_sent','queued','scheduled','awaiting_configuration'],true)) {
                $blocked='Factura a intrat în procesul SPV. Folosește documente de corecție.';
            }
        }
        $terminal=in_array($order['status'],['cancelled','returned','refunded','return_requested','return_refused','return_confirmed','manual_review'],true);
        $reason=$terminal?'Comanda este într-un flux închis sau de retur.':($chain?'Există documente de retur sau corecție. Datele istorice sunt păstrate.':$blocked);
        $itemReason=$reason ?: ($positive && $order['status']==='delivered'?'Comanda a fost livrată. Modifică produsele prin documente de corecție.':'');
        $issueReason=$positive?'Factura inițială este deja emisă.':($terminal?'Comanda nu este eligibilă pentru emitere.':($order['payment_method']==='stripe' && $order['payment_status']!=='paid'?'Așteaptă confirmarea plății cu cardul.':''));
        $decision=static fn(string $why,string $mode):array=>['allowed'=>$why==='','reason'=>$why,'mode'=>$mode];
        return [
            'edit_contact'=>$decision($reason,$positive?'revise_invoice':'order'),
            'edit_delivery'=>$decision($reason ?: ($order['status']==='delivered'?'Adresa livrării finalizate rămâne în istoric.':''),'order'),
            'edit_items'=>$decision($itemReason,$positive?'revise_invoice':'order'),
            'issue_invoice'=>$decision($issueReason,'issue'),
            'correct'=>$decision(!$positive || $chain || $terminal?'Corecția cere o factură inițială fără alte documente dependente.':'','fiscal_correction'),
        ];
    }

    /** @param array<string,mixed> $order */
    public function assert(array $order,string $action): void
    {
        $decision=$this->actions($order)[$action];
        if(!$decision['allowed']) { throw new InvoiceException($decision['reason'],409); }
    }
}
