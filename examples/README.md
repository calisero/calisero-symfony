# Calisero Symfony SMS Examples

This directory contains focused, copy-paste friendly examples for common scenarios when integrating the
`calisero/calisero-symfony` bundle into a Symfony application.

> These files are illustrative: they belong in a Symfony application (`src/`, `config/`), not in this repository.
> Each one is a class you can drop in `src/` (rename its namespace if needed), or a configuration file for `config/`.

## Prerequisites

1. Install the bundle (and, for the Notifier and the constraints, their components):
   ```bash
   composer require calisero/calisero-symfony
   composer require symfony/notifier symfony/validator   # optional
   ```
2. Copy the configuration of [`config/`](config) into your application's `config/` directory.
3. Set the variables in `.env.local` (or Symfony's secrets vault):
   ```env
   CALISERO_API_KEY=your-api-key
   CALISERO_ACCOUNT_ID=your-account-id            # optional: account, balance, daily limit
   CALISERO_WEBHOOK_ENABLED=true                  # the webhook route & the callback_url of every message
   CALISERO_WEBHOOK_TOKEN=a-long-random-secret    # required as ?token= from every callback
   CALISERO_DSN=calisero://default?from=MyBrand   # the Notifier transport
   ```

## File Overview

| File | Purpose |
|------|---------|
| `config/packages/calisero.yaml` | Every option of the bundle, with its default |
| `config/routes/calisero.yaml` | The bundle's routes: the delivery webhook |
| `config/packages/notifier.yaml` | Calisero as the SMS transport of the Notifier |
| `send_sms_service.php` | A service sending an SMS through `SmsClientInterface`, every error handled |
| `send_sms_with_shortened_urls.php` | Shortened URLs and their clicks, scheduling, what the answer reports |
| `notifier_texter.php` | An SMS through the Notifier's `TexterInterface`, with `CaliseroOptions` |
| `notification_example.php` | A notification sent by SMS, shaping its own message |
| `verification_controller.php` | Two-factor authentication: send a code, check it |
| `webhook_listeners.php` | Listeners for the delivery status events |
| `credit_monitoring_listeners.php` | Listeners for the credit threshold events |
| `daily_limit.php` | Read the daily sending limit, handle its refusal, listen for `DailyLimitLowEvent` |
| `validation_constraints.php` | `#[PhoneE164]` and `#[SenderId]` on a form model |
| `sdk_services.php` | The SDK's services, autowired: opt-outs, verifications, deleting a message |
| `multi_tenant_client.php` | One API key per tenant, through `ClientFactory` |

The configuration files are tested: the bundle's test suite boots an application on them
(`tests/Integration/DocumentedConfigurationTest.php`), so they cannot drift from the bundle.

---
## 1. Sending an SMS
See: `send_sms_service.php`
- Autowires `SmsClientInterface`, keeps the message ID the delivery webhook reports on
- Handles each refusal apart: field errors (422), the daily sending limit, the request rate limit, the rest
- Logs the trace ID of every failed request: Calisero support can look it up

## 2. Shortened URLs and Scheduling
See: `send_sms_with_shortened_urls.php`
- `'shorten_urls' => true` (or `CaliseroOptions::shortenUrls()`) replaces the links of the text with short ones
- `getShortenedUrls()` lists them, with their click counts once the message is read again
- `schedule_at` accepts a `\DateTimeInterface`, converted to Romania time; a string must be `Y-m-d H:i:s`

## 3. The Notifier
See: `notifier_texter.php` and `notification_example.php`
- `TexterInterface::send()` with an `SmsMessage`; its `from` wins over the DSN's
- `CaliseroOptions` for the Calisero options: `shortenUrls()`, `validity()`, `scheduleAt()`, `visibleBody()`, `callbackUrl()`
- A refusal is a `CaliseroTransportException`: `getApiException()` tells why, `getDebug()` gives the trace ID
- A notification implementing `SmsNotificationInterface` shapes its own SMS

## 4. Verification API (2FA)
See: `verification_controller.php`
- Codes are **generated** by Calisero: 6 characters, case-insensitive
- `brand` (Calisero's default text) or `template` (your text, containing `{code}`)
- `expires_in`: 1 to 10 minutes, 5 by default
- A wrong, expired or exhausted code is a `ValidationException` (422); status `'verified'` when right

**Parameters:**

| Parameter | Required | Type | Constraints |
|-----------|----------|------|-------------|
| `to` (or `phone`) | Yes | string | E.164 format |
| `brand` | Conditional | string | Max 120 chars, required if no template |
| `template` | Conditional | string | Max 600 chars, must contain `{code}`, required if no brand |
| `expires_in` (or `expiresIn`) | No | integer | 1-10 minutes (default: 5) |

## 5. Webhook Events
See: `webhook_listeners.php`
- `MessageSentEvent` (`sent`), `MessageDeliveredEvent` (`delivered`), `MessageFailedEvent` (`undelivered`)
- The raw payload (`getPayload()`) or typed (`getMessage()`, the SDK's `DeliveryWebhookMessage`)
- Calisero retries a callback only when it gets no answer within 2 seconds: keep the listeners fast

## 6. Credit Monitoring
See: `credit_monitoring_listeners.php`
- Reacts to `CreditLowEvent` and `CreditCriticalEvent` (only the latter below the critical threshold)
- A good place to alert the team

## 7. Daily Sending Limit
See: `daily_limit.php`
- Reads the account's limit, what is left today and what was sent
- Tells `DailyLimitExceededException` from the request rate limit, and says how long to wait
- Reacts to `DailyLimitLowEvent` before the limit is reached

## 8. Validation
See: `validation_constraints.php`
- `#[PhoneE164]` and `#[SenderId]` as attributes, or as constraint objects for a value alone
- Messages translated into Romanian; custom messages through the constraints' options

## 9. The SDK's Services and Tenants
See: `sdk_services.php` and `multi_tenant_client.php`
- `OptOutService`, `VerificationService`, `MessageService` and `AccountService`, autowired
- `ClientFactory::make($apiKey)`: the same services on another API key

---
## Production Notes
- Send from a Messenger worker for throughput, but never retry a send that timed out: the API takes no idempotency
  key, so the message could go out twice. The API refuses (422) the same message to the same recipient sent again
  within a few seconds.
- Set `framework.router.default_uri` when the webhook is enabled: workers and commands build the callback URL on it.
- Monitor the credit and the daily sending limit with the threshold events, and alert on them.
- Log `getTraceId()` with every failed request: Calisero support can look it up.
- For 2FA, use the Verifications API instead of generating codes yourself; rate limit the endpoints that send codes
  (Symfony's RateLimiter), and keep the error details from users.

## Support

- [Main README](../README.md)
- [Calisero API Documentation](https://docs.calisero.ro)
- [GitHub Issues](https://github.com/calisero/calisero-symfony/issues)
