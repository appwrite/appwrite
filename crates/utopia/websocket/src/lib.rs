//! WebSocket servers behind one interface, and a WebSocket client.
//!
//! A [`Server`] drives an [`Adapter`]: register hooks for the server's
//! events (start, worker start and stop, a connection opening, a message,
//! a connection closing, a plain HTTP request), then start it. From any
//! hook, [`Server::send`] sends a message to many connections at once and
//! [`Server::close`] closes one. The server never returns an error: a
//! failing step is passed to the callbacks registered with
//! [`Server::error`], with the [`Stage`] that failed.
//!
//! Two adapters ship with the crate: [`Network`] serves WebSockets on the
//! network (feature `network`, on by default), and [`Memory`] runs in
//! process, driven by calls, for tests. [`Client`] connects to a WebSocket
//! server, sends, receives and listens, with hooks for messages, pings,
//! pongs, errors, opening and closing.
//!
//! ```
//! use std::sync::{Arc, Mutex};
//!
//! use utopia_websocket::{Memory, Server, Stage};
//!
//! let adapter = Memory::new();
//! let server = Server::new(adapter.clone());
//! let errors = Arc::new(Mutex::new(Vec::new()));
//! let seen = errors.clone();
//! let echo = server.clone();
//! server
//!     .on_message(move |connection, message| echo.send(&[connection], message.to_vec()))
//!     .error(move |error, stage| seen.lock().unwrap().push(format!("{stage}: {error}")));
//!
//! adapter.open(1, &Default::default());
//! adapter.message(1, b"hello");
//! assert_eq!(adapter.frames()[0].data, "hello");
//!
//! adapter.fail(Stage::Close, "cannot close");
//! server.close(1, 1000);
//! assert_eq!(errors.lock().unwrap()[0], "close: cannot close");
//! ```
//!
//! ```php
//! use Utopia\WebSocket\Adapter\Swoole;
//! use Utopia\WebSocket\Server;
//!
//! $server = new Server(new Swoole('0.0.0.0', 8080));
//! $server
//!     ->onMessage(fn (int $connection, string $message) => $server->send([$connection], $message))
//!     ->error(fn (\Throwable $error, string $stage) => print("{$stage}: {$error->getMessage()}\n"));
//! // $server->start();
//! ```
//!
//! PHP: ports `utopia-php/websocket`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\WebSocket\Server` | [`Server`] |
//! | `Server::start()`, `shutdown()`, `send()`, `close()` | [`Server::start`] (async), [`Server::shutdown`], [`Server::send`], [`Server::close`] |
//! | `Server::onStart()`, `onWorkerStart()`, `onWorkerStop()`, `onOpen()`, `onMessage()`, `onClose()`, `onRequest()` | [`Server::on_start`], [`Server::on_worker_start`], [`Server::on_worker_stop`], [`Server::on_open`], [`Server::on_message`], [`Server::on_close`], [`Server::on_request`] |
//! | `Server::error(callable)`, the stage string (`'start'`, `'onMessage'`, ...) | [`Server::error`], [`Stage`] ([`Stage::as_str`]) |
//! | `Utopia\WebSocket\Adapter` (abstract class) | [`Adapter`] (trait) |
//! | `Adapter::setPackageMaxLength()`, `setCompressionEnabled()`, `setWorkerNumber()` | [`Adapter::set_package_max_length`], [`Adapter::set_compression_enabled`], [`Adapter::set_worker_number`] |
//! | `Utopia\WebSocket\Adapter\Swoole` | [`Network`] (the network adapter; `Swoole::DEFAULT_SEND_TIMEOUT` is [`net::DEFAULT_SEND_TIMEOUT`], the settings [`net::Config`]) |
//! | `Utopia\WebSocket\Adapter\Workerman` | [`Network`] (one network adapter serves both) |
//! | `Adapter::getNative()` | none: [`Network::config`], [`Network::local_addr`] and [`Network::connections`] expose what callers read from the native server |
//! | none (the compat test adapter `tests/compat/websocket/Memory.php`) | [`Memory`] |
//! | `Utopia\WebSocket\Client` | [`Client`] |
//! | `new Client($url, ['headers' => ..., 'timeout' => ...])` | [`Client::new`] with [`client::Options`] |
//! | `Client::connect()`, `listen()`, `receive()`, `send()`, `close()`, `isConnected()` | [`Client::connect`], [`Client::listen`], [`Client::receive`], [`Client::send`], [`Client::close`], [`Client::is_connected`] |
//! | `Client::onMessage()`, `onClose()`, `onError()`, `onOpen()`, `onPing()`, `onPong()` | [`Client::on_message`], [`Client::on_close`], [`Client::on_error`], [`Client::on_open`], [`Client::on_ping`], [`Client::on_pong`] |
//! | `Swoole\Coroutine\Http\Client`, `Swoole\WebSocket\Frame` | [`client::Transport`], [`client::Frame`] |
//! | `\InvalidArgumentException`, `\RuntimeException`, `\Exception` | [`Error::InvalidArgument`], [`Error::Runtime`], [`Error::Exception`] |
//!
//! PHP's adapters run Swoole or Workerman worker processes; [`Network`] serves
//! every connection on the runtime it is started on, and calls the
//! worker hooks once per configured worker id.

#![warn(missing_docs)]

pub mod adapter;
pub mod client;
mod error;
pub mod memory;
#[cfg(feature = "network")]
pub mod net;
mod server;

pub use adapter::{Adapter, ConnectionId, Request, Response};
pub use client::Client;
pub use error::Error;
pub use memory::Memory;
#[cfg(feature = "network")]
pub use net::Network;
pub use server::{Server, Stage};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
