# Utopia Messaging

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/messaging`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/messaging) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Email, SMS, push and chat messages sent through provider adapters (SMTP, SES, Mailgun, Sendgrid, Resend; Twilio, Vonage, Bird, WhatsApp and a dozen more SMS gateways with routing by calling code; FCM and APNs; Discord), with per-recipient results and send telemetry.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-messaging = { git = "https://github.com/utopia-rs/messaging" }
```

# Getting started

`utopia-messaging` sends email, text messages, push notifications and Discord messages through the provider an application configured: SMTP or an email API, one of a dozen SMS gateways, APNs or FCM. A message is checked when it is built, a send reports a result per recipient, and only input no provider could deliver is an error.

## Build an email

Every address is checked when the email is built, as are display names and extra headers. A recipient is a bare address or an entry with a display name.

```rust
use utopia_messaging::{Email, Reason, Recipient};

let email = Email::builder(
    [Recipient::named("john@example.test", "John Doe"), Recipient::from("jane@example.test")],
    "Your order has shipped",
    "It is on its way.",
    "Acme",
    "orders@example.test",
)
.header("List-Unsubscribe", "<https://example.test/u?token=abc>")
.build()?;
assert_eq!(email.to().len(), 2);
assert_eq!(email.reply_to_email(), "orders@example.test");

let refused = Email::builder(["john@c.c"], "Hi", "Body", "Acme", "orders@example.test").build().unwrap_err();
assert!(matches!(refused, utopia_messaging::Error::Invalid { reason: Reason::RecipientDomainInvalid, .. }));
```

## Send a text message

An adapter sends one kind of message through one provider. The response says how many recipients were reached, and why each of the others was not.

```rust
use utopia_messaging::sms::Twilio;
use utopia_messaging::{Adapter, Message, Sms};

let twilio = Twilio::new("AC123", "auth-token").with_from(Some("+15550000000".into()));
let sent = twilio.send(&Message::from(Sms::new(["+15551234567"], "Your code is 123456"))).await?;
let response = sent.response().expect("one adapter, one response");
for result in response.details() {
    println!("{}: {} {}", result.recipient, result.status.as_str(), result.error);
}
```

## Route text messages by country

`GeoSms` sends each recipient through the adapter registered for its calling code, the others through the default adapter, and reports each adapter's response by name.

```rust
use std::sync::Arc;

use utopia_messaging::sms::{GeoSms, Msg91, Twilio, calling_code};
use utopia_messaging::{Adapter, Message, Sms};

let router = GeoSms::new(Arc::new(Twilio::new("AC123", "auth-token")))
    .with_local(calling_code::INDIA, Arc::new(Msg91::new("SENDER", "auth-key", "template-id")));
assert_eq!(calling_code::from_phone_number("+91 98765 43210"), Some("91"));
let sent = router.send(&Message::from(Sms::new(["+919876543210", "+15551234567"], "123456"))).await?;
```

## Send email through SMTP

The SMTP adapter tries each host of the list in order, and every recipient gets the server's own answer: one refused address does not fail the others, a 5xx refusal is marked permanent, and a failure carries the reply code behind it (0 when the server gave none).

```rust
use utopia_messaging::email::Smtp;
use utopia_messaging::{Adapter, Email, Message};

let smtp = Smtp::new("tls://smtp1.example.test:587;smtp2.example.test", 25)
    .with_credentials("user", "secret")
    .with_keep_alive(true);
let email = Email::builder(["john@example.test"], "Hello", "Body", "Acme", "noreply@example.test").build()?;
let sent = smtp.send(&Message::from(email)).await?;
for result in sent.response().map(|r| r.details()).unwrap_or_default() {
    if result.permanent {
        println!("do not retry {} ({}): {}", result.recipient, result.code, result.error);
    }
}
smtp.disconnect().await;
```

## Send a push notification

APNs and FCM sign their own tokens from the credentials given; every device token gets a result, and one the provider no longer knows reads `Expired device token`.

```rust
use utopia_messaging::push::Fcm;
use utopia_messaging::{Adapter, Message, Priority, Push};

let fcm = Fcm::new(service_account_json);
let push = Push::builder(["device-token"]).title("Hello").body("You have a new message").priority(Priority::High).build()?;
let sent = fcm.send(&Message::from(push)).await?;
```

## Count sends with telemetry

Every send records how many recipients were reached and how many were not on the `messaging.send` counter, by provider, type and the message's origin.

```rust
use utopia_messaging::Configure;
use utopia_messaging::sms::Twilio;
use utopia_telemetry::MemoryTelemetry;

let telemetry = MemoryTelemetry::default();
let twilio = Twilio::new("AC123", "auth-token").with_telemetry(&telemetry);
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/messaging`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/messaging). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-messaging
cargo clippy -p utopia-messaging --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

Replay the results PHP recorded for every case against Rust, without PHP:

```bash
COMPAT_SERVICES=1 COMPAT_LIB=messaging cargo test -p compat
```

Without `COMPAT_SERVICES=1` the cases that need a service are skipped.

Run every case on both runtimes, fuzz both with generated inputs, then run the whole conversion check. These need the dev stack (`docker compose up -d`):

```bash
tests/compat/messaging/services.sh up
bin/compat run messaging
bin/compat fuzz messaging
bin/compat check messaging
```

### Benchmark

```bash
bin/compat bench messaging
```

This times every benchmark on PHP and on a release build of Rust, both in containers on the dev stack. It fails if Rust is slower. Add `--write` to record the run in [`tests/compat/messaging/bench.json`](https://github.com/appwrite/appwrite/blob/main/tests/compat/messaging/bench.json). Run it on a quiet machine, one library at a time.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
