<?php

declare(strict_types=1);

namespace Calisero\SymfonySms;

use Calisero\SymfonySms\Client\ClientFactory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The Calisero SMS bundle, configured under the `calisero` key: the Calisero PHP SDK's
 * services built on that configuration, the SmsClient, the Notifier transport
 * (calisero://), the delivery webhook and its events, the console commands and the
 * validation constraints.
 */
final class CaliseroSmsBundle extends AbstractBundle
{
    protected string $extensionAlias = 'calisero';

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        \assert($root instanceof ArrayNodeDefinition);

        $root
            ->children()
                ->scalarNode('api_key')
                    ->info('Your API key, from the API Keys section of the Calisero dashboard. Use an environment variable: %env(CALISERO_API_KEY)%.')
                    ->defaultNull()
                ->end()
                ->scalarNode('base_uri')
                    ->info('The Calisero API base URI.')
                    ->defaultValue(ClientFactory::DEFAULT_BASE_URI)
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('account_id')
                    ->info('Your account ID, needed by SmsClient::getAccount(), getBalance() and the calisero:account command.')
                    ->defaultNull()
                ->end()
                ->floatNode('timeout')
                    ->info('Request timeout, in seconds. cURL takes whole seconds, so a fraction is rounded up.')
                    ->defaultValue((float) ClientFactory::DEFAULT_TIMEOUT)
                    ->min(0)
                ->end()
                ->floatNode('connect_timeout')
                    ->info('Connection timeout, in seconds, rounded up like the timeout.')
                    ->defaultValue((float) ClientFactory::DEFAULT_CONNECT_TIMEOUT)
                    ->min(0)
                ->end()
                ->arrayNode('webhook')
                    ->info('The delivery status webhook. Import the bundle routes (type: calisero) to register its endpoint.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Registers the webhook route and sends its URL as the callback_url of every message that does not set its own.')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('path')
                            ->info('The path of the webhook route.')
                            ->defaultValue('/calisero/webhook')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('token')
                            ->info('A shared secret: appended as ?token= to the callback URL, and required from every callback.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('credit')
                    ->info('Credit monitoring: CreditLowEvent and CreditCriticalEvent, dispatched by the delivery webhook. Null disables a threshold.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('low_threshold')
                            ->info('Dispatch CreditLowEvent when the remaining balance is at or below this.')
                            ->defaultNull()
                            ->beforeNormalization()->ifNull()->thenUnset()->end()
                        ->end()
                        ->floatNode('critical_threshold')
                            ->info('Dispatch CreditCriticalEvent, instead of CreditLowEvent, when the remaining balance is at or below this.')
                            ->defaultNull()
                            ->beforeNormalization()->ifNull()->thenUnset()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('daily_limit')
                    ->info('Daily sending limit monitoring: DailyLimitLowEvent, dispatched by the delivery webhook. Null disables it.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('low_threshold')
                            ->info('Dispatch DailyLimitLowEvent when the messages the account can still send today are this many or fewer.')
                            ->defaultNull()
                            ->min(0)
                            ->beforeNormalization()->ifNull()->thenUnset()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * The values may be environment variable placeholders, resolved only at runtime: they
     * are handed to the services as they are, never read here.
     *
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $webhook = self::section($config, 'webhook');
        $credit = self::section($config, 'credit');
        $dailyLimit = self::section($config, 'daily_limit');

        // The route loader tracks these two, so the router cache is rebuilt when they change
        $container->parameters()
            ->set('calisero.webhook.enabled', $webhook['enabled'])
            ->set('calisero.webhook.path', $webhook['path']);

        $services = $container->services();

        $services->get('calisero.http_transport')
            ->arg(0, $config['timeout'])
            ->arg(1, $config['connect_timeout']);

        $services->get('calisero.client_factory')
            ->arg('$baseUri', $config['base_uri']);

        $services->get('calisero.sdk_client')
            ->arg(0, $config['api_key']);

        $services->get('calisero.webhook.callback_url_generator')
            ->arg('$token', $webhook['token']);

        $services->get('calisero.sms_client')
            ->arg('$accountId', $config['account_id']);

        $services->get('calisero.webhook.controller')
            ->arg('$token', $webhook['token'])
            ->arg('$creditLowThreshold', $credit['low_threshold'])
            ->arg('$creditCriticalThreshold', $credit['critical_threshold'])
            ->arg('$dailyLimitLowThreshold', $dailyLimit['low_threshold']);

        if ($builder->hasDefinition('calisero.notifier.transport_factory')) {
            $services->get('calisero.notifier.transport_factory')
                ->arg('$defaultApiKey', $config['api_key']);
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $section = $config[$key] ?? [];
        \assert(\is_array($section));

        /** @var array<string, mixed> $section */
        return $section;
    }
}
