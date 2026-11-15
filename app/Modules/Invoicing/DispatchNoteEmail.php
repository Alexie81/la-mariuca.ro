<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Modules\Companies\CompanyStampStorage;
use App\Shared\Config\Config;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class DispatchNoteEmail
{
    public function __construct(private readonly DispatchNoteRepository $repository,private readonly Config $config,private readonly string $root) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function render(array $payload,string $recipient): array
    {
        $service=new DispatchNoteService($this->repository,$this->root,new CompanyStampStorage($this->root));
        $key=(string)($payload['document_key']??'');$note=$service->publicNote($key);$snapshot=$note['snapshot'];$customer=$snapshot['customer'];
        if((int)($payload['dispatch_note_id']??0)!==(int)$note['id'] || (int)($payload['version']??0)!==(int)$note['row_version'] || mb_strtolower($recipient)!==mb_strtolower($customer['email'])) { throw new InvoiceException('Avizul a fost actualizat. Pregătește un email nou pentru documentul actual.'); }
        $base=$this->config->rootUrl();$image=static fn(string $name):string=>$base.'/assets/images/'.$name;
        $subject='Aviz de însoțire '.$note['reference'].' · La Măriuca';
        $data=['subject'=>$subject,'preheader'=>'Avizul comenzii tale este pregătit. Îl poți deschide direct din email.',
            'badge'=>'Avizul tău de însoțire','first_name'=>explode(' ',$customer['name'])[0],'personal_message'=>(string)($payload['personal_message']??''),
            'number'=>$note['reference'],'order_number'=>$snapshot['order_reference'],'date'=>(new \DateTimeImmutable($note['issued_at']))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y · H:i'),
            'tracking_url'=>$base.'/aviz/'.$key.'/?view=1','download_url'=>$base.'/aviz/'.$key.'/','shop_url'=>$base.'/',
            'contact_email'=>$this->config->get('CONTACT_EMAIL','contact@la-mariuca.ro'),'contact_url'=>$base.'/pagina/contact/','delivery_url'=>$base.'/pagina/livrare-si-retur/','terms_url'=>$base.'/pagina/termeni-si-conditii/','privacy_url'=>$base.'/pagina/confidentialitate/',
            'email'=>$recipient,'logo_url'=>$image('logo-la-mariuca-transparent.png'),'status_icon'=>$image('email-order-truck-v2.png'),'status_color'=>'#75506e','anpc_logo_url'=>$image('anpc-sal.png'),'cab_logo_url'=>$image('cab-it-mark.png'),'care_badge_url'=>$image('email-customer-care.png'),'heart_icon_url'=>$image('icon-favorite-hearts.png'),'year'=>date('Y')];
        $twig=new Environment(new FilesystemLoader($this->root.'/resources/views'),['autoescape'=>'html']);
        return ['subject'=>$subject,'html'=>$twig->render('emails/dispatch-note.twig',$data),
            'text'=>'Bună, '.$customer['name']."!\n\nAvizul ".$note['reference'].' pentru comanda '.$snapshot['order_reference']." este pregătit.\n\n".($data['personal_message']!==''?$data['personal_message']."\n\n":'').'Deschide avizul: '.$data['tracking_url']."\nAvizul PDF este și atașat.\n\nEchipa La Măriuca",
            'attachment'=>$service->publicPdf($key),'attachment_name'=>'Aviz-'.$note['series_code'].'-'.$note['number'].'.pdf'];
    }
}
