<?php
declare(strict_types=1);
namespace App\Modules\Inventory;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Conditional;

/** The six-sheet accounting report. All text is explicit text, never an Excel formula. */
final class StockAccountingWorkbook
{
    public function __construct(private readonly string $root) {}

    /** @param array<string,mixed> $report @param array<string,mixed> $company */
    public function build(array $report,array $company): Spreadsheet
    {
        $book=new Spreadsheet(); $book->removeSheetByIndex(0);
        $book->getProperties()->setCreator('La Măriuca Boutique')->setTitle('Centralizator stocuri și fișe de magazie');
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10)->getColor()->setRGB('49394A');
        $period=$this->periodLabel($report['filters']);
        $generated=(new \DateTimeImmutable($report['generated_at']))->setTimezone(new \DateTimeZone('Europe/Bucharest'));
        $exportId='LM-STOC-'.$generated->format('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(4)));
        $book->getProperties()->setDescription($period.' · '.$exportId)->setCustomProperty('Identificator export',$exportId);
        $summary=$this->sheet($book,'Rezumat','Centralizator stocuri și fișe de magazie',$period,10);
        $stock=$this->sheet($book,'Stoc pe produse','Situația stocului pe produse',$period,15);
        $cards=$this->sheet($book,'Fișe magazie','Fișe de magazie pe produse',$period,16);
        $journal=$this->sheet($book,'Mișcări stoc','Registrul cronologic al mișcărilor',$period,28);
        $sources=$this->sheet($book,'Documente sursă','Centralizator documente-sursă',$period,16);
        $method=$this->sheet($book,'Metodologie','Metodologie, trasabilitate și semnături',$period,10);
        $this->section($summary,6,'IDENTIFICARE ENTITATE ȘI EXPORT',10);
        $summary->mergeCells('B7:E7')->mergeCells('G7:J7')->mergeCells('B8:E8')->mergeCells('G8:J8');
        $this->row($summary,7,['Entitate',$company['legal_name'] ?? '',null,null,null,'CUI / Reg. com.',trim(($company['tax_id'] ?? '').' / '.($company['trade_register'] ?? $company['registration_number'] ?? ''))]);
        $this->text($summary,'A8','Adresă'); $this->text($summary,'B8',trim(($company['registered_address'] ?? '').', '.($company['city'] ?? '')));
        $this->text($summary,'F8','Generat la');
        $summary->setCellValue('G8',Date::PHPToExcel($generated))->getStyle('G8')->getNumberFormat()->setFormatCode('dd.mm.yyyy hh:mm');
        $summary->getStyle('A7:J8')->getAlignment()->setWrapText(true)->setVertical('center');
        $summary->getRowDimension(8)->setRowHeight(38);
        $this->text($summary,'A4',$exportId);
        $logo=$this->root.'/public/assets/images/logo-la-mariuca-transparent.png';
        if(is_file($logo)) {
            $summary->unmergeCells('A1:J2')->mergeCells('C1:J2'); $summary->getCell('A1')->setValue(null);
            $this->text($summary,'C1','La Măriuca Boutique · Centralizator stocuri');
            $summary->unmergeCells('A3:J3')->mergeCells('C3:J3'); $summary->getCell('A3')->setValue(null); $this->text($summary,'C3',$period);
            $drawing=new Drawing(); $drawing->setName('La Măriuca Boutique'); $drawing->setPath($logo)->setCoordinates('A1')->setHeight(66)->setOffsetX(10)->setOffsetY(4)->setWorksheet($summary);
        }
        $this->head($stock,6,['Nr. crt.','Cod produs','Denumire produs','U.M.','Stoc inițial','Intrări','Ieșiri','Stoc final','Stoc online actual','Valoare inițială la cost','Valoare intrări','Valoare ieșiri','Valoare finală la cost','Nr. mișcări','Control / observații']);
        $this->head($journal,6,['Nr. crt.','Direcție','Data mișcării','Tip mișcare','Gestiune','Document','Partener','Comandă','Serie factură intrare','Nr. factură intrare','Data factură intrare','Serie factură ieșire','Nr. factură ieșire','Data factură ieșire','Cod produs','Denumire produs','U.M.','Cantitate intrare','Cantitate ieșire','Stoc după','Cost unitar de achiziție','Valoare stoc','Preț vânzare','Valoare vânzare','Stare asociere cost','Cantitate fără cost asociat','Operator','Observații']);
        $this->head($sources,6,['Nr. crt.','Direcție','Tip document','Număr document','Data','Partener','Gestiune','Serie factură intrare','Nr. factură intrare','Serie factură ieșire','Nr. factură ieșire','Poziții','Cantitate totală','Valoare stoc','Valoare vânzare','Operator']);
        $all=[]; $documents=[]; $cardRow=6;
        foreach($report['details'] as $index=>$detail) {
            $p=$detail['product']; $pending=(int)$detail['pending_cost_units'];
            $note=$pending>0?'Cost de achiziție neasociat: '.$this->qty($pending).' buc. Valoarea afișată include numai costurile cunoscute.':'';
            $control=$detail['consistent']?'Concordant':'De verificat';
            $online=($detail['online_mode'] ?? '')==='mixed'?$detail['online'].' + nelimitat':($detail['unlimited']?'Nelimitat':(int)$detail['online']);
            $this->row($stock,7+$index,[$index+1,$p['primary_sku'],$p['name'],'buc.',$this->qty($detail['opening_units']),$this->qty($detail['incoming_units']),$this->qty($detail['outgoing_units']),$this->qty($detail['closing_units']),$online,$detail['opening_value_bani']/100,$detail['incoming_value_bani']/100,$detail['outgoing_value_bani']/100,$detail['closing_value_bani']/100,count($detail['movements']),$note ?: $control],[10,11,12,13]);
            if($pending>0) { $this->tone($stock,'O'.(7+$index),'FFF4E3','885922'); }
            $cards->mergeCells('A'.$cardRow.':L'.$cardRow)->mergeCells('M'.$cardRow.':P'.$cardRow);
            $this->text($cards,'A'.$cardRow,'FIȘĂ PRODUS · '.$p['primary_sku'].' · '.$p['name']);
            $this->text($cards,'M'.$cardRow,'Stoc inițial: '.$this->qty($detail['opening_units']).' buc.');
            $cards->getStyle('A'.$cardRow.':P'.$cardRow)->getFill()->setFillType('solid')->getStartColor()->setRGB('F5EAF1');
            $cards->getStyle('A'.$cardRow.':P'.$cardRow)->getAlignment()->setVertical('center')->setIndent(1);
            $cards->getStyle('A'.$cardRow.':P'.$cardRow)->getFont()->setBold(true); $cards->getRowDimension($cardRow)->setRowHeight(38);
            $this->head($cards,++$cardRow,['Nr. crt.','Data mișcării','Tip mișcare','Document','Partener','Serie factură intrare','Nr. factură intrare','Serie factură ieșire','Nr. factură ieșire','Intrare','Ieșire','Stoc după','Cost unitar de achiziție','Valoare stoc','Operator','Observații']);
            foreach($detail['movements'] as $mi=>$m) {
                $m['product_name']=$p['name']; $all[]=$m; $q=abs((int)$m['quantity_units']);
                $known=max(0,$q-(int)$m['pending_cost_units']);
                $unit=$m['cost_bani']!==null && $known>0?$m['cost_bani']*100/$known:null;
                $this->row($cards,++$cardRow,[$mi+1,$this->excelDate($m['movement_at']),$m['label'],$m['document'],$m['partner'],$m['invoice_series'] ?? '',$m['invoice_number'] ?? '',$m['series_code'] ?? '',isset($m['invoice_number_out'])?(string)$m['invoice_number_out']:'',$m['quantity_units']>0?$this->qty($q):0,$m['quantity_units']<0?$this->qty($q):0,$this->qty($m['balance_units']),$unit,$m['cost_bani']===null?null:abs($m['cost_bani'])/100,$m['operator'],$this->movementNote($m)],[13,14],[2]);
                $this->tone($cards,'J'.$cardRow,'EDF7F2','24775D'); $this->tone($cards,'K'.$cardRow,'FCEDF2','A43F61');
                $cards->getStyle('M'.$cardRow)->getNumberFormat()->setFormatCode('#,##0.00##');
                $key=(!empty($m['nir_id'])?'nir:'.$m['nir_id']:'invoice:'.$m['invoice_id']).':'.$m['warehouse_id'];
                if(!isset($documents[$key])) { $documents[$key]=['movement'=>$m,'positions'=>0,'quantity'=>0,'cost'=>0,'sale'=>0,'known'=>false]; }
                $documents[$key]['positions']++; $documents[$key]['quantity']+=$q; $documents[$key]['cost']+=abs($m['cost_bani'] ?? 0); $documents[$key]['sale']+=$m['sale_value_bani'] ?? 0; $documents[$key]['known']=$documents[$key]['known'] || $m['cost_bani']!==null;
            }
            if($detail['movements']===[]) { $this->emptyRow($cards,++$cardRow,16,'Fără mișcări în perioada selectată.'); }
            ++$cardRow; $cards->mergeCells('A'.$cardRow.':I'.$cardRow);
            $this->text($cards,'A'.$cardRow,'TOTAL FIȘĂ');
            $this->row($cards,$cardRow,[null,null,null,null,null,null,null,null,null,$this->qty($detail['incoming_units']),$this->qty($detail['outgoing_units']),$this->qty($detail['closing_units']),null,$detail['closing_value_bani']/100],[14]);
            $this->tone($cards,'A'.$cardRow.':P'.$cardRow,'F2E8EF','60445C');
            $cards->getStyle('A'.$cardRow.':P'.$cardRow)->getFont()->setBold(true); $cardRow+=2;
        }
        usort($all,static fn(array $a,array $b):int=>[strtotime($a['movement_at']),strtotime($a['created_at']),(int)$a['id']]<=>[strtotime($b['movement_at']),strtotime($b['created_at']),(int)$b['id']]);
        foreach($all as $index=>$m) {
            $q=abs((int)$m['quantity_units']); $known=max(0,$q-(int)$m['pending_cost_units']); $sign=$m['quantity_units']<0?-1:1;
            $this->row($journal,7+$index,[$index+1,$sign>0?'Intrare':'Ieșire',$this->excelDate($m['movement_at']),$m['label'],$m['warehouse'],$m['document'],$m['partner'],$m['order_number'] ?? '',$m['invoice_series'] ?? '',$m['invoice_number'] ?? '',$this->excelDate($m['invoice_date'] ?? ''),$m['series_code'] ?? '',isset($m['invoice_number_out'])?(string)$m['invoice_number_out']:'',$this->excelDate($m['invoice_date_out'] ?? ''),$m['sku'],$m['product_name'],$m['unit'],$sign>0?$this->qty($q):0,$sign<0?$this->qty($q):0,$this->qty($m['balance_units']),$known>0 && $m['cost_bani']!==null?$m['cost_bani']*100/$known:null,$m['cost_bani']===null?null:abs($m['cost_bani'])/100,$q && $m['sale_value_bani']!==null?$m['sale_value_bani']*100/$q:null,$m['sale_value_bani']===null?null:$m['sale_value_bani']/100,$m['pending_cost_units']>0?'În așteptare':'Cost asociat',$this->qty($m['pending_cost_units']),$m['operator'],$this->movementNote($m)],[21,22,23,24],[3,11,14]);
            $this->tone($journal,'B'.(7+$index),$sign>0?'EDF7F2':'FCEDF2',$sign>0?'24775D':'A43F61');
            $journal->getStyle('U'.(7+$index))->getNumberFormat()->setFormatCode('#,##0.00##');
            $journal->getStyle('K'.(7+$index))->getNumberFormat()->setFormatCode('dd.mm.yyyy');
        }
        $docRow=7;
        usort($documents,static fn(array $a,array $b):int=>[strtotime($a['movement']['movement_at']),(int)$a['movement']['id']]<=>[strtotime($b['movement']['movement_at']),(int)$b['movement']['id']]);
        foreach($documents as $document) {
            $m=$document['movement'];
            $this->row($sources,$docRow,[$docRow-6,$m['quantity_units']<0?'Ieșire':'Intrare',$m['label'],$m['document'],$this->excelDate($m['movement_at']),$m['partner'],$m['warehouse'],$m['invoice_series'] ?? '',$m['invoice_number'] ?? '',$m['series_code'] ?? '',isset($m['invoice_number_out'])?(string)$m['invoice_number_out']:'',$document['positions'],$this->qty($document['quantity']),$document['known']?$document['cost']/100:null,$document['sale']/100,$m['operator']],[14,15],[5]); $docRow++;
        }
        $methodRows=[
            ['Scop','Centralizator al stocului contabil, documentelor justificative și mișcărilor din perioada aleasă.'],
            ['Baza datelor','Sunt incluse numai mișcările documentate prin NIR-uri confirmate și facturi de ieșire active. Ajustările interne și documentele șterse nu intră în acest export.'],
            ['Sold inițial','Cantitatea documentată imediat înaintea primei zile din interval. Pentru toată perioada disponibilă, soldul inițial este zero.'],
            ['Sold final','Sold inițial + intrări − ieșiri. Mișcările ulterioare intervalului nu intră în acest sold.'],
            ['Intrări / ieșiri','Cantitățile și valorile mișcărilor sunt prezentate în valoare absolută; direcția indică adăugarea sau scăderea din stoc. Verde: intrare. Rose: ieșire.'],
            ['Valori la cost','Valorile sunt reconstruite din costurile de achiziție asociate. Costurile necunoscute sunt marcate separat; o valoare incompletă nu reprezintă cost zero.'],
            ['Vânzări înainte de achiziție','Stocul contabil poate fi negativ. Costul se asociază ulterior, la confirmarea NIR-ului; un nou export reflectă costurile cunoscute la data generării.'],
            ['Online / contabil','Disponibilitatea online este situația curentă a magazinului. Nu reprezintă soldul contabil și nu este modificată de generarea acestui registru.'],
            ['Perioadă','Intrările și retururile folosesc data înregistrării NIR-ului. Vânzările folosesc data emiterii facturii. Aceleași date stabilesc ordinea mișcărilor și soldurile. Datele sunt afișate în Europe/Bucharest; prima și ultima zi sunt incluse integral.'],
            ['Cantități','Valorile sunt numere Excel; întregii se afișează fără zecimale sau punct final. SKU-urile și numerele documentelor sunt text.'],
            ['Control','Diferențele între jurnal și soldul contabil curent sunt semnalate pentru verificare. Exportul nu efectuează ajustări.'],
            ['Documente justificative','Tipul, numărul, data, partenerul, seriile și numerele facturilor, produsul, cantitățile, valorile și operatorul se regăsesc în fișele de magazie și în jurnal.'],
            ['Identificator export',$exportId.' · generat la '.$generated->format('d.m.Y H:i').' · '.$period]
        ];
        foreach($methodRows as $index=>$values) {
            $r=6+$index*2; $method->mergeCells('A'.$r.':B'.($r+1))->mergeCells('C'.$r.':J'.($r+1)); $this->text($method,'A'.$r,$values[0]); $this->text($method,'C'.$r,$values[1]); $method->getStyle('A'.$r.':B'.($r+1))->getFont()->setBold(true); $method->getStyle('A'.$r.':J'.($r+1))->getFill()->setFillType('solid')->getStartColor()->setRGB($index%2?'FFFFFF':'FAF4F7');
        }
        $signature=7+count($methodRows)*2;
        $method->mergeCells('A'.$signature.':E'.$signature)->mergeCells('F'.$signature.':J'.$signature);
        $this->text($method,'A'.$signature,'ÎNTOCMIT / GENERAT DE'); $this->text($method,'F'.$signature,'VERIFICAT / APROBAT DE');
        $this->tone($method,'A'.$signature.':J'.$signature,'60445C','FFFFFF');
        ++$signature;
        $method->mergeCells('A'.$signature.':E'.($signature+2))->mergeCells('F'.$signature.':J'.($signature+2));
        foreach(['A','F'] as $column) { $this->text($method,$column.$signature,"Nume: ______________________________\nData: _______________________________\nSemnătura: __________________________"); }
        $method->getStyle('A6:J'.($signature+2))->getAlignment()->setWrapText(true)->setVertical('center');
        $method->getPageSetup()->setPrintArea('A1:J'.($signature+2));
        $this->summary($summary,$report,count($all),count($documents));
        $this->finish($stock,6,15,6+count($report['details']));
        $this->finish($journal,6,28,6+count($all));
        $this->finish($sources,6,16,$docRow-1);
        if($all===[]) { $this->emptyRow($journal,7,28,'Nu există mișcări în perioada selectată.'); }
        if($documents===[]) { $this->emptyRow($sources,7,16,'Nu există documente în perioada selectată.'); }
        if($report['details']===[]) { $this->emptyRow($stock,7,15,'Nu există produse pentru filtrele selectate.'); $this->emptyRow($cards,6,16,'Nu există produse pentru filtrele selectate.'); }
        $cards->freezePane('D8'); $cards->getPageSetup()->setPrintArea('A1:P'.max(6,$cardRow-1));
        $this->widths($summary,[14,20,38,13,13,17,15,20,21,34]);
        $this->widths($stock,[8,20,40,8,14,13,13,14,20,20,20,20,20,13,46]);
        $this->widths($cards,[8,21,26,24,30,18,18,18,18,13,13,14,20,20,22,56]);
        $this->widths($journal,[8,13,21,26,26,24,30,29,18,18,21,18,18,21,20,40,8,14,14,14,20,20,20,20,22,20,22,56]);
        $this->widths($sources,[8,13,26,24,21,30,26,18,18,18,18,12,18,20,20,22]);
        $this->widths($method,[18,18,15,15,15,15,15,15,15,15]);
        $stockLast=6+count($report['details']); $journalLast=6+count($all);
        if($stockLast>=7) {
            foreach(['F','K'] as $c) { $this->tone($stock,$c.'7:'.$c.$stockLast,'EDF7F2','24775D'); }
            foreach(['G','L'] as $c) { $this->tone($stock,$c.'7:'.$c.$stockLast,'FCEDF2','A43F61'); }
            foreach(['E','H'] as $c) { $this->negative($stock,$c.'7:'.$c.$stockLast); }
        }
        if($journalLast>=7) { $this->negative($journal,'T7:T'.$journalLast); }
        foreach([$summary,$stock,$cards,$journal,$sources] as $sheet) { $this->fitTextRows($sheet); }
        $book->setActiveSheetIndex(0); return $book;
    }

