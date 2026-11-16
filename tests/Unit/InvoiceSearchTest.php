<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Modules\Invoicing\InvoiceSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceSearchTest extends TestCase
{
    #[DataProvider('phrases')]
    public function testRecognisesRomanianPhrasesWithoutLosingLiteralSearch(string $query,array $conditions,array $terms): void
    {
        $search=InvoiceSearch::parse($query);
        self::assertSame($conditions,array_column($search['conditions'],'value','field'));
        self::assertSame($terms,$search['terms']);
    }
    public static function phrases(): iterable
    {
        yield ['RETURURI fără NIR de retur Ana',['document_group'=>'credits','receipt'=>'pending'],['ana']];
        yield ['Facturi de retur pentru Ștefania Popescu',['document_group'=>'credits'],['stefania','popescu']];
        yield ['facturi plătite parțial cu card online',['payment_state'=>'partial','payment_method'=>'stripe'],[]];
        yield ['facturi neplătite ramburs',['payment_state'=>'unpaid','payment_method'=>'cod'],[]];
        yield ['nu sunt plătite',['payment_state'=>'unpaid'],[]];
        yield ['nu sunt trimise în SPV',['spv_group'=>'not_sent'],[]];
        yield ['SPV netrimise',['spv_group'=>'not_sent'],[]];
        yield ['trimise în SPV',['spv_group'=>'sent'],[]];
        yield ['acceptate de ANAF',['fiscal_state'=>'accepted'],[]];
        yield ['respinse de ANAF',['fiscal_state'=>'rejected'],[]];
        yield ['retururi cu NIR',['document_group'=>'credits','receipt'=>'received'],[]];
        yield ['neplatite netrimise pe e-mail',['payment_state'=>'unpaid','email_group'=>'not_sent'],[]];
        yield ['trimise pe email',['email_state'=>'sent'],[]];
        yield ['de încasat persoane juridice',['payment_group'=>'outstanding','customer_type'=>'company'],[]];
        yield ['fără retururi',['document_group'=>'sales'],[]];
        yield ['retur pentru corecție',['kind'=>'correction_negative'],[]];
        yield ['LM-DEMO-000344 QA-ANAF-321',[],['lm','demo','000344','qa','anaf','321']];
        yield ['retururi@example.test',[],['retururi@example','test']];
        yield ['"Retururi SRL"',[],['retururi srl']];
        yield ['„Card” Ana',[],['card','ana']];
        yield ['Popescu',[],['popescu']];
    }
    public function testNavigationRemovesOnlyRecognisedQuickFiltersAndKeepsNamesAndPayment(): void
    {
        $search=InvoiceSearch::parse('retururi fără NIR neplătite „Ana Pop”');
        self::assertSame('neplătite „Ana Pop”',$search['navigation_query']);
        self::assertSame(['Facturi de retur','NIR de retur lipsă','Neachitate'],$search['labels']);
        self::assertCount(1,InvoiceSearch::parse('retur retururi')['conditions']);
        self::assertCount(2,InvoiceSearch::parse('platite neplatite')['conditions']);
        self::assertSame([],InvoiceSearch::parse(['invalid'])['terms']);
        self::assertSame([],InvoiceSearch::parse('')['conditions']);
    }
}
