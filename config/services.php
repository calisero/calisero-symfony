<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Calisero\Sms\Http\BaseHttpClient;
use Calisero\Sms\Services\AccountService;
use Calisero\Sms\Services\MessageService;
use Calisero\Sms\Services\OptOutService;
use Calisero\Sms\Services\VerificationService;
use Calisero\SymfonySms\Client\ClientFactory;
use Calisero\SymfonySms\Client\SdkClient;
use Calisero\SymfonySms\Command\AccountCommand;
use Calisero\SymfonySms\Command\CheckVerificationCommand;
use Calisero\SymfonySms\Command\SendTestSmsCommand;
use Calisero\SymfonySms\Command\SendVerificationCommand;
use Calisero\SymfonySms\Command\StatusSmsCommand;
use Calisero\SymfonySms\Notifier\CaliseroTransportFactory;
use Calisero\SymfonySms\SmsClient;
use Calisero\SymfonySms\SmsClientInterface;
use Calisero\SymfonySms\Webhook\CallbackUrlGenerator;
use Calisero\SymfonySms\Webhook\WebhookController;
use Calisero\SymfonySms\Webhook\WebhookRouteLoader;
use Symfony\Component\Notifier\Transport\AbstractTransportFactory;

/*
 * The bundle's services. The arguments that come from the `calisero` configuration
 * are set by CaliseroSmsBundle::loadExtension().
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // The SDK's cURL transport. Replace this service to send the requests another way:
    // it must implement Calisero\Sms\Contracts\HttpClientInterface.
    $services->set('calisero.http_transport', BaseHttpClient::class)
        ->factory([ClientFactory::class, 'createTransport'])
        ->args([
            abstract_arg('the request timeout, in seconds'),
            abstract_arg('the connection timeout, in seconds'),
        ]);

    $services->set('calisero.client_factory', ClientFactory::class)
        ->args([
            '$transport' => service('calisero.http_transport'),
            '$baseUri' => abstract_arg('the API base URI'),
        ]);
    $services->alias(ClientFactory::class, 'calisero.client_factory');

    // The SDK's services on the configured API key
    $services->set('calisero.sdk_client', SdkClient::class)
        ->factory([service('calisero.client_factory'), 'make'])
        ->args([abstract_arg('the API key')]);
    $services->alias(SdkClient::class, 'calisero.sdk_client');

    $services->set('calisero.messages', MessageService::class)
        ->factory([service('calisero.sdk_client'), 'messages']);
    $services->alias(MessageService::class, 'calisero.messages');

    $services->set('calisero.verifications', VerificationService::class)
        ->factory([service('calisero.sdk_client'), 'verifications']);
    $services->alias(VerificationService::class, 'calisero.verifications');

    $services->set('calisero.opt_outs', OptOutService::class)
        ->factory([service('calisero.sdk_client'), 'optOuts']);
    $services->alias(OptOutService::class, 'calisero.opt_outs');

    $services->set('calisero.accounts', AccountService::class)
        ->factory([service('calisero.sdk_client'), 'accounts']);
    $services->alias(AccountService::class, 'calisero.accounts');

    $services->set('calisero.webhook.callback_url_generator', CallbackUrlGenerator::class)
        ->args([
            '$urlGenerator' => service('router')->nullOnInvalid(),
            '$enabled' => param('calisero.webhook.enabled'),
            '$token' => abstract_arg('the webhook token'),
        ]);

    $services->set('calisero.sms_client', SmsClient::class)
        ->args([
            '$client' => service('calisero.sdk_client'),
            '$callbackUrls' => service('calisero.webhook.callback_url_generator'),
            '$accountId' => abstract_arg('the account ID'),
        ]);
    $services->alias(SmsClientInterface::class, 'calisero.sms_client');
    $services->alias(SmsClient::class, 'calisero.sms_client');

    // The delivery webhook: its controller, and the route loader of `type: calisero`
    $services->set('calisero.webhook.controller', WebhookController::class)
        ->public()
        ->args([
            '$dispatcher' => service('event_dispatcher'),
            '$token' => abstract_arg('the webhook token'),
            '$creditLowThreshold' => abstract_arg('the low credit threshold'),
            '$creditCriticalThreshold' => abstract_arg('the critical credit threshold'),
            '$dailyLimitLowThreshold' => abstract_arg('the low daily limit threshold'),
        ])
        ->tag('controller.service_arguments');

    $services->set('calisero.webhook.route_loader', WebhookRouteLoader::class)
        ->args([
            '$enabled' => param('calisero.webhook.enabled'),
            '$path' => param('calisero.webhook.path'),
        ])
        ->tag('routing.loader');

    // Console commands
    foreach ([
        'calisero.command.send_test_sms' => SendTestSmsCommand::class,
        'calisero.command.status_sms' => StatusSmsCommand::class,
        'calisero.command.account' => AccountCommand::class,
        'calisero.command.send_verification' => SendVerificationCommand::class,
        'calisero.command.check_verification' => CheckVerificationCommand::class,
    ] as $id => $class) {
        $services->set($id, $class)
            ->args([service('calisero.sms_client')])
            ->tag('console.command');
    }

    // The Notifier transport (calisero://), when symfony/notifier is installed
    if (class_exists(AbstractTransportFactory::class)) {
        $services->set('calisero.notifier.transport_factory', CaliseroTransportFactory::class)
            ->args([
                '$clientFactory' => service('calisero.client_factory'),
                '$callbackUrls' => service('calisero.webhook.callback_url_generator'),
                '$defaultApiKey' => abstract_arg('the API key'),
                '$dispatcher' => service('event_dispatcher')->ignoreOnInvalid(),
            ])
            ->tag('texter.transport_factory');
    }
};
