<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Shared\Support\Slugger;
use PDO;

final class DemoSeeder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(): void
    {
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0) {
            (new TraditionalCategorySeeder($this->pdo))->run();
            $this->archiveAccessoryCategories();
            return;
        }
        $now = gmdate('c');
        $categories = [
            'nou-nascuti' => ['Nou-născuți', null], 'seturi-nou-nascuti' => ['Seturi nou-născuți', 'nou-nascuti'], 'body-uri' => ['Body-uri', 'nou-nascuti'], 'salopete' => ['Salopete', 'nou-nascuti'], 'compleuri-bebe' => ['Compleuri bebe', 'nou-nascuti'], 'pijamale-bebe' => ['Pijamale bebe', 'nou-nascuti'], 'caciulite-bonete' => ['Căciulițe și bonete', 'nou-nascuti'], 'botosei' => ['Botoșei', 'nou-nascuti'],
            'fetite' => ['Fetițe', null], 'rochite' => ['Rochițe', 'fetite'], 'rochite-zi' => ['Rochițe de zi', 'rochite'], 'rochite-ocazie' => ['Rochițe de ocazie', 'rochite'], 'compleuri-fetite' => ['Compleuri și seturi', 'fetite'], 'bluze-fetite' => ['Bluze și tricouri', 'fetite'], 'fuste' => ['Fuste', 'fetite'], 'pantaloni-colanti' => ['Pantaloni și colanți', 'fetite'], 'treninguri-fetite' => ['Treninguri', 'fetite'], 'pulovere-fetite' => ['Pulovere și cardigane', 'fetite'], 'geci-fetite' => ['Jachete și geci', 'fetite'], 'pijamale-fetite' => ['Pijamale', 'fetite'],
            'baieti' => ['Băieți', null], 'costumase' => ['Costumașe', 'baieti'], 'compleuri-baieti' => ['Compleuri și seturi', 'baieti'], 'camasi' => ['Cămăși', 'baieti'], 'tricouri-baieti' => ['Tricouri și bluze', 'baieti'], 'pantaloni-baieti' => ['Pantaloni', 'baieti'], 'treninguri-baieti' => ['Treninguri', 'baieti'], 'pulovere-baieti' => ['Pulovere și cardigane', 'baieti'], 'geci-baieti' => ['Jachete și geci', 'baieti'], 'pijamale-baieti' => ['Pijamale', 'baieti'],
            'costume-traditionale' => ['Costume tradiționale', null], 'costume-traditionale-fetite' => ['Fetițe', 'costume-traditionale'], 'costume-traditionale-baietei' => ['Băieței', 'costume-traditionale'],
            'botez' => ['Botez', null], 'rochite-botez' => ['Rochițe de botez', 'botez'], 'costumase-botez' => ['Costumașe de botez', 'botez'], 'trusouri' => ['Trusouri', 'botez'], 'lumanari-botez' => ['Lumânări de botez', 'botez'], 'cutii-trusou' => ['Cutii pentru trusou', 'botez'], 'accesorii-botez' => ['Accesorii de botez', 'botez'],
            'incaltaminte' => ['Încălțăminte', null], 'pantofiori' => ['Pantofiori', 'incaltaminte'], 'balerini' => ['Balerini', 'incaltaminte'], 'sneakers' => ['Sneakers', 'incaltaminte'], 'sandale' => ['Sandale', 'incaltaminte'], 'ghete' => ['Ghete', 'incaltaminte'],
            'accesorii' => ['Accesorii', null], 'bentite-fundite' => ['Bentițe și fundițe', 'accesorii'], 'agrafe' => ['Agrafe', 'accesorii'], 'caciuli-bonete' => ['Căciuli și bonete', 'accesorii'], 'sosete-dresuri' => ['Șosete și dresuri', 'accesorii'], 'papioane-bretele' => ['Papioane și bretele', 'accesorii'], 'alte-accesorii' => ['Alte accesorii', 'accesorii'],
        ];
        $ids = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($categories as $slug => [$name, $parent]) {
                $statement = $this->pdo->prepare("INSERT INTO categories (parent_id,name,slug,description,position,status,visibility,seo_title,meta_description,indexable,published_at,created_at,updated_at) VALUES (:parent,:name,:slug,:description,0,'published','public',:seo,:meta,1,:now,:now,:now)");
                $statement->execute(['parent' => $parent ? $ids[$parent] : null, 'name' => $name, 'slug' => $slug, 'description' => 'Selecție demo pentru categoria ' . $name . '. Conținutul se editează din administrare.', 'seo' => $name . ' pentru copii', 'meta' => 'Descoperă selecția ' . mb_strtolower($name) . ' pentru copii.', 'now' => $now]);
                $ids[$slug] = (int) $this->pdo->lastInsertId();
            }
            $this->pdo->prepare('UPDATE categories SET position = :position WHERE slug = :slug')->execute(['position' => 4, 'slug' => 'costume-traditionale']);
            $this->pdo->prepare('UPDATE categories SET position = :position WHERE slug = :slug')->execute(['position' => 1, 'slug' => 'costume-traditionale-fetite']);
            $this->pdo->prepare('UPDATE categories SET position = :position WHERE slug = :slug')->execute(['position' => 2, 'slug' => 'costume-traditionale-baietei']);
            $this->archiveAccessoryCategories();

            $categoryImages = [
                'nou-nascuti' => ['nou-nascuti-editorial-2026.webp', 'Set delicat pentru nou-născuți, în nuanțe de ivoire și roz pudrat'],
                'fetite' => ['fetite-editorial-2026.webp', 'Rochiță roz pudrat cu broderie delicată pentru fetițe'],
                'baieti' => ['baieti-editorial-2026.webp', 'Ținută elegantă bej cu papion și pantofiori pentru băieți'],
                'costume-traditionale' => ['costume-traditionale-editorial-2026.webp', 'Costume tradiționale românești pentru fetițe și băieței, cu broderii bordo'],
                'costume-traditionale-fetite' => ['costume-traditionale-fetite-editorial-2026.webp', 'Costum tradițional românesc pentru fetițe, cu ie brodată și fotă bordo'],
                'costume-traditionale-baietei' => ['costume-traditionale-baietei-editorial-2026.webp', 'Costum tradițional românesc pentru băieței, cu ie, vestă și brâu brodat'],
                'botez' => ['botez-editorial-2026.webp', 'Rochie ivoire cu dantelă și bonetă pentru botez'],
                'incaltaminte' => ['incaltaminte-editorial-2026.webp', 'Pantofiori crem cu funde roz pentru copii'],
                'accesorii' => ['accesorii-editorial-2026.webp', 'Accesorii delicate pentru copii, în tonuri de ivoire și roz pudrat'],
            ];
            $projectRoot = dirname(__DIR__, 2);
            foreach ($categoryImages as $slug => [$filename, $altText]) {
                $publicPath = 'assets/images/categories/' . $filename;
                $absolutePath = $projectRoot . '/public/' . $publicPath;
                $imageInfo = is_file($absolutePath) ? @getimagesize($absolutePath) : false;
                if (!is_array($imageInfo)) {
                    continue;
                }
                $statement = $this->pdo->prepare('INSERT INTO media (original_name,storage_path,public_path,mime_type,width,height,size_bytes,alt_text,created_at) VALUES (:name,:storage,:public,:mime,:width,:height,:size,:alt,:now)');
                $statement->execute([
                    'name' => $filename,
                    'storage' => 'seeded/category-images/' . $filename,
                    'public' => $publicPath,
                    'mime' => 'image/webp',
                    'width' => (int) $imageInfo[0],
                    'height' => (int) $imageInfo[1],
                    'size' => (int) (filesize($absolutePath) ?: 0),
                    'alt' => $altText,
                    'now' => $now,
                ]);
                $this->pdo->prepare('UPDATE categories SET image_media_id = :media_id WHERE id = :category_id')->execute([
                    'media_id' => (int) $this->pdo->lastInsertId(),
                    'category_id' => $ids[$slug],
                ]);
            }

            $products = [
                ['Set nou-născut „Primul dar”','seturi-nou-nascuti','LM-NB-001',18900,8,true,'Set moale din trei piese, pentru primele zile.'],
                ['Body petrecut „Liniște”','body-uri','LM-NB-002',7900,12,false,'Body practic cu închidere laterală.'],
                ['Rochiță de zi „Ana”','rochite-zi','LM-G-001',15900,7,false,'Rochiță lejeră pentru zile senine.'],
                ['Rochiță de ocazie „Amalia”','rochite-ocazie','LM-G-002',29900,5,true,'Ținută demo de ocazie, cu croi confortabil.'],
                ['Costumaș „Micul gentleman”','costumase','LM-B-001',34900,4,true,'Costumaș în trei piese pentru evenimente.'],
                ['Cămașă „Duminică”','camasi','LM-B-002',13900,9,false,'Cămașă clasică pentru băieți.'],
                ['Rochie de botez „Raisa”','rochite-botez','LM-CH-001',45900,3,false,'Rochie de botez cu detalii discrete.'],
                ['Costumaș de botez „Matei”','costumase-botez','LM-CH-002',42900,3,false,'Set pentru botez, disponibil în mai multe mărimi.'],
                ['Trusou botez „Început”','trusouri','LM-TR-001',39900,6,true,'Trusou complet demo, cu opțiune de personalizare.'],
                ['Pantofiori „Primii pași”','pantofiori','LM-SH-001',19900,10,false,'Pantofiori cu talpă flexibilă.'],
                ['Bentiță cu fundă „Dimineață”','bentite-fundite','LM-A-001',7900,15,false,'Accesoriu delicat pentru ținute de zi.'],
                ['Ghete „Explorator”','ghete','LM-SH-002',23900,0,false,'Model demo afișat corect și când stocul este epuizat.'],
            ];
            $productIds = [];
            foreach ($products as [$name, $category, $sku, $price, $stock, $featured, $description]) {
                $slug = Slugger::slug($name);
                $statement = $this->pdo->prepare("INSERT INTO products (name,slug,primary_sku,short_description,description,status,primary_category_id,seo_title,meta_description,composition,care_instructions,size_guide,package_contents,excluded_accessories,featured,indexable,published_at,created_at,updated_at) VALUES (:name,:slug,:sku,:short,:description,'published',:category,:seo,:meta,'Materialele se completează înainte de lansare.','Respectă instrucțiunile de pe eticheta produsului.','Consultă ghidul de mărimi editabil.','','',:featured,1,:now,:now,:now)");
                $statement->execute(['name' => $name, 'slug' => $slug, 'sku' => $sku, 'short' => $description, 'description' => $description . ' Acesta este un produs demonstrativ și nu reprezintă o ofertă comercială reală.', 'category' => $ids[$category], 'seo' => $name, 'meta' => $description, 'featured' => $featured ? 1 : 0, 'now' => $now]);
                $productId = (int) $this->pdo->lastInsertId();
                $productIds[$sku] = $productId;
                $parents = [$category];
                $parent = $categories[$category][1];
                while ($parent !== null) {
                    $parents[] = $parent;
                    $parent = $categories[$parent][1] ?? null;
                }
                foreach (array_unique($parents) as $categoryKey) {
                    $this->pdo->prepare('INSERT INTO product_categories (product_id,category_id) VALUES (:product,:category)')->execute(['product' => $productId, 'category' => $ids[$categoryKey]]);
                }
                $this->pdo->prepare("INSERT INTO product_variants (product_id,sku,option_summary,options_json,options_key,price_bani,status,is_default,created_at,updated_at) VALUES (:product,:sku,'Mărime standard','{}',:key,:price,'active',1,:now,:now)")->execute(['product' => $productId, 'sku' => $sku, 'key' => hash('sha256', '{}'), 'price' => $price, 'now' => $now]);
                $variantId = (int) $this->pdo->lastInsertId();
                $this->pdo->prepare('INSERT INTO inventory (variant_id,physical_quantity,reserved_quantity,updated_at) VALUES (:variant,:stock,0,:now)')->execute(['variant' => $variantId, 'stock' => $stock, 'now' => $now]);
                $this->pdo->prepare("INSERT INTO inventory_movements (variant_id,type,physical_delta,reserved_delta,reason,created_at) VALUES (:variant,'demo_seed',:stock,0,'Date demo explicite',:now)")->execute(['variant' => $variantId, 'stock' => $stock, 'now' => $now]);
            }

            $gentleman = $productIds['LM-B-001'];
            $gentlemanDefaultVariant = (int) $this->pdo->query('SELECT id FROM product_variants WHERE product_id = ' . $gentleman . ' AND is_default = 1 LIMIT 1')->fetchColumn();
            $sizeTwoOptions = json_encode(['Mărime' => '2 ani'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $sizeFourOptions = json_encode(['Mărime' => '4 ani'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->pdo->prepare('UPDATE product_variants SET sku=:sku, option_summary=:summary, options_json=:options, options_key=:options_key, updated_at=:now WHERE id=:id')->execute([
                'sku' => 'LM-B-001-2A',
                'summary' => '2 ani',
                'options' => $sizeTwoOptions,
                'options_key' => hash('sha256', $sizeTwoOptions),
                'now' => $now,
                'id' => $gentlemanDefaultVariant,
            ]);
            $this->pdo->prepare('UPDATE inventory SET physical_quantity=2, updated_at=:now WHERE variant_id=:variant')->execute(['now' => $now, 'variant' => $gentlemanDefaultVariant]);
            $this->pdo->prepare("UPDATE inventory_movements SET physical_delta=2 WHERE variant_id=:variant AND type='demo_seed'")->execute(['variant' => $gentlemanDefaultVariant]);
            $this->pdo->prepare("INSERT INTO product_variants (product_id,sku,option_summary,options_json,options_key,price_bani,status,is_default,created_at,updated_at) VALUES (:product,:sku,:summary,:options,:options_key,34900,'active',0,:now,:now)")->execute([
                'product' => $gentleman,
                'sku' => 'LM-B-001-4A',
                'summary' => '4 ani',
                'options' => $sizeFourOptions,
                'options_key' => hash('sha256', $sizeFourOptions),
                'now' => $now,
            ]);
            $gentlemanSecondVariant = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO inventory (variant_id,physical_quantity,reserved_quantity,updated_at) VALUES (:variant,2,0,:now)')->execute(['variant' => $gentlemanSecondVariant, 'now' => $now]);
            $this->pdo->prepare("INSERT INTO inventory_movements (variant_id,type,physical_delta,reserved_delta,reason,created_at) VALUES (:variant,'demo_seed',2,0,'A doua mărime demo',:now)")->execute(['variant' => $gentlemanSecondVariant, 'now' => $now]);

            $trusou = $productIds['LM-TR-001'];
            $productImageSeeds = [
                'LM-TR-001' => ['trusou-botez-inceput.webp', 'Trusou complet pentru botez cu prosop, pânză de mir, fașă, săpun și cutie pentru prima șuviță'],
            ];
            foreach ($productImageSeeds as $sku => [$filename, $altText]) {
                $publicPath = 'assets/images/products/' . $filename;
                $absolutePath = $projectRoot . '/public/' . $publicPath;
                $imageInfo = is_file($absolutePath) ? @getimagesize($absolutePath) : false;
                if (!is_array($imageInfo)) {
                    continue;
                }
                $statement = $this->pdo->prepare('INSERT INTO media (original_name,storage_path,public_path,mime_type,width,height,size_bytes,alt_text,created_at) VALUES (:name,:storage,:public,:mime,:width,:height,:size,:alt,:now)');
                $statement->execute([
                    'name' => $filename,
                    'storage' => 'seeded/product-images/' . $filename,
                    'public' => $publicPath,
                    'mime' => 'image/webp',
                    'width' => (int) $imageInfo[0],
                    'height' => (int) $imageInfo[1],
                    'size' => (int) (filesize($absolutePath) ?: 0),
                    'alt' => $altText,
                    'now' => $now,
                ]);
                $this->pdo->prepare('INSERT INTO product_images (product_id,media_id,position) VALUES (:product,:media,0)')->execute([
                    'product' => $productIds[$sku],
                    'media' => (int) $this->pdo->lastInsertId(),
                ]);
            }
            $this->pdo->prepare("UPDATE products SET package_contents='Prosop cu glugă, pânză de mir, fașă, săpun și cutie pentru prima șuviță.', excluded_accessories='Lumânarea și decorul fotografic nu sunt incluse.' WHERE id=:id")->execute(['id' => $trusou]);
            $this->pdo->prepare("INSERT INTO personalization_options (product_id,type,label,allowed_values_json,max_length,required,surcharge_bani,position) VALUES (:product,'text','Nume brodat','[]',24,0,3000,0)")->execute(['product' => $trusou]);

            $this->pdo->prepare("INSERT INTO collections (name,slug,description,type,status,indexable,seo_title,meta_description,published_at,updated_at) VALUES ('Noutăți','noutati','Produse publicate recent.','new','published',1,'Noutăți pentru copii','Selecție de produse noi.',:now,:now)")->execute(['now' => $now]);
            $collectionId = (int) $this->pdo->lastInsertId();
            $position = 0;
            foreach (array_slice(array_values($productIds), 0, 6) as $productId) {
                $this->pdo->prepare('INSERT INTO collection_products (collection_id,product_id,position) VALUES (:collection,:product,:position)')->execute(['collection' => $collectionId, 'product' => $productId, 'position' => $position++]);
            }

            $aboutPageBody = <<<'HTML'
<p>La Măriuca Boutique a început ca o afacere de familie. Un proiect în care am pus idei, timp și mult suflet, dorindu-ne să adunăm într-un singur loc hăinuțe și lucruri frumoase pentru copii. Cu timpul, ceea ce începuse ca o inițiativă de familie s-a transformat într-o pasiune pe care o împărtășim: bucuria de a descoperi și de a alege ținute pentru cei mici, de la primele lor zile până la aventurile copilăriei.</p>
<p>Pentru noi, farmecul acestui magazin stă tocmai în lucrurile personale care îi dau viață. Iar primul dintre ele este numele.</p>
<h2>Totul începe cu un nume drag: Maria</h2>
<p><strong>La Măriuca Boutique își poartă numele cu gândul la Maria, o fetiță din familia noastră.</strong> Un nume simplu și drag, care ne duce cu gândul la joacă, la curiozitate și la bucuria de a vedea un copil crescând.</p>
<p>Am ales „La Măriuca” pentru că ne-am dorit ca magazinul să păstreze ceva din căldura de acasă. Să fie un loc primitor, în care să intri cu drag atunci când cauți primele hăinuțe pentru un bebeluș, o ținută pentru o zi importantă sau un cadou pentru un copil apropiat.</p>
<p>Maria ne-a inspirat numele. Familia i-a dat acestui proiect rădăcini. Iar pasiunea pentru ceea ce facem îl ajută să crească.</p>
<h2>Dintr-un proiect de familie, o pasiune comună</h2>
<p>În spatele La Măriuca Boutique suntem noi: o familie care se implică, discută fiecare alegere și se bucură de detalii. Ne atrage o broderie delicată, o combinație frumoasă de culori, un costumaș bine gândit sau un set pe care ni-l imaginăm oferit cu drag.</p>
<p>Pe măsură ce am construit acest magazin, am înțeles că ne place nu doar să alegem produse, ci și să ne gândim la locul lor în viața unei familii. La părinții care pregătesc venirea pe lume a unui copil. La nașii care caută un trusou de botez. La bunicii care vor să ofere ceva frumos. La o ținută aleasă împreună cu cel mic, care începe deja să știe ce îi place.</p>
<p>Aceste gânduri ne păstrează aproape de motivul pentru care am pornit la drum.</p>
<h2>Pentru fiecare etapă a copilăriei</h2>
<p>Colecțiile noastre sunt dedicate <strong>copiilor de la naștere până la 8 ani</strong> și aduc împreună seturi pentru nou-născuți, hăinuțe, rochițe, costumașe, încălțăminte și accesorii.</p>
<p>Botezul are un loc aparte în povestea noastră. Prin trusouri, ținute și accesorii pentru această zi, ne dorim să îi ajutăm pe părinți și pe nași să găsească lucruri care se potrivesc frumos împreună și care îi reprezintă.</p>
<p>Dar copilăria nu înseamnă doar ocazii speciale. Înseamnă și zile obișnuite, plimbări, joacă și mici descoperiri. Ne dorim ca La Măriuca Boutique să fie aproape de toate aceste etape, cu alegeri în care aspectul frumos și confortul să își găsească locul împreună.</p>
<h2>Bine ai venit în povestea noastră</h2>
<p>Suntem o afacere de familie și vrem să păstrăm această apropiere în tot ceea ce facem: în felul în care alegem produsele, în atenția acordată întrebărilor tale și în grija cu care construim magazinul.</p>
<p>Îți mulțumim că ai ajuns la noi. Fie că pregătești primele hăinuțe, cauți o ținută de sărbătoare sau alegi un dar, ne bucurăm că La Măriuca Boutique poate face parte din povestea familiei tale.</p>
<p><strong>La Măriuca Boutique — mai mult decât haine, povești pentru copii.</strong></p>
HTML;
            $pages = [
                ['Povestea La Măriuca Boutique','despre-noi',$aboutPageBody,0],
                ['Contact','contact','<p>Scrie-ne despre produsul, mărimea, comanda sau cadoul pentru care ai nevoie de ajutor. Formularul pregătește mesajul și deschide conversația WhatsApp cu La Măriuca Boutique.</p>',0],
                ['Livrare și retur','livrare-si-retur','<p>Draft: metodele, costurile și termenele se verifică împreună cu operatorul comercial și curierul.</p>',1],
                ['Termeni și condiții','termeni-si-conditii','<p>Draft juridic de completat și verificat înainte de lansare.</p>',1],
                ['Confidențialitate','confidentialitate','<p>Draft privind protecția datelor, de completat și verificat înainte de lansare.</p>',1],
                ['Cookies','cookies','<p>Draft: scripturile neesențiale sunt dezactivate implicit.</p>',1],
                ['Ghid de mărimi','ghid-marimi','<p>Valorile ghidului de mărimi se completează pentru fiecare gamă de produse.</p>',0],
            ];
            foreach ($pages as [$title, $slug, $body, $legal]) {
                $metaDescription = match ($slug) {
                    'despre-noi' => 'Descoperă povestea La Măriuca Boutique, afacerea de familie inspirată de Maria și dedicată copiilor de la naștere până la 8 ani.',
                    'contact' => 'Contactează La Măriuca Boutique direct pe WhatsApp pentru ajutor cu produse, mărimi, comenzi, botezuri sau cadouri.',
                    default => '',
                };
                $this->pdo->prepare("INSERT INTO content_pages (title,slug,body_html,status,legal_review_required,seo_title,meta_description,indexable,published_at,updated_at) VALUES (:title,:slug,:body,'published',:legal,:title,:meta,1,:now,:now)")->execute(['title' => $title, 'slug' => $slug, 'body' => $body, 'legal' => $legal, 'meta' => $metaDescription, 'now' => $now]);
            }
            $this->pdo->prepare("INSERT INTO homepage_sections (type,title,content_json,enabled,position,updated_at) VALUES ('hero','Începuturi frumoase','{}',1,0,:now),('categories','Categorii','{}',1,1,:now),('products','Produse recomandate','{}',1,2,:now),('editorial','Despre colecții','{}',1,3,:now)")->execute(['now' => $now]);
            foreach ([['store_name','La Măriuca Boutique',1],['store_currency','RON',1],['legal_review_status','required',0]] as [$key,$value,$public]) {
                $this->pdo->prepare('INSERT INTO settings (setting_key,setting_value,is_public,updated_at) VALUES (:key,:value,:public,:now)')->execute(['key' => $key, 'value' => $value, 'public' => $public, 'now' => $now]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function archiveAccessoryCategories(): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM categories WHERE slug = :slug LIMIT 1');
        $statement->execute(['slug' => 'accesorii']);
        $categoryId = $statement->fetchColumn();
        if ($categoryId === false) {
            return;
        }

        $statement = $this->pdo->prepare("UPDATE categories SET status = 'archived', visibility = 'hidden', updated_at = :now WHERE id = :id OR parent_id = :id");
        $statement->execute(['now' => gmdate('c'), 'id' => (int) $categoryId]);
    }
}
