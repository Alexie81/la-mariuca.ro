<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use PDO;

/** Both return entry points share the same original invoice allocations. */
final class ReturnStockLinks
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function consumptions(int $orderItemId, ?int $returnId = null, ?int $layerId = null): array
    {
        $sql="SELECT c.* FROM nir_fifo_consumptions c WHERE ((c.source_type='order_item' AND c.source_id=:legacy_item)
            OR (c.source_type='invoice_line' AND EXISTS (SELECT 1 FROM invoice_lines s JOIN invoices i ON i.id=s.invoice_id
                WHERE s.id=c.source_id AND s.order_item_id=:invoice_item AND i.deleted_at IS NULL";
        $params=['legacy_item'=>$orderItemId,'invoice_item'=>$orderItemId];
        if ($returnId !== null) {
            $sql.=' AND (NOT EXISTS(SELECT 1 FROM order_return_documents d WHERE d.return_id=:unlinked_return)
                OR EXISTS(SELECT 1 FROM invoice_lines credit JOIN order_return_documents d ON d.invoice_id=credit.invoice_id
                    JOIN invoices ci ON ci.id=credit.invoice_id WHERE d.return_id=:linked_return AND ci.deleted_at IS NULL AND credit.parent_line_id=s.id))';
            $params['unlinked_return']=$returnId; $params['linked_return']=$returnId;
        }
        $sql.=')))';
        if ($layerId !== null) { $sql.=' AND c.layer_id=:layer'; $params['layer']=$layerId; }
        $q=$this->pdo->prepare($sql.' ORDER BY c.id'); $q->execute($params); return $q->fetchAll();
    }

    public function hasInvoiceReceipt(int $returnId): bool
    {
        $q=$this->pdo->prepare('SELECT 1 FROM order_return_documents d JOIN invoice_receipts r ON r.invoice_id=d.invoice_id WHERE d.return_id=:id AND r.reversed_at IS NULL LIMIT 1');
        $q->execute(['id'=>$returnId]); return $q->fetchColumn()!==false;
    }

    /** @return array<string,mixed>|null */
    public function nirForInvoice(int $invoiceId): ?array
    {
        $q=$this->pdo->prepare('SELECT n.* FROM nir_documents n JOIN invoice_receipts r ON r.id=n.invoice_receipt_id WHERE r.invoice_id=:id AND r.reversed_at IS NULL LIMIT 1');
        $q->execute(['id'=>$invoiceId]); $direct=$q->fetch(); if($direct) { return $direct; }
        $q=$this->pdo->prepare('SELECT n.* FROM nir_documents n JOIN order_return_documents d ON d.return_id=n.return_id WHERE d.invoice_id=:id LIMIT 1');
        $q->execute(['id'=>$invoiceId]); return $q->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function invoicesForReturn(int $returnId): array
    {
        $q=$this->pdo->prepare('SELECT i.* FROM invoices i JOIN order_return_documents d ON d.invoice_id=i.id WHERE d.return_id=:id AND i.deleted_at IS NULL ORDER BY i.id');
        $q->execute(['id'=>$returnId]); return $q->fetchAll();
    }

    /** The customer-return receipt refers to the sale invoice through its credit document.
     * @return list<array<string,mixed>>
     */
    public function originalInvoicesForNir(int $nirId): array
    {
        $q=$this->pdo->prepare('SELECT DISTINCT original.id,original.series_code,original.number,original.issued_at
            FROM nir_documents n JOIN invoices credit ON (
                EXISTS (SELECT 1 FROM invoice_receipts r WHERE r.id=n.invoice_receipt_id AND r.invoice_id=credit.id)
                OR EXISTS (SELECT 1 FROM order_return_documents d WHERE d.return_id=n.return_id AND d.invoice_id=credit.id))
            JOIN invoices original ON original.id=credit.parent_id
            WHERE n.id=:id AND n.type=\'customer_return\' AND credit.deleted_at IS NULL ORDER BY original.id');
        $q->execute(['id'=>$nirId]); return $q->fetchAll();
    }
}
