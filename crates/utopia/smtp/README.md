# Utopia SMTP

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/smtp`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/smtp) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

An async SMTP client: STARTTLS and implicit TLS, PLAIN, LOGIN and XOAUTH2, partial recipient refusal, and MIME messages with attachments built for the wire.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-smtp = { git = "https://github.com/utopia-rs/smtp" }
```

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
```

## Send it

A `Client` over a `TcpTransport` connects on first use. `Encryption::StartTls` refuses a server without STARTTLS; authenticators are tried in order against the mechanisms the server offers. A server may refuse some recipients and take the others: the `Delivery` says which.

```rust
use utopia_smtp::auth::Plain;
use utopia_smtp::{Address, Client, Encryption, Message, TcpTransport, Tls};

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

let server = Scripted {
    replies: b"220 hi\r\n250 hello\r\n250 ok\r\n250 ok\r\n354 go\r\n250 Ok: queued as ABC\r\n".to_vec(),
    ..Scripted::default()
};
let mut client = Client::new(server).with_encryption(Encryption::None);
let envelope = Envelope::new("jane@example.test", ["john@example.test"])?;

let delivery = client.send_raw(&envelope, b"Subject: Hi\r\n\r\n.Body").await?;
assert_eq!(delivery.message_id, "ABC");
assert!(client.transport().written.ends_with(b"DATA\r\nSubject: Hi\r\n\r\n..Body\r\n.\r\n"));
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
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/smtp`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/smtp). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-smtp
cargo clippy -p utopia-smtp --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

Replay the results PHP recorded for every case against Rust, without PHP:

```bash
COMPAT_SERVICES=1 COMPAT_LIB=smtp cargo test -p compat
```

Without `COMPAT_SERVICES=1` the cases that need a service are skipped.

Run every case on both runtimes, fuzz both with generated inputs, then run the whole conversion check. These need the dev stack (`docker compose up -d`):

```bash
tests/compat/smtp/services.sh up
bin/compat run smtp
bin/compat fuzz smtp
bin/compat check smtp
```

### Benchmark

```bash
bin/compat bench smtp
```

This times every benchmark on PHP and on a release build of Rust, both in containers on the dev stack. It fails if Rust is slower. Add `--write` to record the run in [`tests/compat/smtp/bench.json`](https://github.com/appwrite/appwrite/blob/main/tests/compat/smtp/bench.json). Run it on a quiet machine, one library at a time.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
