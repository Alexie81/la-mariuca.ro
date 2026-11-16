<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;
use PDO;

/** Shared composition for HTTP, verified payment events and a one-shot cron. */
final class InvoiceModule
{
    public static function service(PDO $pdo,string $root): InvoiceService
    {
        return new InvoiceService(new InvoiceRepository($pdo),new InvoiceSnapshot($pdo,$root),new \App\Modules\Inventory\InvoiceStockService($pdo));
    }
    public static function prepare(PDO $pdo,int $orderId): void
    {
        (new InvoiceAutomationService(new InvoiceAutomationRepository($pdo)))->prepareForOrder($orderId);
    }
    /** @return array{issued:int,failed:int,emails:int} */
    public static function process(PDO $pdo,string $root,int $limit=10,?int $orderId=null): array
    {
        if($pdo->inTransaction()) { throw new \LogicException('Invoice dispatch requires committed orders.'); }
        $repository=new InvoiceRepository($pdo); $service=self::service($pdo,$root);
        $result=['issued'=>0,'failed'=>0,'emails'=>0];
        $actor=$repository->one('users',['role'=>'administrator','status'=>'active']);
        if(!$actor) { return $result; }
        $query=$pdo->prepare("SELECT * FROM invoice_automation_intents WHERE state IN ('pending','failed','issued') AND attempts<5"
            .($orderId===null?" AND (state<>'failed' OR updated_at<:retry)":' AND order_id=:order_id')." ORDER BY order_id LIMIT ".max(1,min(100,$limit)));
        $query->execute($orderId===null?['retry'=>gmdate('Y-m-d\TH:i:s\Z',time()-120)]:['order_id'=>$orderId]);
        $intents=$query->fetchAll();
        foreach($intents as $intent) {
            try {
                // Each intent is locked together with the invoice; retries cannot issue twice.
                $completed=$repository->transaction(function()use($intent,$repository,$service,$actor):array {
                    $counts=['issued'=>0,'emails'=>0];
                    $current=$repository->one('invoice_automation_intents',['order_id'=>$intent['order_id']]);
                    if(!$current || $current['state']==='complete' || (int)$current['attempts']>=5) { return $counts; }
                    $invoice=$current['invoice_id']?$service->load((int)$current['invoice_id']):$service->issue((int)$current['order_id'],['request_key'=>$current['issue_key']],$actor);
                    if(!$current['invoice_id']) { $counts['issued']++; }
                    if($current['send_email'] && in_array($invoice['email_state'],['not_sent','needs_resend'],true)) {
                        $service->queueEmail((int)$invoice['id'],$current['email_key'],$actor,true); $counts['emails']++;
                    }
                    $repository->update('invoice_automation_intents',['invoice_id'=>$invoice['id'],'state'=>'complete','last_error'=>null,'updated_at'=>$repository->now()],['order_id'=>$current['order_id']]);
                    return $counts;
                });
                $result['issued']+=$completed['issued']; $result['emails']+=$completed['emails'];
            } catch(\Throwable $e) {
                $repository->transaction(function()use($repository,$intent,$e):void {
                    $current=$repository->one('invoice_automation_intents',['order_id'=>$intent['order_id']]);
                    if(!$current || $current['state']==='complete') { return; }
                    $repository->update('invoice_automation_intents',['state'=>'failed','attempts'=>min(5,(int)$current['attempts']+1),'last_error'=>$e instanceof InvoiceException?$e->getMessage():'Verifică datele și reia emiterea din comandă.','updated_at'=>$repository->now()],['order_id'=>$intent['order_id']]);
                }); $result['failed']++;
            }
        }
        return $result;
    }
}
