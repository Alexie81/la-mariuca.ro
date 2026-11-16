<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Shared\Automation\RequestAutomation;
use App\Shared\Config\Config;
use Tests\DatabaseTestCase;

final class RequestAutomationTest extends DatabaseTestCase
{
    public function testOnlySuccessfulPageVisitsAndPostsTriggerMaintenance(): void
    {
        self::assertTrue(RequestAutomation::eligible('GET',200,'text/html; charset=utf-8'));
        self::assertTrue(RequestAutomation::eligible('POST',303,'text/html'));
        self::assertTrue(RequestAutomation::eligible('POST',200,'application/json'));
        foreach([['GET',404,'text/html'],['POST',403,'text/html'],['GET',200,'application/zip'],['GET',200,'application/json'],['HEAD',200,'text/html']] as [$method,$status,$type]) {
            self::assertFalse(RequestAutomation::eligible($method,$status,$type));
        }
    }

    public function testDisabledMaintenanceCannotClaimLeaseOrCreateNotifications(): void
    {
        $env=$_ENV;
        try {
            $_ENV['REQUEST_AUTOMATIONS_ENABLED']='false';
            self::assertFalse((new RequestAutomation($this->pdo,Config::load($this->root),$this->root))->run()['ran']);
            self::assertNull($this->pdo->query('SELECT lease_token FROM request_automation_state')->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM automation_alerts')->fetchColumn());
        } finally { $_ENV=$env; }
    }

    public function testLiveLeaseBlocksAnotherConnectionAndExpiredLeaseIsRecoverable(): void
    {
        $env=$_ENV;
        try {
            $_ENV['REQUEST_AUTOMATIONS_ENABLED']='true'; $_ENV['MAIL_MESSAGE_TYPES']='verify_email';
            $config=Config::load($this->root);
            $second=\App\Shared\Database\ConnectionFactory::create($config,$this->root);
            $this->pdo->prepare('UPDATE request_automation_state SET lease_token=?,lease_until=?')->execute(['occupied',time()+60]);
            $runtime=new RequestAutomation($second,$config,$this->root);
            self::assertFalse($runtime->run()['ran']);
            $this->pdo->exec('UPDATE request_automation_state SET lease_until=0');
            self::assertTrue($runtime->run()['ran']);
            self::assertNull($this->pdo->query('SELECT lease_token FROM request_automation_state')->fetchColumn());
            unset($runtime,$second);
        } finally { $_ENV=$env; }
    }
}
