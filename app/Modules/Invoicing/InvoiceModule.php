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
    public static function process(PDO $pdo,string $root,int $limit=10): array
    {
        if($pdo->inTransaction()) { throw new \LogicException('Invoice dispatch requires committed orders.'); }
        $repository=new InvoiceRepository($pdo); $service=self::service($pdo,$root);
        $result=['issued'=>0,'failed'=>0,'emails'=>0];
        $actor=$repository->one('users',['role'=>'administrator','status'=>'active']);
        if(!$actor) { return $result; }
        $intents=$pdo->query("SELECT * FROM invoice_automation_intents WHERE state IN ('pending','failed','issued') AND attempts<5 ORDER BY order_id LIMIT ".max(1,min(100,$limit)))->fetchAll();
        foreach($intents as $intent) {
            try {
                // Each intent is locked together with the invoice; retries cannot issue twice.
                $repository->transaction(function()use($intent,$repository,$service,$actor,&$result):void {
                    $current=$repository->one('invoice_automation_intents',['order_id'=>$intent['order_id']]);
                    if(!$current || $current['state']==='complete') { return; }
                    $invoice=$current['invoice_id']?$service->load((int)$current['invoice_id']):$service->issue((int)$current['order_id'],['request_key'=>$current['issue_key']],$actor);
                    if(!$current['invoice_id']) { $result['issued']++; }
                    if($current['send_email']) {
                        $service->queueEmail((int)$invoice['id'],$current['email_key'],$actor,true); $result['emails']++;
                    }
                    $repository->update('invoice_automation_intents',['invoice_id'=>$invoice['id'],'state'=>'complete','last_error'=>null,'updated_at'=>$repository->now()],['order_id'=>$current['order_id']]);
                });
            } catch(\Throwable $e) {
                $repository->update('invoice_automation_intents',['state'=>'failed','attempts'=>(int)$intent['attempts']+1,'last_error'=>$e instanceof InvoiceException?$e->getMessage():'Verifică datele și reia emiterea din registrul facturilor.','updated_at'=>$repository->now()],['order_id'=>$intent['order_id']]); $result['failed']++;
            }
        }
        return $result;
    }
}
