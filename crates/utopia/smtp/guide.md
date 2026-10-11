`utopia-smtp` submits mail to a server you configure: it builds the message (headers, MIME tree, attachments, encodings), opens the session (STARTTLS or implicit TLS, then authentication), and reports which recipients the server took. One `Client` is one connection, reopened after it is dropped.

## Build a message

A message picks its MIME structure from what you give it: one text part, `multipart/alternative` for text and HTML, `multipart/related` for inline images and `multipart/mixed` for attachments. Blind recipients go in the envelope, never in a header.

```rust
use utopia_smtp::{Address, Attachment, Date, Message};

let message = Message::builder(Address::new("jane@example.test", "Jane Doe")?, "Quarterly report")
    .to(Address::new("john@example.test", "John")?)
    .bcc(Address::new("audit@example.test", "")?)
    .text("The numbers are in.")
    .html("<p>The numbers are in.</p>")
    .attachment(Attachment::from_bytes(b"1,2,3".to_vec(), "numbers.csv", Some("text/csv"))?)
    .date(Date::new(1_786_613_400, 0))
    .build()?;

let rendered = String::from_utf8(message.to_bytes()?).unwrap();
assert!(rendered.contains("Content-Type: multipart/mixed;"));
assert!(!rendered.contains("audit@example.test"));
# Ok::<(), utopia_smtp::Error>(())
```

```php
use Utopia\SMTP\Address;
use Utopia\SMTP\Attachment;
use Utopia\SMTP\Message;

$message = new Message(
    from: new Address('jane@example.test', 'Jane Doe'),
    to: [new Address('john@example.test', 'John')],
    subject: 'Quarterly report',
    text: 'The numbers are in.',
    html: '<p>The numbers are in.</p>',
    bcc: [new Address('audit@example.test')],
    attachments: [Attachment::fromString('1,2,3', 'numbers.csv', 'text/csv')],
);
var_dump(str_contains((string) $message, 'multipart/mixed')); // bool(true)
```

## Send it

A `Client` over a `TcpTransport` connects on first use. `Encryption::StartTls` refuses a server without STARTTLS; authenticators are tried in order against the mechanisms the server offers. A server may refuse some recipients and take the others: the `Delivery` says which.

```rust,no_run
use utopia_smtp::auth::Plain;
use utopia_smtp::{Address, Client, Encryption, Message, TcpTransport, Tls};

# async fn run() -> Result<(), utopia_smtp::Error> {
let transport = TcpTransport::new("smtp.example.test", 587, Tls::default());
let mut client = Client::new(transport)
    .with_domain("app.example.test")
    .with_authenticator(Plain::new("jane", "secret"))
    .with_encryption(Encryption::StartTls);

let message = Message::builder(Address::new("jane@example.test", "")?, "Hello")
    .to(Address::new("john@example.test", "")?)
    .text("Hi John")
    .build()?;

let delivery = client.send(&message).await?;
for (recipient, reply) in &delivery.rejected {
    eprintln!("{recipient} refused: {reply}");
}
client.close().await;
# Ok(()) }
```

```php
use Utopia\SMTP\Auth\Plain;
use Utopia\SMTP\Client;
use Utopia\SMTP\Encryption;
use Utopia\SMTP\Transport\Native;

$client = new Client(new Native('smtp.example.test', 587), 'app.example.test', [new Plain('jane', 'secret')], Encryption::StartTls);
// $result = $client->send($message); foreach ($result->rejected as $recipient => $reply) { ... }
```

## Plug in your own transport

`Transport` moves bytes and nothing else; line assembly and the reply grammar live in the client. Implement it to tunnel SMTP through something else, or to script a server in a test.

```rust
use std::time::Duration;
use utopia_smtp::{Client, Encryption, Envelope, Error, Transport};

#[derive(Default)]
struct Scripted {
    replies: Vec<u8>,
    written: Vec<u8>,
}

impl Transport for Scripted {
    async fn connect(&mut self, _: Duration, _: bool) -> Result<(), Error> {
        Ok(())
    }
    async fn read(&mut self, buffer: &mut Vec<u8>, length: usize, _: Duration) -> Result<usize, Error> {
        let n = length.min(self.replies.len());
        if n == 0 {
            return Err(Error::Connection("The server closed the connection".into()));
        }
        buffer.extend(self.replies.drain(..n));
        Ok(n)
    }
    async fn write(&mut self, data: &[u8], _: Duration) -> Result<(), Error> {
        self.written.extend_from_slice(data);
        Ok(())
    }
    async fn start_tls(&mut self, _: Duration) -> Result<(), Error> {
        Ok(())
    }
    fn is_tls(&self) -> bool {
        false
    }
    fn close(&mut self) {}
}

# tokio::runtime::Builder::new_current_thread().build().unwrap().block_on(async {
let server = Scripted {
    replies: b"220 hi\r\n250 hello\r\n250 ok\r\n250 ok\r\n354 go\r\n250 Ok: queued as ABC\r\n".to_vec(),
    ..Scripted::default()
};
let mut client = Client::new(server).with_encryption(Encryption::None);
let envelope = Envelope::new("jane@example.test", ["john@example.test"])?;

let delivery = client.send_raw(&envelope, b"Subject: Hi\r\n\r\n.Body").await?;
assert_eq!(delivery.message_id, "ABC");
assert!(client.transport().written.ends_with(b"DATA\r\nSubject: Hi\r\n\r\n..Body\r\n.\r\n"));
# Ok::<(), Error>(()) }).unwrap();
```

```php
use Utopia\SMTP\Transport\Transport;

// A class implementing Transport (connect, read, write, startTls, isTls,
// close) is all a Client needs; see tests/Support/FakeTransport.php.
var_dump(interface_exists(Transport::class)); // bool(true)
```

## Read what the server says

Replies carry their class and enhanced status code, and a refused transaction keeps the reply (and each recipient's refusal) on the error, so a caller can tell a retry from a bounce.

```rust
use utopia_smtp::{Capabilities, Outcome, Reply};

let ehlo = Reply::new(250, ["mail.example.test", "SIZE 35882577", "AUTH PLAIN LOGIN", "STARTTLS"])?;
let capabilities = Capabilities::from_reply(&ehlo);
assert!(capabilities.has("starttls"));
assert_eq!(capabilities.max_size(), Some(35_882_577));

let busy = Reply::new(450, ["4.2.1 Mailbox busy"])?;
assert_eq!(busy.outcome(), Outcome::Transient);
assert_eq!(busy.status(), Some("4.2.1"));
# Ok::<(), utopia_smtp::Error>(())
```

```php
use Utopia\SMTP\Capabilities;
use Utopia\SMTP\Outcome;
use Utopia\SMTP\Reply;

$capabilities = Capabilities::fromReply(new Reply(250, ['mail.example.test', 'SIZE 35882577', 'AUTH PLAIN LOGIN', 'STARTTLS']));
var_dump($capabilities->has('starttls')); // bool(true)
var_dump((new Reply(450, ['4.2.1 Mailbox busy']))->outcome === Outcome::Transient); // bool(true)
```
