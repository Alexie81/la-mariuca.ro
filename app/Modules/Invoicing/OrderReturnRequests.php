<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

/** A customer's request does not itself issue an invoice or move stock. */
final class OrderReturnRequests
{
    public function __construct(private readonly InvoiceRepository $repository) {}

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function create(int $orderId,array $input,array $actor): int
    {
        $this->allow($actor);
        return $this->repository->transaction(function()use($orderId,$input,$actor):int {
            $key=(string)($input['request_key'] ?? '');
            if(!preg_match('/^[a-zA-Z0-9:._-]{12,190}$/D',$key)) { throw new InvoiceException('Redeschide formularul solicitării.'); }
            $reason=trim((string)($input['reason'] ?? ''));
            if(mb_strlen($reason)<3 || mb_strlen($reason)>2000) { throw new InvoiceException('Completează motivul solicitării (3–2000 de caractere).'); }
            $raw=(array)($input['quantities'] ?? []);$quantities=[];
            foreach($raw as $id=>$value) {
                if(!preg_match('/^[0-9]{1,9}$/D',(string)$value)) { throw new InvoiceException('Cantitatea trebuie să fie un număr întreg.'); }
                if((int)$value>0) { $quantities[(int)$id]=(int)$value; }
            }
            ksort($quantities);$json=InvoiceSnapshot::json($quantities);
            $storedOrder=$this->repository->one('orders',['id'=>$orderId]) ?? throw new InvoiceException('Comanda nu există.',404);
            $refundJson=InvoiceSnapshot::json(RefundDestination::normalize($input,$storedOrder['payment_method']==='stripe'));
            $prior=$this->repository->one('order_return_requests',['request_key'=>$key]);
            if($prior) {
                if((int)$prior['order_id']!==$orderId || $prior['reason']!==$reason || $prior['quantities_json']!==$json || $prior['refund_details_json']!==$refundJson) { throw new InvoiceException('Confirmarea a fost folosită pentru alte date.',409); }
                return (int)$prior['id'];
            }
            $order=(new \App\Modules\Orders\OrderService($this->repository->pdo()))->findForAdmin($orderId);
            if(!$order || !in_array($order['status'],['delivered','returned','return_requested','return_confirmed','return_refused'],true) || !$order['can_return']) { throw new InvoiceException('Solicitarea este disponibilă după livrare, pentru cantitățile încă eligibile.'); }
            if($this->repository->one('order_return_requests',['order_id'=>$orderId]) || $this->repository->one('invoice_return_requests',['order_id'=>$orderId])) { throw new InvoiceException('Comanda are deja o cerere de retur. Se permite o singură cerere pe comandă.',409); }
            $available=array_column($order['items'],'returnable_quantity','id');
            if($quantities===[]) { throw new InvoiceException('Alege cel puțin un produs.'); }
            foreach($quantities as $id=>$quantity) { if($quantity>($available[$id] ?? 0)) { throw new InvoiceException('Cantitățile solicitate nu mai sunt disponibile.',409); } }
            $id=$this->repository->insert('order_return_requests',['order_id'=>$orderId,'request_key'=>$key,'reason'=>$reason,'quantities_json'=>$json,'refund_details_json'=>$refundJson,'decision_reason'=>'','created_by'=>$actor['id'],'created_at'=>$this->repository->now()]);
            $this->audit($orderId,'order.return_requested',$actor,['request_id'=>$id,'quantities'=>$quantities,'reason'=>$reason]);
            $this->notify($order,$id,'received','Am primit solicitarea ta de retur. O analizăm și te anunțăm după luarea unei decizii.');
            return $id;
        });
    }

    /** @param array<string,mixed> $actor */
    public function reject(int $orderId,int $requestId,string $reason,array $actor): void
    {
        $this->allow($actor);
        $this->repository->transaction(function()use($orderId,$requestId,$reason,$actor):void {
            $request=$this->pending($orderId,$requestId);
            $reason=trim($reason);if(mb_strlen($reason)<3 || mb_strlen($reason)>2000) { throw new InvoiceException('Explică motivul refuzului (3–2000 de caractere).'); }
            $this->repository->update('order_return_requests',['state'=>'rejected','decision_reason'=>$reason,'decided_by'=>$actor['id'],'decided_at'=>$this->repository->now()],['id'=>$request['id']]);
            $this->audit($orderId,'order.return_request_rejected',$actor,['request_id'=>$requestId,'reason'=>$reason]);
            $this->notify($this->repository->one('orders',['id'=>$orderId]),$requestId,'rejected','Solicitarea de retur nu a fost acceptată. '.$reason);
        });
    }

    /** @return array<string,mixed> */
    public function pending(int $orderId,int $id): array
    {
        return $this->repository->one('order_return_requests',['id'=>$id,'order_id'=>$orderId,'state'=>'pending']) ?? throw new InvoiceException('Solicitarea nu mai este în analiză.',409);
    }

    /** @param array<string,mixed> $actor */
    private function allow(array $actor): void
    {
        $user=$this->repository->one('users',['id'=>$actor['id'] ?? 0]);
        if(!$user || !in_array($user['role'],['administrator','manager'],true)) { throw new InvoiceException('Nu ai dreptul de a gestiona retururi.',403); }
    }

    /** @param array<string,mixed> $order */
    private function notify(array $order,int $id,string $state,string $message): void
    {
        $this->repository->insert('outbox_messages',['deduplication_key'=>'order-return-request:'.$id.':'.$state,'message_type'=>'order_status_update','recipient'=>$order['email'],'payload_json'=>InvoiceSnapshot::json(['order_id'=>$order['id'],'status'=>$order['status'],'message'=>$message]),'status'=>'pending','attempts'=>0,'available_at'=>$this->repository->now(),'created_at'=>$this->repository->now()]);
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $context */
    private function audit(int $orderId,string $action,array $actor,array $context): void
    {
        $this->repository->insert('audit_log',['actor_user_id'=>$actor['id'],'action'=>$action,'entity_type'=>'order','entity_id'=>$orderId,'context_json'=>InvoiceSnapshot::json($context),'created_at'=>$this->repository->now()]);
    }
}