    private function sheet(Spreadsheet $book,string $name,string $title,string $period,int $columns): Worksheet
    {
        $s=$book->createSheet()->setTitle($name); $end=Coordinate::stringFromColumnIndex($columns);
        $s->setShowGridlines(false); $s->getTabColor()->setRGB('885578');
        $s->mergeCells('A1:'.$end.'2')->mergeCells('A3:'.$end.'3')->mergeCells('A4:'.$end.'4');
        $this->text($s,'A1','La Măriuca Boutique · '.$title); $this->text($s,'A3',$period); $this->text($s,'A4','Evidență contabilă · Documente confirmate · Valori în Lei');
        $s->getStyle('A1:'.$end.'2')->getFont()->setSize(16)->setBold(true)->getColor()->setRGB('60445C');
        $s->getStyle('A1:'.$end.'4')->getAlignment()->setVertical('center')->setWrapText(true);
        $s->getStyle('A3:'.$end.'4')->getFont()->setSize(10)->getColor()->setRGB('947587');
        $s->getDefaultRowDimension()->setRowHeight(24);
        $s->getRowDimension(5)->setRowHeight(9);
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
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setVertical('center')->setHorizontal('center'); $sheet->getRowDimension($row)->setRowHeight(44);
        $sheet->getStyle($range)->getBorders()->getInside()->setBorderStyle('thin')->getColor()->setRGB('FFFFFF');
    }
    /** @param list<mixed> $values @param list<int> $money @param list<int> $dates */
    private function row(Worksheet $sheet,int $row,array $values,array $money=[],array $dates=[]): void
    {
        foreach($values as $i=>$value) {
            if($value===null) { continue; } $cell=$sheet->getCell([$i+1,$row]);
            if(is_int($value)||is_float($value)) {
                $cell->setValueExplicit($value,DataType::TYPE_NUMERIC);
                $cell->getStyle()->getAlignment()->setHorizontal('right');
                $cell->getStyle()->getNumberFormat()->setFormatCode(in_array($i+1,$dates,true)?'dd.mm.yyyy hh:mm':(in_array($i+1,$money,true)?'#,##0.00':(floor((float)$value)===(float)$value?'#,##0':'#,##0.####')));
            } else { $cell->setValueExplicit((string)$value,DataType::TYPE_STRING); $cell->getStyle()->getAlignment()->setHorizontal('left'); }
        }
        $range='A'.$row.':'.Coordinate::stringFromColumnIndex(count($values)).$row;
        $sheet->getStyle($range)->getAlignment()->setVertical('center')->setWrapText(true)->setIndent(1);
        $sheet->getStyle($range)->getFill()->setFillType('solid')->getStartColor()->setRGB($row%2?'FFFFFF':'FBF5F8');
        $sheet->getRowDimension($row)->setRowHeight(42);
    }
    private function finish(Worksheet $s,int $header,int $columns,int $last): void
    {
        $end=Coordinate::stringFromColumnIndex($columns); $last=max($header,$last);
        $s->setAutoFilter('A'.$header.':'.$end.$last)->freezePane('D'.($header+1));
        $s->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($header,$header)->setPrintArea('A1:'.$end.max($header+1,$last));
    }

