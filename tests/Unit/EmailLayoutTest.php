<?php
declare(strict_types=1);
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class EmailLayoutTest extends TestCase
{
    public function testInternalLayoutKeepsValidIconsWhenRendererOmitsOrEmptiesOptionalImageFields(): void
    {
        $root=dirname(__DIR__,2);
        $twig=new Environment(new FilesystemLoader($root.'/resources/views'),['autoescape'=>'html','strict_variables'=>true]);
        $base='https://shop.example.test/boutique/';
        $data=['shop_url'=>$base,'logo_url'=>$base.'assets/images/logo-la-mariuca-transparent.png','subject'=>'Test intern','preheader'=>'Test',
            'alert_title'=>'Necesită atenție','alert_message'=>'Verifică factura','admin_url'=>$base.'admin/spv-efactura/',
            'email'=>'owner@example.test','year'=>'2026','number'=>'Facturare și SPV','date'=>'16.11.2026'];
        foreach([[],['status_icon'=>'','heart_icon_url'=>'']] as $optional) {
            $html=$twig->render('emails/automation-alert.twig',$optional+$data);
            self::assertStringNotContainsString('src=""',$html);
            preg_match_all('/<img[^>]+src="([^"]*)"/',$html,$images);
            self::assertCount(3,$images[1]);
            foreach($images[1] as $url) {
                self::assertStringStartsWith($base.'assets/images/',$url);
                self::assertFileExists($root.'/public/'.substr($url,strlen($base)));
            }
        }
    }
}
