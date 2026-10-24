An MQTT broker keeps connections open and routes what clients publish to the clients subscribed. It has two lifecycles. The server's: it starts, serves until it is stopped, and stops. And each connection's: it opens, sends CONNECT, then any number of packets, and closes. Your `Handler` makes the decisions. The server decodes each packet, keeps the subscription store and the keep-alive timer, and encodes every answer in the client's protocol version, 3.1.1 or 5.0.

## The server

```flow
Build | `Server::new` with an adapter and a `Handler`, then the `on_start`, `on_worker_start`, `on_stop` and `error` callbacks
Start | `start` puts the server's open, receive and close hooks on the adapter, hands it the start callbacks, and starts it
Listen | `Network` binds every transport: TCP, TLS and WebSocket
On start | the `on_start` callback, once every listener is bound
Worker start | the `on_worker_start` callback, once for each worker id
> Until `stop` is called
Serve | each connection runs the lifecycle below
<
Stop | `stop` shuts the adapter down, then runs `on_stop`. `Network` closes every open connection, and `start` returns
! Error callbacks | the adapter could not start, such as a port in use. They get the error with the stage `start`, and `start` returns
```

Set the callbacks before `start`: that is when the server hands them to the adapter. Each `on_*` method replaces the callback set before. Clones of a server share one broker, so a clone can call `stop` from a signal handler while another awaits `start`.

The examples run on the `Memory` adapter, which has no network. Its `start` runs the start callbacks and returns at once, and the test then plays the clients with `open`, `receive` and `disconnect`. The handler below accepts every client and logs each call. Later examples reuse it:

```
use std::sync::{Arc, Mutex};
use utopia_mqtt::packet::{Auth, Connack, Connect, Disconnect, Puback, Publish, Suback, Subscribe, Unsuback, Unsubscribe};
use utopia_mqtt::server::{AuthReply, ConnectReply, Subscriber};
use utopia_mqtt::spec::v5;
use utopia_mqtt::{Connection, Error, Handler, Memory, Server};

type Log = Arc<Mutex<Vec<String>>>;

fn text(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}

/// Accepts everyone, grants up to QoS 1, refuses `admin/` topics,
/// delivers to every subscriber, and logs each call.
struct Recorder(Log);

impl Recorder {
    fn log(&self, connection: &Connection, step: String) {
        self.0.lock().unwrap().push(format!("{} {step}", connection.fd()));
    }
}

impl Handler for Recorder {
    fn on_connect(&self, connect: &Connect, connection: &Connection) -> Result<ConnectReply, Error> {
        self.log(connection, format!("connect {}", text(&connect.client_id)));
        Ok(ConnectReply::Connack(Connack::accept(false, None)))
    }
    fn on_authenticate(&self, auth: &Auth, _: &Connection) -> Result<AuthReply, Error> {
        Ok(AuthReply::Auth(Auth::success(&auth.method)))
    }
    fn on_subscribe(&self, subscribe: &Subscribe, connection: &Connection) -> Result<Suback, Error> {
        let mut suback = Suback::new();
        for filter in &subscribe.filters {
            self.log(connection, format!("subscribe {}", text(&filter.topic)));
            suback.grant(filter.qos.min(1));
        }
        Ok(suback)
    }
    fn on_unsubscribe(&self, unsubscribe: &Unsubscribe, _: &Connection) -> Result<Unsuback, Error> {
        let mut unsuback = Unsuback::new();
        for _ in &unsubscribe.filters {
            unsuback.success();
        }
        Ok(unsuback)
    }
    fn on_publish(&self, publish: &Publish, connection: &Connection, subscribers: &[Subscriber]) -> Result<(), Error> {
        if publish.topic.starts_with(b"admin/") {
            return Err(Error::Runtime("not allowed".into()));
        }
        self.log(connection, format!("publish {} to {}", text(&publish.topic), subscribers.len()));
        for (subscriber, qos) in subscribers {
            subscriber.publish(&publish.topic, &publish.payload, *qos, false, None)?;
        }
        Ok(())
    }
    fn on_puback(&self, puback: &Puback, connection: &Connection) -> Result<(), Error> {
        self.log(connection, format!("puback {}", puback.packet_id));
        Ok(())
    }
    fn on_disconnect(&self, disconnect: Option<&Disconnect>, connection: &Connection) -> Result<(), Error> {
        self.log(connection, if disconnect.is_some() { "disconnect".into() } else { "gone".into() });
        Ok(())
    }
}

let log: Log = Arc::default();
let adapter = Memory::new();
let server = Server::new(adapter.clone(), Recorder(log.clone()));
let (start, worker, stop) = (log.clone(), log.clone(), log.clone());
server
    .on_start(move || start.lock().unwrap().push("start".into()))
    .on_worker_start(move |id| worker.lock().unwrap().push(format!("worker {id} start")))
    .on_stop(move || stop.lock().unwrap().push("stop".into()));

utopia_async::Builder::current_thread().build()?.block_on(server.start());
adapter.open(1)?;
adapter.receive(1, &v5::connect(b"sensor", 60, true, None)?)?;
adapter.disconnect(1)?;
server.stop();

assert_eq!(*log.lock().unwrap(), ["start", "worker 0 start", "1 connect sensor", "1 gone", "stop"]);
# Ok::<(), Box<dyn std::error::Error>>(())
```

