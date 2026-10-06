# Calisero SMS Bundle for Symfony

[![Packagist version](https://img.shields.io/packagist/v/calisero/calisero-symfony.svg?style=flat-square)](https://packagist.org/packages/calisero/calisero-symfony)
[![CI](https://img.shields.io/github/actions/workflow/status/calisero/calisero-symfony/ci.yml?branch=main&label=tests&style=flat-square)](https://github.com/calisero/calisero-symfony/actions/workflows/ci.yml)
[![Coverage](https://img.shields.io/codecov/c/github/calisero/calisero-symfony?style=flat-square)](https://codecov.io/gh/calisero/calisero-symfony)
[![PHP](https://img.shields.io/packagist/dependency-v/calisero/calisero-symfony/php.svg?style=flat-square)](https://www.php.net)
[![Symfony](https://img.shields.io/packagist/dependency-v/calisero/calisero-symfony/symfony/framework-bundle.svg?label=symfony&style=flat-square)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg?style=flat-square)](https://phpstan.org)
[![Calisero PHP SDK](https://img.shields.io/packagist/dependency-v/calisero/calisero-symfony/calisero/calisero-php.svg?label=calisero-php&style=flat-square)](https://github.com/calisero/calisero-php)
[![Code style: Symfony](https://img.shields.io/badge/code%20style-symfony-000000.svg?style=flat-square)](https://cs.symfony.com)
[![License](https://img.shields.io/packagist/l/calisero/calisero-symfony.svg?style=flat-square)](LICENSE.md)
[![Downloads](https://img.shields.io/packagist/dm/calisero/calisero-symfony.svg?style=flat-square)](https://packagist.org/packages/calisero/calisero-symfony)

**Official Symfony bundle for the [Calisero](https://calisero.ro) transactional SMS API.**

It wraps the [Calisero PHP SDK](https://github.com/calisero/calisero-php) and brings it into Symfony the Symfony way:
semantic configuration, autowired services, a Notifier transport, a delivery webhook dispatching events, console
commands and validation constraints. Supports **Symfony 6.4, 7.x and 8.x** on **PHP 8.2 to 8.5**.

## Features

- 🚀 **Autowired client**: `SmsClientInterface` sends SMS and verification codes, reads messages and the account
- 🔔 **Notifier transport**: `calisero://` for `TexterInterface` and the `sms` channel of notifications, no `symfony/http-client` needed
- 🔗 **URL shortening** with click statistics for every link of a message
- 🔐 **Two-factor authentication** with the Verifications API: Calisero generates, sends and checks the codes
- 📬 **Delivery webhook** with token security, dispatched as typed Symfony events
- 📈 **Daily sending limit & credit monitoring** through events and the account API
- ✅ **Validation constraints** `#[PhoneE164]` and `#[SenderId]`, with Romanian translations
- 🧪 **Console commands** to send a test SMS, read a message, the account, send and check codes
- 🧰 **The SDK's services, autowired**: messages, verifications, opt-outs, accounts
- 🧯 **Typed error handling**, with the trace ID of every failed request
- ⚙️ **Environment variables everywhere**: every setting can be `%env(...)%`, read only at runtime
- 🔒 **Type safe**: PHPStan level 9, 100% test coverage, tested on every supported PHP and Symfony version

## Requirements

| Bundle | Symfony | PHP | Calisero PHP SDK | Calisero API |
| --- | --- | --- | --- | --- |
| `^1.0` | 6.4, 7.x, 8.x | 8.2 – 8.5 (Symfony 8 requires PHP 8.4+) | `^2.3` | 1.0.14 |

Composer picks the Symfony release your PHP version allows: on PHP 8.2 and 8.3 you get Symfony 6.4 or 7.x, on PHP 8.4
and 8.5 any of the three. The SDK needs `ext-curl` and `ext-json`.

The Notifier transport needs `symfony/notifier`, the constraints `symfony/validator`; both are optional.

## Installation

```bash
composer require calisero/calisero-symfony
```

With Symfony Flex the bundle is registered for you. Otherwise add it to `config/bundles.php`:

```php
return [
    // ...
    Calisero\SymfonySms\CaliseroSmsBundle::class => ['all' => true],
];
```

### Configure the bundle

Create `config/packages/calisero.yaml`:

```yaml
calisero:
    api_key: '%env(CALISERO_API_KEY)%'
    account_id: '%env(CALISERO_ACCOUNT_ID)%'   # optional: the account, balance and daily limit lookups
```

Declare the variables in `.env`, committed, without their values:

```env
# .env
CALISERO_API_KEY=
CALISERO_ACCOUNT_ID=
```

and set their values in `.env.local`, in your secrets vault (`bin/console secrets:set CALISERO_API_KEY`) or in your
platform's environment:

```env
# .env.local
CALISERO_API_KEY=your-api-key-here
CALISERO_ACCOUNT_ID=your-account-id
```

That is all you need to send. The [Configuration Reference](#configuration-reference) lists every option; the
webhook, the Notifier and the constraints are set up in their own sections below.

## Getting Your API Key

1. Log in to your Calisero account at [https://calisero.ro](https://calisero.ro)
2. Open the **API Keys** section of the dashboard and click **Add Key**
3. Name it after the application and the environment (one key per environment), set IP filters if you need them
4. Copy the key: it is shown only once

> **Security Note**: never commit an API key. Keep it in `.env.local`, in Symfony's secrets vault or in your
> platform's environment. A sandbox key sends no real message and costs nothing: use one in development.

## Usage

### Quick Start: Send an SMS

Autowire `SmsClientInterface`:

```php
use Calisero\SymfonySms\SmsClientInterface;

final class WelcomeSms
{
    public function __construct(private readonly SmsClientInterface $sms) {}

    public function send(string $phone): string
    {
        $response = $this->sms->sendSms([
            'to' => $phone,               // E.164: +40712345678
            'text' => 'Welcome to our app!',
            // 'from' => 'MyBrand',       // only once Calisero approved it
        ]);

        $message = $response->getData();
        $message->getStatus();            // scheduled, sent...

        return $message->getId();         // keep it to match the delivery webhooks
    }
}
```

### Sending Options

Every optional parameter accepts snake_case or camelCase (`shorten_urls` or `shortenUrls`):

```php
$response = $this->sms->sendSms([
    'to' => '+40712345678',
    'text' => 'Your order shipped: https://shop.example.com/orders/123',
    'from' => 'MyBrand',                                 // an approved sender ID
    'shorten_urls' => true,                              // replace the links with short ones
    'schedule_at' => new \DateTimeImmutable('+1 hour'),  // a date-time, or a 'Y-m-d H:i:s' string in Romania time
    'validity' => 24,                                    // hours
    'visible_body' => 'Your order shipped',              // shown in the dashboard and the API instead of the text
    'callback_url' => 'https://example.com/hooks/sms',   // instead of the bundle's webhook
]);
```

- **`schedule_at`**: the API reads it as `Y-m-d H:i:s` in Romania time (`Europe/Bucharest`) and refuses ISO 8601
  strings such as `2026-10-05T10:00:00Z`. Pass a `\DateTimeInterface` and the bundle converts it, daylight saving time
  included; a string is sent as is.
- **`shorten_urls`**: Calisero replaces the `http://` and `https://` links of the text with short ones before sending.
  The short links, and how many times each was opened, come back on the message:

```php
foreach ($response->getData()->getShortenedUrls() as $link) {
    echo $link->getOriginalLink().' -> '.$link->getShortenedLink();
}

// Later, with click statistics
foreach ($this->sms->getMessageStatus($messageId)->getData()->getShortenedUrls() as $link) {
    echo $link->getShortenedLink().': '.$link->getClickCount().' clicks, last '.($link->getLastClick() ?? 'never');
}
```

- **Unknown parameters are refused**: a typo such as `sheduleAt` throws an `\InvalidArgumentException` naming the
  accepted parameters, instead of sending the message without it. So does a value of the wrong type (`'validity' =>
  'two days'`). Strings of digits and booleans as forms give them (`'48'`, `'false'`, `'on'`) are read as such.
- **What the answer reports**: `$response->getResponseMeta()` gives the request's trace ID and, while the account has
  a daily sending limit, how many messages it can still send today:

```php
$meta = $response->getResponseMeta();
$meta->getTraceId();        // quote it to Calisero support
$meta->getDailyLimit();     // null when the account has no daily limit
$meta->getDailyRemaining(); // what is left today, after this message
```

### Verification Codes (2FA)

Calisero generates the code (6 characters, case-insensitive), sends it by SMS and checks it:

```php
// Send a code, with your brand in Calisero's default text...
$response = $this->sms->sendVerification([
    'to' => '+40712345678',
    'brand' => 'MyApp',
    'expires_in' => 5,               // minutes, 1 to 10 (5 by default)
]);

// ...or with your own text, which must contain {code}
$response = $this->sms->sendVerification([
    'to' => '+40712345678',
    'template' => 'Your MyApp code is {code}. It expires in 5 minutes.',
]);

$response->getData()->getExpiresAt();

// Check the code the user typed
$verification = $this->sms->checkVerification([
    'to' => '+40712345678',
    'code' => 'SBMH0f',
])->getData();

if ('verified' === $verification->getStatus()) {
    // The code is right
}
```

A wrong, expired or exhausted code is refused with a `ValidationException` (422), whose message says which. Either
`brand` or `template` is required. A code's SMS counts towards the [daily sending limit](#daily-sending-limit). See
[`examples/verification_controller.php`](examples/verification_controller.php) for a whole flow.

### Messages and the Account

```php
$this->sms->getMessageStatus($messageId); // GetMessageResponse: status, dates, shortened links
$this->sms->listMessages(page: 2);        // PaginatedMessages
$this->sms->deleteMessage($messageId);    // only while it is still scheduled
$this->sms->getAccount();                 // Account: credit, status, daily limit (needs calisero.account_id)
$this->sms->getBalance();                 // float, the account's credit
```

### Through the Notifier

With `symfony/notifier`, Calisero becomes an SMS transport (`TexterInterface`, and the `sms` channel of
notifications):

```bash
composer require symfony/notifier
```

```yaml
# config/packages/notifier.yaml
framework:
    notifier:
        texter_transports:
            calisero: '%env(CALISERO_DSN)%'
```

```env
CALISERO_DSN=calisero://default?from=MyBrand
```

| DSN | Means |
| --- | --- |
| `calisero://default` | The configured API key (`calisero.api_key`) and base URI |
| `calisero://API_KEY@default` | Its own API key |
| `?from=MyBrand` | The sender of the messages that do not set one (optional) |
| `calisero://API_KEY@staging.example.com` | Another host: `https://staging.example.com/api/v1` (a port and a path may follow) |

The transport applies the bundle's configuration like `SmsClientInterface` does: timeouts, User-Agent and the
webhook's `callback_url`. It sends through the SDK, so it needs no `symfony/http-client`.

```php
use Calisero\SymfonySms\Notifier\CaliseroOptions;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

$sms = new SmsMessage('+40712345678', 'Track your order: https://shop.example.com/orders/123');
$sms->options((new CaliseroOptions())
    ->shortenUrls()                         // replace the links with short ones
    ->scheduleAt(new \DateTimeImmutable('+1 hour'))
    ->validity(24)                          // hours
    ->visibleBody('Track your order')       // shown in the dashboard instead
    ->callbackUrl('https://example.com/hooks/sms'));

$sentMessage = $texter->send($sms);
$sentMessage->getMessageId();               // the Calisero message ID
$sentMessage->getInfo('response');          // Symfony 7.3+: the SDK's CreateMessageResponse
```

The sender is the `SmsMessage`'s `from` (third constructor argument), else the DSN's, else Calisero's default.

Notifications go out the same way, through the `sms` channel:

```php
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

$notifier->send(new Notification('Your order has shipped', ['sms']), new Recipient(phone: '+40712345678'));
```

A notification implementing `SmsNotificationInterface` shapes its own `SmsMessage`, Calisero options included: see
[`examples/notification_example.php`](examples/notification_example.php).

When the API refuses a message, the transport throws a `CaliseroTransportException`, a `TransportExceptionInterface`
of the Notifier: `getApiException()` (also its previous exception) gives the SDK's, to tell a
`DailyLimitExceededException` from a `ValidationException`, and `getDebug()` the HTTP status, the trace ID and the
error body.

### Sending Asynchronously

Route the SMS of the Notifier through Messenger to send them from a worker:

```yaml
framework:
    messenger:
        transports:
            sms:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                # A send that timed out may have reached the phone: never retry it blindly
                retry_strategy:
                    max_retries: 0
        failure_transport: failed
        routing:
            'Symfony\Component\Notifier\Message\SmsMessage': sms
```

The bundle never retries a request: the API takes no idempotency key, so a send retried after a timeout could deliver
the message twice. The API refuses (422) the same message to the same recipient sent again within a few seconds.
Retry from your own code when a failure means nothing was sent: a `DailyLimitExceededException` (after
`getResetsAt()`), a `RateLimitedException` (after `getRetryAfter()` seconds), an `UnauthorizedException`...

A worker has no request to build absolute URLs from: set `framework.router.default_uri` (see
[Webhook Handling](#webhook-handling)).

### The SDK's Services

`SmsClientInterface` covers what an application needs most. The Calisero PHP SDK's services are autowired too, built
on the bundle configuration (base URI, timeouts, User-Agent), for everything else:

| Autowire | Service | Gives |
| --- | --- | --- |
| `Calisero\Sms\Services\MessageService` | `calisero.messages` | `create()`, `get()`, `list()`, `delete()` |
| `Calisero\Sms\Services\VerificationService` | `calisero.verifications` | `create()`, `get()`, `list($page, $status)`, `validate()` |
| `Calisero\Sms\Services\OptOutService` | `calisero.opt_outs` | `create()`, `get()`, `list()`, `update()`, `delete()` |
| `Calisero\Sms\Services\AccountService` | `calisero.accounts` | `get($accountId)` |
| `Calisero\SymfonySms\Client\SdkClient` | `calisero.sdk_client` | The four, and the SDK's `HttpClient` (`getLastResponse()`) |
| `Calisero\SymfonySms\Client\ClientFactory` | `calisero.client_factory` | `make($apiKey)`: the same on another API key |

```php
use Calisero\Sms\Dto\CreateOptOutRequest;
use Calisero\Sms\Services\OptOutService;

final class Gdpr
{
    public function __construct(private readonly OptOutService $optOuts) {}

    public function optOut(string $phone): void
    {
        $this->optOuts->create(new CreateOptOutRequest($phone, 'Unsubscribed from the website'));
    }
}
```

One API key per tenant? `ClientFactory::make($apiKey)` builds the services on it, and `new SmsClient($sdkClient,
null, $accountId)` the client: see [`examples/multi_tenant_client.php`](examples/multi_tenant_client.php). The
[SDK's README](https://github.com/calisero/calisero-php) documents every service and DTO.

### Validation Constraints

```bash
composer require symfony/validator
```

```php
use Calisero\SymfonySms\Validator\PhoneE164;
use Calisero\SymfonySms\Validator\SenderId;
use Symfony\Component\Validator\Constraints as Assert;

final class SmsForm
{
    #[Assert\NotBlank]
    #[PhoneE164]                              // +, the country code and the number: 7 to 15 digits
    public ?string $phone = null;

    #[SenderId]                               // 3 to 11 letters, digits, spaces, hyphens and dots
    public ?string $sender = null;
}
```

| Constraint | Options | Error codes |
| --- | --- | --- |
| `PhoneE164` | `message` | `PhoneE164::INVALID_FORMAT_ERROR` |
| `SenderId` | `lengthMessage`, `charactersMessage` | `SenderId::INVALID_LENGTH_ERROR`, `SenderId::INVALID_CHARACTERS_ERROR` |

Like Symfony's own constraints, both let `null` and `''` through (add `NotBlank` to require a value) and report a value
that is not a string as a type error. Their messages are translated into Romanian (`validators` domain); override them
in your `translations/validators.ro.xlf`.

### Webhook Handling

When a message has a `callback_url`, Calisero posts its delivery status there each time it changes. The bundle answers
these callbacks and dispatches them as events.

**1. Enable the webhook** and set a token:

```yaml
# config/packages/calisero.yaml
calisero:
    webhook:
        enabled: '%env(bool:CALISERO_WEBHOOK_ENABLED)%'
        token: '%env(CALISERO_WEBHOOK_TOKEN)%'
        # path: /calisero/webhook
```

```env
# .env (defaults), .env.local or the platform's environment
CALISERO_WEBHOOK_ENABLED=true
CALISERO_WEBHOOK_TOKEN=a-long-random-secret
```

**2. Import the bundle's routes**:

```yaml
# config/routes/calisero.yaml
calisero:
    resource: .
    type: calisero
```

The route, `POST /calisero/webhook` named `calisero_webhook`, is loaded only while the webhook is enabled.

**3. Tell the router your public URL**, for the messages sent outside a request (console commands, Messenger
workers), where it has none to build the callback URL from:

```yaml
# config/packages/routing.yaml
framework:
    router:
        default_uri: '%env(DEFAULT_URI)%'   # e.g. https://shop.example.com
```

While the webhook is enabled, every message that sets no `callback_url`, sent by `SmsClientInterface` or the
Notifier, carries the webhook's absolute URL, e.g. `https://shop.example.com/calisero/webhook?token=…`. If the route
is not loaded, the message is refused with a `\LogicException`, rather than sent with a callback URL nothing answers.

#### Securing the Webhook

With a `token` set, the callback URL carries it as `?token=`, and the endpoint answers `401` to a callback without it,
compared in constant time. If you set a `callback_url` yourself, add the `?token=` to it. Without a token the endpoint
is open to anyone who knows its URL (POST only): prefer a token, or other controls (IP allow-list, WAF).

To rotate the token, update `CALISERO_WEBHOOK_TOKEN` and deploy: messages sent from then on carry the new one, and the
callbacks of the messages sent before still carry the old one, refused from then on.

#### Listening for the Events

```php
use Calisero\SymfonySms\Event\MessageDeliveredEvent;
use Calisero\SymfonySms\Event\MessageFailedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class SmsDeliveryListener
{
    #[AsEventListener]
    public function onDelivered(MessageDeliveredEvent $event): void
    {
        $message = $event->getMessage();     // the SDK's DeliveryWebhookMessage, null when a required field is missing

        $message?->getMessageId();           // string
        $message?->getDeliveredAt();         // ?string
        $message?->getPrice();               // float
        $message?->getDailyRemaining();      // ?int, null when the account has no daily limit
    }

    #[AsEventListener]
    public function onFailed(MessageFailedEvent $event): void
    {
        $event->getMessageId();
        $event->getPayload();                // the raw payload, as Calisero sent it
    }
}
```

| Status | Event | Meaning |
| --- | --- | --- |
| `sent` | `MessageSentEvent` | Accepted and handed to the network |
| `delivered` | `MessageDeliveredEvent` | The handset or the network confirmed the delivery |
| `undelivered` | `MessageFailedEvent` | The delivery failed for good (`failed`, posted by hand, too) |

The three extend `DeliveryStatusEvent`: `getPayload()`, `getMessage()`, `getMessageId()`, `getStatus()`. The webhook
also dispatches the monitoring events of [Credit Monitoring](#credit-monitoring) and
[Daily Sending Limit](#daily-sending-limit).

The endpoint answers `200` `{"ok": true}`, `401` for a wrong token and `400` for a body that is not a JSON object.
Calisero retries a callback only when the connection fails or your endpoint does not answer within 2 seconds (at most
5 attempts); a non-2xx answer is not retried. Keep the listeners fast: hand slow work to Messenger.

Payload example:

```json
{
  "price": 0.0378,
  "sender": "CALISERO",
  "sentAt": "2025-09-19T11:59:44.000000Z",
  "status": "delivered",
  "messageId": "019961d8-3338-700c-be17-10d061f03a5c",
  "recipient": "+40742***350",
  "scheduleAt": "2025-09-19T11:59:42.000000Z",
  "deliveredAt": "2025-09-19T12:00:24.000000Z",
  "remainingBalance": 999.43,
  "dailyLimit": 1000,
  "dailyRemaining": 588,
  "sentToday": 412
}
```

`dailyLimit` and `dailyRemaining` are `null` while the account has no daily sending limit. A failed delivery has
`"status": "undelivered"` and a `deliveredAt` of `null`.

### Console Commands

```bash
# Send a test SMS: --from, --text, --visible-body, --validity (hours), --schedule-at (Y-m-d H:i:s, Romania time),
# --callback-url and --shorten-urls
bin/console calisero:sms:test +40712345678 --from=MyApp --text="Test message"

# A message, its status and its shortened links with their clicks
bin/console calisero:sms:status 019961d8-3338-700c-be17-10d061f03a5c

# The account of calisero.account_id: credit, status, sandbox, daily limit
bin/console calisero:account

# Send a verification code, with a brand or a template
bin/console calisero:verification:send +40712345678 --brand=MyApp
bin/console calisero:verification:send +40712345678 --template="Your code is {code}" --expires-in=5

# Check a code
bin/console calisero:verification:check +40712345678 SBMH0f
```

A refusal prints the API's message and the trace ID, tells the daily sending limit from the request rate limit, and
lists the fields at fault in a `422`; the command then exits with `1`.

## Configuration Reference

```yaml
# config/packages/calisero.yaml
calisero:
    api_key: '%env(CALISERO_API_KEY)%'             # required to call the API
    base_uri: 'https://rest.calisero.ro/api/v1'
    account_id: '%env(CALISERO_ACCOUNT_ID)%'
    timeout: 10                                    # seconds, rounded up to whole seconds
    connect_timeout: 3
    webhook:
        enabled: false
        path: /calisero/webhook
        token: null
    credit:
        low_threshold: null                        # CreditLowEvent at or below this balance
        critical_threshold: null                   # CreditCriticalEvent at or below this balance
    daily_limit:
        low_threshold: null                        # DailyLimitLowEvent at or below this many messages left
```

`bin/console config:dump-reference calisero` prints it, with the description of every option. Every value may come
from an environment variable, read only when the application runs; cast the ones that are not strings:
`%env(bool:CALISERO_WEBHOOK_ENABLED)%`, `%env(float:CALISERO_TIMEOUT)%`, `%env(int:CALISERO_DAILY_LIMIT_LOW)%`, and
declare every variable in `.env` with its default (`CALISERO_WEBHOOK_ENABLED=false`, `CALISERO_ACCOUNT_ID=`): an
empty account ID or token counts as none. An empty API key is checked by the first request, which then throws a
`\RuntimeException` naming `calisero.api_key`: an application without one still boots, and fails only where it calls
the API.

| Suggested variable | Option | Description |
| --- | --- | --- |
| `CALISERO_API_KEY` | `api_key` | Your API key, from the dashboard |
| `CALISERO_ACCOUNT_ID` | `account_id` | Your account ID, for `getAccount()`, `getBalance()` and `calisero:account` |
| `CALISERO_WEBHOOK_ENABLED` | `webhook.enabled` | Load the webhook route and send its URL as the `callback_url` |
| `CALISERO_WEBHOOK_TOKEN` | `webhook.token` | Shared secret required from every callback |
| `CALISERO_DSN` | `framework.notifier.texter_transports` | The Notifier transport, e.g. `calisero://default?from=MyBrand` |

## User-Agent

Every request names the bundle and its version, PHP, Symfony and the platform, as the other Calisero libraries do, so
Calisero can tell the bundle's requests apart and see which versions sent them:

```
User-Agent: Calisero-SMS-Symfony/1.0.1 (PHP 8.4.13; Symfony 7.4.0; linux x86_64)
```

The header cannot be configured: a request whose `User-Agent` starts with `Calisero-SMS-Symfony/` always comes from
this bundle.

## Sender ID (Alphanumeric) Requirements

Custom alphanumeric sender IDs (the `from` parameter) must be **approved by Calisero** before they can be used in
production. With an unapproved sender the API may refuse the message (422), or the gateway substitute a default one.

- Length: 3–11 characters; letters, digits, spaces, hyphens (`-`) and dots (`.`), as the `SenderId` constraint checks.
- No fully numeric sender IDs unless explicitly provisioned; avoid trademarks you do not own.
- To request one: Calisero dashboard → Sender IDs, with a short business justification.
- In multi-tenant applications, map tenants to approved senders: never take a sender from user input.

Without an approved sender, leave `from` out and Calisero assigns its default one.

## Credit Monitoring

Set thresholds, and every delivery callback compares the account's remaining balance with them:

```yaml
calisero:
    credit:
        low_threshold: 500        # CreditLowEvent when remainingBalance <= 500
        critical_threshold: 100   # CreditCriticalEvent when remainingBalance <= 100
```

```php
use Calisero\SymfonySms\Event\CreditCriticalEvent;
use Calisero\SymfonySms\Event\CreditLowEvent;

#[AsEventListener]
public function onCreditLow(CreditLowEvent $event): void
{
    $this->logger->warning('Calisero credit low', ['remaining' => $event->getRemainingBalance()]);
}

#[AsEventListener]
public function onCreditCritical(CreditCriticalEvent $event): void
{
    $this->logger->critical('Calisero credit CRITICAL', ['remaining' => $event->getRemainingBalance()]);
}
```

At or below the critical threshold only `CreditCriticalEvent` is dispatched. Leave a threshold `null` to disable it.
The webhook must be enabled.

## Daily Sending Limit

Every Calisero account has its own daily sending limit. Each real message counts once, whatever its number of parts,
verification codes included; test messages (a sandbox account or API key) never count. The day ends at midnight,
Romania time (`Europe/Bucharest`).

**Where to read it**

```php
// The account (needs calisero.account_id); bin/console calisero:account shows it too
$account = $this->sms->getAccount();
$account->getDailyLimit();     // ?int, null when no limit applies
$account->getDailyRemaining(); // ?int
$account->getSentToday();      // int

// After each message or verification code you create
$response->getResponseMeta()->getDailyRemaining();
```

**When it is reached**, the API answers `429` and the SDK throws `DailyLimitExceededException`: nothing was sent and
nothing billed. It extends `RateLimitedException`, so catch it first to tell it from the request rate limit (240
requests a minute):

```php
use Calisero\Sms\Exceptions\DailyLimitExceededException;
use Calisero\Sms\Exceptions\RateLimitedException;

try {
    $this->sms->sendSms(['to' => '+40712345678', 'text' => 'Hello!']);
} catch (DailyLimitExceededException $e) {
    $e->getDailyLimit();  // e.g. 1000
    $e->getResetsAt();    // e.g. 2026-10-07T00:00:00+03:00
    $e->getRetryAfter();  // seconds until midnight, Romania time
} catch (RateLimitedException $e) {
    $e->getRetryAfter();  // seconds; the request rate limit frees up quickly
}
```

**Before it is reached**, set a threshold and listen for `DailyLimitLowEvent`, dispatched by the delivery webhook
(which must be enabled) whenever the messages left today are at or below it:

```yaml
calisero:
    daily_limit:
        low_threshold: 100
```

```php
use Calisero\SymfonySms\Event\DailyLimitLowEvent;

#[AsEventListener]
public function onDailyLimitLow(DailyLimitLowEvent $event): void
{
    $this->logger->warning('Calisero daily limit almost reached', [
        'limit' => $event->getDailyLimit(),
        'remaining' => $event->getDailyRemaining(),
        'sent_today' => $event->getSentToday(),
    ]);
}
```

To raise the limit, contact Calisero.

## Error Handling

The SDK throws a typed exception for every error status, carrying the API's own message:

```php
use Calisero\Sms\Exceptions\ApiException;
use Calisero\Sms\Exceptions\DailyLimitExceededException;
use Calisero\Sms\Exceptions\RateLimitedException;
use Calisero\Sms\Exceptions\UnauthorizedException;
use Calisero\Sms\Exceptions\ValidationException;

try {
    $this->sms->sendSms(['to' => '+40712345678', 'text' => 'Hello!']);
} catch (UnauthorizedException $e) {
    // 401: the API key is missing or invalid
} catch (ValidationException $e) {
    // 422: $e->getValidationErrors() lists the fields at fault
} catch (DailyLimitExceededException $e) {
    // 429: the daily sending limit is reached until $e->getResetsAt()
} catch (RateLimitedException $e) {
    // 429: the request rate limit, retry after $e->getRetryAfter() seconds
} catch (ApiException $e) {
    // 403, 404, 5xx, no answer...: $e->getStatusCode()
    $this->logger->error('Calisero error: '.$e->getMessage(), ['trace_id' => $e->getTraceId()]);
}
```

| Exception | Status |
| --- | --- |
| `UnauthorizedException` | 401 |
| `ForbiddenException` | 403 |
| `NotFoundException` | 404 |
| `ValidationException` | 422 |
| `DailyLimitExceededException` (extends `RateLimitedException`) | 429, `code: daily_limit_exceeded` |
| `RateLimitedException` | 429 |
| `ServerException` | 500, 502, 503, 504 |
| `ApiException` (the parent of all of the above) | any other error |
| `TransportException` | no answer (connection failed, timeout) |

All in `Calisero\Sms\Exceptions`. Every `ApiException` has `getTraceId()`: quote it to Calisero support, or look the
request up in the dashboard under Developers → Debug. The bundle's own `\InvalidArgumentException` (a missing, unknown
or wrongly typed parameter), `\RuntimeException` (a missing API key or account ID) and `\LogicException` (the webhook
enabled without its route) report a mistake before any request is made. Through the Notifier, API errors arrive
wrapped in a `CaliseroTransportException`.

## Coming from calisero/laravel-sms

The client's methods and parameters are the same as the Laravel package's:

| Laravel package | This bundle |
| --- | --- |
| `Calisero::sendSms([...])` (facade) | `SmsClientInterface::sendSms([...])` (autowired) |
| `config/calisero.php`, `CALISERO_*` variables | `config/packages/calisero.yaml`, `%env(CALISERO_*)%` |
| Notification channel `calisero`, `SmsMessage` | Notifier transport `calisero://`, `SmsMessage` + `CaliseroOptions` |
| `MessageSent`, `MessageDelivered`, `MessageFailed` | `MessageSentEvent`, `MessageDeliveredEvent`, `MessageFailedEvent` |
| `$event->messageData`, `$event->message()` | `$event->getPayload()`, `$event->getMessage()` |
| `CreditLow`, `CreditCritical`, `DailyLimitLow` | `CreditLowEvent`, `CreditCriticalEvent`, `DailyLimitLowEvent` |
| `Rule::phoneE164()`, `Rule::senderId()` | `#[PhoneE164]`, `#[SenderId]` |
| `php artisan calisero:…` | `bin/console calisero:…` (same names and options) |
| Route registered when enabled | Routes imported once (`type: calisero`), loaded when enabled |

Unlike the Laravel package, the bundle refuses unknown `sendSms()` parameters instead of ignoring them.

## Testing Your Application

Mock `SmsClientInterface` in unit tests. In functional tests, replace the bundle's transport, the service
`calisero.http_transport`, with a stub implementing `Calisero\Sms\Contracts\HttpClientInterface`: everything else
(the client, the SDK's services, the Notifier transport) then runs for real, without a request leaving the test.

```yaml
# config/services_test.yaml
services:
    calisero.http_transport:
        class: App\Tests\Calisero\FakeCaliseroApi
        public: true
```

```php
namespace App\Tests\Calisero;

use Calisero\Sms\Contracts\HttpClientInterface;
use Calisero\Sms\Http\RequestInterface;
use Calisero\Sms\Http\Response;
use Calisero\Sms\Http\ResponseInterface;

final class FakeCaliseroApi implements HttpClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new Response(201, ['Content-Type' => ['application/json']], json_encode(['data' => [
            'id' => '9e2574e8-3615-4090-9b5a-0fc812079da8', 'recipient' => '+40712345678', 'body' => 'Hello',
            'parts' => 1, 'created_at' => '2026-01-01T00:00:00.000000Z', 'status' => 'scheduled',
        ]]));
    }
}
```

The bundle's own tests work the same way: see [TESTING.md](TESTING.md).

## Examples

Copy-paste friendly examples for a Symfony application live in [`examples/`](examples):

| Scenario | File |
| --- | --- |
| Configuration: bundle, routes, Notifier | `examples/config/` |
| Send from a service, with error handling | `examples/send_sms_service.php` |
| Shortened URLs, scheduling, the daily limit left | `examples/send_sms_with_shortened_urls.php` |
| Send through the Notifier | `examples/notifier_texter.php` |
| A notification sent by SMS | `examples/notification_example.php` |
| Two-factor authentication controller | `examples/verification_controller.php` |
| Delivery webhook listeners | `examples/webhook_listeners.php` |
| Credit monitoring listeners | `examples/credit_monitoring_listeners.php` |
| Daily sending limit (account, refusal, `DailyLimitLowEvent`) | `examples/daily_limit.php` |
| Validation constraints | `examples/validation_constraints.php` |
| The SDK's services: opt-outs, verifications | `examples/sdk_services.php` |
| One API key per tenant | `examples/multi_tenant_client.php` |

See the [Examples README](examples/README.md).

## Development

The development environment runs in Docker: nothing but Docker is needed on your machine.

```bash
make up            # Build and start the PHP container (automation/local/docker-compose.yml)
make install       # composer install, in the container
make qa            # Everything CI runs: composer validate, PHP-CS-Fixer, PHPStan, PHPUnit
make test-matrix   # The tests on PHP 8.2 to 8.5, with Symfony 6.4, 7.4 and 8
make help          # Every target
```

[CONTRIBUTING.md](CONTRIBUTING.md) explains the workflow, [automation/README.md](automation/README.md) the
environment, including a demo application to try the bundle against the real API.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email support@calisero.ro instead of using the issue tracker.

## License

The MIT License (MIT). Please see the [License File](LICENSE.md) for more information.

## Support

- 📖 Documentation: [https://docs.calisero.ro](https://docs.calisero.ro)
- 🐘 Calisero PHP SDK: [calisero/calisero-php](https://github.com/calisero/calisero-php)
- 🐛 Issues: [GitHub Issues](https://github.com/calisero/calisero-symfony/issues)
- 📧 Email: support@calisero.ro
