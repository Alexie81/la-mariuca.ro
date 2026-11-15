<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Modules\Inventory\NirDocumentStorage;
use Slim\Psr7\UploadedFile;

/** A private, independent copy of the actual return invoice in the receipt's documents. */
final class ReturnInvoiceAttachment
{
    private readonly NirDocumentStorage $storage;
    private readonly InvoiceExportService $exports;
    public function __construct(InvoiceRepository $repository,string $root)
    {
        $this->storage=new NirDocumentStorage($root);
        $this->exports=new InvoiceExportService($repository,$root);
    }
    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    public function store(array $invoice): array
    {
        $path=$this->exports->pdf($invoice);
        return $this->storage->store(new UploadedFile($path,'Factura de retur '.$invoice['series_code'].' '.$invoice['number'].'.pdf','application/pdf',filesize($path)));
    }
    /** @param array<string,mixed> $file */
    public function remove(array $file): void { $this->storage->remove($file['stored_name']); }
}
