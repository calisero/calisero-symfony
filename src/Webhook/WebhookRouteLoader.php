<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Webhook;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Config\ContainerParametersResource;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads the bundle's routes, imported by the application with `type: calisero`:
 *
 *     # config/routes/calisero.yaml
 *     calisero:
 *         resource: .
 *         type: calisero
 *
 * The webhook route, POST calisero.webhook.path, is loaded only while
 * calisero.webhook.enabled is on, so that the route and the callback_url sent to
 * Calisero always agree.
 */
final class WebhookRouteLoader extends Loader
{
    /**
     * The type of the routing resource the application imports.
     */
    public const TYPE = 'calisero';

    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $path = '/calisero/webhook',
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();

        // The routes depend on the configuration, not on a file: rebuild the router
        // cache when either setting changes
        $routes->addResource(new ContainerParametersResource([
            'calisero.webhook.enabled' => $this->enabled,
            'calisero.webhook.path' => $this->path,
        ]));

        if ($this->enabled) {
            $routes->add(CallbackUrlGenerator::ROUTE, new Route(
                path: '/'.ltrim($this->path, '/'),
                defaults: ['_controller' => 'calisero.webhook.controller'],
                methods: ['POST'],
            ));
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }
}
