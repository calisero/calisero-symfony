<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\App;

use Calisero\SymfonySms\CaliseroSmsBundle;
use Calisero\SymfonySms\Event\CreditCriticalEvent;
use Calisero\SymfonySms\Event\CreditLowEvent;
use Calisero\SymfonySms\Event\DailyLimitLowEvent;
use Calisero\SymfonySms\Event\MessageDeliveredEvent;
use Calisero\SymfonySms\Event\MessageFailedEvent;
use Calisero\SymfonySms\Event\MessageSentEvent;
use Calisero\SymfonySms\Tests\Doubles\StubTransport;
use Calisero\SymfonySms\Tests\Fixtures\AutowiredServices;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * A minimal application around the bundle: FrameworkBundle, the bundle and its routes.
 *
 * The tests boot it with the configuration under test and the StubTransport in place of
 * the cURL transport. Kernel::demo() boots it on the real API, configured by the
 * environment (automation/local/.env), for bin/console and public/index.php.
 */
final class Kernel extends BaseKernel
{
    /**
     * @param array<string, mixed> $calisero      the bundle's configuration
     * @param array<string, mixed> $framework     merged over the framework configuration below
     * @param bool                 $stubTransport answer the requests with a StubTransport instead of the API
     * @param list<string>         $configFiles   configuration files loaded after the above, as an application's
     */
    public function __construct(
        string $environment = 'test',
        bool $debug = true,
        private readonly array $calisero = ['api_key' => 'test-api-key'],
        private readonly array $framework = [],
        private readonly bool $stubTransport = true,
        private readonly array $configFiles = [],
    ) {
        parent::__construct($environment, $debug);
    }

    /**
     * The demo application of the development environment (make console, make serve), on
     * the real API: the variables come from automation/local/.env.
     */
    public static function demo(): self
    {
        return new self('dev', true, [
            'api_key' => '%env(CALISERO_API_KEY)%',
            'account_id' => '%env(CALISERO_ACCOUNT_ID)%',
            'webhook' => [
                'enabled' => '%env(bool:CALISERO_WEBHOOK_ENABLED)%',
                'token' => '%env(CALISERO_WEBHOOK_TOKEN)%',
            ],
            'credit' => ['low_threshold' => 100.0, 'critical_threshold' => 20.0],
            'daily_limit' => ['low_threshold' => 50],
        ], [
            'router' => [
                // The host of the callback_url: Calisero must be able to reach it
                'default_uri' => '%env(DEMO_PUBLIC_URL)%',
            ],
            'notifier' => [
                'texter_transports' => ['calisero' => 'calisero://default'],
            ],
        ], false);
    }

    public static function cacheRoot(): string
    {
        return sys_get_temp_dir().'/calisero-symfony-sms';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new CaliseroSmsBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', array_replace_recursive(self::frameworkConfig(), $this->framework));
            $container->loadFromExtension('calisero', $this->calisero);

            if ($this->stubTransport) {
                // Takes the place of the bundle's cURL transport, and stays reachable from the tests
                $container->register('calisero.http_transport', StubTransport::class)->setPublic(true);
            }

            if ('test' === $this->environment) {
                // The 404s and 405s the tests ask for would otherwise be logged on stderr
                $container->register('logger', NullLogger::class);
            } else {
                $listener = $container->register(DemoListener::class);

                foreach ([MessageSentEvent::class, MessageDeliveredEvent::class, MessageFailedEvent::class, CreditLowEvent::class, CreditCriticalEvent::class, DailyLimitLowEvent::class] as $event) {
                    $listener->addTag('kernel.event_listener', ['event' => $event, 'method' => '__invoke']);
                }
            }

            // Framework services the tests use, which the container would otherwise remove as unused
            foreach (['texter', 'notifier', 'validator', 'translator'] as $id) {
                $container->setAlias('test.'.$id, $id)->setPublic(true);
            }

            $container->register(AutowiredServices::class)->setAutowired(true)->setPublic(true);
        });

        foreach ($this->configFiles as $file) {
            $loader->load($file);
        }
    }

    /**
     * This directory, which has no config/ directory: Symfony 8 writes config/reference.php
     * into the project's config/ directory, which would be the bundle's own.
     */
    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return self::cacheRoot().'/'.$this->configurationHash().'/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return self::cacheRoot().'/'.$this->configurationHash().'/log';
    }

    /**
     * @return array<string, mixed>
     */
    private static function frameworkConfig(): array
    {
        $config = [
            'secret' => 'calisero-test-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => [
                'resource' => __DIR__.'/routes.php',
                'utf8' => true,
                // The host of the absolute URLs built outside a request: the callback_url
                'default_uri' => 'https://app.example.test',
            ],
            'validation' => ['enabled' => true],
            'translator' => [
                'enabled' => true,
                'default_path' => __DIR__.'/translations',
                'fallbacks' => ['en'],
            ],
            'notifier' => [
                'texter_transports' => [
                    'calisero' => 'calisero://default?from=CALISERO',
                ],
            ],
        ];

        // Symfony 6.4 deprecates leaving it unset
        if (BaseKernel::VERSION_ID < 70000) {
            $config['validation']['email_validation_mode'] = 'html5';
        }

        return $config;
    }

    private function configurationHash(): string
    {
        return md5(serialize([$this->environment, $this->calisero, $this->framework, $this->stubTransport, $this->configFiles, $this->debug]));
    }
}
