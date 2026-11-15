<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Modules\Reviews\{ProductReviewRepository,ProductReviewService};
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\DatabaseTestCase;

final class ReviewAdminWorkspaceTest extends DatabaseTestCase
{
    private int $adminId;
    private int $productId;
    /** @var list<int> */
    private array $ids=[];

    protected function setUp(): void
    {
        parent::setUp(); $this->seed();
        $this->pdo->exec("INSERT INTO users(email,password_hash,first_name,last_name,role,status,created_at,updated_at) VALUES('review-admin@example.test','x','Paula','QA','administrator','active','2026-11-15','2026-11-15')");
        $this->adminId=(int)$this->pdo->lastInsertId(); $_SESSION['user_id']=$this->adminId; $_SESSION['_csrf']='reviews-qa-token';
        $this->productId=(int)$this->pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
        $repository=new ProductReviewRepository($this->pdo);
        for($index=0;$index<26;$index++) {
            $date='2026-11-'.str_pad((string)($index+1),2,'0',STR_PAD_LEFT).'T10:00:00Z';
            $this->ids[]=$repository->create(['product_id'=>$this->productId,'customer_id'=>null,
                'author_name'=>'Client QA '.str_pad((string)$index,2,'0',STR_PAD_LEFT),'author_email'=>'client'.$index.'@example.test',
                'order_number'=>'LM-QA-'.$index,'rating'=>($index%5)+1,'title'=>'Titlu QA','body'=>$index===0?'Recenzie 100% literală <script>alert(1)</script>':str_repeat('Conținut de recenzie '.$index.'. ',12),
                'status'=>['pending','approved','rejected'][$index%3],'is_verified_purchase'=>true,'is_demo'=>false,'created_at'=>$date,'updated_at'=>$date]);
        }
    }

    public function testPaginationIsBoundedStableAndClampsOutOfRangePages(): void
    {
        $service=new ProductReviewService(new ProductReviewRepository($this->pdo));
        $first=$service->moderationWorkspace([]); $last=$service->moderationWorkspace(['pagina'=>'9999999999']);
        self::assertSame(26,$first['total']); self::assertSame(3,$first['pages']); self::assertCount(10,$first['reviews']);
        self::assertSame(1,$first['from']); self::assertSame(10,$first['to']); self::assertSame('pending',$first['reviews'][0]['status']);
        self::assertSame(3,$last['page']); self::assertCount(6,$last['reviews']); self::assertSame(21,$last['from']); self::assertSame(26,$last['to']);
        self::assertSame([],array_intersect(array_column($first['reviews'],'id'),array_column($last['reviews'],'id')));
        self::assertCount(20,$service->moderationWorkspace(['per_page'=>20])['reviews']);
        $newest=$service->moderationWorkspace(['sortare'=>'newest']); self::assertSame($this->ids[25],(int)$newest['reviews'][0]['id']);
        $oldest=$service->moderationWorkspace(['sortare'=>'oldest']); self::assertSame($this->ids[0],(int)$oldest['reviews'][0]['id']);
        $html=(string)$this->request('GET','/admin/recenzii/')->getBody();
        self::assertSame(10,substr_count($html,'data-review-row=')); self::assertStringContainsString('Pagina 1 / 3',$html);
        self::assertStringNotContainsString('<textarea',$html); self::assertStringContainsString('Paginarea recenziilor',$html);
    }

    public function testCombinedFiltersLiteralSearchAndGlobalCounts(): void
    {
        $repository=new ProductReviewRepository($this->pdo); $repository->respond($this->ids[4],'Răspuns public de test',$this->adminId);
        $service=new ProductReviewService($repository);
        $filtered=$service->moderationWorkspace(['stare'=>'approved','nota'=>'5','raspuns'=>'yes']);
        self::assertSame(1,$filtered['total']); self::assertSame($this->ids[4],(int)$filtered['reviews'][0]['id']);
        self::assertSame(26,$filtered['summary']['total']); self::assertSame(9,$filtered['summary']['pending']); self::assertSame(1,$filtered['summary']['responded']);
        self::assertSame(25,$service->moderationWorkspace(['raspuns'=>'no'])['total']);
        self::assertSame(1,$service->moderationWorkspace(['q'=>'100%'])['total']);
        self::assertSame(1,$service->moderationWorkspace(['q'=>'client25@example.test'])['total']);
        self::assertSame(1,$service->moderationWorkspace(['q'=>'LM-QA-24'])['total']);
        $empty=$service->moderationWorkspace(['q'=>"' OR 1=1 --"]); self::assertSame(0,$empty['total']); self::assertSame(0,$empty['from']);
        $invalid=$service->moderationWorkspace(['q'=>[],'stare'=>['approved'],'per_page'=>999999,'sortare'=>'DROP TABLE users','pagina'=>-2]);
        self::assertCount(10,$invalid['reviews']); self::assertSame(1,$invalid['page']); self::assertSame('priority',$invalid['filters']['sortare']);
    }

