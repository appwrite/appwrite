//! An async SMTP client for submitting mail to a configured server: STARTTLS
//! and implicit TLS, PLAIN, LOGIN and XOAUTH2, partial recipient refusal,
//! and MIME messages (text, HTML, attachments, inline images) built for the
//! wire.
//!
//! One [`Client`] is one connection, over a [`Transport`]: [`TcpTransport`]
//! (utopia-async and rustls), or any implementation of the trait. Messages are
//! streamed: an attachment read from disk is encoded block by block while it
//! is sent.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\SMTP\Client` | [`Client`] |
//! | `new Client($transport, $domain, $authenticators, $encryption, $timeouts)` | [`Client::new`], [`Client::with_domain`], [`Client::with_authenticator`], [`Client::with_encryption`], [`Client::with_timeouts`] |
//! | `send()`, `sendRaw($envelope, $string)`, `sendRaw($envelope, $iterable)` | [`Client::send`], [`Client::send_raw`], [`Client::send_chunks`] |
//! | `capabilities()`, `command()`, `noop()`, `reset()`, `close()` | [`Client::capabilities`], [`Client::command`], [`Client::noop`], [`Client::reset`], [`Client::close`] |
//! | `idle()`, `ping()`, `$transactions` | [`Client::idle`], [`Client::ping`], [`Client::transactions`] |
//! | `Utopia\SMTP\Result`, `isComplete()` | [`Delivery`], [`Delivery::is_complete`] |
//! | `Utopia\SMTP\Message`, `isInternational()`, `toIterable()`, `__toString()` | [`Message`] built by [`MessageBuilder`], [`Message::is_international`], [`Message::chunks`], [`Message::to_bytes`] |
//! | the `\DateTimeImmutable` of a message | [`Date`] |
//! | `Utopia\SMTP\Address`, `isInternational()`, `__toString()` | [`Address`], [`Address::is_international`], `Display` |
//! | `Utopia\SMTP\Attachment`, `fromPath()`, `fromString()`, `inline()` | [`Attachment`], [`Attachment::from_path`], [`Attachment::from_bytes`], [`Attachment::inline`] |
//! | `Utopia\SMTP\Envelope`, `fromMessage()`, `isInternational()` | [`Envelope`], [`Envelope::from_message`], [`Envelope::is_international`] |
//! | `Utopia\SMTP\Reply`, `text()`, `__toString()` | [`Reply`], [`Reply::text`], `Display` |
//! | `Utopia\SMTP\Outcome`, `fromCode()` | [`Outcome`], [`Outcome::from_code`] |
//! | `Utopia\SMTP\Capabilities`, `none()`, `fromReply()`, `has()`, `params()`, `maxSize()`, `mechanisms()` | [`Capabilities`], [`Capabilities::none`], [`Capabilities::from_reply`], [`Capabilities::has`], [`Capabilities::params`], [`Capabilities::max_size`], [`Capabilities::mechanisms`] |
//! | `Utopia\SMTP\Timeouts` | [`Timeouts`] |
//! | `Utopia\SMTP\Tls`, `Verification`, `Encryption` | [`Tls`], [`Verification`], [`Encryption`] |
//! | `Auth\Authenticator`, `Auth\Plain`, `Auth\Login`, `Auth\XOAuth2` | [`auth::Authenticator`], [`auth::Plain`], [`auth::Login`], [`auth::XOAuth2`] |
//! | `Transport\Transport` | [`Transport`] (an async trait) |
//! | `Transport\Native`, `Transport\Swoole` | [`TcpTransport`] |
//! | `Mime\Encoding` | [`mime::encoding`] |
//! | `Mime\Header::line()`, `phrase()` | [`mime::header::line`], [`mime::header::phrase`] |
//! | `Mime\Part`, `text()`, `attachment()`, `multipart()`, `head()`, `body()` | [`mime::Part`], [`mime::Part::text`], [`mime::Part::attachment`], [`mime::Part::multipart`], [`mime::Part::head`], [`mime::Part::body`] |
//! | `Exception\*Exception`, `\InvalidArgumentException`, `\LogicException` | [`Error`], one variant per class |
//! | `TransactionException::$reply`, `$rejected`, `isTransient()`, `isPermanent()` | [`Refusal`], [`Refusal::is_transient`], [`Refusal::is_permanent`] |
//!
//! PHP: strings are bytes there and UTF-8 here, so an address, subject or
//! header that is not UTF-8 cannot be expressed, and reply text that is not
//! UTF-8 is read lossily. PHP's two transports (stream sockets and Swoole
//! coroutines) are one async transport here.

#![warn(missing_docs)]

mod address;
mod attachment;
pub mod auth;
mod capabilities;
mod client;
mod envelope;
mod error;
mod message;
pub mod mime;
mod reply;
mod settings;
mod transport;

pub use address::Address;
pub use attachment::{Attachment, Source, detect};
pub use capabilities::Capabilities;
pub use client::{Client, Delivery};
pub use envelope::Envelope;
pub use error::{Error, Refusal};
pub use message::{Date, Message, MessageBuilder};
pub use reply::{Outcome, Reply};
pub use settings::{Encryption, Timeouts, Tls, Verification};
pub use transport::{TcpTransport, Transport};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