    /** @param array<string,mixed> $report */
    private function summary(Worksheet $sheet,array $report,int $movements,int $documents): void
    {
        $details=$report['details'];
        $this->section($sheet,10,'INDICATORI PERIOADĂ',10);
        $metrics=[
            ['Produse urmărite',count($details),'Mișcări',$movements],
            ['Documente sursă',$documents,'Intrări (buc.)',$this->qty(array_sum(array_column($details,'incoming_units')))],
            ['Ieșiri (buc.)',$this->qty(array_sum(array_column($details,'outgoing_units'))),'Stoc final (buc.)',$this->qty(array_sum(array_column($details,'closing_units')))],
            ['Valoare finală la cost',array_sum(array_column($details,'closing_value_bani'))/100,'Cantitate fără cost asociat',$this->qty(array_sum(array_column($details,'pending_cost_units')))],
        ];
        foreach($metrics as $i=>$metric) {
            $r=11+$i;
            $sheet->mergeCells('A'.$r.':C'.$r)->mergeCells('D'.$r.':E'.$r)->mergeCells('F'.$r.':H'.$r)->mergeCells('I'.$r.':J'.$r);
            $this->row($sheet,$r,[$metric[0],null,null,$metric[1],null,$metric[2],null,null,$metric[3],null],$i===3?[4]:[]);
            $sheet->getRowDimension($r)->setRowHeight(32);
            foreach(['D','I'] as $c) { $sheet->getStyle($c.$r)->getFont()->setBold(true)->setSize(13); }
            $this->tone($sheet,'A'.$r.':J'.$r,$i===3?'60445C':'F8F1F5',$i===3?'FFFFFF':'60445C');
        }
        $sheet->getRowDimension(9)->setRowHeight(9); $sheet->getRowDimension(15)->setRowHeight(9);
        $this->section($sheet,16,'PRODUSE CU CEA MAI MARE ACTIVITATE ÎN PERIOADĂ',10);
        $this->head($sheet,17,['Nr. crt.','Cod produs','Denumire','Mișcări','Intrări','Ieșiri','Stoc final','Valoare finală','Cantitate fără cost asociat','Observație']);
        $active=array_values(array_filter($details,static fn(array $detail):bool=>$detail['movements']!==[]));
        usort($active,static fn(array $a,array $b):int=>count($b['movements'])<=>count($a['movements']) ?: strcmp($a['product']['name'],$b['product']['name']));
        $active=array_slice($active,0,10);
        foreach($active as $i=>$d) {
            $this->row($sheet,18+$i,[$i+1,$d['product']['primary_sku'],$d['product']['name'],count($d['movements']),$this->qty($d['incoming_units']),$this->qty($d['outgoing_units']),$this->qty($d['closing_units']),$d['closing_value_bani']/100,$this->qty($d['pending_cost_units']),$d['pending_cost_units']>0?'Cost de achiziție incomplet':($d['consistent']?'Reconciliat':'De verificat')],[8]);
        }
        if($active===[]) { $this->emptyRow($sheet,18,10,'Nu există mișcări în perioada selectată.'); }
        $note=19+max(1,count($active));
        $sheet->mergeCells('A'.$note.':J'.$note);
        $this->text($sheet,'A'.$note,'Valori în Lei, calculate din documentele și costurile de achiziție cunoscute. Pozițiile fără cost asociat necesită reconciliere.');
        $sheet->getStyle('A'.$note)->getFont()->getColor()->setRGB('806A7C');
        $sheet->getStyle('A'.$note)->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($note)->setRowHeight(30);
        $sheet->freezePane(null);
        $sheet->getPageSetup()->setPrintArea('A1:J'.$note)->setPaperSize(9);
    }

