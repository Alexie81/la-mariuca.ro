<?php
declare(strict_types=1);
namespace App\Shared\Mail;

use App\Shared\Config\Config;

final class MailQueueHealth
{
    public function __construct(private readonly Config $config,private readonly string $root) {}

    public function warning(string $type): string
    {
        $types=array_values(array_filter(array_map('trim',explode(',',$this->config->get('MAIL_MESSAGE_TYPES')))));
        if($types!==[] && !in_array($type,$types,true)) { return 'Acest tip de email este exclus de filtrul de trimitere. Mesajele rămân în așteptare până la actualizarea configurației procesorului.'; }
        if(MailDeliveryPolicy::resolve($this->config)!=='smtp') { return 'Emailurile sunt în modul de previzualizare locală. Nu se livrează în căsuța clientului.'; }
        $file=$this->root.'/storage/private/mail-worker-heartbeat.json';
        $heartbeat=is_file($file)?json_decode((string)file_get_contents($file),true):null;
        if(!is_array($heartbeat) || (int)($heartbeat['at'] ?? 0)<time()-180) { return 'Procesorul de email nu a confirmat activitate în ultimele 3 minute. Mesajele sunt păstrate în coadă; verifică procesorul de trimitere.'; }
        if(($heartbeat['ok'] ?? false)!==true) { return 'Procesorul de email a raportat o eroare. Verifică mesajele eșuate și configurația de trimitere.'; }
        return '';
    }
}
