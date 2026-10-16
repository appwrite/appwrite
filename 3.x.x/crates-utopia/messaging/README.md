# utopia-messaging

Multi-adapter SMS, email, push, and chat messaging for Utopia. Rust port of
[`utopia-php/messaging`](https://github.com/utopia-php/messaging) (PHP SHA
[`4c6df414f9ae`](https://github.com/utopia-php/monorepo/commit/4c6df414f9ae)).

```toml
utopia-messaging = { path = "../utopia-messaging" }
```

```rust
use utopia_messaging::adapter::sms::Mock;
use utopia_messaging::messages::SMS;

let mock = Mock::new("username", "password");
mock.set_endpoint("http://127.0.0.1:15000/mock-sms");
let message = SMS::new(
    vec!["+123456789".into()],
    "Test Content",
    Some("+987654321".into()),
    None,
    None,
);
mock.send(&message)?;
```

## Prelude

```rust
use utopia_messaging::prelude::*;
```

SMS, email, push, and chat adapters live under `adapter` (`adapter::sms::Twilio`, `adapter::email::SMTP`, `adapter::push::APNS`, `adapter::chat::Discord`). Payloads live under `messages` (`messages::SMS`, `messages::Email`). `Helpers\JWT` is `helpers::JWT`.

**Not in prelude:** provider adapters, `AdapterBase`, `GroupedSend`, `HttpClient`, `SequenceClient`,
`Mime`, `MimeMessage`, `SMTPEncryption`, `CallingCode`, `Recipient`,
`RecipientInput`, `MessageKind`.

## Intentional deviations from PHP

| Topic | PHP | Rust |
|-------|-----|------|
| HTTP | `utopia-php/client` + `utopia-php/pools` | [`utopia-client`](../utopia-client) cURL adapter; `request_multi` checks clients out of [`utopia-pools`](../utopia-pools) (std threads, max 25) |
| `request_multi` concurrency | Swoole coroutines, max 25 | std threads, max 25 (`MAX_CONCURRENT_REQUESTS`) |
| Live providers | PHPUnit e2e against paid APIs | Default tests use `utopia-test-wiremock` / `SequenceClient` / an SMTP catcher (Mailpit when `127.0.0.1:11025` is up) |
| SMTP | `utopia-php/smtp` | [`lettre`](https://crates.io/crates/lettre) 0.11 (`smtp-transport`, rustls) |
| JWT `ES256K` | OpenSSL | Not implemented (`AlgorithmNotSupported`); HS256/384/512, RS*, ES256/ES384 are |
| Message type | PHP FQCN string from `getMessageType()` | [`MessageKind`] enum; `class_name()` returns the PHP FQCN |
| Client factory | `$clientFactory` closure | `Adapter::set_client_factory` / `ClientFactory` |

HTTP result shape still matches PHP `buildResult`:

```text
{ url, statusCode, response (JSON object or string), headers (lowercase), error, errorCode }
```

Default `User-Agent` is `Appwrite {Name} Message Sender` when the caller omits
one. JSON / `application/x-www-form-urlencoded` / multipart encoding follows
the PHP `Content-Type` rules. Transport errors set `statusCode` to `0` and do
not throw.

## Adapters

Every PHP adapter class is present.

### SMS (`get_type()` = `"sms"`)

| Adapter | PHP class | `get_name()` | Max / request | Constructor |
|---------|-----------|--------------|---------------|-------------|
| [`SmsMock`](src/adapters/sms/mock.rs) | `Adapter\SMS\Mock` | `Mock` | 1000 | `new(user, secret)`; `set_endpoint` |
| [`Clickatell`](src/adapters/sms/clickatell.rs) | `Adapter\SMS\Clickatell` | `Clickatell` | 500 | `new(api_key, from)` |
| [`Fast2SMS`](src/adapters/sms/fast2sms.rs) | `Adapter\SMS\Fast2SMS` | `Fast2SMS` | 1000 | `new(api_key, sender_id, message_id, use_dlt)` |
| [`GEOSMS`](src/adapters/sms/geosms.rs) | `Adapter\SMS\GEOSMS` | `GEOSMS` | `usize::MAX` | `new(default)`; `set_local(calling_code, adapter)` |
| [`Infobip`](src/adapters/sms/infobip.rs) | `Adapter\SMS\Infobip` | `Infobip` | 1000 | `new(api_base_url, api_key, from)` |
| [`Inforu`](src/adapters/sms/inforu.rs) | `Adapter\SMS\Inforu` | `Inforu` | 100 | `new(sender_id, api_token)` |
| [`Msg91`](src/adapters/sms/msg91.rs) | `Adapter\SMS\Msg91` | `Msg91` | 100 | `new(sender_id, auth_key, template_id)` |
| [`Plivo`](src/adapters/sms/plivo.rs) | `Adapter\SMS\Plivo` | `Plivo` | 1000 | `new(auth_id, auth_token, from)` |
| [`Seven`](src/adapters/sms/seven.rs) | `Adapter\SMS\Seven` | `Seven` | 1000 | `new(api_key, from)` |
| [`Sinch`](src/adapters/sms/sinch.rs) | `Adapter\SMS\Sinch` | `Sinch` | 1000 | `new(service_plan_id, api_token, from)` |
| [`Telesign`](src/adapters/sms/telesign.rs) | `Adapter\SMS\Telesign` | `Telesign` | 1000 | `new(customer_id, api_key)` |
| [`Telnyx`](src/adapters/sms/telnyx.rs) | `Adapter\SMS\Telnyx` | `Telnyx` | 1 | `new(api_key, from)` |
| [`TextMagic`](src/adapters/sms/textmagic.rs) | `Adapter\SMS\TextMagic` | `Textmagic` | 1000 | `new(username, api_key, from)` |
| [`Twilio`](src/adapters/sms/twilio.rs) | `Adapter\SMS\Twilio` | `Twilio` | 1 | `new(account_sid, auth_token, from, messaging_service_sid)` |
| [`Vonage`](src/adapters/sms/vonage.rs) | `Adapter\SMS\Vonage` | `Vonage` | 1 | `new(api_key, api_secret, from)` |

[`CallingCode`](src/adapters/sms/calling_code.rs) ports `Adapter\SMS\GEOSMS\CallingCode`
(`from_phone_number`, country constants). [`MetadataParameter`](src/adapters/sms/msg91.rs)
ports `Adapter\SMS\Msg91\MetadataParameter` (`clientId`, `CRQID`, `UUID`).

GEOSMS `send` returns [`SendResult::Grouped`] keyed by child adapter name.
When a send is split into multiple batches, `CRQID` / `UUID` metadata is
suffixed `-1`, `-2`, … (truncated to 80 characters).

### Email (`get_type()` = `"email"`)

| Adapter | PHP class | `get_name()` | Max / request | Constructor |
|---------|-----------|--------------|---------------|-------------|
| [`EmailMock`](src/adapters/email/mock.rs) | `Adapter\Email\Mock` | `Mock` | 1000 | `new(host, port)` - SMTP to maildev; xMailer `Utopia Mailer` |
| [`SMTP`](src/adapters/email/smtp.rs) | `Adapter\Email\SMTP` | `SMTP` | 1000 | `new(host, port, username, password, smtp_secure, smtp_auto_tls, x_mailer, timeout, keep_alive, timelimit)` |
| [`Mailgun`](src/adapters/email/mailgun.rs) | `Adapter\Email\Mailgun` | `Mailgun` | 1000 | `new(api_key, domain, is_eu)` |
| [`Resend`](src/adapters/email/resend.rs) | `Adapter\Email\Resend` | `Resend` | 100 | `new(api_key)` |
| [`Sendgrid`](src/adapters/email/sendgrid.rs) | `Adapter\Email\Sendgrid` | `Sendgrid` | 1000 | `new(api_key)` |
| [`SES`](src/adapters/email/ses.rs) | `Adapter\Email\SES` | `SES` | 50 | `new(access_key, secret_key, region, session_token)` |

[`Mime`](src/adapters/email/mime.rs) ports `Adapter\Email\Mime` (RFC 5322 render +
`size()`). [`SMTPEncryption`] is `None` / `Implicit` / `StartTls` / `Opportunistic`.
`SMTP::hosts()` is public for host-string parsing tests.

SES bulk vs raw routing, template auto-create, and SigV4 signing match the PHP
tests (`SESRoutingTest`, `SESSigningTest`). Resend uses `/emails/batch` without
attachments and `/emails` per recipient with attachments.

### Push (`get_type()` = `"push"`)

| Adapter | PHP class | `get_name()` | Max / request | Constructor |
|---------|-----------|--------------|---------------|-------------|
| [`APNS`](src/adapters/push/apns.rs) | `Adapter\Push\APNS` | `APNS` | 5000 | `new(auth_key, auth_key_id, team_id, bundle_id, sandbox)` |
| [`FCM`](src/adapters/push/fcm.rs) | `Adapter\Push\FCM` | `FCM` | 5000 | `new(service_account_json)` |

Expired device tokens use PHP `EXPIRED_MESSAGE` (`"Expired device token"`).

### Chat (`get_type()` = `"chat"`)

| Adapter | PHP class | `get_name()` | Max / request | Constructor |
|---------|-----------|--------------|---------------|-------------|
| [`Discord`](src/adapters/chat/discord.rs) | `Adapter\Chat\Discord` | `Discord` | 1 | `new(webhook_url)` - HTTPS + host `discord.com` + non-empty webhook id |

## API reference

### `Adapter`

| Method | Description |
|--------|-------------|
| `get_name` | PHP `getName()` |
| `get_type` | PHP `getType()` (`sms` / `email` / `push` / `chat`) |
| `get_message_type` | PHP `getMessageType()` as [`MessageKind`] |
| `get_max_messages_per_request` | PHP `getMaxMessagesPerRequest()` |
| `send` | Validate type + recipient count, `process`, record telemetry |
| `set_telemetry` | PHP `setTelemetry` |
| `set_client_factory` | Inject HTTP (tests: `SequenceClient`, `RewriteClient`, WireMock) |
| `request` / `request_default` | PHP `request()` |
| `request_multi` | PHP `requestMulti()` |
| `process` | Provider send; default error: `"Adapter does not implement process method."` |

`send` errors:

- `"Invalid message type."`
- `"{Name} can only send {max} messages per request."`

Telemetry counter `messaging.send` with attributes `result` (`success`\|`failure`),
`type`, `provider` (lowercase `get_name()`), and optional `origin`.

### `Response`

PHP `{deliveredTo, type, results: [{recipient, status, error}]}`.
`add_result`: empty or `"0"` error ⇒ `success` (PHP `empty()` / explicit `'0'`).

### Messages

| Type | PHP | Notes |
|------|-----|-------|
| [`SMS`](src/messages/sms.rs) | `Messages\SMS` | `new(to, content, from, attachments, metadata)` |
| [`Email`](src/messages/email.rs) | `Messages\Email` | Recipients: string or `{email, name}` via [`RecipientInput`] |
| [`Attachment`](src/messages/attachment.rs) | `Messages\Email\Attachment` | Path and/or in-memory `content` |
| [`Push`](src/messages/push.rs) | `Messages\Push` | Requires at least one of title, body, data |
| [`DiscordMessage`](src/messages/discord.rs) | `Messages\Discord` | Webhook payload fields |
| [`Priority`](src/priority.rs) | `Priority` | `Normal = 0`, `High = 1` |

`Message::set_origin` is object-safe (`()`). Concrete types also have fluent
`with_origin`.

### `JWT`

PHP `Helpers\JWT::encode`. HMAC uses PHP `JSON_UNESCAPED_SLASHES` + base64url.
HS256 / HS384 / HS512 required; RS256/384/512 and ES256/ES384 via `jsonwebtoken`.

## Tests

Default CI needs no paid APIs. PHPUnit ports:

- Mime, CallingCode, SMTP hosts, SES signing / routing, Resend routing
- GEOSMS routing + metadata suffixes
- Telemetry (`utopia_telemetry::TestAdapter`)
- Mock SMS against WireMock via utopia-test-wiremock (URL, method, `User-Agent`, `X-Username` / `X-Key`, JSON body)
- Other SMS/email adapters against `SequenceClient` canned responses
- SMTP unreachable host (`127.0.0.1:1`) without a live server

SMTP/Mock email send requires Mailpit (`MAIL_CATCHER_HOST`/`MAIL_CATCHER_PORT`, default `127.0.0.1:11025`). SMS providers use `SequenceClient` / utopia-test-wiremock (Twilio-shaped HTTP).

## Benchmarks

Rust: `crates-utopia/messaging/benches/messaging.rs` (`Response::to_array` + Mock
SMS with `NoopClient`). PHP twin: `benchmarks/messaging/`.
