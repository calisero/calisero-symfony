<?php

declare(strict_types=1);

use Calisero\SymfonySms\Tests\App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// The front controller of the demo application, served by `make serve`: the bundle's
// webhook answers on http://localhost:8096/calisero/webhook while
// CALISERO_WEBHOOK_ENABLED is true in automation/local/.env.
$kernel = Kernel::demo();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