    public function testPrivateModalAndStandaloneDetailEscapeTextAndShowOnlyTheirHistory(): void
    {
        $repository=new ProductReviewRepository($this->pdo); $repository->moderate($this->ids[0],'approved',$this->adminId);
        $repository->moderate($this->ids[1],'rejected',$this->adminId);
        $path='/admin/recenzii/'.$this->ids[0].'/';
        $response=$this->request('GET',$path,[],['X-Review-Modal'=>'1']); $html=(string)$response->getBody();
        self::assertSame(200,$response->getStatusCode()); self::assertSame('no-store',$response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString('<html',$html); self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;',$html);
        self::assertStringContainsString('12:00',$html); self::assertStringContainsString('data-review-form',$html);
        $history=(string)$this->request('GET',$path.'?panel=history',[],['X-Review-Modal'=>'1'])->getBody();
        self::assertStringContainsString('Recenzie aprobată',$history); self::assertStringNotContainsString('Recenzie respinsă',$history);
        self::assertStringContainsString('<html',(string)$this->request('GET',$path)->getBody());
        self::assertSame(404,$this->request('GET','/admin/recenzii/999999/',[],['X-Review-Modal'=>'1'])->getStatusCode());
    }

    public function testHttpActionsPersistAndPreserveSafeListContext(): void
    {
        $id=$this->ids[0]; $path='/admin/recenzii/'.$id.'/actiune/';
        $base=['_csrf'=>'reviews-qa-token','return_query'=>'stare=pending&pagina=2&q=Client&return_url=https%3A%2F%2Fevil.test'];
        $invalid=$this->request('POST',$path,$base+['action'=>'respond','admin_response'=>'Salut!'],['Accept'=>'application/json']);
        self::assertSame(422,$invalid->getStatusCode());
        foreach(['approve','respond','reject','pending'] as $action) {
            $response=$this->request('POST',$path,$base+['action'=>$action,'admin_response'=>'Mulțumim pentru recenzie!'],['Accept'=>'application/json']);
            self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
            $payload=json_decode((string)$response->getBody(),true); self::assertTrue($payload['ok']);
            self::assertStringStartsWith('/admin/recenzii/?',$payload['redirect']); self::assertStringContainsString('pagina=2',$payload['redirect']); self::assertStringNotContainsString('evil.test',$payload['redirect']);
            if($action==='respond') { $public=array_column((new ProductReviewRepository($this->pdo))->approvedForProduct($this->productId),'admin_response','id'); self::assertSame('Mulțumim pentru recenzie!',$public[$id]); }
        }
        self::assertSame('pending',$this->pdo->query('SELECT status FROM product_reviews WHERE id='.$id)->fetchColumn());
        $removed=$this->request('POST',$path,$base+['action'=>'remove']); self::assertSame(303,$removed->getStatusCode());
        self::assertNotNull($this->pdo->query('SELECT deleted_at FROM product_reviews WHERE id='.$id)->fetchColumn());
        self::assertSame(5,(int)$this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='product_review' AND entity_id=".$id)->fetchColumn());
        self::assertSame(404,$this->request('GET','/admin/recenzii/'.$id.'/')->getStatusCode());
    }

    public function testNoJavascriptValidationPreservesTheReplyAndRequiresCsrf(): void
    {
        $id=$this->ids[1]; $path='/admin/recenzii/'.$id.'/actiune/';
        self::assertSame(419,$this->request('POST',$path,['action'=>'reject'])->getStatusCode());
        $response=$this->request('POST',$path,['_csrf'=>'reviews-qa-token','action'=>'respond','admin_response'=>str_repeat('A',1501)]);
        self::assertSame(422,$response->getStatusCode()); self::assertStringContainsString(str_repeat('A',1501),(string)$response->getBody());
        self::assertSame('',$this->pdo->query('SELECT admin_response FROM product_reviews WHERE id='.$id)->fetchColumn());
        $success=$this->request('POST',$path,['_csrf'=>'reviews-qa-token','action'=>'respond','admin_response'=>'Răspuns din formularul HTML.']);
        self::assertSame(303,$success->getStatusCode()); self::assertSame('Răspuns din formularul HTML.',$this->pdo->query('SELECT admin_response FROM product_reviews WHERE id='.$id)->fetchColumn());
    }

    public function testAnonymousAndCustomerCannotReadOrModeratePrivateReviews(): void
    {
        $_SESSION=[]; $path='/admin/recenzii/'.$this->ids[0].'/';
        self::assertContains($this->request('GET','/admin/recenzii/')->getStatusCode(),[302,303,401,403]);
        self::assertContains($this->request('GET',$path,[],['X-Review-Modal'=>'1'])->getStatusCode(),[302,303,401,403]);
        $this->pdo->exec("UPDATE users SET role='customer' WHERE id=".$this->adminId); $_SESSION['user_id']=$this->adminId; $_SESSION['_csrf']='reviews-qa-token';
        self::assertContains($this->request('GET',$path)->getStatusCode(),[302,303,401,403]);
        self::assertContains($this->request('POST',$path.'actiune/',['_csrf'=>'reviews-qa-token','action'=>'approve'])->getStatusCode(),[302,303,401,403]);
        self::assertSame('pending',$this->pdo->query('SELECT status FROM product_reviews WHERE id='.$this->ids[0])->fetchColumn());
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    private function request(string $method,string $path,array $data=[],array $headers=[]): ResponseInterface
    {
        $request=(new ServerRequestFactory())->createServerRequest($method,'http://localhost'.$path,['REMOTE_ADDR'=>'127.0.0.97'])->withParsedBody($data);
        foreach($headers as $name=>$value) { $request=$request->withHeader($name,$value); }
        $app=AppFactory::create($this->root); $response=$app->handle($request); unset($app); gc_collect_cycles(); return $response;
    }
}
