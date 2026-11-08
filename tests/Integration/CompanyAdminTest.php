<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Tests\DatabaseTestCase;

final class CompanyAdminTest extends DatabaseTestCase
{
    private const BASE = '/admin/detaliile-firmei/';
    private const PASSWORD = 'Company-admin-test-2026!';
    private int $adminId;
    /** @var list<string> */
    private array $temporaryFiles = [];
    /** @var list<string> */
    private array $stampFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $now = gmdate('c');
        $insert = $this->pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,role,status,email_verified_at,created_at,updated_at) VALUES ('company.admin@example.test',:password,'Paula','Admin','administrator','active',:now,:now,:now)");
        $insert->execute(['password'=>password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'now'=>$now]);
        $this->adminId = (int) $this->pdo->lastInsertId();
        $this->request('GET', '/admin');
        $login = $this->request('POST', '/admin', ['_csrf'=>$_SESSION['_csrf'], 'email'=>'company.admin@example.test', 'password'=>self::PASSWORD]);
        self::assertSame(303, $login->getStatusCode());
    }

    protected function tearDown(): void
    {
        foreach (array_unique([...$this->temporaryFiles, ...$this->stampFiles]) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testCompaniesKeepCommercialDataAndExactlyOneDefaultAcrossCreateEditAndDelete(): void
    {
        $company = [
            'legal_name'=>'Atelier Copilărie SRL', 'trade_name'=>'Atelier Copilărie',
            'tax_id'=>'RO12345678', 'trade_register'=>'J40/1234/2020',
            'registered_address'=>'Strada Florilor nr. 10', 'city'=>'București', 'county'=>'București',
            'postal_code'=>'010101', 'country'=>'România', 'email'=>'atelier@example.test',
            'phone'=>'+40 730 000 344', 'website'=>'https://atelier.example.test',
            'physical_address'=>'Strada Atelierului nr. 3', 'service_area'=>'România',
            'bank_name'=>'BCR', 'iban'=>'ro49 aaaa 1b31 0075 9384 0000', 'share_capital'=>'1250,50',
        ];
        $firstId = $this->createCompany($company);
        $row = $this->company($firstId);
        self::assertSame(1, (int) $row['is_default']);
        foreach (['legal_name','trade_name','tax_id','trade_register','registered_address','city','county','postal_code','country','email','phone','website','physical_address','service_area','bank_name'] as $field) {
            self::assertSame($company[$field], $row[$field], $field);
        }
        self::assertSame('RO49AAAA1B31007593840000', $row['iban']);
        self::assertSame(125050, (int) $row['share_capital_bani']);

        $secondId = $this->createCompany(['legal_name'=>'Al doilea atelier SRL']);
        self::assertSame(0, (int) $this->company($secondId)['is_default']);
        $update = $this->post(self::BASE . $secondId . '/editare/', ['legal_name'=>'Atelier implicit SRL', 'is_default'=>'1', 'share_capital'=>'0,01']);
        self::assertSame(200, $update->getStatusCode());
        self::assertSame($secondId, $this->defaultCompanyId());
        self::assertSame(1, (int) $this->company($secondId)['share_capital_bani']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM companies WHERE is_default=1')->fetchColumn());

        $warehouseId = $this->createWarehouse($secondId, 'Depozitul firmei');
        $confirm = $this->request('GET', self::BASE . $secondId . '/sterge/');
        self::assertSame(200, $confirm->getStatusCode());
        self::assertNotEmpty($this->company($secondId), 'A confirmation GET must never delete data.');
        self::assertSame(200, $this->post(self::BASE . $secondId . '/sterge/')->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM companies WHERE id=' . $secondId)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM company_warehouses WHERE id=' . $warehouseId)->fetchColumn());
        self::assertSame($firstId, $this->defaultCompanyId());

        self::assertSame(200, $this->post(self::BASE . $firstId . '/sterge/')->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn());
        $replacementId = $this->createCompany(['legal_name'=>'O firmă nouă SRL']);
        self::assertSame($replacementId, $this->defaultCompanyId());
    }

    public function testWarehouseDefaultsAndNamesAreScopedToTheOwningCompany(): void
    {
        $firstCompany = $this->createCompany(['legal_name'=>'Firma unu SRL']);
        $secondCompany = $this->createCompany(['legal_name'=>'Firma doi SRL']);
        $firstWarehouse = $this->createWarehouse($firstCompany, 'Depozit central');
        $secondWarehouse = $this->createWarehouse($firstCompany, 'Magazin', true);
        $otherWarehouse = $this->createWarehouse($secondCompany, 'Depozit central');
        self::assertSame($secondWarehouse, $this->defaultWarehouseId($firstCompany));
        self::assertSame($otherWarehouse, $this->defaultWarehouseId($secondCompany));

        $duplicate = $this->post($this->warehouseBase($firstCompany) . 'nou/', ['name'=>'depozit central']);
        $this->assertFieldError($duplicate, 'name');
        $crossCompanyPath = $this->warehouseBase($secondCompany) . $firstWarehouse;
        self::assertSame(404, $this->request('GET', $crossCompanyPath . '/editare/modal/')->getStatusCode());
        self::assertSame(404, $this->post($crossCompanyPath . '/editare/', ['name'=>'Modificat din altă firmă', 'is_default'=>'1'])->getStatusCode());
        self::assertSame(404, $this->post($crossCompanyPath . '/sterge/')->getStatusCode());
        self::assertSame('Depozit central', $this->pdo->query('SELECT name FROM company_warehouses WHERE id=' . $firstWarehouse)->fetchColumn());
        self::assertSame($otherWarehouse, $this->defaultWarehouseId($secondCompany));

        self::assertSame(200, $this->post($this->warehouseBase($firstCompany) . $firstWarehouse . '/editare/', ['name'=>'Atelier principal', 'is_default'=>'1'])->getStatusCode());
        self::assertSame($firstWarehouse, $this->defaultWarehouseId($firstCompany));
        self::assertSame(200, $this->post($this->warehouseBase($firstCompany) . $firstWarehouse . '/sterge/')->getStatusCode());
        self::assertSame($secondWarehouse, $this->defaultWarehouseId($firstCompany));
        self::assertSame($otherWarehouse, $this->defaultWarehouseId($secondCompany));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM company_warehouses WHERE company_id=' . $firstCompany . ' AND is_default=1')->fetchColumn());
    }

    public function testInvalidCompanyFieldsDoNotChangeStoredDataOrDefaults(): void
    {
        $companyId = $this->createCompany(['legal_name'=>'Firma păstrată SRL', 'email'=>'valid@example.test', 'share_capital'=>'25,75']);
        $otherId = $this->createCompany(['legal_name'=>'Cealaltă firmă SRL']);
        foreach ([
            ['legal_name', '   '], ['legal_name', str_repeat('a', 251)],
            ['email', 'adresa-gresita'], ['website', 'javascript:alert(1)'],
            ['iban', 'RO48AAAA1B31007593840000'], ['iban', 'RO1'],
            ['share_capital', '-1'], ['share_capital', '12,345'],
            ['share_capital', '1e3'], ['share_capital', '1000000000000'],
        ] as [$field, $value]) {
            $response = $this->post(self::BASE . $otherId . '/editare/', ['legal_name'=>'Titlu nesalvat', 'is_default'=>'1', $field=>$value]);
            $this->assertFieldError($response, $field);
            self::assertSame($companyId, $this->defaultCompanyId());
            self::assertSame('Cealaltă firmă SRL', $this->company($otherId)['legal_name']);
            self::assertSame(2575, (int) $this->company($companyId)['share_capital_bani']);
        }
        $missingName = $this->post($this->warehouseBase($companyId) . 'nou/', ['name'=>'   ']);
        $this->assertFieldError($missingName, 'name');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM company_warehouses')->fetchColumn());
    }

    public function testHtmlValidationRetainsEscapedValuesAndModalFormsWorkWithoutMutation(): void
    {
        $name = 'Atelier păstrat & <nou>';
        $invalid = $this->request('POST', self::BASE . 'nou/', ['_csrf'=>$_SESSION['_csrf'], 'legal_name'=>$name, 'email'=>'incomplet']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString(htmlspecialchars($name, ENT_QUOTES), (string) $invalid->getBody());
        self::assertStringContainsString('incomplet', (string) $invalid->getBody());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn());
        self::assertSame(200, $this->request('GET', self::BASE . 'nou/modal/')->getStatusCode());

        $companyId = $this->createCompany(['legal_name'=>'Firma modalelor SRL']);
        $warehouseId = $this->createWarehouse($companyId, 'Atelier');
        foreach ([
            self::BASE . $companyId . '/editare/', self::BASE . $companyId . '/editare/modal/',
            self::BASE . $companyId . '/sterge/modal/', $this->warehouseBase($companyId) . 'nou/modal/',
            $this->warehouseBase($companyId) . $warehouseId . '/editare/modal/',
            $this->warehouseBase($companyId) . $warehouseId . '/sterge/modal/',
        ] as $path) {
            $form = $this->request('GET', $path);
            self::assertSame(200, $form->getStatusCode(), $path);
            self::assertStringContainsString('name="_csrf"', (string) $form->getBody());
        }
        $nonJsSave = $this->request('POST', $this->warehouseBase($companyId) . $warehouseId . '/editare/', ['_csrf'=>$_SESSION['_csrf'], 'name'=>'Atelier redenumit']);
        self::assertSame(303, $nonJsSave->getStatusCode());
        self::assertStringStartsWith(self::BASE, $nonJsSave->getHeaderLine('Location'));
        self::assertSame('Atelier redenumit', $this->pdo->query('SELECT name FROM company_warehouses WHERE id=' . $warehouseId)->fetchColumn());
    }

    public function testPrivateStampIsValidatedReencodedReplacedAndRemoved(): void
    {
        $companyId = $this->createCompany(['legal_name'=>'Firma cu ștampilă SRL']);
        $upload = $this->pngUpload();
        $save = $this->post(self::BASE . $companyId . '/editare/', ['legal_name'=>'Firma cu ștampilă SRL'], ['stamp_file'=>$upload]);
        self::assertSame(200, $save->getStatusCode());
        $firstPath = $this->rememberStampPath($companyId);
        self::assertFileExists($firstPath);
        $stamp = $this->request('GET', self::BASE . $companyId . '/stampila/');
        self::assertSame(200, $stamp->getStatusCode());
        self::assertSame('image/png', $stamp->getHeaderLine('Content-Type'));
        self::assertStringContainsString('no-store', $stamp->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", (string) $stamp->getBody());
        self::assertStringNotContainsString('untrusted-image-trailer', (string) $stamp->getBody());

        foreach ([
            $this->textUpload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'stamp.png', 'image/png'),
            $this->textUpload(str_repeat('x', 2 * 1024 * 1024 + 1), 'too-big.png', 'image/png'),
        ] as $invalidUpload) {
            $response = $this->post(self::BASE . $companyId . '/editare/', ['legal_name'=>'Firma neschimbată'], ['stamp_file'=>$invalidUpload]);
            $this->assertFieldError($response, 'stamp_file');
            self::assertSame('Firma cu ștampilă SRL', $this->company($companyId)['legal_name']);
            self::assertSame($firstPath, $this->rememberStampPath($companyId));
            self::assertFileExists($firstPath);
        }

        $replace = $this->post(self::BASE . $companyId . '/editare/', ['legal_name'=>'Firma cu ștampilă SRL'], ['stamp_file'=>$this->pngUpload()]);
        self::assertSame(200, $replace->getStatusCode());
        $secondPath = $this->rememberStampPath($companyId);
        self::assertNotSame($firstPath, $secondPath);
        self::assertFileDoesNotExist($firstPath);
        self::assertFileExists($secondPath);
        $remove = $this->post(self::BASE . $companyId . '/editare/', ['legal_name'=>'Firma cu ștampilă SRL', 'remove_stamp'=>'1']);
        self::assertSame(200, $remove->getStatusCode());
        self::assertEmpty($this->company($companyId)['stamp_path']);
        self::assertFileDoesNotExist($secondPath);
        self::assertSame(404, $this->request('GET', self::BASE . $companyId . '/stampila/')->getStatusCode());
        self::assertSame(200, $this->post(self::BASE . $companyId . '/editare/', ['legal_name'=>'Firma cu ștampilă SRL'], ['stamp_file'=>$this->pngUpload()])->getStatusCode());
        $lastPath = $this->rememberStampPath($companyId);
        self::assertFileExists($lastPath);
        self::assertSame(200, $this->post(self::BASE . $companyId . '/sterge/')->getStatusCode());
        self::assertFileDoesNotExist($lastPath);
    }

    public function testEveryCompanyAndWarehouseMutationRequiresCsrfAndAdminAuthentication(): void
    {
        $companyId = $this->createCompany(['legal_name'=>'Firma protejată SRL']);
        $warehouseId = $this->createWarehouse($companyId, 'Depozit protejat');
        $paths = [
            self::BASE . 'nou/', self::BASE . $companyId . '/editare/', self::BASE . $companyId . '/sterge/',
            $this->warehouseBase($companyId) . 'nou/',
            $this->warehouseBase($companyId) . $warehouseId . '/editare/',
            $this->warehouseBase($companyId) . $warehouseId . '/sterge/',
        ];
        foreach ($paths as $path) {
            self::assertSame(419, $this->request('POST', $path, ['_csrf'=>'invalid-token', 'legal_name'=>'Nu trebuie salvat', 'name'=>'Nu trebuie salvat'])->getStatusCode());
        }
        $this->pdo->prepare("UPDATE users SET role='customer' WHERE id=?")->execute([$this->adminId]);
        self::assertSame(303, $this->request('GET', self::BASE)->getStatusCode());
        self::assertSame(303, $this->request('GET', self::BASE . $companyId . '/stampila/')->getStatusCode());
        self::assertSame(303, $this->post(self::BASE . $companyId . '/editare/', ['legal_name'=>'Modificare de client'])->getStatusCode());
        $this->pdo->prepare("UPDATE users SET role='administrator' WHERE id=?")->execute([$this->adminId]);
        unset($_SESSION['user_id']);
        foreach ([self::BASE, self::BASE . $companyId . '/editare/modal/', self::BASE . $companyId . '/stampila/', ...$paths] as $path) {
            $method = in_array($path, $paths, true) ? 'POST' : 'GET';
            $response = $this->request($method, $path, $method === 'POST' ? ['_csrf'=>$_SESSION['_csrf'], 'legal_name'=>'Nu trebuie salvat', 'name'=>'Nu trebuie salvat'] : []);
            self::assertSame(303, $response->getStatusCode(), $path);
            self::assertSame('/admin', $response->getHeaderLine('Location'));
        }
        $_SESSION['user_id'] = $this->adminId;
        self::assertSame('Firma protejată SRL', $this->company($companyId)['legal_name']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM company_warehouses')->fetchColumn());
    }

    /** @param array<string,mixed> $values */
    private function createCompany(array $values): int
    {
        $response = $this->post(self::BASE . 'nou/', $values);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertStringStartsWith(self::BASE, $body['redirect_url']);
        return (int) $this->pdo->query('SELECT MAX(id) FROM companies')->fetchColumn();
    }

    private function createWarehouse(int $companyId, string $name, bool $isDefault = false): int
    {
        $response = $this->post($this->warehouseBase($companyId) . 'nou/', ['name'=>$name, 'is_default'=>$isDefault ? '1' : '0']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return (int) $this->pdo->query('SELECT MAX(id) FROM company_warehouses')->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function company(int $id): array
    {
        $row = $this->pdo->query('SELECT * FROM companies WHERE id=' . $id)->fetch();
        self::assertIsArray($row);
        return $row;
    }

    private function defaultCompanyId(): int
    {
        return (int) $this->pdo->query('SELECT id FROM companies WHERE is_default=1')->fetchColumn();
    }

    private function defaultWarehouseId(int $companyId): int
    {
        return (int) $this->pdo->query('SELECT id FROM company_warehouses WHERE company_id=' . $companyId . ' AND is_default=1')->fetchColumn();
    }

    private function warehouseBase(int $companyId): string
    {
        return self::BASE . $companyId . '/gestiuni/';
    }

    private function assertFieldError(ResponseInterface $response, string $field): void
    {
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($body['success']);
        self::assertArrayHasKey($field, $body['errors']);
    }

    private function pngUpload(): UploadedFileInterface
    {
        $path = tempnam(sys_get_temp_dir(), 'lm-company-stamp-');
        self::assertIsString($path);
        $this->temporaryFiles[] = $path;
        $image = imagecreatetruecolor(32, 32);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefill($image, 0, 0, imagecolorallocate($image, 80, 110, 160));
        imagepng($image, $path);
        imagedestroy($image);
        file_put_contents($path, 'untrusted-image-trailer', FILE_APPEND);
        return new UploadedFile($path, 'stamp.php.png', 'image/png', filesize($path), UPLOAD_ERR_OK);
    }

    private function textUpload(string $contents, string $filename, string $mime): UploadedFileInterface
    {
        $path = tempnam(sys_get_temp_dir(), 'lm-company-invalid-');
        self::assertIsString($path);
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);
        return new UploadedFile($path, $filename, $mime, filesize($path), UPLOAD_ERR_OK);
    }

    private function rememberStampPath(int $companyId): string
    {
        $name = (string) $this->company($companyId)['stamp_path'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $name);
        $path = $this->root . '/storage/private/company-stamps/' . $name;
        $this->stampFiles[] = $path;
        return $path;
    }

    /** @param array<string,mixed> $data @param array<string,UploadedFileInterface> $uploads */
    private function post(string $path, array $data = [], array $uploads = []): ResponseInterface
    {
        return $this->request('POST', $path, ['_csrf'=>$_SESSION['_csrf']] + $data, ['Accept'=>'application/json'], $uploads);
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers @param array<string,UploadedFileInterface> $uploads */
    private function request(string $method, string $path, array $data = [], array $headers = [], array $uploads = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR'=>'127.0.0.95'])->withHeader('User-Agent', 'LaMariuca-CompanyAdmin-Test/1.0');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($data !== []) {
            $request = $request->withParsedBody($data);
        }
        if ($uploads !== []) {
            $request = $request->withUploadedFiles($uploads);
        }
        $app = AppFactory::create($this->root);
        $response = $app->handle($request);
        unset($app);
        gc_collect_cycles();
        return $response;
    }
}
