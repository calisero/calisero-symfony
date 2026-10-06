<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Unit\Webhook;

use Calisero\SymfonySms\Webhook\CallbackUrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class CallbackUrlGeneratorTest extends TestCase
{
    public function testItGivesNothingWhileTheWebhookIsDisabled(): void
    {
        $generator = new CallbackUrlGenerator(null, false, 'secret');

        $this->assertFalse($generator->isEnabled());
        $this->assertNull($generator->generate());
    }

    #[DataProvider('callbackUrls')]
    public function testItGivesTheAbsoluteUrlOfTheWebhookRoute(string $context, string $path, ?string $token, string $expected): void
    {
        $routes = new RouteCollection();
        $routes->add(CallbackUrlGenerator::ROUTE, new Route($path, methods: ['POST']));

        $generator = new CallbackUrlGenerator(new UrlGenerator($routes, RequestContext::fromUri($context)), true, $token);

        $this->assertTrue($generator->isEnabled());
        $this->assertSame($expected, $generator->generate());
    }

    /**
     * @return iterable<string, array{string, string, ?string, string}>
     */
    public static function callbackUrls(): iterable
    {
        yield 'no token' => ['https://app.example.test', '/calisero/webhook', null, 'https://app.example.test/calisero/webhook'];
        yield 'an empty token is no token' => ['https://app.example.test', '/calisero/webhook', '', 'https://app.example.test/calisero/webhook'];
        yield 'a token, encoded' => ['https://app.example.test', '/calisero/webhook', 'a b+c&d', 'https://app.example.test/calisero/webhook?token=a%20b%2Bc%26d'];
        yield 'another path' => ['https://app.example.test', '/hooks/sms', 'secret', 'https://app.example.test/hooks/sms?token=secret'];
        yield 'an application under a base path' => ['https://example.test:8443/shop', '/calisero/webhook', null, 'https://example.test:8443/shop/calisero/webhook'];
    }

    public function testItRefusesWhenTheRouteIsNotLoaded(): void
    {
        $generator = new CallbackUrlGenerator(new UrlGenerator(new RouteCollection(), RequestContext::fromUri('https://app.example.test')), true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('calisero.webhook.enabled is on, but the "calisero_webhook" route is not loaded: import the bundle routes (resource: ".", type: "calisero"), or turn calisero.webhook.enabled off.');

        $generator->generate();
    }

    public function testItRefusesWithoutARouter(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the router is not available');

        (new CallbackUrlGenerator(null, true))->generate();
    }
}
