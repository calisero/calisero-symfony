<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Unit\Webhook;

use Calisero\SymfonySms\Webhook\CallbackUrlGenerator;
use Calisero\SymfonySms\Webhook\WebhookRouteLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Config\ContainerParametersResource;

final class WebhookRouteLoaderTest extends TestCase
{
    #[DataProvider('types')]
    public function testItLoadsTheCaliseroTypeOnly(?string $type, bool $expected): void
    {
        $this->assertSame($expected, (new WebhookRouteLoader())->supports('.', $type));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function types(): iterable
    {
        yield 'calisero' => ['calisero', true];
        yield 'no type' => [null, false];
        yield 'attribute' => ['attribute', false];
        yield 'yaml' => ['yaml', false];
    }

    public function testItLoadsTheWebhookRouteWhileTheWebhookIsEnabled(): void
    {
        $route = (new WebhookRouteLoader(true, '/calisero/webhook'))->load('.', 'calisero')->get(CallbackUrlGenerator::ROUTE);

        $this->assertNotNull($route);
        $this->assertSame('/calisero/webhook', $route->getPath());
        $this->assertSame(['POST'], $route->getMethods());
        $this->assertSame('calisero.webhook.controller', $route->getDefault('_controller'));
    }

    public function testItLoadsNoRouteWhileTheWebhookIsDisabled(): void
    {
        $this->assertCount(0, (new WebhookRouteLoader(false, '/calisero/webhook'))->load('.', 'calisero'));
    }

    #[DataProvider('paths')]
    public function testItTakesThePathWithOrWithoutItsLeadingSlash(string $path, string $expected): void
    {
        $this->assertSame($expected, (new WebhookRouteLoader(true, $path))->load('.', 'calisero')->get(CallbackUrlGenerator::ROUTE)?->getPath());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'with the slash' => ['/hooks/sms', '/hooks/sms'];
        yield 'without the slash, as the Laravel package spells it' => ['calisero/webhook', '/calisero/webhook'];
    }

    /**
     * The routes depend on the configuration, not on a file: the router cache must be
     * rebuilt when it changes, which the parameters resource tells the router.
     */
    public function testTheRoutesTrackTheConfiguration(): void
    {
        $resources = (new WebhookRouteLoader(true, '/hooks/sms'))->load('.', 'calisero')->getResources();

        $this->assertEquals([new ContainerParametersResource(['calisero.webhook.enabled' => true, 'calisero.webhook.path' => '/hooks/sms'])], $resources);
    }
}
