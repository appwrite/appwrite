# Getting started

`utopia-messaging` sends email, text messages, push notifications and Discord messages through the provider an application configured: SMTP or an email API, one of a dozen SMS gateways, APNs or FCM. A message is checked when it is built, a send reports a result per recipient, and only input no provider could deliver is an error.

## Build an email

Every address is checked when the email is built, as are display names and extra headers. A recipient is a bare address or an entry with a display name.

```
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
# Ok::<(), utopia_messaging::Error>(())
```

```php
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Messages\Email;

$email = new Email(
    to: [['email' => 'john@example.test', 'name' => 'John Doe'], 'jane@example.test'],
    subject: 'Your order has shipped',
    content: 'It is on its way.',
    fromName: 'Acme',
    fromEmail: 'orders@example.test',
    headers: ['List-Unsubscribe' => '<https://example.test/u?token=abc>'],
);
echo count($email->getTo()), "\n"; // 2
echo $email->getReplyToEmail(), "\n"; // orders@example.test

try {
    new Email(['john@c.c'], 'Hi', 'Body', 'Acme', 'orders@example.test');
} catch (InvalidArgumentException $e) {
    echo $e->getType(), "\n"; // recipient_domain_invalid
}
```

## Send a text message

An adapter sends one kind of message through one provider. The response says how many recipients were reached, and why each of the others was not.

```no_run
use utopia_messaging::sms::Twilio;
use utopia_messaging::{Adapter, Message, Sms};

# async fn run() -> Result<(), utopia_messaging::Error> {
let twilio = Twilio::new("AC123", "auth-token").with_from(Some("+15550000000".into()));
let sent = twilio.send(&Message::from(Sms::new(["+15551234567"], "Your code is 123456"))).await?;
let response = sent.response().expect("one adapter, one response");
for result in response.details() {
    println!("{}: {} {}", result.recipient, result.status.as_str(), result.error);
}
# Ok(())
# }
```

```php
use Utopia\Messaging\Adapter\SMS\Twilio;
use Utopia\Messaging\Messages\SMS;

$twilio = new Twilio('AC123', 'auth-token', '+15550000000');
// $response = $twilio->send(new SMS(['+15551234567'], 'Your code is 123456'));
// foreach ($response['results'] as $result) { echo $result['recipient'], ': ', $result['status'], "\n"; }
```

## Route text messages by country

`GeoSms` sends each recipient through the adapter registered for its calling code, the others through the default adapter, and reports each adapter's response by name.

```no_run
use std::sync::Arc;

use utopia_messaging::sms::{GeoSms, Msg91, Twilio, calling_code};
use utopia_messaging::{Adapter, Message, Sms};

# async fn run() -> Result<(), utopia_messaging::Error> {
let router = GeoSms::new(Arc::new(Twilio::new("AC123", "auth-token")))
    .with_local(calling_code::INDIA, Arc::new(Msg91::new("SENDER", "auth-key", "template-id")));
assert_eq!(calling_code::from_phone_number("+91 98765 43210"), Some("91"));
let sent = router.send(&Message::from(Sms::new(["+919876543210", "+15551234567"], "123456"))).await?;
# let _ = sent;
# Ok(())
# }
```

```php
use Utopia\Messaging\Adapter\SMS\GEOSMS;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Messaging\Adapter\SMS\Msg91;
use Utopia\Messaging\Adapter\SMS\Twilio;
use Utopia\Messaging\Messages\SMS;

$router = new GEOSMS(new Twilio('AC123', 'auth-token'));
$router->setLocal(CallingCode::INDIA, new Msg91('SENDER', 'auth-key', 'template-id'));
echo CallingCode::fromPhoneNumber('+91 98765 43210'), "\n"; // 91
// $results = $router->send(new SMS(['+919876543210', '+15551234567'], '123456'));
```

## Send email through SMTP

The SMTP adapter tries each host of the list in order, and every recipient gets the server's own answer: one refused address does not fail the others, and a 5xx refusal is marked permanent.

```no_run
use utopia_messaging::email::Smtp;
use utopia_messaging::{Adapter, Email, Message};

# async fn run() -> Result<(), utopia_messaging::Error> {
let smtp = Smtp::new("tls://smtp1.example.test:587;smtp2.example.test", 25)
    .with_credentials("user", "secret")
    .with_keep_alive(true);
let email = Email::builder(["john@example.test"], "Hello", "Body", "Acme", "noreply@example.test").build()?;
let sent = smtp.send(&Message::from(email)).await?;
for result in sent.response().map(|r| r.details()).unwrap_or_default() {
    if result.permanent {
        println!("do not retry {}: {}", result.recipient, result.error);
    }
}
smtp.disconnect().await;
# Ok(())
# }
```

```php
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Messages\Email;

$smtp = new SMTP(host: 'tls://smtp1.example.test:587;smtp2.example.test', username: 'user', password: 'secret', keepAlive: true);
$email = new Email(['john@example.test'], 'Hello', 'Body', 'Acme', 'noreply@example.test');
// $response = $smtp->send($email);
// $smtp->disconnect();
```

## Send a push notification

APNs and FCM sign their own tokens from the credentials given; every device token gets a result, and one the provider no longer knows reads `Expired device token`.

```no_run
use utopia_messaging::push::Fcm;
use utopia_messaging::{Adapter, Message, Priority, Push};

# async fn run(service_account_json: String) -> Result<(), utopia_messaging::Error> {
let fcm = Fcm::new(service_account_json);
let push = Push::builder(["device-token"]).title("Hello").body("You have a new message").priority(Priority::High).build()?;
let sent = fcm.send(&Message::from(push)).await?;
# let _ = sent;
# Ok(())
# }
```

```php
use Utopia\Messaging\Adapter\Push\FCM;
use Utopia\Messaging\Messages\Push;
use Utopia\Messaging\Priority;

// $fcm = new FCM($serviceAccountJSON);
$push = new Push(to: ['device-token'], title: 'Hello', body: 'You have a new message', priority: Priority::HIGH);
// $response = $fcm->send($push);
```

## Count sends with telemetry

Every send records how many recipients were reached and how many were not on the `messaging.send` counter, by provider, type and the message's origin.

```
use utopia_messaging::Configure;
use utopia_messaging::sms::Twilio;
use utopia_telemetry::MemoryTelemetry;

let telemetry = MemoryTelemetry::default();
let twilio = Twilio::new("AC123", "auth-token").with_telemetry(&telemetry);
# let _ = twilio;
```

```php
use Utopia\Messaging\Adapter\SMS\Twilio;
use Utopia\Telemetry\Adapter\None;

$twilio = new Twilio('AC123', 'auth-token');
$twilio->setTelemetry(new None());
```
