<?php

declare(strict_types=1);

use Calisero\SymfonySms\Tests\App\Kernel;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

// Every run builds the containers of the test kernels afresh
(new Filesystem())->remove(Kernel::cacheRoot());
