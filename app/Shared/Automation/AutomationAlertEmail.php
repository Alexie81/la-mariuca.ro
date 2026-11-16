<?php
declare(strict_types=1);
namespace App\Shared\Automation;

use App\Shared\Config\Config;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AutomationAlertEmail
{
    public function __construct(private readonly Config $config,private readonly string $root) {}
    /** @param array<string,mixed> $payload @return array{subject:string,text:string,html:string} */
    public function render(array $payload,string $recipient): array
    {
        $base=$this->config->rootUrl(); $path=(string)($payload['target_path'] ?? '');
        if(!preg_match('~^admin/(?:comenzi/\d+/|facturi/\d+/|spv-efactura/|automatizari/)$~D',$path)) { throw new \RuntimeException('Invalid alert destination'); }
        $title=(string)$payload['title']; $message=(string)$payload['message']; $url=$base.'/'.$path;
        $data=['subject'=>$title.' · La Măriuca','preheader'=>$message,'alert_title'=>$title,'alert_message'=>$message,'admin_url'=>$url,
            'shop_url'=>$base.'/','logo_url'=>$base.'/assets/images/logo-la-mariuca-transparent.png','year'=>date('Y'),'email'=>$recipient,
            'number'=>'Facturare și SPV','date'=>(new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y · H:i')];
        $twig=new Environment(new FilesystemLoader($this->root.'/resources/views'),['autoescape'=>'html']);
        return ['subject'=>$data['subject'],'text'=>$title."\n\n".$message."\n\nDeschide administrarea: ".$url,'html'=>$twig->render('emails/automation-alert.twig',$data)];
    }
}
