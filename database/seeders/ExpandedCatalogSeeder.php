<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Shared\Support\Slugger;
use PDO;
use RuntimeException;

final class ExpandedCatalogSeeder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(): int
    {
        $products = $this->products();
        $projectRoot = dirname(__DIR__, 2);
        $categoryRows = $this->pdo->query('SELECT id, parent_id, slug FROM categories')->fetchAll();
        $categories = [];
        foreach ($categoryRows as $category) {
            $categories[(string) $category['slug']] = [
                'id' => (int) $category['id'],
                'parent_id' => $category['parent_id'] === null ? null : (int) $category['parent_id'],
            ];
        }

        $categoriesById = [];
        foreach ($categories as $category) {
            $categoriesById[$category['id']] = $category;
        }

        $imageMetadata = [];
        foreach ($products as $product) {
            if (!isset($categories[$product['category']])) {
                throw new RuntimeException('Categoria lipsește pentru produsul ' . $product['name'] . ': ' . $product['category']);
            }

            $productImages = array_merge(
                [['image' => $product['image'], 'alt' => $product['alt']]],
                $product['gallery']
            );
            foreach ($productImages as $productImage) {
                $absolutePath = $projectRoot . '/public/assets/images/products/collection-2026/' . $productImage['image'];
                $imageInfo = is_file($absolutePath) ? @getimagesize($absolutePath) : false;
                if (!is_array($imageInfo)) {
                    throw new RuntimeException('Imaginea lipsește sau este invalidă: ' . $absolutePath);
                }
                $imageMetadata[$productImage['image']] = [
                    'path' => $absolutePath,
                    'width' => (int) $imageInfo[0],
                    'height' => (int) $imageInfo[1],
                    'size' => (int) (filesize($absolutePath) ?: 0),
                ];
            }
        }

        $now = gmdate('c');
        $inserted = 0;
        $this->pdo->beginTransaction();
        try {
            $existingStatement = $this->pdo->prepare('SELECT p.id FROM products p LEFT JOIN product_variants pv ON pv.product_id = p.id WHERE p.slug = :slug OR pv.sku = :sku LIMIT 1');
            $productStatement = $this->pdo->prepare("INSERT INTO products (name,slug,short_description,description,status,primary_category_id,seo_title,meta_description,composition,care_instructions,size_guide,package_contents,excluded_accessories,featured,indexable,published_at,created_at,updated_at) VALUES (:name,:slug,:short,:description,'published',:category,:seo,:meta,:composition,:care,:size_guide,:package_contents,:excluded_accessories,:featured,1,:now,:now,:now)");
            $productCategoryStatement = $this->pdo->prepare('INSERT INTO product_categories (product_id,category_id) VALUES (:product,:category)');
            $variantStatement = $this->pdo->prepare("INSERT INTO product_variants (product_id,sku,option_summary,options_json,options_key,price_bani,compare_price_bani,status,is_default,created_at,updated_at) VALUES (:product,:sku,:option_summary,:options_json,:options_key,:price,:compare_price,'active',:is_default,:now,:now)");
            $inventoryStatement = $this->pdo->prepare('INSERT INTO inventory (variant_id,physical_quantity,reserved_quantity,updated_at) VALUES (:variant,:stock,0,:now)');
            $movementStatement = $this->pdo->prepare("INSERT INTO inventory_movements (variant_id,type,physical_delta,reserved_delta,reason,created_at) VALUES (:variant,'catalog_seed',:stock,0,'Colecție editorială demo 2026',:now)");
            $mediaLookupStatement = $this->pdo->prepare('SELECT id FROM media WHERE storage_path = :storage LIMIT 1');
            $mediaStatement = $this->pdo->prepare('INSERT INTO media (original_name,storage_path,public_path,mime_type,width,height,size_bytes,alt_text,created_at) VALUES (:name,:storage,:public,:mime,:width,:height,:size,:alt,:now)');
            $productImageStatement = $this->pdo->prepare('INSERT INTO product_images (product_id,media_id,position) VALUES (:product,:media,:position)');

            foreach ($products as $product) {
                $slug = Slugger::slug($product['name']);
                $existingStatement->execute(['slug' => $slug, 'sku' => $product['sku']]);
                if ($existingStatement->fetchColumn() !== false) {
                    continue;
                }

                $category = $categories[$product['category']];
                $productStatement->execute([
                    'name' => $product['name'],
                    'slug' => $slug,
                    'short' => $product['short'],
                    'description' => $product['description'],
                    'category' => $category['id'],
                    'seo' => $product['name'] . ' pentru copii',
                    'meta' => $product['short'],
                    'composition' => $product['composition'],
                    'care' => $product['care'],
                    'size_guide' => $product['size_guide'],
                    'package_contents' => $product['package_contents'],
                    'excluded_accessories' => 'Elementele decorative din fotografie nu sunt incluse.',
                    'featured' => $product['featured'] ? 1 : 0,
                    'now' => $now,
                ]);
                $productId = (int) $this->pdo->lastInsertId();

                $categoryId = $category['id'];
                while (isset($categoriesById[$categoryId])) {
                    $productCategoryStatement->execute(['product' => $productId, 'category' => $categoryId]);
                    $parentId = $categoriesById[$categoryId]['parent_id'];
                    if ($parentId === null) {
                        break;
                    }
                    $categoryId = $parentId;
                }

                $variants = $product['variants'] ?: [[
                    'sku' => $product['sku'],
                    'option_summary' => 'Mărime standard',
                    'options' => [],
                    'price' => $product['price'],
                    'compare_price' => $product['compare_price'],
                    'stock' => $product['stock'],
                    'is_default' => true,
                ]];
                foreach ($variants as $variant) {
                    $optionsJson = json_encode($variant['options'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $variantStatement->execute([
                        'product' => $productId,
                        'sku' => $variant['sku'],
                        'option_summary' => $variant['option_summary'],
                        'options_json' => $optionsJson,
                        'options_key' => hash('sha256', $optionsJson),
                        'price' => $variant['price'],
                        'compare_price' => $variant['compare_price'],
                        'is_default' => $variant['is_default'] ? 1 : 0,
                        'now' => $now,
                    ]);
                    $variantId = (int) $this->pdo->lastInsertId();
                    $inventoryStatement->execute(['variant' => $variantId, 'stock' => $variant['stock'], 'now' => $now]);
                    $movementStatement->execute(['variant' => $variantId, 'stock' => $variant['stock'], 'now' => $now]);
                }

                $productImages = array_merge(
                    [['image' => $product['image'], 'alt' => $product['alt']]],
                    $product['gallery']
                );
                foreach ($productImages as $position => $productImage) {
                    $publicPath = 'assets/images/products/collection-2026/' . $productImage['image'];
                    $storagePath = 'seeded/expanded-catalog-2026/' . $productImage['image'];
                    $mediaLookupStatement->execute(['storage' => $storagePath]);
                    $mediaId = $mediaLookupStatement->fetchColumn();
                    if ($mediaId === false) {
                        $metadata = $imageMetadata[$productImage['image']];
                        $mediaStatement->execute([
                            'name' => $productImage['image'],
                            'storage' => $storagePath,
                            'public' => $publicPath,
                            'mime' => 'image/webp',
                            'width' => $metadata['width'],
                            'height' => $metadata['height'],
                            'size' => $metadata['size'],
                            'alt' => $productImage['alt'],
                            'now' => $now,
                        ]);
                        $mediaId = (int) $this->pdo->lastInsertId();
                    }
                    $productImageStatement->execute([
                        'product' => $productId,
                        'media' => (int) $mediaId,
                        'position' => $position,
                    ]);
                }
                $inserted++;
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $inserted;
    }

    /** @return list<array<string, mixed>> */
    private function products(): array
    {
        $clothingCare = 'Spălare delicată la 30°C, cu articole în culori similare. Nu folosi înălbitor.';
        $accessoryCare = 'Curățare delicată, manuală. Păstrează produsul ferit de umezeală și lumină directă.';
        $footwearCare = 'Curăță cu o lavetă moale, ușor umedă. Lasă produsul să se usuce natural.';
        $standardSizes = 'Alege mărimea potrivită din opțiunile disponibile pe pagina produsului.';

        return [
            $this->product('Salopetă tricotată „Nor de lapte”', 'salopete', 'LM-26-001', 21900, 9, true, 'Salopetă moale, tricotată fin, pentru primele luni.', 'Un model confortabil cu închidere frontală, detalii cu norișori și botoșei asortați, potrivit pentru zile liniștite și primele fotografii.', '100% bumbac moale.', $clothingCare, $standardSizes, 'Salopetă și botoșei asortați.', 'salopeta-nor-de-lapte.webp', 'Salopetă crem tricotată cu norișori și botoșei asortați'),
            $this->product('Compleu bebe „Iepuraș blând”', 'compleuri-bebe', 'LM-26-002', 16900, 12, false, 'Compleu petrecut din două piese, delicat cu pielea bebelușului.', 'Bluza petrecută se așază ușor, iar pantalonii cu botoșei păstrează piciorușele confortabile. Broderia discretă cu iepuraș completează ținuta.', '95% bumbac organic, 5% elastan.', $clothingCare, $standardSizes, 'Bluză petrecută și pantaloni cu botoșei.', 'compleu-iepuras-bland.webp', 'Compleu bebe ivoire și bleu cu iepuraș brodat'),
            $this->product('Pijama bebe „Somn ușor”', 'pijamale-bebe', 'LM-26-003', 12900, 15, false, 'Pijama cu botoșei și capse, creată pentru seri liniștite.', 'Materialul respirabil și croiul lejer oferă libertate de mișcare, iar imprimeul fin cu lună și stele aduce o notă caldă ritualului de seară.', '100% bumbac pieptănat.', $clothingCare, $standardSizes, '', 'pijama-somn-usor.webp', 'Pijama bebe ivoire cu lună și stele roz pudrat'),
            $this->product('Bonetă cu dantelă „Bun venit”', 'caciulite-bonete', 'LM-26-004', 8900, 10, false, 'Bonetă ivoire cu dantelă fină și panglici satinate.', 'Un accesoriu delicat pentru venirea acasă, ședințe foto sau evenimente de familie, finisat cu o mică rozetă roz pudrat.', 'Bumbac fin, dantelă din bumbac și panglică satinată.', $accessoryCare, $standardSizes, '', 'boneta-bun-venit.webp', 'Bonetă ivoire cu dantelă, panglici satinate și rozetă roz'),

            $this->product('Rochiță „Grădina roz”', 'rochite-zi', 'LM-26-005', 18900, 8, true, 'Rochiță de zi cu imprimeu floral și mâneci bufante.', 'Croiul lejer și bumbacul moale o fac potrivită pentru plimbări, aniversări și zile senine, fără să limiteze mișcarea.', '100% bumbac.', $clothingCare, $standardSizes, '', 'rochita-gradina-roz.webp', 'Rochiță roz pudrat cu imprimeu floral delicat'),
            $this->product('Rochie de ocazie „Perla mică”', 'rochite-ocazie', 'LM-26-006', 34900, 6, true, 'Rochie elegantă ivoire și roz pudrat, cu flori brodate.', 'Straturile fine de tul, căptușeala moale și detaliul perlat din talie creează o ținută festivă, confortabilă pentru cele mici.', 'Exterior din tul și satin; căptușeală din bumbac.', $clothingCare, $standardSizes, '', 'rochie-perla-mica.webp', 'Rochie de ocazie ivoire cu tul roz și broderii florale'),
            $this->product('Compleu fetițe „Duminică”', 'compleuri-fetite', 'LM-26-007', 23900, 7, false, 'Bluză cu guler brodat și pantaloni roz pudrat.', 'Un set versatil, cu proporții atent gândite și talie elastică, potrivit atât pentru ieșiri speciale, cât și pentru o zi frumoasă în familie.', 'Bluză din bumbac; pantaloni din amestec de in și bumbac.', $clothingCare, $standardSizes, 'Bluză și pantaloni cu cordon.', 'compleu-duminica.webp', 'Compleu cu bluză ivoire și pantaloni roz pudrat'),
            $this->product('Fustă tutu „Vis de balerină”', 'fuste', 'LM-26-008', 15900, 11, false, 'Fustă tutu în straturi moi, cu fundă satinată.', 'Talia confortabilă și tulul suplu oferă volum delicat fără rigiditate, pentru ținute de sărbătoare sau joacă de poveste.', 'Tul moale, căptușeală din bumbac și betelie satinată.', $clothingCare, $standardSizes, '', 'fusta-vis-de-balerina.webp', 'Fustă tutu roz pudrat cu fundă satinată'),
            $this->product('Cardigan „Norișor”', 'pulovere-fetite', 'LM-26-009', 17900, 9, false, 'Cardigan crem cu model ajurat și flori brodate.', 'Tricotul din bumbac este plăcut la atingere, iar nasturii din lemn și marginile festonate completează aspectul delicat.', '100% bumbac tricotat.', $clothingCare, $standardSizes, '', 'cardigan-norisor.webp', 'Cardigan crem ajurat cu flori roz brodate'),

            $this->product('Compleu băieți „Mica aventură”', 'compleuri-baieti', 'LM-26-010', 29900, 6, true, 'Ținută în patru piese, în tonuri de ivoire, bej și bleu.', 'Cămașa, vesta, pantalonii și papionul formează un set elegant, dar confortabil, pregătit pentru aniversări și evenimente în familie.', 'Bumbac și amestec de in.', $clothingCare, $standardSizes, 'Cămașă, vestă, pantaloni și papion.', 'compleu-mica-aventura.webp', 'Compleu elegant de băieți cu vestă bej și pantaloni bleu'),
            $this->product('Set băieți „Micul urs”', 'tricouri-baieti', 'LM-26-011', 16900, 12, false, 'Bluză ivoire cu ursuleț și pantaloni comozi bej.', 'Un set practic pentru fiecare zi, realizat din materiale moi și prevăzut cu talie elastică și șnur decorativ.', 'Bumbac și catifea raiată moale.', $clothingCare, $standardSizes, 'Bluză și pantaloni.', 'set-micul-urs.webp', 'Set cu bluză ivoire și pantaloni bej pentru băieți'),
            $this->product('Pantaloni chino „Răgaz”', 'pantaloni-baieti', 'LM-26-012', 13900, 14, false, 'Pantaloni chino bej cu croi confortabil și tiv întors.', 'Nuanța neutră îi face ușor de asortat, iar bumbacul moale și talia reglabilă îi păstrează comozi întreaga zi.', '98% bumbac, 2% elastan.', $clothingCare, $standardSizes, '', 'pantaloni-ragaz.webp', 'Pantaloni chino bej pentru băieți'),
            $this->product('Cardigan „Stejar”', 'pulovere-baieti', 'LM-26-013', 18900, 8, false, 'Cardigan bleu cu torsade și coate întărite.', 'Un strat călduros pentru zile răcoroase, cu nasturi din lemn și detalii bej care completează ușor ținutele de sezon.', '100% bumbac tricotat.', $clothingCare, $standardSizes, '', 'cardigan-stejar.webp', 'Cardigan bleu cu torsade și petice bej la coate'),

            $this->product('Ie pentru fetițe „Floare de măr”', 'costume-traditionale-fetite', 'LM-26-014', 25900, 7, true, 'Ie românească pentru fetițe, cu broderie bordo.', 'Pânza naturală și motivele geometrice inspirate din portul românesc alcătuiesc o piesă luminoasă pentru serbări și sărbători.', '100% in; broderie din fir de bumbac.', $clothingCare, $standardSizes, '', 'ie-fetite-floare-de-mar.webp', 'Ie românească albă pentru fetițe cu broderie bordo'),
            $this->product('Costum tradițional „Măriucica”', 'costume-traditionale-fetite', 'LM-26-015', 48900, 5, true, 'Costum tradițional pentru fetițe, cu ie, fotă și brâu.', 'Ansamblul păstrează armonia motivelor românești în nuanțe de bordo și negru, cu un croi adaptat celor mici.', 'Ie din in; fotă și brâu din țesătură cu motive tradiționale.', $clothingCare, $standardSizes, 'Ie, fotă și brâu.', 'costum-traditional-maricica.webp', 'Costum tradițional românesc pentru fetițe în alb și bordo'),
            $this->product('Ie pentru băieți „Brâu de dor”', 'costume-traditionale-baietei', 'LM-26-016', 26900, 7, false, 'Ie românească pentru băieți, cu brâu țesut.', 'Broderia geometrică bordo și neagră conturează o piesă tradițională echilibrată, potrivită pentru serbări și momente de familie.', '100% in; broderie din fir de bumbac.', $clothingCare, $standardSizes, 'Ie și brâu.', 'ie-baieti-brau-de-dor.webp', 'Ie românească pentru băieți cu broderie și brâu bordo'),
            $this->product('Costum tradițional „Micul român”', 'costume-traditionale-baietei', 'LM-26-017', 52900, 4, true, 'Costum tradițional complet pentru băieți.', 'Ia, vesta brodată, pantalonii și brâul formează o ținută autentică și confortabilă, creată pentru sărbători cu suflet.', 'In, bumbac și vestă din țesătură de lână.', $clothingCare, $standardSizes, 'Ie, vestă, pantaloni și brâu.', 'costum-traditional-micul-roman.webp', 'Costum tradițional românesc complet pentru băieți'),

            $this->product('Lumânare de botez „Înger păzitor”', 'lumanari-botez', 'LM-26-018', 32900, 5, true, 'Lumânare ivoire decorată cu dantelă și flori roz pudrat.', 'Compoziția delicată îmbină panglica amplă, florile uscate și un mic pandantiv în formă de înger pentru un moment de neuitat.', 'Ceară, dantelă din bumbac, panglică și flori decorative.', $accessoryCare, 'Înălțime aproximativă: 40 cm.', '', 'lumanare-inger-pazitor.webp', 'Lumânare de botez ivoire cu fundă roz și decor cu înger'),
            $this->product('Cutie de trusou „Prima amintire”', 'cutii-trusou', 'LM-26-019', 24900, 6, false, 'Cutie rotundă ivoire, decorată cu dantelă și panglică.', 'Interiorul generos păstrează trusoul și micile amintiri ale zilei, iar medalionul simplu poate fi personalizat ulterior.', 'Carton rigid premium, hârtie mată, dantelă și panglică satinată.', $accessoryCare, 'Diametru aproximativ: 32 cm.', '', 'cutie-prima-amintire.webp', 'Cutie rotundă ivoire pentru trusou cu panglică roz'),
            $this->product('Set accesorii botez „Alb curat”', 'accesorii-botez', 'LM-26-020', 19900, 8, false, 'Bonetă, bavețică, botoșei și bentiță pentru botez.', 'Piesele coordonate sunt lucrate în ivoire și champagne, cu broderii discrete și panglici moi pentru o apariție armonioasă.', 'Bumbac, dantelă și panglică satinată.', $accessoryCare, $standardSizes, 'Bonetă, bavețică, botoșei și bentiță.', 'accesorii-alb-curat.webp', 'Set ivoire de accesorii pentru botez cu broderii champagne'),
            $this->product('Trusou de botez „Zori de zi”', 'trusouri', 'LM-26-021', 44900, 5, true, 'Trusou complet în ivoire și roz pudrat.', 'Prosopul cu glugă, pânza de mir și accesoriile sunt coordonate prin broderii florale fine și prezentate într-o cutie elegantă.', 'Bumbac absorbant, in, dantelă și satin.', $accessoryCare, 'Mărime universală.', 'Prosop cu glugă, pânză de mir, fașă, săculeț și cutie.', 'trusou-zori-de-zi.webp', 'Trusou complet de botez ivoire cu flori roz pudrat'),

            $this->product('Balerini „Rosa”', 'balerini', 'LM-26-022', 17900, 9, false, 'Balerini roz pudrat cu barete și funde satinate.', 'Forma rotunjită, interiorul moale și talpa flexibilă îi fac potriviți pentru evenimente și pași mici, dar siguri.', 'Exterior și interior din piele ecologică moale; talpă flexibilă.', $footwearCare, $standardSizes, '', 'balerini-rosa.webp', 'Balerini roz pudrat pentru fetițe cu funde'),
            $this->product('Sneakers „Micul pas”', 'sneakers', 'LM-26-023', 22900, 10, true, 'Sneakers ivoire și bleu cu închidere ușoară.', 'Cele două barete reglabile, călcâiul susținut și talpa aderentă însoțesc confortabil plimbările de zi cu zi.', 'Piele ecologică, inserții din piele întoarsă și talpă din cauciuc.', $footwearCare, $standardSizes, '', 'sneakers-micul-pas.webp', 'Sneakers pentru copii în ivoire, bleu și bej'),
            $this->product('Sandale „Lumi”', 'sandale', 'LM-26-024', 18900, 8, false, 'Sandale ivoire și roz cu flori delicate.', 'Baretele moi, călcâiul închis și talpa flexibilă oferă susținere și confort pentru zilele calde.', 'Piele ecologică moale și talpă flexibilă.', $footwearCare, $standardSizes, '', 'sandale-lumi.webp', 'Sandale ivoire și roz pudrat pentru fetițe'),
            $this->product('Ghete „Pădure”', 'ghete', 'LM-26-025', 25900, 7, false, 'Ghete caramel cu guler călduros și fermoar lateral.', 'Modelul robust, dar ușor, protejează piciorul în zilele răcoroase și se încalță rapid datorită fermoarului.', 'Exterior din piele întoarsă ecologică, căptușeală moale și talpă din cauciuc.', $footwearCare, $standardSizes, '', 'ghete-padure.webp', 'Ghete caramel pentru copii cu guler crem călduros'),

            $this->product('Set cadou „Bun venit”', 'seturi-cadou', 'LM-26-026', 34900, 7, true, 'Set cadou pentru nou-născut, pregătit într-o cutie elegantă.', 'Pătura, boneta, botoșeii și micul obiect din lemn sunt coordonate în nuanțe calde și gata să fie dăruite.', 'Bumbac tricotat, lemn natur și ambalaj premium.', $accessoryCare, 'Mărime potrivită pentru 0-3 luni.', 'Pătură, bonetă, botoșei, jucărie din lemn și cutie.', 'set-cadou-bun-venit.webp', 'Set cadou pentru nou-născut în cutie ivoire cu fundă roz'),
            $this->product('Cutie muzicală „Prima poveste”', 'cadouri-pentru-bebelusi', 'LM-26-027', 21900, 6, false, 'Cutie muzicală în formă de carusel, în tonuri calde.', 'Mecanismul delicat și finisajul pictat transformă caruselul într-un obiect decorativ de păstrat în camera copilului.', 'Lemn pictat și mecanism metalic.', $accessoryCare, 'Înălțime aproximativă: 24 cm.', '', 'cutie-muzicala-prima-poveste.webp', 'Cutie muzicală carusel ivoire și roz pudrat'),
            $this->product('Păturică „Îmbrățișare”', 'seturi-cadou', 'LM-26-028', 15900, 12, false, 'Păturică ivoire, moale, cu margine festonată.', 'Textura pufoasă și broderia discretă cu norișor o fac potrivită pentru somn, plimbări și un cadou de bun venit.', '100% bumbac plușat.', $clothingCare, 'Dimensiune aproximativă: 80 × 100 cm.', '', 'paturica-imbratisare.webp', 'Păturică ivoire cu fundă roz și norișor brodat'),

            $this->product('Iepuraș textil „Nani”', 'jucarii', 'LM-26-029', 11900, 14, true, 'Iepuraș moale din in, cu detalii cusute.', 'Urechile lungi, umplutura moale și chipul brodat îl transformă într-un prieten blând pentru joacă și odihnă.', 'Exterior din in și bumbac; umplutură hipoalergenică.', 'Curățare manuală delicată. Verifică periodic cusăturile.', 'Înălțime aproximativă: 32 cm.', '', 'iepuras-textil-nani.webp', 'Iepuraș textil bej cu urechi florale și fundă roz'),
            $this->product(
                'Cuburi moi „Poveste”',
                'jucarii',
                'LM-26-030',
                13900,
                10,
                false,
                'Set de cuburi senzoriale moi, în culori liniștite.',
                'Texturile diferite, formele aplicate și inelele din lemn încurajează explorarea tactilă și joaca atentă.',
                'Bumbac, velur, catifea raiată și umplutură moale.',
                'Curățare manuală. Nu introduce în mașina de uscat.',
                'Latura unui cub: aproximativ 10 cm.',
                'Șase cuburi senzoriale.',
                'cuburi-moi-poveste.webp',
                'Cuburi senzoriale moi în roz, bleu, ivoire și bej',
                [
                    [
                        'image' => 'cuburi-moi-poveste-02.webp',
                        'alt' => 'Cuburi senzoriale moi fotografiate de sus, în roz, bleu, ivoire și bej',
                    ],
                    [
                        'image' => 'cuburi-moi-poveste-03.webp',
                        'alt' => 'Cub senzorial bleu cu lună și stele, alături de cuburile moi din set',
                    ],
                ],
                16900,
                $this->softCubeVariants(),
            ),
        ];
    }

    /**
     * @param list<array{image: string, alt: string}> $gallery
     * @return array<string, mixed>
     */
    private function product(
        string $name,
        string $category,
        string $sku,
        int $price,
        int $stock,
        bool $featured,
        string $short,
        string $description,
        string $composition,
        string $care,
        string $sizeGuide,
        string $packageContents,
        string $image,
        string $alt,
        array $gallery = [],
        ?int $comparePrice = null,
        array $variants = [],
    ): array {
        return compact('name', 'category', 'sku', 'price', 'stock', 'featured', 'short', 'description', 'composition', 'care', 'sizeGuide', 'packageContents', 'image', 'alt', 'gallery', 'variants') + [
            'compare_price' => $comparePrice,
            'size_guide' => $sizeGuide,
            'package_contents' => $packageContents,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function softCubeVariants(): array
    {
        $sizes = [
            ['code' => 'S01', 'label' => '0-6 luni', 'price' => 13900],
            ['code' => 'S02', 'label' => '0-7 luni', 'price' => 13600],
            ['code' => 'S03', 'label' => '0-8 luni', 'price' => 13500],
            ['code' => 'S04', 'label' => '0-9 luni', 'price' => 13000],
            ['code' => 'S05', 'label' => '1 an–1,5 ani', 'price' => 13900],
            ['code' => 'S06', 'label' => '0-3 luni', 'price' => 13900],
            ['code' => 'S07', 'label' => '0-2 luni', 'price' => 14900],
        ];
        $colors = [
            ['code' => 'C01', 'label' => 'Roșu', 'surcharge' => 1000],
            ['code' => 'C02', 'label' => 'Negru', 'surcharge' => 0],
            ['code' => 'C03', 'label' => 'Verde', 'surcharge' => 500],
            ['code' => 'C04', 'label' => 'Galben', 'surcharge' => 1500],
            ['code' => 'C05', 'label' => 'Bej', 'surcharge' => 1200],
            ['code' => 'C06', 'label' => 'Alb', 'surcharge' => 0],
        ];

        $variants = [];
        foreach ($sizes as $sizeIndex => $size) {
            foreach ($colors as $colorIndex => $color) {
                $isDefault = $sizeIndex === 0 && $colorIndex === 0;
                $variants[] = [
                    'sku' => $isDefault ? 'LM-26-030' : sprintf('LM-26-030-%s-%s', $size['code'], $color['code']),
                    'option_summary' => sprintf('Mărime: %s · Culoare: %s · Dimensiune: 1 cm', $size['label'], $color['label']),
                    'options' => ['Mărime' => $size['label'], 'Culoare' => $color['label'], 'Dimensiune' => '1 cm'],
                    'price' => $size['price'] + $color['surcharge'] + 1000,
                    'compare_price' => 16900 + $color['surcharge'] + 1000,
                    'stock' => 10,
                    'is_default' => $isDefault,
                ];
            }
        }

        return $variants;
    }
}
