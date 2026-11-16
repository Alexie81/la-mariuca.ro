<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Shared\Config\Config;
use App\Shared\Mail\MailQueueHealth;
use PHPUnit\Framework\TestCase;

final class MailQueueHealthTest extends TestCase
{
    public function testExcludedTypesPreviewAndStoppedWorkerAreVisibleWithoutSending(): void
    {
        $env=$_ENV;$root=sys_get_temp_dir().'/mail-health-'.bin2hex(random_bytes(6));mkdir($root.'/storage/private',0770,true);
        try {
            $_ENV['APP_ENV']='development';$_ENV['MAIL_DELIVERY_MODE']='smtp';$_ENV['MAILER_DSN']='smtp://127.0.0.1:2525';$_ENV['MAIL_MESSAGE_TYPES']='order_status_update';
            self::assertStringContainsString('exclus',(new MailQueueHealth(Config::load($root),$root))->warning('invoice'));
            $_ENV['MAIL_MESSAGE_TYPES']='invoice,invoice_return_notice,order_details_changed';
            $_ENV['REQUEST_AUTOMATIONS_ENABLED']='true';
            self::assertSame('',(new MailQueueHealth(Config::load($root),$root))->warning('invoice'),'Invoice email is processed by site visits without a worker heartbeat.');
            $_ENV['REQUEST_AUTOMATIONS_ENABLED']='false';
            self::assertStringContainsString('3 minute',(new MailQueueHealth(Config::load($root),$root))->warning('invoice'));
            file_put_contents($root.'/storage/private/mail-worker-heartbeat.json',json_encode(['at'=>time(),'ok'=>true]));
            self::assertSame('',(new MailQueueHealth(Config::load($root),$root))->warning('invoice'));
            file_put_contents($root.'/storage/private/mail-worker-heartbeat.json',json_encode(['at'=>time(),'ok'=>false]));
            self::assertStringContainsString('eroare',(new MailQueueHealth(Config::load($root),$root))->warning('invoice_return_notice'));
            $_ENV['MAIL_DELIVERY_MODE']='preview';
            self::assertStringContainsString('previzualizare',(new MailQueueHealth(Config::load($root),$root))->warning('order_details_changed'));
        } finally {
            $_ENV=$env;@unlink($root.'/storage/private/mail-worker-heartbeat.json');rmdir($root.'/storage/private');rmdir($root.'/storage');rmdir($root);
        }
    }
}
