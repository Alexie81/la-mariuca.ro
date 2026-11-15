<?php

declare(strict_types=1);

namespace App\Modules\Invoicing;

use App\Modules\Companies\CompanyStampStorage;
use DateTimeImmutable;
use DateTimeZone;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class DispatchNoteService
{
    public function __construct(private readonly DispatchNoteRepository $repository,private readonly string $root,private readonly CompanyStampStorage $stamps) {}

    /** @param array<string,mixed> $actor */
    public function allow(array $actor): void
    {
        if(!in_array($actor['role'] ?? '',['administrator','manager'],true)){throw new InvoiceException('Nu ai dreptul să gestionezi avize.',403);}
    }

    /** @param array<string,mixed> $actor @return array<string,mixed>|null */
    public function forOrder(int $orderId,array $actor): ?array
    {
        $this->allow($actor); $note=$this->repository->activeForOrder($orderId); return $note?$this->decorate($note):null;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function issue(int $orderId,string $key,array $actor): array
    {
        $this->allow($actor); $this->key($key);
        return $this->repository->transaction(function()use($orderId,$key,$actor):array {
            $hash=hash('sha256','issue:'.$orderId); $prior=$this->repository->action($key);
            if($prior){if($prior['action']!=='issue'||!hash_equals($prior['request_hash'],$hash)){throw new InvoiceException('Această confirmare a fost folosită pentru alt aviz.',409);} return $this->decorate($this->repository->find((int)$prior['dispatch_note_id'])??throw new InvoiceException('Avizul nu mai există.',404));}
            if($existing=$this->repository->activeForOrder($orderId)){return $this->decorate($existing);}
            $order=$this->repository->order($orderId); $this->eligible($order);
            $now=$this->repository->now(); $number=$this->repository->nextNumber($now); $snapshot=$this->snapshot($order,$this->repository->orderItems($orderId),$this->repository->company());
            $id=$this->repository->insert(['document_key'=>bin2hex(random_bytes(32)),'order_id'=>$orderId,'active_order_id'=>$orderId,'series_code'=>$number['series'],'number'=>$number['number'],'issued_at'=>$now,'snapshot_json'=>InvoiceSnapshot::json($snapshot),'row_version'=>1,'created_by'=>(int)$actor['id'],'deleted_at'=>null,'updated_at'=>$now]);
            $this->repository->remember($key,'issue',$hash,$id); $this->repository->audit($id,(int)$actor['id'],'dispatch_note.issued',['order_id'=>$orderId,'number'=>$number]);
            return $this->decorate($this->repository->find($id)??throw new InvoiceException('Avizul nu a putut fi încărcat.'));
        });
    }

    /** Refresh an existing mutable note without changing its number. @param array<string,mixed> $actor */
    public function regenerate(int $orderId,array $actor): void
    {
        $this->allow($actor); $this->repository->transaction(function()use($orderId,$actor):void {
            $note=$this->repository->activeForOrder($orderId); if(!$note){return;}
            $order=$this->repository->order($orderId); $snapshot=$this->snapshot($order,$this->repository->orderItems($orderId),$this->repository->company());
            $this->repository->update((int)$note['id'],['snapshot_json'=>InvoiceSnapshot::json($snapshot),'row_version'=>(int)$note['row_version']+1,'updated_at'=>$this->repository->now()]);
            $this->cancelQueuedEmail((int)$note['id']);
            $this->repository->audit((int)$note['id'],(int)$actor['id'],'dispatch_note.regenerated',['order_id'=>$orderId,'before_version'=>(int)$note['row_version'],'after_version'=>(int)$note['row_version']+1]);
        });
    }

    /** @param array<string,mixed> $actor */
    public function delete(int $orderId,string $key,array $actor,?int $expectedId=null,?int $expectedVersion=null): void
    {
        $this->allow($actor); $this->key($key);
        $this->repository->transaction(function()use($orderId,$key,$actor,$expectedId,$expectedVersion):void {
            $prior=$this->repository->action($key);
            if($prior){$priorNote=$this->repository->find((int)$prior['dispatch_note_id']);$hash=hash('sha256','delete:'.$prior['dispatch_note_id']);if($prior['action']!=='delete'||!hash_equals($prior['request_hash'],$hash)||(int)($priorNote['order_id']??0)!==$orderId||($expectedId!==null && (int)$prior['dispatch_note_id']!==$expectedId)){throw new InvoiceException('Această confirmare a fost folosită pentru altă operațiune.',409);} return;}
            $note=$this->repository->activeForOrder($orderId)??throw new InvoiceException('Avizul nu există.',404); $hash=hash('sha256','delete:'.$note['id']);
            if(($expectedId!==null && $expectedId!==(int)$note['id']) || ($expectedVersion!==null && $expectedVersion!==(int)$note['row_version'])) { throw new InvoiceException('Avizul s-a modificat. Reîncarcă pagina înainte de ștergere.',409); }
            $blocker=(new DispatchNotePolicy($this->repository))->deletionBlocker($note);
            if($blocker!=='') { throw new InvoiceException($blocker,409); }
            $this->repository->update((int)$note['id'],['active_order_id'=>null,'active_number'=>null,'deleted_at'=>$this->repository->now(),'row_version'=>(int)$note['row_version']+1,'updated_at'=>$this->repository->now()]);
            $this->repository->releaseNumber($note);
            $this->cancelQueuedEmail((int)$note['id']);
            $this->repository->remember($key,'delete',$hash,(int)$note['id']); $this->repository->audit((int)$note['id'],(int)$actor['id'],'dispatch_note.deleted',['order_id'=>$orderId]);
        });
    }

    /** @param array<string,mixed> $actor */
    public function pdf(int $orderId,array $actor): string
    {
        return $this->renderPdf($this->forOrder($orderId,$actor)??throw new InvoiceException('Generează mai întâi avizul.',404));
    }

    /** @return array<string,mixed> */
    public function publicNote(string $key): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$key)) { throw new InvoiceException('Avizul nu este disponibil.',404); }
        return $this->decorate($this->repository->byPublicKey($key)??throw new InvoiceException('Avizul nu este disponibil.',404));
    }

    public function publicPdf(string $key): string { return $this->renderPdf($this->publicNote($key)); }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function queueEmail(int $orderId,array $input,array $actor): void
    {
        $this->allow($actor);$key=(string)($input['request_key']??'');$this->key($key);
        $message=trim((string)($input['personal_message']??''));
        if(mb_strlen($message)>2000) { throw new InvoiceException('Mesajul poate avea maximum 2.000 de caractere.'); }
        $this->repository->transaction(function()use($orderId,$input,$actor,$key,$message):void {
            $note=$this->forOrder($orderId,$actor)??throw new InvoiceException('Avizul nu mai este disponibil.',404);
            if((int)($input['dispatch_note_id']??0)!==(int)$note['id'] || (int)($input['version']??0)!==(int)$note['row_version']) { throw new InvoiceException('Avizul s-a modificat. Redeschide confirmarea de trimitere.',409); }
            $email=(string)$note['snapshot']['customer']['email'];
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new InvoiceException('Adresa de email a clientului nu este validă.'); }
            $payload=['dispatch_note_id'=>$note['id'],'order_id'=>$orderId,'document_key'=>$note['document_key'],'version'=>$note['row_version'],'personal_message'=>$message];
            $hash=hash('sha256',InvoiceSnapshot::json($payload));$prior=$this->repository->action($key);
            if($prior) { if($prior['action']!=='email'||!hash_equals($prior['request_hash'],$hash)) { throw new InvoiceException('Confirmarea a fost folosită pentru alt mesaj.',409); }return; }
            (new InvoiceRepository($this->repository->pdo()))->insert('outbox_messages',['deduplication_key'=>'dispatch-email:'.$key,'message_type'=>'dispatch_note','recipient'=>$email,'payload_json'=>InvoiceSnapshot::json($payload),'status'=>'pending','attempts'=>0,'available_at'=>$this->repository->now(),'created_at'=>$this->repository->now()]);
            $this->repository->remember($key,'email',$hash,(int)$note['id']);$this->repository->audit((int)$note['id'],(int)$actor['id'],'dispatch_note.email_queued',['order_id'=>$orderId]);
        });
    }

    private function cancelQueuedEmail(int $id): void
    {
        $repo=new InvoiceRepository($this->repository->pdo());
        foreach($repo->all('outbox_messages',['message_type'=>'dispatch_note']) as $message) {
            $payload=json_decode($message['payload_json'],true);
            if((int)($payload['dispatch_note_id']??0)===$id && in_array($message['status'],['pending','failed'],true)) { $repo->update('outbox_messages',['status'=>'failed','attempts'=>5,'last_error'=>'Avizul a fost modificat sau șters. Pregătește o trimitere nouă.'],['id'=>$message['id']]); }
        }
    }

    /** @param array<string,mixed> $note */
    private function renderPdf(array $note): string
    {
        $data=$this->pdfData($note);
        $directory=$this->root.'/storage/cache/dispatch-notes'; if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory)){throw new InvoiceException('Avizul nu poate fi pregătit.');}
        $path=$directory.'/aviz-'.$note['document_key'].'.pdf'; $options=new Options(); $options->set('isRemoteEnabled',false); $options->set('isPhpEnabled',false); $options->set('isJavascriptEnabled',false); $options->setChroot([$this->root.'/public',$directory]); $options->setFontDir($directory); $options->setFontCache($directory); $options->setTempDir($directory); $options->set('isFontSubsettingEnabled',true); $options->setFontHeightRatio(0.8);
        $pdf=new Dompdf($options); $twig=new Environment(new FilesystemLoader($this->root.'/resources/views'),['autoescape'=>'html','strict_variables'=>true]); $pdf->loadHtml($twig->render('admin/invoices/dispatch-note-preview.twig',$data),'UTF-8'); $pdf->setPaper('A4'); $pdf->render(); $font=$pdf->getFontMetrics()->getFont('Nunito PDF','normal')??'Helvetica'; $pdf->getCanvas()->page_text(500,813,'Pagina {PAGE_NUM} / {PAGE_COUNT}',$font,7,[.5,.44,.49]);
        if(file_put_contents($path,$pdf->output(),LOCK_EX)===false){throw new InvoiceException('PDF-ul avizului nu a putut fi salvat.');} unset($pdf); gc_collect_cycles(); return $path;
    }

    /** @param array<string,mixed> $order @param list<array<string,mixed>> $items @param array<string,mixed> $company @return array<string,mixed> */
    private function snapshot(array $order,array $items,array $company): array
    {
        if($items===[]){throw new InvoiceException('Comanda nu are produse.');}
        $shipping=json_decode((string)$order['shipping_address_json'],true,512,JSON_THROW_ON_ERROR); $lines=[]; $total=0; $quantity=0;
        foreach($items as $position=>$item){$value=(int)$item['line_total_bani']; $lines[]=['position'=>$position+1,'name'=>(string)$item['product_name'],'sku'=>(string)$item['sku'],'unit'=>'buc','quantity'=>(int)$item['quantity'],'unit_bani'=>(int)$item['unit_price_bani'],'value_bani'=>$value,'image_path'=>(string)($item['image_path']??'')]; $total+=$value; $quantity+=(int)$item['quantity'];}
        $address=implode(', ',array_filter([$shipping['address']??'',$shipping['postal_code']??'',$shipping['city']??'',$shipping['county']??'',$shipping['country']??'România'],static fn($value):bool=>trim((string)$value)!==''));
        return ['order_reference'=>(string)($order['order_number']?:'#'.$order['id']),'company'=>$company,'customer'=>['name'=>trim($order['first_name'].' '.$order['last_name']),'type'=>$order['customer_type']==='company'?'Persoană juridică':'Persoană fizică','address'=>$address,'phone'=>(string)$order['phone'],'email'=>(string)$order['email']],'lines'=>$lines,'total_bani'=>$total,'quantity'=>$quantity,'dispatch'=>['delegate'=>'','identity'=>'','vehicle'=>(string)($order['awb']??''),'time'=>'','loading_place'=>(string)($company['physical_address']?:$company['registered_address']),'carrier'=>(string)($order['shipping_method_name']??'')]];
    }

    /** @param array<string,mixed> $note @return array<string,mixed> */
    private function decorate(array $note): array {$note['snapshot']=json_decode($note['snapshot_json'],true,512,JSON_THROW_ON_ERROR); $note['reference']=$note['series_code'].' '.$note['number']; $note['delete_blocker']=(new DispatchNotePolicy($this->repository))->deletionBlocker($note); $note['can_delete']=$note['delete_blocker']===''; return $note;}

    /** @param array<string,mixed> $note @return array<string,mixed> */
    private function pdfData(array $note): array
    {
        $snapshot=$note['snapshot']; $company=$snapshot['company']; $company['website_display']=preg_replace('~^https?://~i','',rtrim((string)$company['website'],'/')); $lines=[];
        foreach($snapshot['lines'] as $line){$line['unit_price']=$this->money((int)$line['unit_bani']); $line['value']=$this->money((int)$line['value_bani']); $line['image_data']=$this->publicImage((string)$line['image_path']); $lines[]=$line;}
        $stampPath=$this->stamps->path(is_string($company['stamp_path']??null)?$company['stamp_path']:null); $stamp=$stampPath?file_get_contents($stampPath):false; $date=(new DateTimeImmutable($note['issued_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Bucharest'));
        return ['is_model'=>false,'company'=>$company,'lines'=>$lines,'line_groups'=>count($lines)>20?[array_slice($lines,0,11),...array_chunk(array_slice($lines,11),20)]:[$lines],'series'=>$note['series_code'],'number'=>(string)$note['number'],'reference'=>$note['reference'],'date'=>$date->format('d.m.Y'),'order_reference'=>$snapshot['order_reference'],'total'=>$this->money((int)$snapshot['total_bani']),'quantity'=>(int)$snapshot['quantity'],'customer'=>$snapshot['customer'],'dispatch'=>$snapshot['dispatch'],'logo'=>$this->asset('assets/images/logo-la-mariuca-transparent.png'),'monogram'=>$this->asset('assets/images/apple-touch-icon-lm.png'),'stamp_data'=>$stamp?'data:image/png;base64,'.base64_encode($stamp):'','font_regular'=>'file://'.str_replace('\\','/',$this->root.'/public/assets/fonts/nunito-sans-pdf-regular.ttf'),'font_bold'=>'file://'.str_replace('\\','/',$this->root.'/public/assets/fonts/nunito-sans-pdf-bold.ttf')];
    }

    private function publicImage(string $path): string {if($path===''){return $this->asset('assets/images/apple-touch-icon-lm.png');}$path=ltrim($path,'/'); $full=realpath($this->root.'/public/'.$path); $public=realpath($this->root.'/public'); if($full===false||$public===false||!str_starts_with($full,$public.DIRECTORY_SEPARATOR)||!is_file($full)){return $this->asset('assets/images/apple-touch-icon-lm.png');}$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($full)?:'image/png'; return 'data:'.$mime.';base64,'.base64_encode((string)file_get_contents($full));}
    private function asset(string $relative): string {$path=$this->root.'/public/'.$relative; return 'data:'.(str_ends_with($relative,'.png')?'image/png':'application/octet-stream').';base64,'.base64_encode((string)file_get_contents($path));}
    private function money(int $bani): string {return number_format(intdiv($bani,100),0,',','.').','.str_pad((string)(abs($bani)%100),2,'0',STR_PAD_LEFT).' Lei';}
    /** @param array<string,mixed> $order */
    private function eligible(array $order): void {if(in_array($order['status'],['cancelled','returned','refunded'],true)){throw new InvoiceException('Comanda închisă nu poate primi un aviz.',409);}}
    private function key(string $key): void {if(!preg_match('/^[a-f0-9]{32,128}$/D',$key)){throw new InvoiceException('Reîncarcă pagina înainte de confirmare.',409);}}
}