## A connection

```flow
Open | the server keeps a record of the connection and schedules it on the timer, with 30 seconds of keep-alive to send CONNECT
CONNECT | the protocol version, Clean Start, keep-alive and client id are stored, then `on_connect` decides. Accepted: the CONNACK goes out and the connection is active. Refused: the CONNACK goes out and the connection is closed. A second CONNECT on the same connection is a protocol error: a 5.0 client gets a DISCONNECT with reason 0x82, and the connection is closed
AUTH | 5.0 enhanced authentication. `on_authenticate` answers with an AUTH, a CONNACK, or a DISCONNECT that closes the connection
> For each packet
SUBSCRIBE | `on_subscribe` grants or denies each filter. The granted ones go into the store, then the SUBACK goes out
UNSUBSCRIBE | the filters leave the store, then `on_unsubscribe` answers and the UNSUBACK goes out
PUBLISH | the store finds the open connections subscribed to the topic under the publisher's prefix. `on_publish` gets them and delivers
PUBACK | `on_puback` gets the client's acknowledgement of a QoS 1 delivery
PINGREQ | the server answers PINGRESP itself
Keep alive | after each packet, an active connection that is still open is scheduled again for the keep-alive it asked for
<
Close | a DISCONNECT calls `on_disconnect` with it. A connection that closes without one, by the client, the server or the timer, calls it with `None`. Then its subscriptions and its timer are cleared
! Close on error | a packet that does not decode, a handler error or a failed send. The error callbacks get it with the stage `receive`, and the connection is closed
```

The handler delivers. `on_publish` gets the subscribers and calls `publish` on each one's `Connection`, which encodes the message for that client's protocol version. Acknowledging a QoS 1 publish with `puback` is the handler's to do too. A connection's prefix scopes its subscriptions and the publishes it reaches. Set `connection.state().prefix` in `on_connect` to keep tenants apart.

In the example, a sensor publishes a reading to a phone subscribed to every room:

```
# use std::sync::{Arc, Mutex};
# use utopia_mqtt::packet::{Auth, Connack, Connect, Disconnect, Puback, Publish, Suback, Subscribe, Unsuback, Unsubscribe};
# use utopia_mqtt::server::{AuthReply, ConnectReply, Subscriber};
# use utopia_mqtt::{Connection, Error, Handler};
# type Log = Arc<Mutex<Vec<String>>>;
# fn text(bytes: &[u8]) -> String { String::from_utf8_lossy(bytes).into_owned() }
# /// Accepts everyone, grants up to QoS 1, refuses `admin/` topics, delivers to every subscriber, and logs each call.
# struct Recorder(Log);
# impl Recorder {
#     fn log(&self, connection: &Connection, step: String) { self.0.lock().unwrap().push(format!("{} {step}", connection.fd())); }
# }
# impl Handler for Recorder {
#     fn on_connect(&self, connect: &Connect, connection: &Connection) -> Result<ConnectReply, Error> {
#         self.log(connection, format!("connect {}", text(&connect.client_id)));
#         Ok(ConnectReply::Connack(Connack::accept(false, None)))
#     }
#     fn on_authenticate(&self, auth: &Auth, _: &Connection) -> Result<AuthReply, Error> {
#         Ok(AuthReply::Auth(Auth::success(&auth.method)))
#     }
#     fn on_subscribe(&self, subscribe: &Subscribe, connection: &Connection) -> Result<Suback, Error> {
#         let mut suback = Suback::new();
#         for filter in &subscribe.filters {
#             self.log(connection, format!("subscribe {}", text(&filter.topic)));
#             suback.grant(filter.qos.min(1));
#         }
#         Ok(suback)
#     }
#     fn on_unsubscribe(&self, unsubscribe: &Unsubscribe, _: &Connection) -> Result<Unsuback, Error> {
#         let mut unsuback = Unsuback::new();
#         for _ in &unsubscribe.filters { unsuback.success(); }
#         Ok(unsuback)
#     }
#     fn on_publish(&self, publish: &Publish, connection: &Connection, subscribers: &[Subscriber]) -> Result<(), Error> {
#         if publish.topic.starts_with(b"admin/") {
#             return Err(Error::Runtime("not allowed".into()));
#         }
#         self.log(connection, format!("publish {} to {}", text(&publish.topic), subscribers.len()));
#         for (subscriber, qos) in subscribers {
#             subscriber.publish(&publish.topic, &publish.payload, *qos, false, None)?;
#         }
#         Ok(())
#     }
#     fn on_puback(&self, puback: &Puback, connection: &Connection) -> Result<(), Error> {
#         self.log(connection, format!("puback {}", puback.packet_id));
#         Ok(())
#     }
#     fn on_disconnect(&self, disconnect: Option<&Disconnect>, connection: &Connection) -> Result<(), Error> {
#         self.log(connection, if disconnect.is_some() { "disconnect".into() } else { "gone".into() });
#         Ok(())
#     }
# }
use utopia_mqtt::packet::pingreq;
use utopia_mqtt::spec::v5;
use utopia_mqtt::{Memory, Server};

let log: Log = Arc::default();
let adapter = Memory::new();
let server = Server::new(adapter.clone(), Recorder(log.clone()));
utopia_async::Builder::current_thread().build()?.block_on(server.start());

adapter.open(1)?;
adapter.receive(1, &v5::connect(b"sensor", 60, true, None)?)?;
adapter.open(2)?;
adapter.receive(2, &v5::connect(b"phone", 60, true, None)?)?;
adapter.receive(2, &v5::subscribe(1, &[b"rooms/+/temp"], 0, None)?)?;
adapter.receive(1, &v5::publish(b"rooms/kitchen/temp", b"21.5", 0, 0, None, false)?)?;
adapter.receive(1, &pingreq())?;
adapter.receive(1, &v5::disconnect(0, None)?)?;

assert_eq!(
    *log.lock().unwrap(),
    ["1 connect sensor", "2 connect phone", "2 subscribe rooms/+/temp", "1 publish rooms/kitchen/temp to 1", "1 disconnect"]
);
let sent_to = |connection| adapter.sent().into_iter().filter(|s| s.connection == connection).map(|s| s.data).collect::<Vec<_>>();
assert_eq!(sent_to(1), [v5::connack(0, None)?, b"\xD0\x00".to_vec()]);
assert_eq!(
    sent_to(2),
    [v5::connack(0, None)?, v5::suback(&1u16.to_be_bytes(), &[0], None)?, v5::publish(b"rooms/kitchen/temp", b"21.5", 0, 0, None, false)?]
);
assert_eq!(adapter.closed(), [1]);
# Ok::<(), Box<dyn std::error::Error>>(())
```

## Silent clients

```flow
Open | scheduled with a 30-second keep-alive, the time it has to send CONNECT
CONNECT accepted | scheduled again with the keep-alive the client asked for
Each packet | scheduled again, so only a silent client runs out of time
! Reaped | the timer's tick closes the connection. `on_disconnect` gets `None`
```

The adapter's timer holds the deadlines. A `TimingWheel` reaps a connection once it has been silent for 1.5 keep-alive intervals, checking every 20 seconds by default. `NoTimer`, the `Memory` adapter's default, never reaps. A connection that never sends CONNECT is reaped too, so an idle socket cannot hold a slot.

In the example, the wheel is drained by hand. Connection 1 asked for a 10-second keep-alive. Connection 2 never sent CONNECT:

```
# use std::sync::{Arc, Mutex};
# use utopia_mqtt::packet::{Auth, Connack, Connect, Disconnect, Puback, Publish, Suback, Subscribe, Unsuback, Unsubscribe};
# use utopia_mqtt::server::{AuthReply, ConnectReply, Subscriber};
# use utopia_mqtt::{Connection, Error, Handler};
# type Log = Arc<Mutex<Vec<String>>>;
# fn text(bytes: &[u8]) -> String { String::from_utf8_lossy(bytes).into_owned() }
# /// Accepts everyone, grants up to QoS 1, refuses `admin/` topics, delivers to every subscriber, and logs each call.
# struct Recorder(Log);
# impl Recorder {
#     fn log(&self, connection: &Connection, step: String) { self.0.lock().unwrap().push(format!("{} {step}", connection.fd())); }
# }
# impl Handler for Recorder {
#     fn on_connect(&self, connect: &Connect, connection: &Connection) -> Result<ConnectReply, Error> {
#         self.log(connection, format!("connect {}", text(&connect.client_id)));
#         Ok(ConnectReply::Connack(Connack::accept(false, None)))
#     }
#     fn on_authenticate(&self, auth: &Auth, _: &Connection) -> Result<AuthReply, Error> {
#         Ok(AuthReply::Auth(Auth::success(&auth.method)))
#     }
#     fn on_subscribe(&self, subscribe: &Subscribe, connection: &Connection) -> Result<Suback, Error> {
#         let mut suback = Suback::new();
#         for filter in &subscribe.filters {
#             self.log(connection, format!("subscribe {}", text(&filter.topic)));
#             suback.grant(filter.qos.min(1));
#         }
#         Ok(suback)
#     }
#     fn on_unsubscribe(&self, unsubscribe: &Unsubscribe, _: &Connection) -> Result<Unsuback, Error> {
#         let mut unsuback = Unsuback::new();
#         for _ in &unsubscribe.filters { unsuback.success(); }
#         Ok(unsuback)
#     }
#     fn on_publish(&self, publish: &Publish, connection: &Connection, subscribers: &[Subscriber]) -> Result<(), Error> {
#         if publish.topic.starts_with(b"admin/") {
#             return Err(Error::Runtime("not allowed".into()));
#         }
#         self.log(connection, format!("publish {} to {}", text(&publish.topic), subscribers.len()));
#         for (subscriber, qos) in subscribers {
#             subscriber.publish(&publish.topic, &publish.payload, *qos, false, None)?;
#         }
#         Ok(())
#     }
#     fn on_puback(&self, puback: &Puback, connection: &Connection) -> Result<(), Error> {
#         self.log(connection, format!("puback {}", puback.packet_id));
#         Ok(())
#     }
#     fn on_disconnect(&self, disconnect: Option<&Disconnect>, connection: &Connection) -> Result<(), Error> {
#         self.log(connection, if disconnect.is_some() { "disconnect".into() } else { "gone".into() });
#         Ok(())
#     }
# }
use utopia_mqtt::spec::v5;
use utopia_mqtt::{Memory, Server, TimingWheel};

let now = php_std::datetime::time();
let wheel = Arc::new(TimingWheel::new(20, 1.5, Some(now)));
let log: Log = Arc::default();
let adapter = Memory::with_timer(wheel.clone());
let server = Server::new(adapter.clone(), Recorder(log.clone()));
utopia_async::Builder::current_thread().build()?.block_on(server.start());

adapter.open(1)?;
adapter.receive(1, &v5::connect(b"sensor", 10, true, None)?)?;
adapter.open(2)?;

wheel.drain(now + 20); // 15 seconds of silence for connection 1
assert_eq!(adapter.closed(), [1]);
wheel.drain(now + 50); // 45 seconds without CONNECT for connection 2
assert_eq!(adapter.closed(), [1, 2]);
assert_eq!(*log.lock().unwrap(), ["1 connect sensor", "1 gone", "2 gone"]);
# Ok::<(), Box<dyn std::error::Error>>(())
```

## When something fails

```flow
Any step | starting the adapter, a packet, or stopping
! Error callbacks | every callback added with `error` gets the error and the stage that failed: `start`, `receive` or `shutdown`. Nothing is returned to the caller
```

A failure in one connection closes that connection only. Without an error callback, the failure is dropped.

