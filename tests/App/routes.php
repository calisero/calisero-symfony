<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// The routes of the bundle, imported the way an application does (config/routes/calisero.yaml)
return static function (RoutingConfigurator $routes): void {
    $routes->import('.', 'calisero');
};
