<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use App\Modules\Invoicing\InvoiceCalculator;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Builds private, disposable accounting-stock exports. */
final class StockLedgerExportService
{
    public function __construct(private readonly string $root) {}

    /** @param array<string,mixed> $report @param array<string,mixed> $company @return array{path:string,name:string} */
    public function accounting(array $report,array $company): array
    {
        $book=(new StockAccountingWorkbook($this->root))->build($report,$company);
        return $this->save($book,'La-Mariuca-Centralizator-Stocuri-'.($report['filters']['from'] ?: 'inceput').'--'.($report['filters']['to'] ?: date('Y-m-d')).'.xlsx');
    }

    /** @param array<string,mixed> $registry @param array<string,mixed> $company @return array{path:string,name:string} */
    public function registry(array $registry,array $company): array
    {
        $book=$this->book('Registru stoc contabil');
        $sheet=$book->getActiveSheet()->setTitle('Stoc pe produse');
        $this->brand($sheet,$company,'Registru stoc contabil','Situația curentă a produselor urmărite contabil');
        $headers=['Produs','SKU','Categorie','Furnizor','Stoc contabil','În curs de acoperire','Prag alertă','Disponibilitate online','Valoare contabilă (Lei)','Stare'];
        $sheet->fromArray($headers,null,'A6'); $this->header($sheet,'A6:J6');
        $row=7;
        foreach($registry['items'] as $index=>$item) {
            $online=(bool)$item['unlimited']?'Disponibil la furnizor':(string)$item['online_quantity'];
            $state=['negative'=>'Stoc negativ','low'=>'Sub prag','ok'=>'În regulă'][$item['alert']] ?? 'În regulă';
            $values=[$item['name'],$item['sku'],$item['category'],$item['supplier'],(float)$item['quantity'],(float)$item['pending'],(float)$item['minimum'],$online,(int)$item['value_bani']/100,$state];
            foreach($values as $column=>$value) {
                $cell=$sheet->getCell([$column+1,$row]);
                if(in_array($column,[4,5,6,8],true)) { $cell->setValue($value); $cell->getStyle()->getNumberFormat()->setFormatCode($column===8?'#,##0.00':$this->quantityFormat((float)$value)); }
                else { $cell->setValueExplicit((string)$value,DataType::TYPE_STRING); }
            }
            $sheet->getStyle('A'.$row.':J'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($index%2===0?'FFFFFF':'FBF6F8');
            $row++;
        }
        $end=max(6,$row-1);
        $sheet->freezePane('E7')->setAutoFilter('A6:J'.$end);
        foreach(['A'=>34,'B'=>20,'C'=>22,'D'=>24,'E'=>16,'F'=>18,'G'=>14,'H'=>24,'I'=>20,'J'=>16] as $column=>$width) { $sheet->getColumnDimension($column)->setWidth($width); }
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(6,6)->setPrintArea('A1:J'.$end)->setFitToWidth(1)->setFitToHeight(0);
        return $this->save($book,'Registru-stoc-contabil-'.date('Y-m-d').'.xlsx');
    }

    /** @param array<string,mixed> $detail @param array<string,mixed> $company @return array{path:string,name:string} */
    public function product(array $detail,array $company): array
    {
        $product=$detail['product']; $book=$this->book('Fișa produsului');
        $sheet=$book->getActiveSheet()->setTitle('Fișa produsului');
        $this->brand($sheet,$company,'Fișa produsului',$product['name'].' · '.($product['primary_sku'] ?? ''));
        $sheet->fromArray(['Sold inițial','Intrări','Ieșiri','Sold perioadă','Sold curent'],null,'A6'); $this->header($sheet,'A6:E6');
        $sheet->fromArray([[(float)InvoiceCalculator::quantity((int)$detail['opening_units']),(float)InvoiceCalculator::quantity((int)$detail['incoming_units']),(float)InvoiceCalculator::quantity((int)$detail['outgoing_units']),(float)InvoiceCalculator::quantity((int)$detail['closing_units']),(float)InvoiceCalculator::quantity((int)$detail['current_units'])]],null,'A7');
        foreach(range('A','E') as $column) { $cell=$sheet->getCell($column.'7'); $cell->getStyle()->getNumberFormat()->setFormatCode($this->quantityFormat((float)$cell->getValue())); }
        $headers=['Data și ora','Operațiune','Document','Gestiune','Partener','Intrare','Ieșire','Sold','Cost contabil (Lei)','Valoare vânzare (Lei)','Operator'];
        $sheet->fromArray($headers,null,'A10'); $this->header($sheet,'A10:K10'); $row=11;
        foreach($detail['movements'] as $index=>$movement) {
            $date=(new \DateTimeImmutable((string)$movement['movement_at']))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i');
            $units=(int)$movement['quantity_units'];
            $values=[$date,$movement['label'],$movement['document'],$movement['warehouse'],$movement['partner'],
                $units>0?(float)$movement['quantity']:'',$units<0?(float)ltrim((string)$movement['quantity'],'-'):'',(float)$movement['balance'],
                $movement['cost_bani']===null?'':(int)$movement['cost_bani']/100,$movement['sale_value_bani']===null?'':(int)$movement['sale_value_bani']/100,$movement['operator']];
            foreach($values as $column=>$value) {
                $cell=$sheet->getCell([$column+1,$row]);
                if(in_array($column,[5,6,7,8,9],true) && $value!=='') { $cell->setValue($value); $cell->getStyle()->getNumberFormat()->setFormatCode($column>=8?'#,##0.00':$this->quantityFormat((float)$value)); }
                else { $cell->setValueExplicit((string)$value,DataType::TYPE_STRING); }
            }
            $sheet->getStyle('A'.$row.':K'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($index%2===0?'FFFFFF':'FBF6F8'); $row++;
        }
        $end=max(10,$row-1);
        $sheet->freezePane('F11')->setAutoFilter('A10:K'.$end);
        foreach(['A'=>20,'B'=>24,'C'=>22,'D'=>23,'E'=>24,'F'=>13,'G'=>13,'H'=>13,'I'=>20,'J'=>20,'K'=>20] as $column=>$width) { $sheet->getColumnDimension($column)->setWidth($width); }
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(10,10)->setPrintArea('A1:K'.$end)->setFitToWidth(1)->setFitToHeight(0);
        $safe=preg_replace('/[^A-Za-z0-9_-]+/','-',(string)($product['primary_sku'] ?: $product['id'])) ?: (string)$product['id'];
        return $this->save($book,'Fisa-produs-'.$safe.'-'.date('Y-m-d').'.xlsx');
    }

    public function cleanup(string $path): void
    {
        $directory=realpath($this->directory()); $resolved=realpath($path);
        if($directory!==false && $resolved!==false && dirname($resolved)===$directory && !is_link($path)) { @unlink($resolved); }
    }

    private function book(string $title): Spreadsheet
    {
        $book=new Spreadsheet(); $book->getProperties()->setCreator('La Măriuca Boutique')->setTitle($title);
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10)->getColor()->setRGB('49383E');
        $sheet=$book->getActiveSheet(); $sheet->setShowGridlines(false); $sheet->getTabColor()->setRGB('855260');
        $sheet->getDefaultRowDimension()->setRowHeight(22); $sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(9);
        $sheet->getHeaderFooter()->setOddFooter('&LLa Măriuca Boutique&RPagina &P / &N'); return $book;
    }

    /** @param array<string,mixed> $company */
    private function brand(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,array $company,string $title,string $subtitle): void
    {
        $sheet->mergeCells('A1:C3')->mergeCells('D1:J2')->mergeCells('D3:J3');
        $sheet->setCellValueExplicit('D1',$title,DataType::TYPE_STRING)->setCellValueExplicit('D3',$subtitle,DataType::TYPE_STRING);
        $sheet->mergeCells('A4:J4')->setCellValueExplicit('A4',trim(($company['legal_name'] ?? '').' · CUI '.($company['tax_id'] ?? '').' · '.($company['registered_address'] ?? '')),DataType::TYPE_STRING);
        $sheet->getStyle('D1:J2')->getFont()->setSize(19)->setBold(true)->getColor()->setRGB('60445C');
        $sheet->getStyle('D3:J3')->getFont()->setSize(9)->getColor()->setRGB('9A7D86');
        $sheet->getStyle('D1:J3')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $logo=$this->root.'/public/assets/images/logo-la-mariuca-transparent.png';
        $resource=is_file($logo)?@imagecreatefrompng($logo):false;
        if($resource!==false) { $drawing=new MemoryDrawing(); $drawing->setName((string)($company['trade_name'] ?? 'La Măriuca Boutique')); $drawing->setImageResource($resource); $drawing->setRenderingFunction(MemoryDrawing::RENDERING_PNG); $drawing->setMimeType(MemoryDrawing::MIMETYPE_PNG); $drawing->setCoordinates('A1')->setHeight(62)->setOffsetX(8)->setOffsetY(4)->setWorksheet($sheet); }
        $sheet->getRowDimension(1)->setRowHeight(28); $sheet->getRowDimension(2)->setRowHeight(24); $sheet->getRowDimension(3)->setRowHeight(20);
    }

    private function header(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,string $range): void
    {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('60445C');
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    private function quantityFormat(float $value): string { return floor($value)===$value?'#,##0':'#,##0.####'; }

    /** @return array{path:string,name:string} */
    private function save(Spreadsheet $book,string $name): array
    {
        $path=$this->directory().'/'.bin2hex(random_bytes(12)).'.xlsx'; $temporary=$path.'.tmp';
        try { (new Xlsx($book))->save($temporary); if(!rename($temporary,$path)) { throw new \RuntimeException('Exportul Excel nu a putut fi pregătit.'); } }
        finally { $book->disconnectWorksheets(); if(is_file($temporary)) { @unlink($temporary); } }
        return ['path'=>$path,'name'=>$name];
    }
    private function directory(): string { $dir=$this->root.'/storage/private/stock-exports'; if(!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) { throw new \RuntimeException('Directorul privat pentru export nu este disponibil.'); } return $dir; }
}
