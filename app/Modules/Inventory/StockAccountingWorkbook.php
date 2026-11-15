<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/** The six-sheet accounting report. All text is explicit text, never an Excel formula. */
final class StockAccountingWorkbook
{
    public function __construct(private readonly string $root) {}

    /** @param array<string,mixed> $report @param array<string,mixed> $company */
    public function build(array $report,array $company): Spreadsheet
    {
        $book=new Spreadsheet(); $book->removeSheetByIndex(0);
        $book->getProperties()->setCreator('La Măriuca Boutique')->setTitle('Centralizator stocuri și fișe de magazie');
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(11)->getColor()->setRGB('49394A');
        $period=($report['filters']['from'] ?: 'Începutul evidenței').' — '.($report['filters']['to'] ?: 'Prezent');
        $summary=$this->sheet($book,'Rezumat','Centralizator stocuri și fișe de magazie',$period,10);
        $stock=$this->sheet($book,'Stoc pe produse','Situația stocului pe produse',$period,16);
        $cards=$this->sheet($book,'Fișe magazie','Fișe de magazie pe produse',$period,16);
        $journal=$this->sheet($book,'Mișcări stoc','Registrul cronologic al mișcărilor',$period,28);
        $sources=$this->sheet($book,'Documente sursă','Centralizator documente-sursă',$period,16);
        $method=$this->sheet($book,'Metodologie','Cum se citește acest registru',$period,10);
        $this->text($summary,'A6','ENTITATE ȘI EXPORT'); $summary->getStyle('A6')->getFont()->setBold(true)->getColor()->setRGB('A94D70');
        $summary->mergeCells('B7:E7')->mergeCells('G7:J7')->mergeCells('B8:E9')->mergeCells('G8:J9');
        $this->row($summary,7,['Entitate',$company['legal_name'] ?? '',null,null,null,'CUI / Reg. com.',trim(($company['tax_id'] ?? '').' / '.($company['trade_register'] ?? $company['registration_number'] ?? ''))]);
        $this->text($summary,'A8','Adresă'); $this->text($summary,'B8',trim(($company['registered_address'] ?? '').', '.($company['city'] ?? '')));
        $this->text($summary,'F8','Generat la'); $this->text($summary,'G8',(new \DateTimeImmutable($report['generated_at']))->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i'));
        $logo=$this->root.'/public/assets/images/logo-la-mariuca-transparent.png';
        if(is_file($logo)) {
            $summary->unmergeCells('A1:J2')->mergeCells('D1:J2'); $summary->getCell('A1')->setValue(null);
            $this->text($summary,'D1','Centralizator stocuri');
            $drawing=new Drawing(); $drawing->setPath($logo)->setCoordinates('A1')->setHeight(57)->setOffsetX(10)->setOffsetY(4)->setWorksheet($summary);
        }
        $this->head($summary,12,['SKU','Produs','Mișcări','Intrări','Ieșiri','Stoc final','Valoare la cost, Lei','Fără cost, buc.','Control','Observații']);
        $this->head($stock,6,['Nr. crt.','Cod produs','Denumire produs','U.M.','Stoc inițial','Intrări','Ieșiri','Stoc final','Stoc online actual','Stoc contabil actual','Valoare inițială la cost','Valoare intrări','Valoare ieșiri','Valoare finală la cost','Nr. mișcări','Control / observații']);
        $this->head($journal,6,['Nr. crt.','Direcție','Data / ora','Tip mișcare','Gestiune','Document','Partener','Comandă','Serie factură intrare','Nr. factură intrare','Data factură intrare','Serie factură ieșire','Nr. factură ieșire','Data factură ieșire','Cod produs','Denumire produs','U.M.','Cantitate intrare','Cantitate ieșire','Stoc după','Cost unitar de achiziție','Valoare stoc','Preț vânzare','Valoare vânzare','Stare asociere cost','Cantitate fără cost','Operator','Observații']);
        $this->head($sources,6,['Nr. crt.','Direcție','Tip document','Număr document','Data','Partener','Gestiune','Serie factură intrare','Nr. factură intrare','Serie factură ieșire','Nr. factură ieșire','Poziții','Cantitate totală','Valoare stoc','Valoare vânzare','Operator']);
        $all=[]; $documents=[]; $cardRow=6;
        foreach($report['details'] as $index=>$detail) {
            $p=$detail['product']; $pending=(int)$detail['pending_cost_units'];
            $note=$pending>0?'Cost de achiziție neasociat: '.$this->qty($pending).' buc. Valoarea afișată include numai costurile cunoscute.':'';
            $control=$detail['consistent']?'Concordant':'De verificat';
            $this->row($summary,13+$index,[$p['primary_sku'],$p['name'],count($detail['movements']),$this->qty($detail['incoming_units']),$this->qty($detail['outgoing_units']),$this->qty($detail['closing_units']),$detail['closing_value_bani']/100,$this->qty($pending),$control,$note],[7]);
            $this->row($stock,7+$index,[$index+1,$p['primary_sku'],$p['name'],'buc',$this->qty($detail['opening_units']),$this->qty($detail['incoming_units']),$this->qty($detail['outgoing_units']),$this->qty($detail['closing_units']),$detail['unlimited']?'La furnizor':(int)$detail['online'],$this->qty($detail['current_units']),$detail['opening_value_bani']/100,$detail['incoming_value_bani']/100,$detail['outgoing_value_bani']/100,$detail['closing_value_bani']/100,count($detail['movements']),$note ?: $control],[11,12,13,14]);
            $cards->mergeCells('A'.$cardRow.':L'.$cardRow)->mergeCells('M'.$cardRow.':P'.$cardRow);
            $this->text($cards,'A'.$cardRow,'FIȘĂ PRODUS · '.$p['primary_sku'].' · '.$p['name']);
            $this->text($cards,'M'.$cardRow,'Stoc inițial: '.$this->qty($detail['opening_units']).' buc.');
            $cards->getStyle('A'.$cardRow.':P'.$cardRow)->getFill()->setFillType('solid')->getStartColor()->setRGB('F5EAF1');
            $cards->getStyle('A'.$cardRow.':P'.$cardRow)->getFont()->setBold(true); $cards->getRowDimension($cardRow)->setRowHeight(38);
            $this->head($cards,++$cardRow,['Nr. crt.','Data / ora','Tip mișcare','Document','Partener','Serie factură intrare','Nr. factură intrare','Serie factură ieșire','Nr. factură ieșire','Intrare','Ieșire','Stoc după','Cost unitar de achiziție','Valoare stoc','Operator','Observații']);
            foreach($detail['movements'] as $mi=>$m) {
                $m['product_name']=$p['name']; $all[]=$m; $q=abs((int)$m['quantity_units']); $sign=$m['quantity_units']<0?-1:1;
                $known=max(0,$q-(int)$m['pending_cost_units']);
                $unit=$m['cost_bani']!==null && $known>0?$m['cost_bani']*100/$known:null;
                $pendingNote=$m['pending_cost_units']>0?'Cost neasociat pentru '.$this->qty($m['pending_cost_units']).' buc.':'';
                $this->row($cards,++$cardRow,[$mi+1,$this->excelDate($m['created_at']),$m['label'],$m['document'],$m['partner'],$m['invoice_series'] ?? '',$m['invoice_number'] ?? '',$m['series_code'] ?? '',isset($m['invoice_number_out'])?(string)$m['invoice_number_out']:'',$m['quantity_units']>0?$this->qty($q):0,$m['quantity_units']<0?$this->qty($q):0,$this->qty($m['balance_units']),$unit,$m['cost_bani']===null?null:$sign*$m['cost_bani']/100,$m['operator'],$pendingNote],[13,14],[2]);
                $key=(!empty($m['nir_id'])?'nir:'.$m['nir_id']:'invoice:'.$m['invoice_id']).':'.$m['warehouse_id'];
                if(!isset($documents[$key])) { $documents[$key]=['movement'=>$m,'positions'=>0,'quantity'=>0,'cost'=>0,'sale'=>0,'known'=>false]; }
                $documents[$key]['positions']++; $documents[$key]['quantity']+=$q; $documents[$key]['cost']+=$sign*($m['cost_bani'] ?? 0); $documents[$key]['sale']+=$m['sale_value_bani'] ?? 0; $documents[$key]['known']=$documents[$key]['known'] || $m['cost_bani']!==null;
            }
            ++$cardRow; $cards->mergeCells('A'.$cardRow.':I'.$cardRow);
            $this->text($cards,'A'.$cardRow,'TOTAL FIȘĂ');
            $this->row($cards,$cardRow,[null,null,null,null,null,null,null,null,null,$this->qty($detail['incoming_units']),$this->qty($detail['outgoing_units']),$this->qty($detail['closing_units']),null,$detail['closing_value_bani']/100],[14]);
            $cards->getStyle('A'.$cardRow.':P'.$cardRow)->getFont()->setBold(true); $cardRow+=2;
        }
        usort($all,static fn(array $a,array $b):int=>[strtotime($a['created_at']),(int)$a['id']]<=>[strtotime($b['created_at']),(int)$b['id']]);
        foreach($all as $index=>$m) {
            $q=abs((int)$m['quantity_units']); $known=max(0,$q-(int)$m['pending_cost_units']); $sign=$m['quantity_units']<0?-1:1;
            $this->row($journal,7+$index,[$index+1,$sign>0?'Intrare':'Ieșire',$this->excelDate($m['created_at']),$m['label'],$m['warehouse'],$m['document'],$m['partner'],$m['order_number'] ?? '',$m['invoice_series'] ?? '',$m['invoice_number'] ?? '',$this->excelDate($m['invoice_date'] ?? ''),$m['series_code'] ?? '',isset($m['invoice_number_out'])?(string)$m['invoice_number_out']:'',$this->excelDate($m['invoice_date_out'] ?? ''),$m['sku'],$m['product_name'],$m['unit'],$sign>0?$this->qty($q):0,$sign<0?$this->qty($q):0,$this->qty($m['balance_units']),$known>0 && $m['cost_bani']!==null?$m['cost_bani']*100/$known:null,$m['cost_bani']===null?null:$sign*$m['cost_bani']/100,$q && $m['sale_value_bani']!==null?$m['sale_value_bani']*100/$q:null,$m['sale_value_bani']===null?null:$m['sale_value_bani']/100,$m['pending_cost_units']>0?'În așteptare':'Cost asociat',$this->qty($m['pending_cost_units']),$m['operator'],$m['pending_cost_units']>0?'Costul se completează la confirmarea achiziției.':''],[21,22,23,24],[3,11,14]);
        }
        $docRow=7;
        foreach($documents as $document) {
            $m=$document['movement'];
            $this->row($sources,$docRow,[$docRow-6,$m['quantity_units']<0?'Ieșire':'Intrare',$m['label'],$m['document'],$this->excelDate($m['created_at']),$m['partner'],$m['warehouse'],$m['invoice_series'] ?? '',$m['invoice_number'] ?? '',$m['series_code'] ?? '',isset($m['invoice_number_out'])?(string)$m['invoice_number_out']:'',$document['positions'],$this->qty($document['quantity']),$document['known']?$document['cost']/100:null,$document['sale']/100,$m['operator']],[14,15],[5]); $docRow++;
        }
        $methodRows=[
            ['Scop','Centralizator al stocului contabil, documentelor justificative și mișcărilor din perioada aleasă.'],
            ['Baza datelor','Sunt incluse numai mișcările documentate prin NIR-uri confirmate și facturi de ieșire active. Ajustările interne și documentele șterse nu intră în acest export.'],
            ['Sold inițial','Cantitatea documentată imediat înaintea primei zile din interval. Sold final = sold inițial + intrări − ieșiri.'],
            ['Valori la cost','Valorile sunt reconstruite din costurile de achiziție asociate. Costurile necunoscute sunt marcate separat; o valoare incompletă nu reprezintă cost zero.'],
            ['Vânzări înainte de achiziție','Stocul contabil poate fi negativ. Costul se asociază ulterior, la confirmarea NIR-ului; un nou export reflectă costurile cunoscute la data generării.'],
            ['Online / contabil','Disponibilitatea online este situația curentă a magazinului. Nu reprezintă soldul contabil și nu este modificată de generarea acestui registru.'],
            ['Perioadă','Datele și orele sunt afișate în Europe/Bucharest. Prima și ultima zi sunt incluse integral.'],
            ['Cantități','Valorile sunt numere Excel; întregii se afișează fără zecimale sau punct final. SKU-urile și numerele documentelor sunt text.'],
            ['Control','Diferențele între jurnal și soldul contabil curent sunt semnalate pentru verificare. Exportul nu efectuează ajustări.'],
            ['Întocmit de','Nume și semnătură: __________________________________'],['Verificat de','Nume și semnătură: __________________________________']
        ];
        foreach($methodRows as $index=>$values) {
            $r=6+$index*2; $method->mergeCells('A'.$r.':B'.($r+1))->mergeCells('C'.$r.':J'.($r+1)); $this->text($method,'A'.$r,$values[0]); $this->text($method,'C'.$r,$values[1]); $method->getStyle('A'.$r.':B'.($r+1))->getFont()->setBold(true); $method->getStyle('A'.$r.':J'.($r+1))->getFill()->setFillType('solid')->getStartColor()->setRGB($index%2?'FFFFFF':'FAF4F7');
        }
        $this->finish($summary,12,10,12+count($report['details']));
        $this->finish($stock,6,16,6+count($report['details']));
        $this->finish($journal,6,28,6+count($all));
        $this->finish($sources,6,16,$docRow-1);
        $cards->freezePane('D6'); $cards->getPageSetup()->setPrintArea('A1:P'.max(6,$cardRow-1));
        foreach([$stock,$cards,$sources] as $s) { $s->getColumnDimension('A')->setWidth(9); $s->getColumnDimension('C')->setWidth(40); }
        $summary->getColumnDimension('B')->setWidth(38); $summary->getColumnDimension('J')->setWidth(48);
        $journal->getColumnDimension('P')->setWidth(42); $journal->getColumnDimension('AB')->setWidth(48);
        $book->setActiveSheetIndex(0); return $book;
    }

    private function sheet(Spreadsheet $book,string $name,string $title,string $period,int $columns): Worksheet
    {
        $s=$book->createSheet()->setTitle($name); $end=Coordinate::stringFromColumnIndex($columns);
        $s->setShowGridlines(false); $s->getTabColor()->setRGB('885578');
        $s->mergeCells('A1:'.$end.'2')->mergeCells('A3:'.$end.'3')->mergeCells('A4:'.$end.'4');
        $this->text($s,'A1','La Măriuca Boutique · '.$title); $this->text($s,'A3',$period); $this->text($s,'A4','Evidență contabilă · Documente confirmate · Valori în Lei');
        $s->getStyle('A1:'.$end.'2')->getFont()->setSize(20)->setBold(true)->getColor()->setRGB('60445C');
        $s->getStyle('A1:'.$end.'4')->getAlignment()->setVertical('center')->setWrapText(true);
        $s->getStyle('A3:'.$end.'4')->getFont()->setSize(10)->getColor()->setRGB('947587');
        $s->getDefaultRowDimension()->setRowHeight(27);
        for($c=1;$c<=$columns;$c++) { $s->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(21); }
        $s->getPageSetup()->setOrientation('landscape')->setPaperSize(8)->setFitToWidth(1)->setFitToHeight(0);
        $s->getHeaderFooter()->setOddFooter('&LLa Măriuca Boutique · '.$name.'&RPagina &P / &N'); return $s;
    }
    /** @param list<string> $values */
    private function head(Worksheet $sheet,int $row,array $values): void
    {
        $this->row($sheet,$row,$values); $range='A'.$row.':'.Coordinate::stringFromColumnIndex(count($values)).$row;
        $sheet->getStyle($range)->getFill()->setFillType('solid')->getStartColor()->setRGB('60445C');
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setVertical('center'); $sheet->getRowDimension($row)->setRowHeight(46);
    }
    /** @param list<mixed> $values @param list<int> $money @param list<int> $dates */
    private function row(Worksheet $sheet,int $row,array $values,array $money=[],array $dates=[]): void
    {
        foreach($values as $i=>$value) {
            if($value===null) { continue; } $cell=$sheet->getCell([$i+1,$row]);
            if(is_int($value)||is_float($value)) {
                $cell->setValueExplicit($value,DataType::TYPE_NUMERIC);
                $cell->getStyle()->getNumberFormat()->setFormatCode(in_array($i+1,$dates,true)?'dd.mm.yyyy hh:mm':(in_array($i+1,$money,true)?'#,##0.00':(floor((float)$value)===(float)$value?'#,##0':'#,##0.####')));
            } else { $cell->setValueExplicit((string)$value,DataType::TYPE_STRING); }
        }
        $range='A'.$row.':'.Coordinate::stringFromColumnIndex(count($values)).$row;
        $sheet->getStyle($range)->getAlignment()->setVertical('center')->setWrapText(true);
        $sheet->getStyle($range)->getFill()->setFillType('solid')->getStartColor()->setRGB($row%2?'FFFFFF':'FBF5F8');
        $sheet->getRowDimension($row)->setRowHeight(42);
    }
    private function finish(Worksheet $s,int $header,int $columns,int $last): void
    {
        $end=Coordinate::stringFromColumnIndex($columns); $last=max($header,$last);
        $s->setAutoFilter('A'.$header.':'.$end.$last)->freezePane('D'.($header+1));
        $s->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($header,$header)->setPrintArea('A1:'.$end.$last);
    }
    private function text(Worksheet $s,string $cell,string $value): void { $s->setCellValueExplicit($cell,$value,DataType::TYPE_STRING); }
    private function qty(int $units): float { return $units/10000; }
    private function excelDate(string $value): ?float { return $value===''?null:Date::PHPToExcel((new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('Europe/Bucharest'))); }
}
