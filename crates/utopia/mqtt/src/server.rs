//! The broker: decodes each packet an adapter receives, asks the
//! [`Handler`] what to do, keeps the subscription store and answers clients.

use std::collections::HashMap;
use std::sync::{Arc, Mutex, MutexGuard, RwLock};

use utopia_telemetry::{Counter, NoTelemetry, Telemetry, UpDownCounter, Value as Attribute};

use crate::adapter::{Adapter, Outbound};
use crate::packet::{
    Auth, Connack, Connect, Disconnect, Kind, Packet, Puback, Publish, Suback, Subscribe, Unsuback, Unsubscribe,
    pingresp,
};
use crate::spec::{v3, v5};
use crate::{Connection, Error, Properties, Property, Store};

/// The highest QoS a SUBACK code grants; above it, a code is a failure.
const MAX_QOS: u8 = 2;

/// Seconds a new connection has to send CONNECT before the timer reaps it.
const CONNECT_GRACE: i64 = 30;

/// What a [`Handler`] answers a CONNECT with.
#[derive(Debug, Clone, PartialEq)]
pub enum ConnectReply {
    /// Accept or refuse the connection (a refusal closes it).
    Connack(Connack),
    /// Start enhanced authentication (5.0).
    Auth(Auth),
}

/// What a [`Handler`] answers an AUTH with.
#[derive(Debug, Clone, PartialEq)]
pub enum AuthReply {
    /// Finish authentication. Note that, as in PHP, a CONNACK sent here does
    /// not mark the connection active (a quirk the compat spec records).
    Connack(Connack),
    /// Continue authentication (or confirm a re-authentication).
    Auth(Auth),
    /// Refuse: the DISCONNECT is sent and the connection closed.
    Disconnect(Disconnect),
}

/// A subscriber of a published topic: its connection and granted QoS.
pub type Subscriber = (Arc<Connection>, u8);

/// The broker's decisions: authentication, authorization and delivery. The
/// [`Server`] decodes each packet, calls the matching method and encodes the
/// answer for the client's protocol version.
///
/// An error returned by a method is passed to the server's error callbacks
/// (stage `receive`) and closes the connection.
///
/// PHP: `Utopia\Mqtt\Handler`.
pub trait Handler: Send + Sync + 'static {
    /// A client connects: accept or refuse it, or start enhanced
    /// authentication.
    ///
    /// PHP: `onConnect(Connect $connect, Connection $connection): Connack|Auth`.
    fn on_connect(&self, connect: &Connect, connection: &Connection) -> Result<ConnectReply, Error>;

    /// A client continues authentication or re-authenticates.
    ///
    /// PHP: `onAuthenticate(Auth $auth, Connection $connection): Connack|Auth|Disconnect`.
    fn on_authenticate(&self, auth: &Auth, connection: &Connection) -> Result<AuthReply, Error>;

    /// A client subscribes: grant or deny each filter, in order. Granted
    /// filters (a code of 2 or less) are added to the store.
    ///
    /// PHP: `onSubscribe(Subscribe $subscribe, Connection $connection): Suback`.
    fn on_subscribe(&self, subscribe: &Subscribe, connection: &Connection) -> Result<Suback, Error>;

    /// A client unsubscribes (its filters are already out of the store).
    ///
    /// PHP: `onUnsubscribe(Unsubscribe $unsubscribe, Connection $connection): Unsuback`.
    fn on_unsubscribe(&self, unsubscribe: &Unsubscribe, connection: &Connection) -> Result<Unsuback, Error>;

    /// A client publishes; `subscribers` are the open connections whose
    /// filters match the topic under the publisher's prefix, each with its
    /// granted QoS. Delivering (and acknowledging a QoS 1 publish) is the
    /// handler's to do.
    ///
    /// PHP: `onPublish(Publish $publish, Connection $connection, iterable $subscribers): void`.
    fn on_publish(&self, publish: &Publish, connection: &Connection, subscribers: &[Subscriber]) -> Result<(), Error>;

    /// A client acknowledges a QoS 1 delivery.
    ///
    /// PHP: `onPuback(Puback $puback, Connection $connection): void`.
    fn on_puback(&self, puback: &Puback, connection: &Connection) -> Result<(), Error>;

    /// A client leaves: with its DISCONNECT, or `None` when the connection
    /// closed without one.
    ///
    /// PHP: `onDisconnect(?Disconnect $disconnect, Connection $connection): void`.
    fn on_disconnect(&self, disconnect: Option<&Disconnect>, connection: &Connection) -> Result<(), Error>;
}