| Failure | Stage | Then |
|---|---|---|
| The adapter cannot start | `start` | `start` returns, and nothing is served |
| A packet that does not decode | `receive` | The connection is closed, and `on_disconnect` gets `None` |
| A handler method returns an error | `receive` | The connection is closed, and `on_disconnect` gets `None` |
| A send fails | `receive` | The connection is closed, and `on_disconnect` gets `None` |
| The adapter cannot shut down | `shutdown` | `on_stop` still runs |
| `on_connect` refuses the client | None: it is not an error | The CONNACK with the reason goes out, then the connection is closed |

In the example, the handler refuses a publish to an `admin/` topic. The error callback runs before the connection closes:

```
# use std::sync::{Arc, Mutex};
# use utopia_mqtt::packet::{Auth, Connack, Connect, Disconnect, Puback, Publish, Suback, Subscribe, Unsuback, Unsubscribe};
# use utopia_mqtt::server::{AuthReply, ConnectReply, Subscriber};
# use utopia_mqtt::{Connection, Error, Handler};
# type Log = Arc<Mutex<Vec<String>>>;
# fn text(bytes: &[u8]) -> String { String::from_utf8_lossy(bytes).into_owned() }
# /// Accepts everyone, grants up to QoS 1, refuses `admin/` topics, delivers to every subscriber, and logs each call.
# struct Recorder(Log);
# impl Recorder {
#     fn log(&self, connection: &Connection, step: String) { self.0.lock().unwrap().push(format!("{} {step}", connection.fd())); }
# }
# impl Handler for Recorder {
#     fn on_connect(&self, connect: &Connect, connection: &Connection) -> Result<ConnectReply, Error> {
#         self.log(connection, format!("connect {}", text(&connect.client_id)));
#         Ok(ConnectReply::Connack(Connack::accept(false, None)))
#     }
#     fn on_authenticate(&self, auth: &Auth, _: &Connection) -> Result<AuthReply, Error> {
#         Ok(AuthReply::Auth(Auth::success(&auth.method)))
#     }
#     fn on_subscribe(&self, subscribe: &Subscribe, connection: &Connection) -> Result<Suback, Error> {
#         let mut suback = Suback::new();
#         for filter in &subscribe.filters {
#             self.log(connection, format!("subscribe {}", text(&filter.topic)));
#             suback.grant(filter.qos.min(1));
#         }
#         Ok(suback)
#     }
#     fn on_unsubscribe(&self, unsubscribe: &Unsubscribe, _: &Connection) -> Result<Unsuback, Error> {
#         let mut unsuback = Unsuback::new();
#         for _ in &unsubscribe.filters { unsuback.success(); }
#         Ok(unsuback)
#     }
#     fn on_publish(&self, publish: &Publish, connection: &Connection, subscribers: &[Subscriber]) -> Result<(), Error> {
#         if publish.topic.starts_with(b"admin/") {
#             return Err(Error::Runtime("not allowed".into()));
#         }
#         self.log(connection, format!("publish {} to {}", text(&publish.topic), subscribers.len()));
#         for (subscriber, qos) in subscribers {
#             subscriber.publish(&publish.topic, &publish.payload, *qos, false, None)?;
#         }
#         Ok(())
#     }
#     fn on_puback(&self, puback: &Puback, connection: &Connection) -> Result<(), Error> {
#         self.log(connection, format!("puback {}", puback.packet_id));
#         Ok(())
#     }
#     fn on_disconnect(&self, disconnect: Option<&Disconnect>, connection: &Connection) -> Result<(), Error> {
#         self.log(connection, if disconnect.is_some() { "disconnect".into() } else { "gone".into() });
#         Ok(())
#     }
# }
use utopia_mqtt::spec::v5;
use utopia_mqtt::{Memory, Server};

let log: Log = Arc::default();
let adapter = Memory::new();
let server = Server::new(adapter.clone(), Recorder(log.clone()));
let failures = log.clone();
server.error(move |error, stage| failures.lock().unwrap().push(format!("{stage}: {error}")));
utopia_async::Builder::current_thread().build()?.block_on(server.start());

adapter.open(1)?;
adapter.receive(1, &v5::connect(b"sensor", 60, true, None)?)?;
adapter.receive(1, &v5::publish(b"admin/reset", b"now", 0, 0, None, false)?)?;

assert_eq!(*log.lock().unwrap(), ["1 connect sensor", "receive: not allowed", "1 gone"]);
assert_eq!(adapter.closed(), [1]);
# Ok::<(), Box<dyn std::error::Error>>(())
```
