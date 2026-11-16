<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

/** Current cash is presentation data, separate from the immutable fiscal snapshot. */
final class InvoicePaymentSummary
{
    /** @param array<string,mixed> $invoice @param array{received:int,refunded:int} $cash @return array<string,mixed> */
    public static function forDocument(array $invoice,array $cash,int $orderNet): array
    {
        $negative=in_array($invoice['kind'],['return','correction_negative'],true);
        $received=$cash['received']; $refunded=$cash['refunded'];
        $netCash=max(0,$received-$refunded); $netInvoiced=max(0,$orderNet);
        $outstanding=max(0,$netInvoiced-$netCash);
        $refundDue=max(0,$netCash-$netInvoiced);
        if($negative) {
            $documentRefunded=(int)$invoice['refunded_bani'];
            $documentRefundDue=min(max(0,abs((int)$invoice['gross_bani'])-$documentRefunded),$refundDue);
            [$label,$tone]=match(true) {
                $documentRefunded>0 && $documentRefunded>=abs((int)$invoice['gross_bani'])=>['Rambursată','green'],
                $documentRefunded>0=>['Rambursată parțial','gold'],
                $documentRefundDue>0=>['De restituit','rose'],
                default=>['Retur înregistrat','rose'],
            };
        } else {
            [$label,$tone]=match(true) {
                $refundDue>0=>['De restituit','rose'],
                $received>0 && $refunded>=$received && $outstanding===0=>['Rambursată','green'],
                $outstanding>0 && $netCash>0=>['Achitată parțial','gold'],
                $outstanding>0=>['Neachitată','gold'],
                $netInvoiced>0=>['Achitată','green'],
                default=>['Sold închis','neutral'],
            };
        }
        return compact('label','tone','received','refunded','outstanding','refundDue','netInvoiced')+
            ['scope'=>$negative?'Restituirea acestui retur':'Plata comenzii','document_refunded'=>$negative?$documentRefunded:null,'document_refund_due'=>$negative?$documentRefundDue:null];
    }
}
