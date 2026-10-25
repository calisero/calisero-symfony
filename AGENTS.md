# AGENTS.md: calisero/calisero-symfony

Guidance for AI coding agents. Calisero is a Romanian transactional SMS API (base URL
`https://rest.calisero.ro/api/v1`, `Authorization: Bearer <API key>`). `calisero/calisero-symfony` is its official
Symfony bundle (namespace `Calisero\SymfonySms`, bundle `CaliseroSmsBundle`), built on
[`calisero/calisero-php`](https://github.com/calisero/calisero-php): Symfony 6.4, 7 and 8 on PHP 8.2 to 8.5.

- **Using this library**: you are adding Calisero to a Symfony application.
- **Working on this library**: you are changing this repository.

Every name below exists in `src/` or `config/`; `README.md` documents each feature in full.

## Using this library

### Install and configure

```bash
composer require calisero/calisero-symfony
composer require symfony/notifier    # optional: the calisero:// texter transport
composer require symfony/validator   # optional: the PhoneE164 and SenderId constraints
```

Flex registers the bundle; otherwise add `Calisero\SymfonySms\CaliseroSmsBundle::class => ['all' => true]` to
`config/bundles.php`. Configure it from environment variables, never with literal secrets:

```yaml
# config/packages/calisero.yaml
calisero:
    api_key: '%env(CALISERO_API_KEY)%'          # required to call the API
    account_id: '%env(CALISERO_ACCOUNT_ID)%'    # optional: getAccount(), getBalance(), calisero:account
    # base_uri: 'https://rest.calisero.ro/api/v1'
    # timeout: 10                               # seconds; connect_timeout: 3
    webhook:
        enabled: '%env(bool:CALISERO_WEBHOOK_ENABLED)%'
        token: '%env(CALISERO_WEBHOOK_TOKEN)%'
        # path: /calisero/webhook
    # credit: { low_threshold: ~, critical_threshold: ~ }
    # daily_limit: { low_threshold: ~ }
```

Declare the variables in `.env` without values and set them in `.env.local`, the secrets vault
(`bin/console secrets:set CALISERO_API_KEY`) or the platform's environment. `bin/console config:dump-reference calisero`
lists every option. There is no default sender option: pass `from` per message, or `?from=` in the Notifier DSN.

### Send an SMS

Autowire `Calisero\SymfonySms\SmsClientInterface`:

```php
$response = $this->sms->sendSms([
    'to' => '+40712345678',                              // E.164
    'text' => 'Your order #1001 has shipped.',           // at most 1600 characters
    // 'from' => 'MyShop',                               // only a sender approved for the account
    // 'schedule_at' => new \DateTimeImmutable('+1 hour'), // converted to Romania time
    // 'shorten_urls' => true, 'visible_body' => '...', 'validity' => 24, 'callback_url' => '...'
]);

$message = $response->getData();
$message->getId();                                       // keep it: it matches the delivery events
$message->getStatus();
$response->getResponseMeta()->getTraceId();
```

Parameters accept snake_case or camelCase; an unknown or wrongly typed one throws `\InvalidArgumentException` before
any request. Through the Notifier: `texter_transports: { calisero: '%env(CALISERO_DSN)%' }` with
`CALISERO_DSN=calisero://default?from=MyShop`, then `TexterInterface::send(new SmsMessage($phone, $text))`, with
`Calisero\SymfonySms\Notifier\CaliseroOptions` for the Calisero options. The SDK's `MessageService`,
`VerificationService`, `OptOutService` and `AccountService` are autowired too (opt-outs live there).

### OTP: send and validate

Calisero generates the code (6 characters, letters and digits, case-insensitive), sends it and checks it: never
generate or store it yourself.

```php
use Calisero\Sms\Exceptions\ValidationException;

$this->sms->sendVerification(['to' => $phone, 'brand' => 'MyShop']);
// or 'template' => 'Your MyShop code is {code}'; 'expires_in' => 1 to 10 minutes (5 by default)

try {
    $verification = $this->sms->checkVerification(['to' => $phone, 'code' => $input])->getData();
    // $verification->getStatus() === 'verified'
} catch (ValidationException $e) {
    // wrong or expired code, or too many attempts (422): $e->getMessage() says which
}
```

See `examples/verification_controller.php` for a whole flow.

### Read a message's status

```php
$this->sms->getMessageStatus($messageId)->getData()->getStatus(); // scheduled, sent, delivered, undelivered...
$this->sms->listMessages(page: 2);
$this->sms->deleteMessage($messageId);                            // only while still scheduled
```

A message that stays `sent` was not confirmed by the carrier, which does not mean it failed.

### Delivery webhook

1. Enable it (`calisero.webhook.enabled`, plus a `token`) and import the routes:

   ```yaml
   # config/routes/calisero.yaml
   calisero:
       resource: .
       type: calisero
   ```

   This loads `POST /calisero/webhook` (route `calisero_webhook`) while the webhook is enabled. Every message without
   its own `callback_url` then carries the route's absolute URL with `?token=`.
2. Set `framework.router.default_uri` to the application's public URL, so console commands and Messenger workers
   can build that URL.
3. Listen for the events, each a `DeliveryStatusEvent` (`getPayload()`, `getMessage()`, `getMessageId()`,
   `getStatus()`):

```php
use Calisero\SymfonySms\Event\MessageDeliveredEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class SmsDeliveryListener
{
    #[AsEventListener]
    public function onDelivered(MessageDeliveredEvent $event): void
    {
        // hand slow work to Messenger: the callback waits for this listener
        $this->bus->dispatch(new RecordDelivery($event->getMessageId(), $event->getStatus()));
    }
}
```

`MessageSentEvent` (`sent`), `MessageDeliveredEvent` (`delivered`) and `MessageFailedEvent` (`undelivered`) come with
`CreditLowEvent`, `CreditCriticalEvent` and `DailyLimitLowEvent` when their thresholds are set. The endpoint answers
`200`, `401` for a wrong token, `400` for a body that is not a JSON object.

### Errors and the trace ID

The SDK throws `Calisero\Sms\Exceptions\ApiException` subclasses (`getStatusCode()`, `getTraceId()`):

| Exception | When |
| --- | --- |
| `UnauthorizedException` | 401: missing or invalid API key |
| `ForbiddenException` | 403: not allowed, e.g. deleting a message that is no longer scheduled |
| `NotFoundException` | 404: unknown ID |
| `ValidationException` | 422: `getValidationErrors()` maps each field to its messages |
| `DailyLimitExceededException` | 429 with `daily_limit_exceeded` (`getResetsAt()`); extends `RateLimitedException`, so catch it first |
| `RateLimitedException` | 429: request rate limit, wait `getRetryAfter()` seconds |
| `ServerException` | 500, 502, 503, 504 |
| `TransportException` | no answer: connection failed or timed out |

Through the Notifier the error arrives as a `CaliseroTransportException`: `getApiException()` gives the SDK's,
`getDebug()` the status, the trace ID and the body. The bundle's own `\InvalidArgumentException`, `\RuntimeException`
(missing API key or account ID) and `\LogicException` (webhook enabled without its route) come before any request. Log
the trace ID with every failure and every send. Every answer carries it as `X-Trace-Id` (an error body also as
`trace_id`); the dashboard's Developers → Debug page shows everything that happened to that request.

### Tests

- Unit tests: mock `SmsClientInterface`. Functional tests: replace the service `calisero.http_transport` with a stub
  implementing `Calisero\Sms\Contracts\HttpClientInterface` (see "Testing Your Application" in `README.md`), so
  nothing leaves the test.
- Staging and CI against the real API: use a sandbox API key. It goes through the same validation but sends nothing,
  bills nothing and never counts towards the daily limit; the message is `delivered` at once, callbacks still arrive,
  and a test OTP code shows in the dashboard. `bin/console calisero:sms:test`, `calisero:sms:status`,
  `calisero:account`, `calisero:verification:send` and `calisero:verification:check` try the configuration.

### Rules that matter

- **Phone numbers** in E.164: `+40712345678`, not `0712345678`. Validate input with `#[PhoneE164]`.
- **Length and cost**: one part holds 160 GSM-7 characters, or 70 when the text has Romanian diacritics (ă, â, î, ș,
  ț) or emoji (UCS-2). A longer text is split into parts, each billed as one SMS (`getParts()`); the text takes at
  most 1600 characters. When cost matters, a text without diacritics keeps 160-character parts: an option, not a
  rule.
- **Sender ID**: alphanumeric, at most 11 characters, approved for the account and written exactly as approved;
  otherwise 422 and nothing is sent. Leave `from` out for the default sender. It cannot receive replies. Never take it
  from user input. OTP codes go out from the shared "Verify" sender and need no sender of your own.
- **Scheduling**: the API reads `schedule_at` as `Y-m-d H:i:s`, Romania time (Europe/Bucharest). Pass a
  `\DateTimeInterface` and the bundle converts it; a string is sent as is and must already be in that format and time
  zone.
- **Webhooks**: answer any 2xx within 2 seconds, do the work afterwards (Messenger). Only a timeout or a failed
  connection is retried (at most 5 attempts); a non-2xx answer is not, so a listener that throws loses the update.
  The same update can arrive twice and out of order: key your processing on `messageId` + `status`, and never move a
  message back from `delivered`. The callback URL must be public http(s): with `default_uri` on `localhost` or a
  private address, the API refuses the message; leave the webhook disabled in local development or use a tunnel.
- **429 has two meanings**: the request rate limit (240 requests a minute; retry after `getRetryAfter()`) and the
  daily sending limit (`DailyLimitExceededException`: nothing sent, retrying the same day will not help; it resets at
  midnight, Romania time, `getResetsAt()`). `getResponseMeta()->getDailyRemaining()` reports what is left today.
- **No idempotency key**: the bundle never retries, and you should not retry a send blindly after a timeout or a
  `TransportException`: the SMS may have been sent and billed. Check `listMessages()` first. Route the Notifier's
  `SmsMessage` through a Messenger transport with `retry_strategy: { max_retries: 0 }`.
- **OTP**: `brand` or a `template` holding `{code}`, neither with links. Each code is a billed SMS that counts towards
  the daily limit: rate-limit resends in your application (the RateLimiter component works well).

## Working on this library

Read `CONTRIBUTING.md` (scope, quality gates, standards) and `TESTING.md` (the suite and its helpers).

- **Layout**: `src/CaliseroSmsBundle.php` (configuration tree and extension), `config/services.php` (service
  definitions), `src/SmsClient.php` and `SmsClientInterface.php`, `src/Client/` (SDK wiring, User-Agent),
  `src/Notifier/`, `src/Webhook/` (controller, route loader, callback URL), `src/Event/`, `src/Command/`,
  `src/Validator/`, `translations/`. Tests in `tests/Unit`, `tests/Integration` (through the test app in
  `tests/App`) and `tests/Contract`; examples in `examples/`.
- **OpenAPI**: `resources/openApi/api-v1.json` is the API's contract; `tests/Contract` checks the bundle against it
  (every field the API takes can be sent, every webhook status has its event).
- **Checks**: the environment runs in Docker (`make up`, `make install`; `make help` lists every target).

  ```bash
  make qa            # composer validate --strict, PHP-CS-Fixer, PHPStan level 9, PHPUnit: what CI runs
  make test-matrix   # every supported PHP and Symfony pair
  make test-lowest   # lowest dependencies, PHP 8.2
  ```

  Inside the container (`make shell`), or with PHP on the host: `composer qa`, `composer cs:fix`, `composer stan`,
  `composer test`. The Makefile needs the local `automation/` folder, which is not committed.
- **Conventions**: stay thin (HTTP, DTOs and error mapping belong to `calisero/calisero-php`) and in step with
  `calisero/laravel-sms` (same parameters and names); `declare(strict_types=1)`, `final` classes unless meant to be
  extended, `@internal` for what is not public API; never read a configuration value in `loadExtension()` (it may be an
  env placeholder); 100% coverage of `src/`; no network in tests; phone numbers in tests come from `TestPhones`
  (`+999`, never a live country code).
- **Package contents**: `.gitattributes` keeps tests, `resources/`, `TESTING.md` and the tooling out of the Composer
  archive; `AGENTS.md` ships.
- **Changelog and version**: note changes under `[Unreleased]` in `CHANGELOG.md`; a release sets `SmsClient::VERSION`,
  which a test matches against the latest changelog version.