type ErrorHook = Arc<dyn Fn(&Error, &str) + Send + Sync>;
type Hook = Arc<dyn Fn() + Send + Sync>;
type WorkerHook = Arc<dyn Fn(i64) + Send + Sync>;

struct Metrics {
    packets_received: Counter,
    connections_opened: Counter,
    connections_active: UpDownCounter,
    subscriptions: Counter,
}

impl Metrics {
    fn new(telemetry: &dyn Telemetry) -> Self {
        Self {
            packets_received: telemetry.counter("mqtt.packets.received", None, None),
            connections_opened: telemetry.counter("mqtt.connections.opened", None, None),
            connections_active: telemetry.up_down_counter("mqtt.connections.active", None, None),
            subscriptions: telemetry.counter("mqtt.subscriptions", None, None),
        }
    }
}

#[derive(Default)]
struct Callbacks {
    errors: Vec<ErrorHook>,
    start: Option<Hook>,
    worker_start: Option<WorkerHook>,
    stop: Option<Hook>,
}

struct State {
    connections: HashMap<i64, Arc<Connection>>,
    store: Store,
}

struct Inner<A, H> {
    adapter: A,
    handler: H,
    state: Mutex<State>,
    metrics: RwLock<Metrics>,
    callbacks: RwLock<Callbacks>,
}

/// An MQTT broker over an [`Adapter`], with a [`Handler`] making its
/// decisions. It speaks MQTT 3.1.1 and 5.0, keeps the subscriptions in a
/// [`Store`], answers PINGREQ, schedules each connection's keep-alive on the
/// adapter's timer (30 seconds to CONNECT, then the negotiated keep-alive,
/// refreshed by every packet), and counts packets, connections and
/// subscriptions on a telemetry backend.
///
/// A packet that fails to decode, a handler error or a failed send is passed
/// to the [`error`](Server::error) callbacks with the stage `receive`, and
/// the connection is closed.
///
/// Clones share the same broker.
///
/// ```
/// use utopia_mqtt::packet::{Auth, Connack, Connect, Disconnect, Puback, Publish, Suback, Subscribe, Unsuback, Unsubscribe};
/// use utopia_mqtt::server::{AuthReply, ConnectReply, Subscriber};
/// use utopia_mqtt::spec::v5;
/// use utopia_mqtt::{Connection, Error, Handler, Memory, Server};
///
/// struct Echo;
///
/// impl Handler for Echo {
///     fn on_connect(&self, _: &Connect, _: &Connection) -> Result<ConnectReply, Error> {
///         Ok(ConnectReply::Connack(Connack::accept(false, None)))
///     }
///     fn on_authenticate(&self, auth: &Auth, _: &Connection) -> Result<AuthReply, Error> {
///         Ok(AuthReply::Auth(Auth::success(&auth.method)))
///     }
///     fn on_subscribe(&self, subscribe: &Subscribe, _: &Connection) -> Result<Suback, Error> {
///         let mut suback = Suback::new();
///         subscribe.filters.iter().for_each(|f| { suback.grant(f.qos.min(1)); });
///         Ok(suback)
///     }
///     fn on_unsubscribe(&self, unsubscribe: &Unsubscribe, _: &Connection) -> Result<Unsuback, Error> {
///         let mut unsuback = Unsuback::new();
///         unsubscribe.filters.iter().for_each(|_| { unsuback.success(); });
///         Ok(unsuback)
///     }
///     fn on_publish(&self, publish: &Publish, _: &Connection, subscribers: &[Subscriber]) -> Result<(), Error> {
///         for (subscriber, qos) in subscribers {
///             subscriber.publish(&publish.topic, &publish.payload, *qos, false, None)?;
///         }
///         Ok(())
///     }
///     fn on_puback(&self, _: &Puback, _: &Connection) -> Result<(), Error> { Ok(()) }
///     fn on_disconnect(&self, _: Option<&Disconnect>, _: &Connection) -> Result<(), Error> { Ok(()) }
/// }
///
/// let adapter = Memory::new();
/// let server = Server::new(adapter.clone(), Echo);
/// # tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
/// server.start().await;
/// # });
///
/// adapter.open(1)?;
/// adapter.receive(1, &v5::connect(b"client", 60, true, None)?)?;
/// adapter.receive(1, &v5::subscribe(1, &[b"news/+"], 0, None)?)?;
/// adapter.receive(1, &v5::publish(b"news/tech", b"hello", 0, 0, None, false)?)?;
///
/// let sent = adapter.take_sent();
/// assert_eq!(sent[0].data, v5::connack(0, None)?);
/// assert_eq!(sent[2].data, v5::publish(b"news/tech", b"hello", 0, 0, None, false)?);
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Mqtt\Adapter\Swoole;
/// use Utopia\Mqtt\Server;
///
/// // $handler implements Utopia\Mqtt\Handler
/// // $server = new Server(new Swoole([new Swoole\Tcp('0.0.0.0', 1883)]), $handler);
/// // $server->start();
/// echo Server::class, "\n";
/// ```
///
/// PHP: `Utopia\Mqtt\Server`.
pub struct Server<A: Adapter, H: Handler> {
    inner: Arc<Inner<A, H>>,
}

