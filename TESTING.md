# Testing

The bundle's tests live in `tests/` and run with PHPUnit. None of them reaches the network: the requests the SDK would
send are answered by a stub transport (`tests/Doubles/StubTransport.php`), which takes the place of the cURL transport
and records each request as it would have gone over the wire.

As of 1.0.0 the suite has **279 tests in 18 classes** (PHPUnit counts the cases of a data provider apart) and covers
100% of the lines, methods and classes of `src/`.

## Running the Tests

```bash
# All tests
composer test

# With a coverage report: text in the terminal, HTML in coverage/, Clover XML in coverage/clover.xml
# (needs pcov or Xdebug; CI uploads the Clover report to Codecov for the README's badge)
composer test-coverage

# One suite, one class, or the tests whose names match a pattern
vendor/bin/phpunit --testsuite integration
vendor/bin/phpunit tests/Unit/SmsClientTest.php
vendor/bin/phpunit --filter DailyLimit

# composer.json validation, code style, static analysis and tests, as CI runs them
composer qa
```

In the Docker development environment, use `make test`, `make test-coverage` and `make qa`, or prefix the commands
with `make shell`. `make test-matrix` runs the suite on every supported pair of PHP and Symfony versions, and
`make test-lowest` with the lowest dependencies `composer.json` allows.

## Supported Versions

CI runs the suite on PHP 8.2, 8.3, 8.4 and 8.5 with Symfony 6.4, 7.4 and 8 (Symfony 8 requires PHP 8.4), and once with
the lowest dependencies on PHP 8.2. Depending on the PHP version, Composer installs PHPUnit 11.5, 12 or 13, so every
test must run on all of them:

- PHPUnit attributes only (`#[DataProvider]`), no docblock annotations: PHPUnit 12 no longer reads them.
- Stubs and recording doubles rather than mocks: no test needs an expectation on a mock.
- Code that differs between Symfony versions is tested on each: `SentMessage::getInfo()` exists since Symfony 7.3,
  and the test kernel sets the options Symfony 6.4 deprecates leaving unset.

PHPStan runs at level 9 on `src/`, `config/` and `tests/`, against Symfony 6.4, 7.4 and 8.

## Test Suites

### Unit Tests (`tests/Unit`)

Plain objects, no kernel: the SDK's services run on a `StubTransport`, as `ClientFactory` builds them.

| Test class | Tests | Covers |
|---|---|---|
| `SmsClientTest` | 65 | `sendSms()`: the request and its JSON body, every option, the snake_case and camelCase aliases, the missing, unknown and wrongly typed parameters, booleans and integers as forms give them, `Stringable` values, `schedule_at` converted to Romania time (summer, winter, other time zones), the webhook's `callback_url` with and without a token, an explicit one, none while disabled, the refusal when the route is missing; `getAccount()` and `getBalance()`, without an account ID; `getMessageStatus()`, `listMessages()` and its pages, `deleteMessage()`; `sendVerification()` and `checkVerification()`; the SDK's exceptions reaching the caller |
| `Client\ClientFactoryTest` | 17 | The SDK's services on one HTTP client, the bearer token, an empty API key failing at the first request and not before, the base URI (configured, given, empty, trailing slash), the timeouts rounded up to whole seconds |
| `Client\UserAgentTest` | 5 | The header: the bundle, PHP, Symfony and the platform, in the format of the other Calisero libraries; replacing the SDK's on the wire; `SmsClient::VERSION` matching the latest version of CHANGELOG.md |
| `Notifier\CaliseroTransportTest` | 13 | The messages supported, the phone and the text, the options, the sender of the message against the DSN's, the `SentMessage` (message ID, transport, the answer); API refusals and missing answers as `CaliseroTransportException`, with its debug; the Notifier events of a sent and of a failed message |
| `Notifier\CaliseroTransportFactoryTest` | 15 | The `calisero` scheme, the API key of the DSN or of the configuration, a DSN without either, the host, port and path of the DSN, the transport's name, the default sender |
| `Notifier\CaliseroOptionsTest` | 5 | The options under the names of `sendSms()`, the schedule converted to Romania time |
| `Webhook\CallbackUrlGeneratorTest` | 8 | The absolute URL of the route, the token encoded, a base path; disabled; the route or the router missing |
| `Webhook\WebhookRouteLoaderTest` | 9 | The `calisero` type, the route while enabled (path, POST, controller), none while disabled, the path with or without its slash, the configuration tracked for the router cache |
| `Event\DeliveryStatusEventTest` | 5 | The payload read with the SDK's `DeliveryWebhookMessage`, once; a payload missing a required field; the message ID and the status of the raw payload |
| `Validator\PhoneE164ValidatorTest` | 20 | Valid and invalid numbers (length, `+`, country code, separators, a trailing line break), `null` and `''`, values that are not strings, a custom message, the error code, the attribute |
| `Validator\SenderIdValidatorTest` | 22 | Valid and invalid sender IDs (length, characters, diacritics, line breaks), `null` and `''`, values that are not strings, custom messages, the error codes, the attribute |

