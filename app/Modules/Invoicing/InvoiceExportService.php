<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

use App\Modules\Companies\CompanyRepository;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class InvoiceExportService
{
    public function __construct(private readonly InvoiceRepository $repository,private readonly string $root) {}
    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    public function pdfData(array $invoice): array
    {
        $snapshot=$invoice['snapshot']; $company=$snapshot['company']; $customer=$snapshot['customer']; $currency=$invoice['currency'];
        $company['iban_display']=trim(chunk_split((string)$company['iban'],4,' '));
        $company['capital_display']=$company['share_capital_bani']===null?'':InvoiceCalculator::money((int)$company['share_capital_bani']);
        $company['website_display']=preg_replace('~^https?://~','',(string)$company['website']);
        $customer['type_label']=$customer['type']==='company'?'Persoană juridică':'Persoană fizică';
        $customer['tax_id']=$customer['company']['tax_id'] ?? $customer['company']['cui'] ?? '';
        if($customer['type']==='company') { $customer['name']=$customer['company']['name'] ?? $customer['company']['company_name'] ?? $customer['name']; }
        $customer['address']=self::address($customer['address']); $lines=[]; $groups=[];
        foreach($invoice['lines'] as $line) {
            $detail=json_decode($line['snapshot_json'],true,512,JSON_THROW_ON_ERROR); $rate=(int)$line['vat_basis_points'];
            $lines[]=['name'=>$line['name'],'sku'=>$line['sku'],'unit'=>$line['unit'],'quantity'=>InvoiceCalculator::quantity((int)$line['quantity_units']),
                'unit_net'=>str_replace('.',',',rtrim(rtrim($line['unit_net'],'0'),'.')).' '.($currency==='RON'?'Lei':$currency),
                'net'=>InvoiceCalculator::money((int)$line['net_bani'],$currency),'total'=>InvoiceCalculator::money((int)$line['gross_bani'],$currency),
                'image_data'=>$detail['image_data'] ?? '', 'vat'=>$this->percent($rate), 'discount_display'=>(int)$line['discount_bani']!==0?InvoiceCalculator::money(abs((int)$line['discount_bani']),$currency):''];
            $groups[$rate] ??= ['net'=>0,'tax'=>0]; $groups[$rate]['net']+=(int)$line['net_bani']; $groups[$rate]['tax']+=(int)$line['tax_bani'];
        }
        $vatGroups=[]; foreach($groups as $rate=>$values) { $vatGroups[]=['rate'=>$this->percent($rate),'net'=>InvoiceCalculator::money($values['net'],$currency),'tax'=>InvoiceCalculator::money($values['tax'],$currency)]; }
        $negative=in_array($invoice['kind'],['return','correction_negative'],true); $paid=$invoice['payment_state']==='paid';
        $refunded=array_sum(array_column($this->repository->all('invoice_refunds',['invoice_id'=>$invoice['id']]),'amount_bani'));
        return ['preview'=>false,'company'=>$company,'customer'=>$customer,'lines'=>$lines,'number'=>$invoice['series_code'].' '.$invoice['number'],
            'document_title'=>['sale'=>'FACTURĂ','return'=>'FACTURĂ RETUR','correction_negative'=>'FACTURĂ STORNO','corrected_positive'=>'FACTURĂ CORECTATĂ'][$invoice['kind']],
            'date'=>(new DateTimeImmutable($invoice['issued_at']))->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('d.m.Y'),'due_date'=>(new DateTimeImmutable($invoice['due_date']))->format('d.m.Y'),
            'order_number'=>$snapshot['order_number'],'original_reference'=>isset($snapshot['original_number'])?'Document original '.$snapshot['original_number']:'',
            'payment_label'=>$negative?'Retur / corecție':($paid?'Achitată':((int)$invoice['paid_bani']>0?'Achitată parțial':'Neachitată')),
            'payment_copy'=>$negative?'Document valoric. Restituirea banilor se urmărește separat.':($paid?'Plată înregistrată.':($invoice['payment_method']==='cod'?'Ramburs, la primirea comenzii.':'Plată online cu cardul.')),
            'payment_method_label'=>$invoice['payment_method']==='cod'?'Ramburs la curier':'Card online','fiscal_accepted'=>$invoice['fiscal_state']==='accepted',
            'notes'=>trim($invoice['notes'].($invoice['reason']!==''?"\nMotiv: ".$invoice['reason']:'')),'vat'=>$this->percent((bool)$company['is_vat_payer']?(int)$company['vat_rate_basis_points']:0),'vat_groups'=>$vatGroups,
            'net'=>InvoiceCalculator::money((int)$invoice['net_bani'],$currency),'tax'=>InvoiceCalculator::money((int)$invoice['tax_bani'],$currency),'total'=>InvoiceCalculator::money((int)$invoice['gross_bani'],$currency),
            'balance_label'=>$negative?'DE RESTITUIT':($paid?'ACHITAT':'DE PLATĂ'),'balance_display'=>InvoiceCalculator::money($negative?max(0,abs((int)$invoice['gross_bani'])-$refunded):($paid?(int)$invoice['paid_bani']:(int)$invoice['gross_bani']-(int)$invoice['paid_bani']),$currency),
            'logo'=>$snapshot['logo_data'],'monogram'=>$this->asset('assets/images/apple-touch-icon-lm.png'),
            'font_regular'=>'file://'.str_replace('\\','/',$this->root.'/public/assets/fonts/nunito-sans-pdf-regular.ttf'),
            'font_bold'=>'file://'.str_replace('\\','/',$this->root.'/public/assets/fonts/nunito-sans-pdf-bold.ttf')];
    }
    /** @param array<string,mixed> $invoice */
    public function pdf(array $invoice): string
    {
        $path=$this->path($invoice,'pdf');
        if(!is_file($path)) {
            $temporary=$path.'.'.bin2hex(random_bytes(5)).'.tmp';
            try { (new InvoicePdfPreview(new CompanyRepository($this->repository->pdo()),new InvoiceConfigurationRepository($this->repository->pdo()),$this->root))->render($temporary,$this->pdfData($invoice)); if(!rename($temporary,$path)) { throw new InvoiceException('Copia PDF nu a putut fi păstrată.'); } }
            finally { if(is_file($temporary)) { unlink($temporary); } }
        }
        return $path;
    }
    /** @param array<string,mixed> $invoice */
    public function xlsx(array $invoice): string
    {
        $path=$this->path($invoice,'xlsx'); if(is_file($path)) { return $path; }
        $data=$this->pdfData($invoice); $book=$this->workbook(); $sheet=$book->getActiveSheet()->setTitle('Factură');
        $sheet->mergeCells('A1:K3'); $sheet->setCellValueExplicit('A1',$data['document_title'].'  '.$data['number'],DataType::TYPE_STRING);
        $sheet->getStyle('A1:K3')->getFont()->setSize(21)->setBold(true)->getColor()->setRGB('60445C');
        $sheet->getStyle('A1:K3')->getAlignment()->setHorizontal('right')->setVertical('center'); $sheet->getRowDimension(2)->setRowHeight(32);
        $this->logo($sheet,$invoice['snapshot']['logo_data']);
        $sheet->mergeCells('A5:F5')->mergeCells('G5:K5'); $this->text($sheet,'A5',$data['company']['trade_name'] ?: $data['company']['legal_name']); $this->text($sheet,'G5',$data['customer']['name']);
        $sheet->mergeCells('A6:F6')->mergeCells('G6:K6'); $this->text($sheet,'A6',$data['company']['legal_name'].' · CUI '.$data['company']['tax_id']); $this->text($sheet,'G6',$data['customer']['type_label'].' · '.$data['customer']['tax_id']);
        $sheet->mergeCells('A7:F8')->mergeCells('G7:K8'); $this->text($sheet,'A7',self::address(['address'=>$data['company']['registered_address'],'city'=>$data['company']['city'],'county'=>$data['company']['county'],'postal_code'=>$data['company']['postal_code'],'country'=>$data['company']['country']])); $this->text($sheet,'G7',$data['customer']['address']);
        $sheet->mergeCells('A9:F9')->mergeCells('G9:K9'); $this->text($sheet,'A9',$data['company']['bank_name'].' · '.$data['company']['iban']); $this->text($sheet,'G9',$data['customer']['email'].' · '.$data['customer']['phone']);
        $sheet->mergeCells('A11:K11'); $this->text($sheet,'A11','Emisă '.$data['date'].'  ·  Scadență '.$data['due_date'].'  ·  '.$data['payment_label'].'  ·  Comanda '.$data['order_number']);
        $headers=['Nr.','SKU','Articol','U.M.','Cantitate','Preț unitar fără TVA','Reducere','TVA','Bază','TVA valoare','Total']; $sheet->fromArray($headers,null,'A13'); $this->header($sheet,'A13:K13');
        $row=14;
        foreach($invoice['lines'] as $index=>$line) {
            $sheet->setCellValue('A'.$row,$index+1); $this->text($sheet,'B'.$row,$line['sku']); $this->text($sheet,'C'.$row,$line['name']); $this->text($sheet,'D'.$row,$line['unit']);
            $quantity=InvoiceCalculator::quantity((int)$line['quantity_units']); $sheet->setCellValue('E'.$row,(float)$quantity); $sheet->getStyle('E'.$row)->getNumberFormat()->setFormatCode(str_contains($quantity,'.')?'0.'.str_repeat('0',strlen(explode('.',$quantity)[1])):'0');
            $sheet->setCellValue('F'.$row,(float)$line['unit_net']); $sheet->setCellValue('G'.$row,(int)$line['discount_bani']/100); $sheet->setCellValue('H'.$row,(int)$line['vat_basis_points']/10000);
            $sheet->setCellValue('I'.$row,(int)$line['net_bani']/100); $sheet->setCellValue('J'.$row,(int)$line['tax_bani']/100); $sheet->setCellValue('K'.$row,'=I'.$row.'+J'.$row);
            $sheet->getStyle('F'.$row.':K'.$row)->getNumberFormat()->setFormatCode('#,##0.00'); $sheet->getStyle('H'.$row)->getNumberFormat()->setFormatCode((int)$line['vat_basis_points']%100===0?'0%':'0.00%');
            $sheet->getStyle('A'.$row.':K'.$row)->getFill()->setFillType('solid')->getStartColor()->setRGB($index%2?'FBF5F8':'FFFFFF'); $sheet->getRowDimension($row)->setRowHeight(34); $row++;
        }
        $sheet->mergeCells('A'.$row.':H'.$row); $this->text($sheet,'A'.$row,'TOTAL · '.$invoice['currency']);
        foreach(['I','J','K'] as $column) { $sheet->setCellValue($column.$row,'=SUM('.$column.'14:'.$column.($row-1).')'); }
        $this->header($sheet,'A'.$row.':K'.$row); $sheet->getStyle('I'.$row.':K'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->mergeCells('A'.($row+2).':K'.($row+3)); $this->text($sheet,'A'.($row+2),$data['notes']);
        $sheet->mergeCells('A'.($row+5).':K'.($row+5)); $this->text($sheet,'A'.($row+5),'Factura ține loc de document justificativ pentru garanție. '.$data['company']['email']);
        foreach(['A'=>6,'B'=>20,'C'=>42,'D'=>8,'E'=>11,'F'=>18,'G'=>13,'H'=>10,'I'=>15,'J'=>15,'K'=>16] as $column=>$width) { $sheet->getColumnDimension($column)->setWidth($width); }
        $sheet->getStyle('A1:K'.($row+5))->getAlignment()->setVertical('center')->setWrapText(true); $sheet->freezePane('E14'); $sheet->setAutoFilter('A13:K'.($row-1));
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(13,13)->setPrintArea('A1:K'.($row+5));
        $temporary=$path.'.'.bin2hex(random_bytes(5)).'.tmp';
        try { (new Xlsx($book))->save($temporary); if(!rename($temporary,$path)) { throw new InvoiceException('Copia Excel nu a putut fi păstrată.'); } }
        finally { $book->disconnectWorksheets(); if(is_file($temporary)) { unlink($temporary); } }
        return $path;
    }
    /** @param list<array<string,mixed>> $invoices */
    public function registry(array $invoices): string
    {
        $book=$this->workbook(); $sheet=$book->getActiveSheet()->setTitle('Registru facturi'); $sheet->mergeCells('A1:H3'); $sheet->setCellValue('A1','La Măriuca · Registru facturi'); $sheet->getStyle('A1:H3')->getFont()->setSize(22)->setBold(true)->getColor()->setRGB('60445C');
        $headers=['Factură','Tip','Emisă la','Scadență','Comandă','Client','Tip client','CUI client','Email','Telefon','Adresă facturare','Monedă','Fără TVA','TVA','Total','Reducere','Încasat','Rest de plată','Metodă plată','Stare plată','Email','Stare fiscală','Referință fiscală','Document original','Motiv','Note','Articole','Cantități','SKU','Furnizor legal','CUI emitent','Sediu emitent','Bancă','IBAN','TVA emitent','Creat de','Versiune','Primire retur'];
        $sheet->fromArray($headers,null,'A5'); $this->header($sheet,'A5:AL5'); $row=6;
        foreach($invoices as $invoice) {
            $data=$this->pdfData($invoice); $snapshot=$invoice['snapshot'];
            $values=[$data['number'],InvoiceService::KINDS[$invoice['kind']],$data['date'],$data['due_date'],$snapshot['order_number'],$data['customer']['name'],$data['customer']['type_label'],$data['customer']['tax_id'],$data['customer']['email'],$data['customer']['phone'],$data['customer']['address'],$invoice['currency'],
                $invoice['net_bani']/100,$invoice['tax_bani']/100,$invoice['gross_bani']/100,$invoice['discount_bani']/100,$invoice['paid_bani']/100,max(0,$invoice['gross_bani']-$invoice['paid_bani'])/100,$data['payment_method_label'],$data['payment_label'],$invoice['email_state'],$invoice['fiscal_state'],$invoice['fiscal_reference'] ?? '',$snapshot['original_number'] ?? '',$invoice['reason'],$invoice['notes'],implode('; ',array_column($invoice['lines'],'name')),implode('; ',array_column($data['lines'],'quantity')),implode('; ',array_column($invoice['lines'],'sku')),$data['company']['legal_name'],$data['company']['tax_id'],self::address($snapshot['company']),$data['company']['bank_name'],$data['company']['iban'],$data['company']['is_vat_payer']?'Da':'Nu',(string)$invoice['created_by'],(string)$invoice['row_version'],$this->repository->one('invoice_receipts',['invoice_id'=>$invoice['id']])?'Înregistrată':''];
            foreach($values as $column=>$value) { $cell=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column+1).$row; if($column>=12 && $column<=17) { $sheet->setCellValue($cell,$value); $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00'); } else { $this->text($sheet,$cell,(string)$value); } }
            $sheet->getStyle('A'.$row.':AL'.$row)->getFill()->setFillType('solid')->getStartColor()->setRGB($row%2?'FBF5F8':'FFFFFF'); $row++;
        }
        foreach(range(1,38) as $c) { $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setWidth(in_array($c,[6,9,11,25,26,27],true)?35:18); }
        $sheet->freezePane('G6')->setAutoFilter('A5:AL'.max(5,$row-1)); $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(5,5)->setFitToWidth(4);
        $path=$this->temporary('Registru-facturi','xlsx'); (new Xlsx($book))->save($path); $book->disconnectWorksheets(); return $path;
    }
    /** @param list<array<string,mixed>> $invoices */
    public function zip(array $invoices,bool $registry=true): string
    {
        if(!class_exists(\ZipArchive::class)) { throw new InvoiceException('Extensia PHP ZIP trebuie activată pe server.'); }
        $path=$this->temporary('Facturi','zip'); $zip=new \ZipArchive(); if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true) { throw new InvoiceException('Dosarul ZIP nu poate fi creat.'); }
        $registryPath=null;
        try {
            if($registry) { $registryPath=$this->registry($invoices); $zip->addFile($registryPath,'Registru-facturi.xlsx'); }
            foreach($invoices as $invoice) { $name=$this->filename($invoice); $prefix=$registry?$name.'/':''; $zip->addFile($this->pdf($invoice),$prefix.$name.'.pdf'); $zip->addFile($this->xlsx($invoice),$prefix.$name.'.xlsx'); }
            if(!$zip->close()) { throw new InvoiceException('Dosarul ZIP nu a putut fi finalizat.'); }
        } finally { if($registryPath && is_file($registryPath)) { unlink($registryPath); } }
        return $path;
    }
    /** @param array<string,mixed> $invoice */
    public function filename(array $invoice): string { return 'Factura-'.(preg_replace('/[^a-zA-Z0-9_-]/','-',$invoice['series_code']) ?: 'LM').'-'.(int)$invoice['number']; }
    /** @param array<string,mixed> $address */
    public static function address(array $address): string
    {
        $parts=[]; foreach(['address','registered_address','street','address_line1','postal_code','city','county','country'] as $key) { if(!empty($address[$key]) && is_scalar($address[$key])) { $parts[]=(string)$address[$key]; } } return implode(', ',array_unique($parts));
    }
    private function percent(int $rate): string { return rtrim(rtrim(number_format($rate/100,2,',',''),'0'),','); }
    /** @param array<string,mixed> $invoice */
    private function path(array $invoice,string $extension): string
    {
        $dir=$this->directory().'/'.$invoice['id']; if(!is_dir($dir)) { mkdir($dir,0770,true); }
        $refunds=$this->repository->all('invoice_refunds',['invoice_id'=>$invoice['id']]);
        $hash=substr(hash('sha256',InvoiceSnapshot::json([InvoiceService::documentHash($invoice),$invoice['paid_bani'],$invoice['payment_state'],$invoice['fiscal_state'],$refunds,'layout-20261113-v3'])),0,16);
        return $dir.'/'.$this->filename($invoice).'-'.$hash.'.'.$extension;
    }
    private function directory(): string { $dir=$this->root.'/storage/private/invoices'; if(!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) { throw new InvoiceException('Directorul privat de facturi nu este disponibil.'); } return $dir; }
    private function temporary(string $name,string $extension): string { return $this->directory().'/'.$name.'-'.bin2hex(random_bytes(8)).'.'.$extension; }
    private function asset(string $path): string { return 'data:image/png;base64,'.base64_encode((string)file_get_contents($this->root.'/public/'.$path)); }
    private function workbook(): Spreadsheet
    {
        $book=new Spreadsheet(); $book->getProperties()->setCreator('La Măriuca Boutique')->setTitle('Facturare La Măriuca'); $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11)->getColor()->setRGB('453640');
        $sheet=$book->getActiveSheet(); $sheet->setShowGridlines(false); $sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(9)->setFitToWidth(1)->setFitToHeight(0); $sheet->getHeaderFooter()->setOddFooter('&LLa Măriuca Boutique&RPagina &P / &N'); return $book;
    }
    private function text(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,string $cell,string $text): void { $sheet->setCellValueExplicit($cell,$text,DataType::TYPE_STRING); }
    private function header(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,string $range): void { $sheet->getStyle($range)->getFill()->setFillType('solid')->getStartColor()->setRGB('60445C'); $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF'); $sheet->getStyle($range)->getAlignment()->setVertical('center')->setWrapText(true); }
    private function logo(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,string $data): void
    {
        $encoded=explode(',',$data,2)[1] ?? ''; $binary=base64_decode($encoded,true); $resource=$binary!==false?@imagecreatefromstring($binary):false;
        if(!$resource) { return; }
        $image=new MemoryDrawing(); $image->setName('La Măriuca Boutique'); $image->setImageResource($resource); $image->setRenderingFunction(MemoryDrawing::RENDERING_PNG)->setMimeType(MemoryDrawing::MIMETYPE_PNG);
        $image->setCoordinates('A1')->setHeight(65)->setOffsetX(10)->setOffsetY(4)->setWorksheet($sheet);
    }
}
