//! Building blocks for MQTT brokers that speak MQTT 5.0 and 3.1.1.
//!
//! The crate has four layers, each usable on its own:
//!
//! - **The codec.** [`packet`] splits a byte stream into control packets
//!   ([`packet::frames`], [`Packet::parse`]) and decodes the ones a broker
//!   reads ([`packet::Connect`], [`packet::Publish`], [`packet::Subscribe`],
//!   ...); [`spec::v3`] and [`spec::v5`] encode every packet for each
//!   protocol version; [`Properties`] is a 5.0 property block; [`wire`]
//!   holds the primitive encodings.
//! - **Subscriptions.** [`Store`] is a topic-filter trie scoped by a prefix
//!   (a tenant, a project): one walk finds every connection whose filters
//!   (`+`, `#`) match a topic, with its granted QoS.
//! - **Connections.** [`Connection`] holds what CONNECT negotiated and the
//!   delivery state of QoS 1 messages: packet ids, messages in flight, and a
//!   per-topic cursor of the sequences acknowledged without a gap.
//! - **The broker.** [`Server`] runs on an [`Adapter`] ([`Network`] on TCP, TLS
//!   and WebSocket listeners; [`Memory`] in process, for tests) and asks a
//!   [`Handler`] what to do with each CONNECT, AUTH, SUBSCRIBE, UNSUBSCRIBE,
//!   PUBLISH, PUBACK and DISCONNECT, keeps the store, answers PINGREQ and
//!   reaps silent clients with the adapter's [`Timer`] ([`TimingWheel`]).
//!   [`Client`] connects to any broker over TCP or TLS.
//!
//! ```
//! use utopia_mqtt::packet::{Connect, Packet, Publish};
//! use utopia_mqtt::spec::v5;
//! use utopia_mqtt::{Properties, Property, Store};
//!
//! // A client's CONNECT and PUBLISH, as they arrive.
//! let properties = Properties::new().with(Property::user("projectId", "p1"));
//! let connect = v5::connect(b"device-1", 60, true, Some(&properties))?;
//! let publish = v5::publish(b"sensors/room1/temp", b"21.5", 1, 7, None, false)?;
//!
//! let connect = Connect::decode(Packet::parse(&connect)?.body)?;
//! assert_eq!(connect.user_properties(), [(b"projectId".to_vec(), b"p1".to_vec())]);
//!
//! // Who receives the PUBLISH, under the client's project.
//! let mut store = Store::new();
//! store.subscribe(b"p1", b"user-1", b"sensors/+/temp", 11, 1);
//! store.subscribe(b"p2", b"user-2", b"sensors/#", 12, 1);
//! let publish = Publish::decode(&Packet::parse(&publish)?, connect.protocol_level)?;
//! let subscribers = store.subscribers(b"p1", &publish.topic);
//! assert_eq!(subscribers.into_iter().collect::<Vec<_>>(), [(11, 1)]);
//! # Ok::<(), utopia_mqtt::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Mqtt\Packet;
//! use Utopia\Mqtt\Packet\Connect;
//! use Utopia\Mqtt\Packet\Publish;
//! use Utopia\Mqtt\Packet\Specs\V5;
//! use Utopia\Mqtt\Properties;
//! use Utopia\Mqtt\Property;
//! use Utopia\Mqtt\Subscription\Store;
//!
//! $properties = (new Properties())->add(new Property(Property::USER, ['projectId' => 'p1']));
//! $connect = Connect::decode(Packet::parse(V5::connect('device-1', 60, true, $properties))->body);
//! print_r($connect->userProperties()); // ['projectId' => 'p1']
//!
//! $store = new Store();
//! $store->subscribe('p1', 'user-1', 'sensors/+/temp', 11, 1);
//! $store->subscribe('p2', 'user-2', 'sensors/#', 12, 1);
//! $publish = Publish::decode(Packet::parse(V5::publish('sensors/room1/temp', '21.5', 1, 7)), $connect->protocolLevel);
//! print_r($store->getSubscribers('p1', $publish->topic)); // [11 => 1]
//! ```
//!
//! PHP: ports `utopia-php/mqtt`. Decoding keeps PHP's behaviour on any
//! input, malformed included: bytes past the end read as zeros, and a
//! variable byte integer may run past MQTT's four bytes (see
//! [`wire::Length`]).
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Mqtt\Packet` (`$type`, `$flags`, `$body`; `name()`, `qos()`, `dup()`) | [`Packet`] ([`packet::Kind`]; [`Packet::name`], [`Packet::qos`], [`Packet::dup`]) |
//! | `Packet::CONNECT` ... `Packet::AUTH`, `Packet::QOS_1` | [`Packet::CONNECT`] ... [`Packet::AUTH`], [`Packet::QOS_1`] |
//! | `Packet::parse()`, `frames()`, `isCleanStart()`, `getClientId()`, `pingreq()`, `pingresp()` | [`Packet::parse`], [`packet::frames`], [`Packet::is_clean_start`], [`Packet::client_id`], [`packet::pingreq`], [`packet::pingresp`] |
//! | `Packet::decodeLength()`, `encodeLength()`, `readString()`, `encodeString()`, `readInt16()`, `readByte()` | [`wire::decode_length`] ([`wire::Length`]), [`wire::encode_length`], [`wire::read_string`], [`wire::encode_string`], [`wire::read_int16`], [`wire::read_byte`] |
//! | `Utopia\Mqtt\Property` (`wireType()`, `encode()`, the identifier and `TYPE_*` constants) | [`Property`] ([`Property::wire_type`] → [`WireType`], [`Property::encode`], [`Value`]; the same constant names) |
//! | `Utopia\Mqtt\Properties` (`add()`, `all()`, `get()`, `user()`, `encode()`, `parse()`, `skip()`) | [`Properties`] ([`Properties::add`], [`Properties::all`], [`Properties::get`], [`Properties::user`], [`Properties::encode`], [`Properties::parse`], [`Properties::skip`]) |
//! | `Packet\Connect`, `Packet\Will` | [`packet::Connect`], [`packet::Will`] |
//! | `Packet\Connack` (`accept()`, `refuse()`, `accepted()`) | [`packet::Connack`] |
//! | `Packet\Auth` (`decode()`, `success()`, `challenge()`) | [`packet::Auth`] |
//! | `Packet\Disconnect` (`decode()`, `refuse()`, `normal()`) | [`packet::Disconnect`] |
//! | `Packet\Publish`, `Packet\Puback` | [`packet::Publish`], [`packet::Puback`] |
//! | `Packet\Subscribe` (`filters()`), `Packet\Filter`, `Packet\Suback` | [`packet::Subscribe`] ([`packet::Subscribe::filters`]), [`packet::Filter`], [`packet::Suback`] |
//! | `Packet\Unsubscribe`, `Packet\Unsuback` | [`packet::Unsubscribe`], [`packet::Unsuback`] |
//! | `Packet\Specs\V3`, `Packet\Specs\V5` | [`spec::v3`], [`spec::v5`] (functions and constants) |
//! | `Utopia\Mqtt\Subscription\Store` (`subscribe()`, `unsubscribe()`, `close()`, `getConnection()`, `getSubscribers()`) | [`Store`] ([`Store::subscribe`], [`Store::unsubscribe`], [`Store::close`], [`Store::connection`] → [`Record`], [`Store::subscribers`] → [`Subscribers`]) |
//! | `Utopia\Mqtt\Subscription\Node` | none: the trie's nodes are private to [`Store`] |
//! | `Utopia\Mqtt\Connection` (public properties; `publish()`, `puback()`, `disconnect()`, `updateExpiresAt()`, `nextPacketId()`, `resume()`, `track()`, `acknowledge()`, `setClientId()`, `getClientId()`) | [`Connection`] ([`Connection::state`] → [`State`]; [`Connection::publish`], [`Connection::puback`], [`Connection::disconnect`], [`Connection::update_expires_at`], [`Connection::next_packet_id`], [`Connection::resume`], [`Connection::track`], [`Connection::acknowledge`] → [`Ack`], [`Connection::set_client_id`], [`Connection::client_id`]) |
//! | `Utopia\Mqtt\Handler` | [`Handler`] (trait; replies [`server::ConnectReply`], [`server::AuthReply`]) |
//! | `Utopia\Mqtt\Server` (`setTelemetry()`, `onStart()`, `onWorkerStart()`, `onStop()`, `error()`, `start()`, `stop()`, `subscribers()`, `unsubscribe()`) | [`Server`] ([`Server::set_telemetry`], [`Server::on_start`], [`Server::on_worker_start`], [`Server::on_stop`], [`Server::error`], [`Server::start`] (async), [`Server::stop`], [`Server::subscribers`], [`Server::unsubscribe`]) |
//! | `Utopia\Mqtt\Adapter` (abstract class) | [`Adapter`] (trait), with [`Outbound`] (`send()`, `close()`) |
//! | `Utopia\Mqtt\Adapter\Swoole` | [`Network`] |
//! | `Adapter\Swoole\Transport`, `Tcp`, `Tls`, `WebSocket` (`getSockType()`, `getSettings()`) | [`Transport`] ([`Transport::tcp`], [`Transport::tls`], [`Transport::websocket`], [`Transport::max_packet_size`]) |
//! | `Adapter\Swoole\Timer`, `Timers\TimingWheel`, `Timers\NoTimer` | [`Timer`] (trait), [`TimingWheel`], [`NoTimer`] |
//! | none (the compat suite's `tests/compat/mqtt/Memory.php`) | [`Memory`] |
//! | `Utopia\Mqtt\Client` | [`Client`] ([`client::Options`]) |
//! | `\RuntimeException`, `\InvalidArgumentException`, `\TypeError` | [`Error::Runtime`], [`Error::InvalidArgument`], [`Error::Type`] |
//!
//! PHP's Swoole adapter runs a Swoole server with worker processes; [`Network`]
//! serves every listener on the runtime it is started on and calls the
//! worker start hook once per configured worker id. Swoole's settings
//! arrays (`getSettings()`) and socket types (`getSockType()`) are the
//! variants of [`Transport`].

#![warn(missing_docs)]

pub mod adapter;
#[cfg(feature = "network")]
pub mod client;
mod connection;
mod error;
pub mod memory;
#[cfg(feature = "network")]
pub mod net;
pub mod packet;
mod property;
pub mod server;
pub mod spec;
mod store;
mod timer;
pub mod wire;

pub use adapter::{Adapter, Outbound, Timer};
#[cfg(feature = "network")]
pub use client::Client;
pub use connection::{Ack, Connection, State};
pub use error::Error;
pub use memory::Memory;
#[cfg(feature = "network")]
pub use net::{Network, Transport};
pub use packet::Packet;
pub use property::{Properties, Property, Value, WireType};
pub use server::{Handler, Server};
pub use store::{Record, Store, Subscribers};
pub use timer::{NoTimer, TimingWheel};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