impl<A: Adapter, H: Handler> Clone for Server<A, H> {
    fn clone(&self) -> Self {
        Self { inner: self.inner.clone() }
    }
}

impl<A: Adapter + Send + Sync, H: Handler> Server<A, H> {
    /// A broker on `adapter`, deciding with `handler`, with an empty store
    /// and no telemetry.
    ///
    /// PHP: `new Server(Adapter $adapter, Handler $handler, Store $store = new Store())`.
    pub fn new(adapter: A, handler: H) -> Self {
        Self::with_store(adapter, handler, Store::new())
    }

    /// A broker keeping its subscriptions in `store`.
    pub fn with_store(adapter: A, handler: H, store: Store) -> Self {
        Self {
            inner: Arc::new(Inner {
                adapter,
                handler,
                state: Mutex::new(State { connections: HashMap::new(), store }),
                metrics: RwLock::new(Metrics::new(&NoTelemetry)),
                callbacks: RwLock::default(),
            }),
        }
    }

    /// Counts on `telemetry`: `mqtt.packets.received` (by `type`),
    /// `mqtt.connections.opened` (by `result`), `mqtt.connections.active`
    /// and `mqtt.subscriptions` (by `result`).
    ///
    /// PHP: `setTelemetry(Telemetry $telemetry)`.
    pub fn set_telemetry(&self, telemetry: &dyn Telemetry) -> &Self {
        *self.inner.metrics.write().unwrap_or_else(|e| e.into_inner()) = Metrics::new(telemetry);
        self
    }

    /// Sets the callback run once the adapter is listening.
    ///
    /// PHP: `onStart(callable $callback)`.
    pub fn on_start(&self, callback: impl Fn() + Send + Sync + 'static) -> &Self {
        self.callbacks().start = Some(Arc::new(callback));
        self
    }

    /// Sets the callback run with each worker id as it starts.
    ///
    /// PHP: `onWorkerStart(callable $callback)`.
    pub fn on_worker_start(&self, callback: impl Fn(i64) + Send + Sync + 'static) -> &Self {
        self.callbacks().worker_start = Some(Arc::new(callback));
        self
    }

    /// Sets the callback run after [`stop`](Server::stop).
    ///
    /// PHP: `onStop(callable $callback)`.
    pub fn on_stop(&self, callback: impl Fn() + Send + Sync + 'static) -> &Self {
        self.callbacks().stop = Some(Arc::new(callback));
        self
    }

