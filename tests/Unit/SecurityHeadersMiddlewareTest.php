<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Config\Config;
use App\Shared\Http\SecurityHeadersMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    #[DataProvider('responseStatuses')]
    public function testFormsCanFollowOnlyLocalAndExactHostedStripeDestinations(int $status): void
    {
        $response = (new Response($status))->withHeader('Referrer-Policy', 'no-referrer');
        if ($status === 303) { $response = $response->withHeader('Location', 'https://checkout.stripe.com/c/pay/test'); }
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($response);
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/plata/token/reincearca/');
        $result = (new SecurityHeadersMiddleware(Config::load(__DIR__)))->process($request, $handler);
        $directives = [];
        foreach (explode(';', $result->getHeaderLine('Content-Security-Policy')) as $directive) {
            [$name, $value] = explode(' ', trim($directive), 2);
            $directives[$name] = $value;
        }
        self::assertSame("'self' https://checkout.stripe.com https://checkout.stripe.dev", $directives['form-action']);
        self::assertSame("'self'", $directives['script-src']);
        self::assertSame("'self'", $directives['default-src']);
        self::assertSame("'none'", $directives['frame-ancestors']);
        self::assertStringNotContainsString('*', $result->getHeaderLine('Content-Security-Policy'));
        self::assertSame('no-referrer', $result->getHeaderLine('Referrer-Policy'));
        self::assertSame($status, $result->getStatusCode());
        if ($status === 303) { self::assertSame('https://checkout.stripe.com/c/pay/test', $result->getHeaderLine('Location')); }
    }

    public static function responseStatuses(): array
    {
        return [[200], [303]];
    }
}