### Integration Tests (`tests/Integration`)

An application around the bundle, `tests/App/Kernel.php` (FrameworkBundle, the bundle, its routes, the Notifier, the
validator and the translator), booted by `tests/IntegrationTestCase.php` on the configuration under test, with the
`StubTransport` in place of the service `calisero.http_transport`.

| Test class | Tests | Covers |
|---|---|---|
| `WebhookTest` | 38 | Over HTTP: the event of each status, none for an unknown or missing status, the typed payload; the `400` of a body that is not a JSON object, the `405` of a GET, no route while disabled, another path; the token, right and wrong in every way (missing, empty, prefix, extension, case, array); the credit thresholds (low, critical, on them, above, strings, disabled) and the daily limit threshold |
| `ConsoleCommandsTest` | 22 | Each command's output and exit code: a sent SMS with the daily limit left and the trace ID, every option of `calisero:sms:test`, the daily limit against the rate limit, field errors, a wrong option, no answer, a wrong key, 403, 404, other errors, 500; the status with its clicks; the account with and without a daily limit, without an account ID; sending and checking a code |
| `BundleTest` | 15 | The default configuration, the invalid ones (a User-Agent setting included), null thresholds; the services autowired; the client and the timeouts built on the configuration; the configuration from environment variables, read only at runtime; the console commands and the Romanian translations registered |
| `NotifierTest` | 6 | `TexterInterface` and the `sms` channel of a notification through the `calisero` transport: the request, the options, the DSN's key and sender, the webhook's callback URL, a refusal |
| `DocumentedConfigurationTest` | 4 | An application booted on the configuration files of `examples/config`: the client, the account, the Notifier and the webhook as documented |
| `ValidationTranslationTest` | 2 | The constraints through the application's validator, in English and in Romanian |

### Contract Tests (`tests/Contract`)

| Test class | Tests | Covers |
|---|---|---|
| `OpenApiContractTest` | 8 | The bundle against the API's OpenAPI document (`resources/openApi/api-v1.json`): its version; every field of a message (through the client and the Notifier), of a verification and of a verification check can be sent; the documented webhook payload is dispatched with its typed fields; every documented status has its event; the documented daily limit refusal is reported |

When the API changes, replace `resources/openApi/api-v1.json` with the new document: the contract tests then show what
the bundle lacks.

## Test Helpers

| Helper | Purpose |
|---|---|
| `tests/Doubles/StubTransport.php` | Answers the SDK's requests with queued responses (`respond()`, `fail()`), records them (`lastRequest()`, `lastPayload()`, `lastHeader()`) |
| `tests/Doubles/EventRecorder.php` | A listener keeping the events it is given (`of()`, `classes()`) |
| `tests/IntegrationTestCase.php` | Boots the test application (`bootKernel()`), gives its services (`service()`, `transport()`), records events (`recordEvents()`), posts webhooks (`postWebhook()`), sets environment variables for one test (`setEnvironmentVariables()`), and puts PHPUnit's error handlers back after FrameworkBundle registered its own |
| `tests/Support/ApiFixtures.php` | What the API answers, after the examples of the OpenAPI document: messages, verifications, accounts, pages, errors, webhook payloads |
| `tests/Support/TestPhones.php` | Phone numbers in the ITU-reserved `+999` range, which can reach no one |
| `tests/App/Kernel.php` | The test application; `Kernel::demo()` is the demo application of the development environment |

## Writing Tests

- Build fixtures from what the API really sends: `ApiFixtures` and the examples of the OpenAPI document.
- **Never use a phone number with a live country code** (`+40`, `+1`, `+44`...), not even an invented one: it may
  belong to a real person. Take the numbers from `TestPhones`, add one there if you need another.
- Check what goes over the wire (method, URI, JSON body, headers) with the `StubTransport`, rather than mocking the SDK.
- Cover the error paths as well as the successful ones: each exception, and what it tells the user.
- Integration tests: boot the application with the configuration under test, then record the events or read the
  `StubTransport`. Set environment variables with `setEnvironmentVariables()`, which restores them after the test.
- Run `composer qa` before opening a pull request.
