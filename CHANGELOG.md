# Changelog

All notable changes to `calisero/symfony-sms` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-06

First release of the official Symfony bundle for the Calisero SMS API, covering version 1.0.14 of the API through `calisero/calisero-php` 2.3: what `calisero/laravel-sms` 1.3 offers a Laravel application, the Symfony way. Symfony 6.4, 7.x and 8.x on PHP 8.2 to 8.5.

### Added
- **Bundle configuration** under the `calisero` key: API key, base URI, account ID, timeouts, webhook, credit and daily sending limit thresholds. Every value may come from an environment variable (`%env(CALISERO_API_KEY)%`): the bundle never reads one while the container is built, only when the application runs. The API key is checked by the first request, so an application without one still boots.
- **`SmsClient`**, autowired by `SmsClientInterface` (service `calisero.sms_client`): `sendSms()`, `getAccount()`, `getBalance()`, `getMessageStatus()`, `listMessages()`, `deleteMessage()`, `sendVerification()` and `checkVerification()`, with the parameters of the Laravel package, in snake_case or camelCase. An unknown parameter or a value of the wrong type is refused with an `\InvalidArgumentException` before any request, so a typo cannot drop an option silently. `schedule_at` accepts a `\DateTimeInterface`, converted to the `Y-m-d H:i:s`, Romania time, the API expects.
- **The SDK's services, autowired**: `MessageService`, `VerificationService`, `OptOutService` and `AccountService` (services `calisero.messages`, `calisero.verifications`, `calisero.opt_outs`, `calisero.accounts`), the `SdkClient` that holds them and the `ClientFactory` that builds them, on another API key too.
- **Notifier transport**, `calisero://API_KEY@default?from=SENDER`, for `TexterInterface` and the `sms` channel of notifications. Without a key in the DSN it uses `calisero.api_key`. `CaliseroOptions` sets the Calisero options of an `SmsMessage` (`shortenUrls()`, `validity()`, `scheduleAt()`, `visibleBody()`, `callbackUrl()`); the `SentMessage` carries the message ID, and the SDK's answer in `getInfo('response')`. An API refusal is a `CaliseroTransportException`, a `TransportExceptionInterface` of the Notifier whose previous exception is the SDK's. The transport needs no `symfony/http-client`.
- **Delivery webhook**: a POST route, loaded by importing the bundle's routes (`type: calisero`) while `calisero.webhook.enabled` is on, with an optional `?token=` checked in constant time. It dispatches `MessageSentEvent`, `MessageDeliveredEvent` and `MessageFailedEvent` (`undelivered`), whose `getMessage()` reads the payload with the SDK's `DeliveryWebhookMessage`, and the monitoring events `CreditLowEvent`, `CreditCriticalEvent` and `DailyLimitLowEvent`. A body that is not a JSON object is answered with a `400`.
- **Callback URL**: while the webhook is enabled, every message that sets no `callback_url`, sent by the client or the Notifier, gets the webhook's absolute URL, with the token. If the route is not loaded, the message is refused with a `\LogicException` rather than sent with a callback URL nothing answers.
- **Console commands**: `calisero:sms:test`, `calisero:sms:status`, `calisero:account`, `calisero:verification:send` and `calisero:verification:check`. They tell the daily sending limit from the request rate limit, list the fields at fault in a `422`, and print the trace ID of every failed request.
- **Validation constraints** `PhoneE164` and `SenderId`, as attributes or objects, with their messages translated into Romanian.
- **User-Agent**: every request names the bundle, PHP, Symfony and the platform, as the other Calisero libraries do, e.g. `Calisero-SMS-Symfony/1.0.0 (PHP 8.4.13; Symfony 7.4.0; linux x86_64)`, in place of the SDK's. It cannot be configured. `SmsClient::VERSION` holds the bundle's version, kept in step with this changelog by a test.
- A Docker based development environment (`automation/`, driven by the `Makefile`): QA, the test matrix of every supported PHP and Symfony version, and a demo application to run the console commands and the webhook against the real API.
- Tests: unit tests, integration tests through an application around the bundle (configuration, HTTP, Notifier, console, translations), and contract tests against the API's OpenAPI document (`resources/openApi/api-v1.json`). CI runs them on PHP 8.2, 8.3, 8.4 and 8.5 with Symfony 6.4, 7.4 and 8.

### Notes
- The bundle never retries a request: the API takes no idempotency key, so a send retried after a timeout could deliver the message twice. The same goes for the Notifier through Messenger: see "Sending Asynchronously" in the README.
- Coming from `calisero/laravel-sms`: the client's methods and parameters are the same; the facade becomes the autowired `SmsClientInterface`, the notification channel the Notifier transport, the Laravel events the `*Event` classes of `Calisero\SymfonySms\Event`, the validation rules the `PhoneE164` and `SenderId` constraints.