    /// Adds a callback for failures, with the stage that failed (`start`,
    /// `shutdown`, `receive`).
    ///
    /// PHP: `error(callable $callback)`.
    pub fn error(&self, callback: impl Fn(&Error, &str) + Send + Sync + 'static) -> &Self {
        self.callbacks().errors.push(Arc::new(callback));
        self
    }

    fn callbacks(&self) -> std::sync::RwLockWriteGuard<'_, Callbacks> {
        self.inner.callbacks.write().unwrap_or_else(|e| e.into_inner())
    }

    fn state(&self) -> MutexGuard<'_, State> {
        self.inner.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// Registers the broker's hooks on the adapter and serves until it shuts
    /// down. A failure to start goes to the error callbacks.
    ///
    /// PHP: `start()`.
    pub async fn start(&self) {
        let adapter = &self.inner.adapter;
        let (opened, received, closed) = (self.clone(), self.clone(), self.clone());
        adapter.on_open(Arc::new(move |fd| opened.opened(fd)));
        adapter.on_receive(Arc::new(move |fd, data| received.receive(fd, data)));
        adapter.on_close(Arc::new(move |fd| closed.closed(fd)));
        let (start, worker_start) = {
            let callbacks = self.inner.callbacks.read().unwrap_or_else(|e| e.into_inner());
            (callbacks.start.clone(), callbacks.worker_start.clone())
        };
        if let Some(start) = start {
            adapter.on_start(start);
        }
        if let Some(worker_start) = worker_start {
            adapter.on_worker_start(worker_start);
        }
        if let Err(error) = adapter.start().await {
            self.handle_error(&error, "start");
        }
    }

    /// Shuts the adapter down (a failure goes to the error callbacks), then
    /// runs the stop callback.
    ///
    /// PHP: `stop()`.
    pub fn stop(&self) {
        if let Err(error) = self.inner.adapter.shutdown() {
            self.handle_error(&error, "shutdown");
        }
        let stop = self.inner.callbacks.read().unwrap_or_else(|e| e.into_inner()).stop.clone();
        if let Some(stop) = stop {
            stop();
        }
    }

    /// The open connections subscribed to `topic` under `prefix`, each with
    /// its granted QoS.
    ///
    /// PHP: `subscribers(string $prefix, string $topic)`.
    pub fn subscribers(&self, prefix: &[u8], topic: &[u8]) -> Vec<Subscriber> {
        let state = self.state();
        state
            .store
            .subscribers(prefix, topic)
            .into_iter()
            .filter_map(|(fd, qos)| state.connections.get(&fd).map(|c| (c.clone(), qos)))
            .collect()
    }

    /// The broker's record of an open connection.
    pub fn connection(&self, fd: i64) -> Option<Arc<Connection>> {
        self.state().connections.get(&fd).cloned()
    }

    fn open(&self, fd: i64) -> Arc<Connection> {
        let outbound: Arc<dyn Outbound> = Arc::new(self.inner.adapter.clone());
        Arc::new(Connection::new(fd, Some(outbound)))
    }

    fn connection_or_open(&self, fd: i64) -> Arc<Connection> {
        if let Some(connection) = self.state().connections.get(&fd) {
            return connection.clone();
        }
        let connection = self.open(fd);
        self.state().connections.entry(fd).or_insert(connection).clone()
    }

    fn receive(&self, fd: i64, data: &[u8]) -> Result<(), Error> {
        let connection = self.connection_or_open(fd);
        if let Err(error) = Packet::parse(data).and_then(|packet| self.dispatch(&packet, &connection)) {
            self.handle_error(&error, "receive");
            return self.inner.adapter.close(fd);
        }
        if connection.state().active {
            let keep_alive = connection.state().keep_alive;
            self.inner.adapter.timer().schedule(fd, i64::from(keep_alive));
        }
        Ok(())
    }

    fn dispatch(&self, packet: &Packet<'_>, connection: &Arc<Connection>) -> Result<(), Error> {
        let adapter = &self.inner.adapter;
        let fd = connection.fd();
        self.metrics().packets_received.add(1, &[("type", Attribute::from(packet.name()))]);
        match packet.kind {
            Kind::Connect => {
                let connect = Connect::decode(packet.body)?;
                {
                    let mut state = connection.state();
                    state.protocol = connect.protocol_level;
                    state.clean_start = connect.clean_start;
                    state.keep_alive = connect.keep_alive;
                }
                connection.set_client_id(&connect.client_id);
                let reply = self.inner.handler.on_connect(&connect, connection)?;
                let protocol = connection.state().protocol;
                let encoded = match &reply {
                    ConnectReply::Connack(connack) => encode_connack(connack, protocol)?,
                    ConnectReply::Auth(auth) => encode_auth(auth)?,
                };
                adapter.send(fd, &encoded)?;
                if let ConnectReply::Connack(connack) = reply {
                    if connack.accepted() {
                        connection.state().active = true;
                        let metrics = self.metrics();
                        metrics.connections_opened.add(1, &[("result", Attribute::from("accepted"))]);
                        metrics.connections_active.add(1, &[]);
                    } else {
                        self.metrics().connections_opened.add(1, &[("result", Attribute::from("rejected"))]);
                        adapter.close(fd)?;
                    }
                }
            }
            Kind::Auth => {
                let auth = Auth::decode(packet.body)?;
                let reply = self.inner.handler.on_authenticate(&auth, connection)?;
                let protocol = connection.state().protocol;
                let encoded = match &reply {
                    AuthReply::Connack(connack) => encode_connack(connack, protocol)?,
                    AuthReply::Auth(auth) => encode_auth(auth)?,
                    AuthReply::Disconnect(disconnect) => encode_disconnect(disconnect, protocol)?,
                };
                adapter.send(fd, &encoded)?;
                if let AuthReply::Disconnect(_) = reply {
                    adapter.close(fd)?;
                }
            }
            Kind::Subscribe => {
                let subscribe = Subscribe::decode(packet.body, connection.state().protocol)?;
                let suback = self.inner.handler.on_subscribe(&subscribe, connection)?;
                self.record(&subscribe, &suback, connection);
                let protocol = connection.state().protocol;
                adapter.send(fd, &encode_suback(&suback, subscribe.packet_id, protocol)?)?;
            }
            Kind::Unsubscribe => {
                let unsubscribe = Unsubscribe::decode(packet.body, connection.state().protocol)?;
                {
                    let mut state = self.state();
                    for filter in &unsubscribe.filters {
                        state.store.unsubscribe(filter, fd);
                    }
                }
                let unsuback = self.inner.handler.on_unsubscribe(&unsubscribe, connection)?;
                let protocol = connection.state().protocol;
                let id = unsubscribe.packet_id.to_be_bytes();
                let encoded = if protocol >= v5::PROTOCOL_LEVEL {
                    v5::unsuback(&id, unsuback.codes().len(), None)?
                } else {
                    v3::unsuback(&id)
                };
                adapter.send(fd, &encoded)?;
            }
            Kind::Publish => {
                let (protocol, prefix) = {
                    let state = connection.state();
                    (state.protocol, state.prefix.clone())
                };
                let publish = Publish::decode(packet, protocol)?;
                let subscribers = self.subscribers(&prefix, &publish.topic);
                self.inner.handler.on_publish(&publish, connection, &subscribers)?;
            }
            Kind::Puback => self.inner.handler.on_puback(&Puback::decode(packet.body), connection)?,
            Kind::Pingreq => adapter.send(fd, &pingresp())?,
            Kind::Disconnect => {
                self.inner.handler.on_disconnect(Some(&Disconnect::decode(packet.body)), connection)?;
                self.cleanup(fd);
                adapter.close(fd)?;
            }
            _ => {}
        }
        Ok(())
    }

    fn metrics(&self) -> std::sync::RwLockReadGuard<'_, Metrics> {
        self.inner.metrics.read().unwrap_or_else(|e| e.into_inner())
    }

    fn opened(&self, fd: i64) -> Result<(), Error> {
        self.connection_or_open(fd);
        // A bounded window to CONNECT: CONNECT re-arms this to the keep-alive,
        // so a connection that never authenticates is reaped.
        self.inner.adapter.timer().schedule(fd, CONNECT_GRACE);
        Ok(())
    }

    fn closed(&self, fd: i64) -> Result<(), Error> {
        let Some(connection) = self.state().connections.get(&fd).cloned() else {
            return Ok(());
        };
        self.inner.handler.on_disconnect(None, &connection)?;
        self.cleanup(fd);
        Ok(())
    }

    fn cleanup(&self, fd: i64) {
        let active = self.state().connections.get(&fd).is_some_and(|c| c.state().active);
        if active {
            self.metrics().connections_active.add(-1, &[]);
        }
        self.inner.adapter.timer().clear(fd);
        let mut state = self.state();
        state.store.close(fd);
        state.connections.remove(&fd);
    }

    fn record(&self, subscribe: &Subscribe, suback: &Suback, connection: &Connection) {
        let codes = suback.codes();
        let prefix = connection.state().prefix.clone();
        let client_id = connection.client_id();
        for (index, filter) in subscribe.filters.iter().enumerate() {
            let code = codes.get(index).copied().unwrap_or(Suback::DENIED);
            if code <= MAX_QOS {
                self.state().store.subscribe(&prefix, &client_id, &filter.topic, connection.fd(), code);
                self.metrics().subscriptions.add(1, &[("result", Attribute::from("granted"))]);
            } else {
                self.metrics().subscriptions.add(1, &[("result", Attribute::from("denied"))]);
            }
        }
    }

    fn handle_error(&self, error: &Error, stage: &str) {
        let callbacks = self.inner.callbacks.read().unwrap_or_else(|e| e.into_inner()).errors.clone();
        for callback in callbacks {
            callback(error, stage);
        }
    }
}

