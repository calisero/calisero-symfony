<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests;

use Calisero\SymfonySms\Tests\App\Kernel;
use Calisero\SymfonySms\Tests\Doubles\EventRecorder;
use Calisero\SymfonySms\Tests\Doubles\StubTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boots the test application (tests/App/Kernel.php) on the configuration under test, with
 * the StubTransport answering the API requests.
 */
abstract class IntegrationTestCase extends TestCase
{
    private ?Kernel $kernel = null;

    private bool $handlersRemembered = false;

    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    /** @var array<string, array{bool, mixed, bool, mixed}> */
    private array $previousVariables = [];

    protected function tearDown(): void
    {
        $this->shutdownKernel();
        $this->restoreVariables();

        parent::tearDown();
    }

    /**
     * Sets environment variables until the end of the test. Symfony reads $_ENV first,
     * then $_SERVER: the container's own variables (automation/local/.env) are in $_ENV.
     *
     * @param array<string, string> $variables
     */
    protected function setEnvironmentVariables(array $variables): void
    {
        foreach ($variables as $name => $value) {
            $this->previousVariables[$name] ??= [\array_key_exists($name, $_ENV), $_ENV[$name] ?? null, \array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    /**
     * @param array<string, mixed> $calisero    the bundle's configuration
     * @param array<string, mixed> $framework   merged over the test application's framework configuration
     * @param list<string>         $configFiles configuration files loaded after the above
     */
    protected function bootKernel(array $calisero = ['api_key' => 'test-api-key'], array $framework = [], bool $stubTransport = true, array $configFiles = []): Kernel
    {
        $this->shutdownKernel();
        $this->rememberHandlers();

        $this->kernel = new Kernel('test', true, $calisero, $framework, $stubTransport, $configFiles);
        $this->kernel->boot();

        return $this->kernel;
    }

    protected function kernel(): Kernel
    {
        return $this->kernel ?? $this->bootKernel();
    }

    /**
     * The test container: the public services, and the private ones still in use.
     */
    protected function container(): ContainerInterface
    {
        $container = $this->kernel()->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $id, string $class): object
    {
        $service = $this->container()->get($id);
        \assert($service instanceof $class);

        return $service;
    }

    protected function transport(): StubTransport
    {
        return $this->service('calisero.http_transport', StubTransport::class);
    }

    /**
     * Records the events of the given classes dispatched from now on.
     *
     * @param class-string ...$classes
     */
    protected function recordEvents(string ...$classes): EventRecorder
    {
        $recorder = new EventRecorder();
        $dispatcher = $this->service('event_dispatcher', EventDispatcherInterface::class);

        foreach ($classes as $class) {
            $dispatcher->addListener($class, $recorder);
        }

        return $recorder;
    }

    protected function handle(Request $request): Response
    {
        return $this->kernel()->handle($request);
    }

    /**
     * @param array<array-key, mixed>|string $payload a JSON body, or the body as is
     */
    protected function postWebhook(array|string $payload, string $path = '/calisero/webhook'): Response
    {
        return $this->handle(Request::create($path, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: \is_string($payload) ? $payload : json_encode($payload, \JSON_THROW_ON_ERROR)));
    }

    private function restoreVariables(): void
    {
        foreach ($this->previousVariables as $name => [$inEnv, $env, $inServer, $server]) {
            if ($inEnv) {
                $_ENV[$name] = $env;
            } else {
                unset($_ENV[$name]);
            }

            if ($inServer) {
                $_SERVER[$name] = $server;
            } else {
                unset($_SERVER[$name]);
            }
        }

        $this->previousVariables = [];
    }

    private function shutdownKernel(): void
    {
        if (null !== $this->kernel) {
            $this->kernel->shutdown();
            $this->kernel = null;
        }

        $this->restoreHandlers();
    }

    /**
     * FrameworkBundle registers Symfony's error handler when it boots; PHPUnit marks a
     * test that leaves a handler of its own as risky. Remember PHPUnit's, to put it back.
     */
    private function rememberHandlers(): void
    {
        if ($this->handlersRemembered) {
            return;
        }

        $this->errorHandler = self::currentErrorHandler();
        $this->exceptionHandler = self::currentExceptionHandler();
        $this->handlersRemembered = true;
    }

    private function restoreHandlers(): void
    {
        if (!$this->handlersRemembered) {
            return;
        }

        for ($i = 0; $i < 10 && self::currentErrorHandler() !== $this->errorHandler; ++$i) {
            restore_error_handler();
        }

        for ($i = 0; $i < 10 && self::currentExceptionHandler() !== $this->exceptionHandler; ++$i) {
            restore_exception_handler();
        }

        $this->handlersRemembered = false;
    }

    private static function currentErrorHandler(): mixed
    {
        $handler = set_error_handler(null);
        restore_error_handler();

        return $handler;
    }

    private static function currentExceptionHandler(): mixed
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }
}
