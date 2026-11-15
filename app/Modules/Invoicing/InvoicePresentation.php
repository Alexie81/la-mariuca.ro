<?php
declare(strict_types=1);
namespace App\Modules\Invoicing;

/** Shared language for the register and the document sheet; no inferred ANAF success. */
final class InvoicePresentation
{
    /** @return array{label:string,description:string,tone:string,icon:string} */
    public static function fiscal(string $state): array
    {
        [$label,$description,$tone,$icon]=match($state) {
            'accepted'=>['Acceptată de ANAF','Factura a fost transmisă în SPV și acceptată de ANAF.','green','shield'],
            'submitted'=>['SPV · trimisă','Încărcarea a fost înregistrată. Se așteaptă validarea ANAF.','blue','upload'],
            'processing'=>['SPV · în curs','Transmiterea documentului este în desfășurare.','blue','clock'],
            'queued'=>['SPV · în așteptare','Documentul este în coada de transmitere; încă nu a fost trimis.','gold','clock'],
            'scheduled'=>['SPV · programată','Transmiterea este programată; documentul încă nu a fost trimis.','gold','clock'],
            'awaiting_configuration','unconfigured'=>['SPV · netrimisă','Conexiunea sau regulile SPV trebuie configurate înainte de transmitere.','neutral','shield'],
            'not_sent'=>['SPV · netrimisă','Transmiterea acestei facturi nu a fost încă inițiată.','neutral','shield'],
            'rejected'=>['Respinsă de ANAF','ANAF a respins documentul. Verifică răspunsul înainte de reluare.','rose','warning'],
            'transient_error'=>['SPV · eroare','Transmiterea a întâmpinat o eroare. Verifică detaliile și reia din SPV.','rose','warning'],
            'uncertain'=>['SPV · de verificat','Răspuns incomplet. Verifică în SPV dacă factura a fost primită înainte de retrimitere.','gold','warning'],
            default=>['SPV · de verificat','Starea transmiterii necesită verificare în secțiunea SPV.','gold','info'],
        };
        return compact('label','description','tone','icon');
    }

    /** @return array{label:string,tone:string} */
    public static function email(string $state): array
    {
        [$label,$tone]=match($state) {
            'sent'=>['Email trimis','green'],
            'queued'=>['Email în așteptare','gold'],
            'previewed'=>['Previzualizat local · netrimis','neutral'],
            'failed'=>['Email eșuat','rose'],
            'needs_resend'=>['Email de retrimis','gold'],
            default=>['Email netrimis','neutral'],
        };
        return compact('label','tone');
    }
}
