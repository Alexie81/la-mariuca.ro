<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Shared\Config\Config;
use PDO;

final class SpvConnection
{
    public function __construct(private readonly PDO $pdo,private readonly Config $config,private readonly SpvHttpClient $http) {}
    public function configured(): bool { return $this->config->get('ANAF_CLIENT_ID')!=='' && $this->config->get('ANAF_CLIENT_SECRET')!==''; }
    public function callback(): string { return $this->config->rootUrl().'/admin/spv-efactura/retur/'; }
    public function authorizationUrl(string $state): string
    {
        if(!$this->configured()) { throw new InvoiceException('Configurează în fișierul privat al serverului ANAF_CLIENT_ID și ANAF_CLIENT_SECRET, apoi conectează certificatul.'); }
        return 'https://logincert.anaf.ro/anaf-oauth2/v1/authorize?'.http_build_query(['response_type'=>'code','client_id'=>$this->config->get('ANAF_CLIENT_ID'),'redirect_uri'=>$this->callback(),'token_content_type'=>'jwt','state'=>$state]);
    }
    public function exchange(string $code): void
    {
        $current=$this->row();
        $result=$this->tokenRequest(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$this->callback()]);
        $this->save($result,(int)$current['revision']);
    }
    public function accessToken(): string
    {
        if($this->pdo->inTransaction()) { throw new \LogicException('Token exchange must run outside a transaction'); }
        $row=$this->row(); if(!$row['encrypted_tokens']) { throw new InvoiceException('Conectează mai întâi certificatul ANAF.'); }
        $vault=new SpvTokenVault($this->config->get('APP_KEY')); $tokens=$vault->open($row['encrypted_tokens']);
        if((int)$row['expires_at']>time()+300) { return $tokens['access_token']; }
        if(empty($tokens['refresh_token']) || ($row['refresh_expires_at']!==null && (int)$row['refresh_expires_at']<=time())) { throw new InvoiceException('Conexiunea ANAF a expirat. Reconectează certificatul.'); }
        $lease=bin2hex(random_bytes(16));
        $q=$this->pdo->prepare('UPDATE spv_credentials SET refresh_lease=:lease,refresh_lease_until=:until WHERE id=1 AND revision=:revision AND (refresh_lease_until IS NULL OR refresh_lease_until<:now)');
        $q->execute(['lease'=>$lease,'until'=>time()+90,'revision'=>$row['revision'],'now'=>time()]);
        if($q->rowCount()!==1) { throw new InvoiceException('Conexiunea ANAF se reînnoiește în acest moment. Încearcă din nou în câteva secunde.',409); }
        try {
            $result=$this->tokenRequest(['grant_type'=>'refresh_token','refresh_token'=>$tokens['refresh_token']]);
            if(empty($result['refresh_token'])) { $result['refresh_token']=$tokens['refresh_token']; }
            $this->save($result,(int)$row['revision'],$row['refresh_expires_at']===null?null:(int)$row['refresh_expires_at']);
            return (string)$result['access_token'];
        } finally { $q=$this->pdo->prepare('UPDATE spv_credentials SET refresh_lease=NULL,refresh_lease_until=NULL WHERE id=1 AND refresh_lease=:lease'); $q->execute(['lease'=>$lease]); }
    }
    public function test(): void
    {
        // This endpoint echoes headers, including Authorization. Never persist its body.
        $result=$this->http->request('GET','https://api.anaf.ro/TestOauth/jaxrs/hello?name=LaMariuca',['Authorization: Bearer '.$this->accessToken()]);
        if($result['status']!==200) { throw new InvoiceException('ANAF nu a confirmat conexiunea (HTTP '.$result['status'].'). Reconectează certificatul sau verifică drepturile aplicației.'); }
        $q=$this->pdo->prepare('UPDATE spv_credentials SET checked_at=:now,last_error=NULL WHERE id=1'); $q->execute(['now'=>gmdate('Y-m-d\TH:i:s\Z')]);
    }
    public function disconnect(): void
    {
        $this->pdo->exec('UPDATE spv_credentials SET encrypted_tokens=NULL,expires_at=NULL,refresh_expires_at=NULL,connected_at=NULL,checked_at=NULL,refresh_lease=NULL,refresh_lease_until=NULL,revision=revision+1 WHERE id=1');
    }
    /** @return array<string,mixed> */
    public function state(): array
    {
        $row=$this->row(); unset($row['encrypted_tokens'],$row['refresh_lease'],$row['refresh_lease_until']);
        $row['connected']=$row['connected_at']!==null; $row['configured']=$this->configured();
        $row['expired']=$row['connected'] && $row['refresh_expires_at']!==null && (int)$row['refresh_expires_at']<=time();
        return $row;
    }
    /** @return array<string,mixed> */
    private function row(): array { return $this->pdo->query('SELECT * FROM spv_credentials WHERE id=1')->fetch(); }
    /** @param array<string,string> $fields @return array<string,mixed> */
    private function tokenRequest(array $fields): array
    {
        if(!$this->configured()) { throw new InvoiceException('Aplicația ANAF nu este configurată.'); }
        $result=$this->http->request('POST','https://logincert.anaf.ro/anaf-oauth2/v1/token',[
            'Authorization: Basic '.base64_encode($this->config->get('ANAF_CLIENT_ID').':'.$this->config->get('ANAF_CLIENT_SECRET')),
            'Content-Type: application/x-www-form-urlencoded','Accept: application/json'],http_build_query($fields+['token_content_type'=>'jwt']));
        if($result['status']!==200) { throw new InvoiceException('Autorizarea ANAF nu a reușit (HTTP '.$result['status'].'). Reconectează certificatul și verifică adresa de retur.'); }
        $data=json_decode($result['body'],true);
        if(!is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token']==='' || strlen($data['access_token'])>50000) { throw new InvoiceException('ANAF a returnat o autorizare incompletă.'); }
        return $data;
    }
    /** @param array<string,mixed> $tokens */
    private function save(array $tokens,int $revision,?int $oldRefreshExpiry=null): void
    {
        $expiry=isset($tokens['expires_in'])?time()+(int)$tokens['expires_in']:self::jwtExpiry((string)$tokens['access_token']);
        if(!$expiry || $expiry<=time()) { throw new InvoiceException('ANAF nu a furnizat o dată de expirare validă pentru conexiune.'); }
        $refresh=isset($tokens['refresh_expires_in'])?time()+(int)$tokens['refresh_expires_in']:(self::jwtExpiry((string)($tokens['refresh_token'] ?? '')) ?? $oldRefreshExpiry);
        $q=$this->pdo->prepare('UPDATE spv_credentials SET encrypted_tokens=:tokens,expires_at=:expiry,refresh_expires_at=:refresh,connected_at=:now,last_error=NULL,revision=revision+1 WHERE id=1 AND revision=:revision');
        $q->execute(['tokens'=>(new SpvTokenVault($this->config->get('APP_KEY')))->seal($tokens),'expiry'=>$expiry,'refresh'=>$refresh,'now'=>gmdate('Y-m-d\TH:i:s\Z'),'revision'=>$revision]);
        if($q->rowCount()!==1) { throw new InvoiceException('Conexiunea a fost modificată în altă fereastră. Reconectează certificatul.',409); }
    }
    /** Expiry metadata only; never used as identity or authorization proof. */
    private static function jwtExpiry(string $token): ?int
    {
        $parts=explode('.',$token); if(count($parts)!==3) { return null; }
        $data=json_decode((string)base64_decode(strtr($parts[1],'-_','+/'),true),true);
        return is_numeric($data['exp'] ?? null)?(int)$data['exp']:null;
    }
}