    private function section(Worksheet $sheet,int $row,string $title,int $columns): void
    {
        $range='A'.$row.':'.Coordinate::stringFromColumnIndex($columns).$row;
        $sheet->mergeCells($range); $this->text($sheet,'A'.$row,$title);
        $this->tone($sheet,$range,'F0E3EC','754D69');
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setVertical('center');
        $sheet->getRowDimension($row)->setRowHeight(27);
    }

    private function emptyRow(Worksheet $sheet,int $row,int $columns,string $message): void
    {
        $sheet->mergeCells('A'.$row.':'.Coordinate::stringFromColumnIndex($columns).$row);
        $this->text($sheet,'A'.$row,$message);
        $sheet->getStyle('A'.$row)->getFont()->getColor()->setRGB('806A7C');
        $sheet->getStyle('A'.$row)->getAlignment()->setVertical('center');
        $sheet->getRowDimension($row)->setRowHeight(36);
    }

    /** @param list<int> $widths */
    private function widths(Worksheet $sheet,array $widths): void
    {
        foreach($widths as $i=>$width) { $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i+1))->setWidth($width); }
    }

    private function tone(Worksheet $sheet,string $range,string $fill,string $ink): void
    {
        $sheet->getStyle($range)->getFill()->setFillType('solid')->getStartColor()->setRGB($fill);
        $sheet->getStyle($range)->getFont()->getColor()->setRGB($ink);
    }

    private function negative(Worksheet $sheet,string $range): void
    {
        $condition=new Conditional();
        $condition->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_LESSTHAN)->addCondition('0');
        $condition->getStyle()->getFont()->getColor()->setRGB('A43F61');
        $condition->getStyle()->getFont()->setBold(true);
        $sheet->getStyle($range)->setConditionalStyles([$condition]);
    }

    /** Fit wrapped document names/notes without clipping longer user-entered text. */
    private function fitTextRows(Worksheet $sheet): void
    {
        $merged=[];
        foreach($sheet->getMergeCells() as $range) {
            [$start,$end]=Coordinate::rangeBoundaries($range);
            $merged[Coordinate::stringFromColumnIndex($start[0]).$start[1]]=[$start,$end];
        }
        foreach($sheet->getCoordinates() as $address) {
            $cell=$sheet->getCell($address); $value=$cell->getValue();
            if(!is_string($value) || $value==='' || !$cell->getStyle()->getAlignment()->getWrapText()) { continue; }
            $column=$cell->getColumn(); $row=$cell->getRow();
            if($row<6) { continue; }
            $first=Coordinate::columnIndexFromString($column); $last=$first;
            if(isset($merged[$address])) {
                [$start,$end]=$merged[$address];
                if($start[1]!==$end[1]) { continue; }
                $last=$end[0];
            }
            $width=0;
            for($c=$first;$c<=$last;$c++) { $width+=$sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->getWidth(); }
            $characters=max(1,(int)floor($width-3)); $lines=0;
            foreach(explode("\n",$value) as $line) { $lines+=max(1,(int)ceil(mb_strlen($line)/$characters)); }
            $height=min(409,12+$lines*13);
            if($height>$sheet->getRowDimension($row)->getRowHeight()) { $sheet->getRowDimension($row)->setRowHeight($height); }
        }
    }

    /** @param array<string,mixed> $filters */
    private function periodLabel(array $filters): string
    {
        $from=(string)($filters['from'] ?? ''); $to=(string)($filters['to'] ?? '');
        if($from==='' && $to==='') { return 'Toată perioada disponibilă'; }
        $format=static fn(string $date):string=>(new \DateTimeImmutable($date))->format('d.m.Y');
        return ($from===''?'Începutul evidenței':$format($from)).' — '.($to===''?'Prezent':$format($to));
    }

    /** @param array<string,mixed> $movement */
    private function movementNote(array $movement): string
    {
        $notes=[$movement['label'].' · '.$movement['document']];
        if(!empty($movement['order_number'])) { $notes[]='Comanda '.$movement['order_number']; }
        if(!empty($movement['nir_notes'])) { $notes[]=trim((string)$movement['nir_notes']); }
        if($movement['pending_cost_units']>0) { $notes[]='Cost neasociat pentru '.$this->qty($movement['pending_cost_units']).' buc.'; }
        return implode(' · ',$notes);
    }
    private function text(Worksheet $s,string $cell,string $value): void { $s->setCellValueExplicit($cell,$value,DataType::TYPE_STRING); }
    private function qty(int $units): float { return $units/10000; }
    private function excelDate(string $value): ?float
    {
        if($value==='') { return null; }
        $zone=new \DateTimeZone('Europe/Bucharest');
        // Calendar-only supplier invoice dates have no UTC time to convert.
        $date=preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)?new \DateTimeImmutable($value,$zone):(new \DateTimeImmutable($value))->setTimezone($zone);
        return Date::PHPToExcel($date);
    }
}
