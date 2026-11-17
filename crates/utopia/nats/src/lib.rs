//! A NATS client: publish, subscribe, request-reply and batched requests
//! over TCP, TLS and WebSockets, with JetStream streams and consumers, a
//! key-value store, an object store, and NATS micro services.
//!
//! A [`Connection`] is driven by one owner: every call takes `&mut self`,
//! messages for subscriptions are read and dispatched by the calls that
//! read ([`Connection::process_message`], [`Connection::wait`],
//! [`Connection::next_message`], requests), and keepalives ride on them or
//! on [`Connection::tick`]. Callbacks that need to publish (a responder,
//! a service endpoint, a push consumer acknowledging) hold a
//! [`Publisher`], whose publishes the connection sends as soon as the
//! callback returns.
//!
//! ```
//! # utopia_async::run(async {
//! use std::time::Duration;
//! use utopia_nats::{Connection, ConnectionOptions, Message};
//!
//! # let url = std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into());
//! # let options = ConnectionOptions { connect_timeout: Duration::from_millis(200), ..ConnectionOptions::new([url]) };
//! let Ok(mut connection) = Connection::connect(options).await else { return Ok(()) };
//! let replies = connection.publisher();
//! connection
//!     .subscribe("greet.*", Some(Box::new(move |msg: Message| {
//!         replies.publish(msg.reply_to.clone().unwrap_or_default(), format!("hello, {}", &msg.subject[6..]), None, None);
//!         Ok(())
//!     })), None)
//!     .await?;
//! let reply = connection.request("greet.world", "", Some(Duration::from_secs(1)), None).await?;
//! assert_eq!(reply.data, "hello, world");
//! connection.close().await?;
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! # })?;
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! ```
//!
//! ```php
//! use Utopia\NATS\Connection;
//! use Utopia\NATS\ConnectionOptions;
//! use Utopia\NATS\Exception\ConnectionException;
//!
//! try {
//!     $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
//! } catch (ConnectionException) {
//!     return;
//! }
//! $connection->subscribe('greet.*', function ($msg) use ($connection) {
//!     $connection->publish($msg->replyTo, 'hello, ' . substr($msg->subject, 6));
//! });
//! $connection->request('greet.world', '', 1.0)->data; // 'hello, world'
//! $connection->close();
//! ```
//!
//! The client implements the protocol itself rather than wrapping
//! `async-nats`, so what PHP's client writes and reads (the `CONNECT`
//! payload, the order and grouping of writes, the parser's behaviour on
//! broken frames, every error message) is the same here: see `guide.md`.
//!
//! PHP: ports `utopia-php/nats`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\NATS\Connection` | [`Connection`] (`connect`, `publish`, `subscribe`, `queueSubscribe`, `unsubscribe`, `request`, `requestBatch`, `requestMany`, `newInbox`, `tick`, `processMessage`, `wait`, `jetStream`, `flush`, `drain`, `close`, `isConnected`, `isClosed`, `isReconnecting`, `getServerInfo`, `getOptions`, `getStatus`: same names in snake case; `jetStream` is [`Connection::jetstream`]) |
//! | `Connection::closesConnection()`, `reconnectsAfter()`, `mapServerError()`, `reconnectBackoff()`, `reconnectBufferAccepts()` | [`Connection::closes_connection`], [`Connection::reconnects_after`], [`Connection::map_server_error`], [`Connection::reconnect_backoff`], [`Connection::reconnect_buffer_accepts`] |
//! | `Connection::STATUS_*` | [`Status`] |
//! | `$opts` of `requestMany()` | [`RequestMany`] |
//! | `Utopia\NATS\ConnectionOptions` | [`ConnectionOptions`] (float seconds are [`std::time::Duration`]s; callbacks are `Arc<dyn Fn>`) |
//! | `Utopia\NATS\Subscription` | [`Subscription`] (the handle) and [`SubscriptionState`] (its counters and queue); `nextMessage()` and `unsubscribe()` are [`Connection::next_message`] and [`Connection::unsubscribe`] |
//! | `$connection->publish()` inside a callback | [`Publisher`] |
//! | `Utopia\NATS\Message`, `Request`, `Headers`, `Inbox`, `ServerInfo` | [`Message`], [`Request`], [`Headers`], [`Inbox`], [`ServerInfo`] |
//! | `Utopia\NATS\Protocol\Parser`, `Writer`, `ServerOp`, `Command` | [`protocol::Parser`] (with [`protocol::Op`]), [`protocol::Writer`], [`protocol::ServerOp`], [`protocol::Command`] |
//! | `Utopia\NATS\Transport\Transport`, `TcpTransport`, `TlsTransport`, `WebSocketTransport`, `SwooleTransport` | [`transport::Transport`], [`transport::TcpTransport`] (also `SwooleTransport`: every transport is async), [`transport::TlsTransport`], [`transport::WebSocketTransport`]; the `ssl` options array is [`transport::TlsOptions`] |
//! | `Utopia\NATS\Auth\Authenticator`, `NoAuth`, `TokenAuth`, `UserPassAuth`, `NKeyAuth`, `CredentialsAuth` | [`auth::Authenticator`], [`auth::NoAuth`], [`auth::TokenAuth`], [`auth::UserPassAuth`], [`auth::NKeyAuth`], [`auth::CredentialsAuth`] |
//! | `Utopia\NATS\JetStream\JetStream` | [`jetstream::JetStream`] (calls take the connection); `publish()`'s named arguments are [`jetstream::PublishOptions`], `publishMany()`'s arrays [`jetstream::PublishMessage`], `$ttl` [`jetstream::Ttl`] |
//! | `JetStream\Stream`, `Consumer`, `MessageBatch`, `JetStreamMessage`, `PushSubscription`, `OrderedConsumer` | [`jetstream::Stream`], [`jetstream::Consumer`], [`jetstream::MessageBatch`], [`jetstream::JetStreamMessage`] (with [`jetstream::AckKind`]), [`jetstream::PushSubscription`], [`jetstream::OrderedConsumer`] |
//! | `JetStream\StreamConfig`, `ConsumerConfig`, `ConsumerLimits`, `Placement`, `Republish`, `SubjectTransform`, `StreamSource`, `ExternalStream` | the same names in [`jetstream`] (`toArray()`/`fromArray()` are `to_value()`/`from_value()`) |
//! | `JetStream\StreamInfo`, `StreamState`, `ConsumerInfo`, `SequenceInfo`, `AccountInfo`, `ApiError`, `PubAck`, `StreamMessage`, `MsgMetadata` | the same names in [`jetstream`] |
//! | `JetStream\AckPolicy`, `DeliverPolicy`, `DiscardPolicy`, `ReplayPolicy`, `RetentionPolicy`, `StorageType` | the same enums in [`jetstream`] |
//! | `Utopia\NATS\KeyValue\KeyValue`, `KeyValueConfig`, `KeyValueEntry`, `KeyValueOperation`, `KeyValueStatus`, `KeyValueWatchOptions` | the same names in [`key_value`] |
//! | `Utopia\NATS\ObjectStore\ObjectStore`, `ObjectStoreConfig`, `ObjectMeta`, `ObjectLink` | the same names in [`object_store`] |
//! | `Utopia\NATS\Services\Service`, `Group`, `Endpoint`, `ServiceException` | [`services::Service`], [`services::Group`], [`services::Endpoint`], [`Error::Service`] |
//! | `Utopia\NATS\Exception\*`, `\InvalidArgumentException`, `\LogicException`, `\RuntimeException`, `\JsonException`, `\SodiumException` | [`Error`] ([`Error::php_class`]) |

#![warn(missing_docs)]

pub mod auth;
mod connection;
mod error;
mod fields;
mod headers;
pub mod jetstream;
mod json;
pub mod key_value;
mod message;
pub mod object_store;
mod options;
pub mod protocol;
mod publisher;
mod server_info;
pub mod services;
mod subscription;
pub mod transport;

pub use connection::{Connection, RequestMany, Status};
pub use error::{CallbackError, Error, Result};
pub use headers::Headers;
pub use message::{Inbox, Message, Request};
pub use options::{ConnectionOptions, ErrorHook, Hook, Provider, SlowConsumerHook, TransportFactory};
pub use publisher::Publisher;
pub use server_info::ServerInfo;
pub use subscription::{MessageCallback, Subscription, SubscriptionState};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