/// A CONNACK for the client's protocol: 5.0 gets the reason code and
/// properties; 3.1.1 the closest return code. The Session Present flag is
/// not sent, as in PHP (a quirk the compat spec records).
fn encode_connack(connack: &Connack, protocol: u8) -> Result<Vec<u8>, Error> {
    if protocol >= v5::PROTOCOL_LEVEL {
        return v5::connack(connack.reason_code, connack.properties.as_ref());
    }
    Ok(v3::connack(match connack.reason_code {
        Connack::SUCCESS => v3::RETURN_ACCEPTED,
        Connack::BAD_CREDENTIALS => v3::RETURN_BAD_CREDENTIALS,
        Connack::SERVER_UNAVAILABLE | Connack::SERVER_BUSY => v3::RETURN_SERVER_UNAVAILABLE,
        _ => v3::RETURN_NOT_AUTHORIZED,
    }))
}

/// An AUTH, its method and data (when not empty) as properties.
fn encode_auth(auth: &Auth) -> Result<Vec<u8>, Error> {
    let mut properties = Properties::new();
    if !auth.method.is_empty() {
        properties.add(Property::bytes(Property::AUTHENTICATION_METHOD, auth.method.clone()));
    }
    if !auth.data.is_empty() {
        properties.add(Property::bytes(Property::AUTHENTICATION_DATA, auth.data.clone()));
    }
    v5::auth(auth.reason_code, Some(&properties))
}

fn encode_disconnect(disconnect: &Disconnect, protocol: u8) -> Result<Vec<u8>, Error> {
    if protocol >= v5::PROTOCOL_LEVEL {
        v5::disconnect(disconnect.reason_code, disconnect.properties.as_ref())
    } else {
        Ok(v3::disconnect())
    }
}

/// A SUBACK for the client's protocol: 5.0 sends every code; 3.1.1 turns a
/// code above QoS 2 into its failure marker.
fn encode_suback(suback: &Suback, packet_id: u16, protocol: u8) -> Result<Vec<u8>, Error> {
    let id = packet_id.to_be_bytes();
    if protocol >= v5::PROTOCOL_LEVEL {
        return v5::suback(&id, suback.codes(), None);
    }
    let codes: Vec<u8> =
        suback.codes().iter().map(|&code| if code <= MAX_QOS { code } else { v3::SUBSCRIBE_FAILURE }).collect();
    Ok(v3::suback(&id, &codes))
}
