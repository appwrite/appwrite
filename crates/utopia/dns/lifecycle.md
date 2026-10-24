A DNS server answers packets. It has two lifecycles. The server's: it binds its transports, runs the worker start callbacks, and serves until its future is dropped. And each packet's: it is decoded, checked, resolved and encoded to fit the transport it came on. There are no hooks around a packet. Your code goes in the resolver, and a resolver that wraps another one is the place for tracing, rate limits or caching.

## The server

```flow
Build | `Server::new` with a resolver, then the `error` handlers and `set_telemetry`
Adapter | `Network::new` with UDP, TCP and HTTPS transports, and its `on_worker_start` callbacks
Start | `start` binds every transport, in order
Worker start | each `on_worker_start` callback, once, with worker id 0, after every transport is bound
> Until the future is dropped
Serve | every transport hands each packet to the server, which runs the lifecycle below. Packets are handled at the same time, but frames on one TCP connection are answered in order
<
! Error handlers | a transport could not be bound. Nothing is served, the error goes to the error handlers, and `start` returns
```

`start` is `bind` and then `serve`. There is no stop method: serving ends when the future is dropped, for example when a shutdown signal wins a `select`, or when the runtime shuts down. Call `bind` yourself to learn the bound addresses before serving, which is how a server on port 0 finds the port it got.

The example serves on two free ports on the loopback address, one UDP and one TCP, and asks the same question over each with the `Client`:

```
use std::sync::{Arc, Mutex};
use std::time::Duration;
use utopia_dns::adapter::{Adapter, Network, Tcp, Transport, Udp};
use utopia_dns::message::{Message, Question, Record};
use utopia_dns::resolver::Memory;
use utopia_dns::{Client, Server, Zone};

let zone = Zone::new(
    "example.test",
    vec![Record::new("www.example.test", Record::TYPE_A).with_ttl(60).with_rdata("192.0.2.1")],
    Record::new("example.test", Record::TYPE_SOA).with_ttl(60).with_rdata("ns1 hostmaster 1 7200 1800 1209600 3600"),
)?;
let server = Arc::new(Server::new(Memory::new(zone)));
let log = Arc::new(Mutex::new(Vec::new()));

let mut adapter = Network::new(vec![
    Transport::Udp(Udp::new("127.0.0.1", 0)),
    Transport::Tcp(Tcp::new("127.0.0.1", 0)),
])?;
let l = log.clone();
adapter.on_worker_start(Box::new(move |worker| l.lock().unwrap().push(format!("worker {worker} start"))));

utopia_async::Runtime::new()?.block_on(async {
    let bound = adapter.bind().await?;
    let addrs = bound.local_addrs();
    utopia_async::spawn(bound.serve(server));

    let query = Message::query(Question::new("www.example.test", Record::TYPE_A), Some(1), true);
    for (kind, addr) in addrs {
        let client = Client::new("127.0.0.1", addr.port(), Duration::from_secs(2), kind == "tcp")?;
        let answer = client.query(&query).await?;
        log.lock().unwrap().push(format!("{kind}: {}", String::from_utf8_lossy(answer.answers()[0].rdata())));
    }
    Ok::<_, utopia_dns::Error>(())
})?;

assert_eq!(*log.lock().unwrap(), ["worker 0 start", "udp: 192.0.2.1", "tcp: 192.0.2.1"]);
# Ok::<(), Box<dyn std::error::Error>>(())
```

## A packet

```flow
> For each packet
Receive | UDP reads a datagram. TCP reads frames with a 2-byte length, after the PROXY protocol header when it is on. HTTPS reads `?dns=` on a `GET`, or an `application/dns-message` body on a `POST`
Decode | a packet too short for a header gets no answer. One whose header decodes but whose sections do not gets `FORMERR`
Check | an opcode other than `QUERY` gets `NOTIMP`. A query without a question gets `FORMERR`. The resolver is not called for either
Resolve | the resolver gets a `Query`: the message, the client's IP address and port, and the transport
Encode | the response is cut to what the transport carries: 512 bytes over UDP, 65535 over TCP and HTTPS. Answers that do not fit are dropped and the `TC` flag is set, so the client asks again over TCP
Send | the packet goes back to the client. An empty answer sends nothing, and over HTTPS it is a `400`
! SERVFAIL | the resolver failed, or the response could not be encoded. The error goes to the error handlers and the client gets `SERVFAIL`
```

`Handler::handle` is the whole lifecycle from decode to encode. The adapter calls it for every packet, and so can a test, without sockets. The client's address comes from the socket, from the PROXY protocol header on TCP, or from the last `X-Forwarded-For` entry over HTTPS when `trust_proxy` is on. Over UDP it can be forged.

The example wraps the in-memory resolver to record each query it is asked. The first packet has an opcode the server does not serve, so it never reaches the resolver:

```
use std::sync::Mutex;
use utopia_dns::message::{Message, Question, Record, RCODE_NOERROR, RCODE_NOTIMP};
use utopia_dns::resolver::{Memory, Resolver};
use utopia_dns::{Error, Handler, Protocol, Query, Server, Zone};

/// The in-memory resolver, recording where each query came from.
struct Traced {
    inner: Memory,
    seen: Mutex<Vec<String>>,
}

impl Resolver for Traced {
    async fn resolve(&self, query: &Query) -> Result<Message, Error> {
        self.seen.lock().unwrap().push(format!("{} from {}:{}", query.protocol.as_str(), query.ip, query.port));
        self.inner.resolve(query).await
    }
}

# utopia_async::Builder::current_thread().build()?.block_on(async {
let zone = Zone::new(
    "example.test",
    vec![Record::new("www.example.test", Record::TYPE_A).with_ttl(60).with_rdata("192.0.2.1")],
    Record::new("example.test", Record::TYPE_SOA).with_ttl(60).with_rdata("ns1 hostmaster 1 7200 1800 1209600 3600"),
)?;
let server = Server::new(Traced { inner: Memory::new(zone), seen: Mutex::default() });
let query = Message::query(Question::new("www.example.test", Record::TYPE_A), Some(7), true).encode(None)?;

// The opcode is bits 3 to 6 of the third byte: 2 is STATUS.
let mut status = query.clone();
status[2] |= 2 << 3;
let answer = Message::decode(&server.handle(&status, "192.0.2.7", 53000, Protocol::Udp).await)?;
assert_eq!(answer.header().response_code, RCODE_NOTIMP);

let answer = Message::decode(&server.handle(&query, "192.0.2.7", 53000, Protocol::Udp).await)?;
assert_eq!(answer.header().response_code, RCODE_NOERROR);
assert_eq!(*server.resolver().seen.lock().unwrap(), ["udp from 192.0.2.7:53000"]);
# Ok::<(), Error>(())
# })?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

## What fits the transport

The resolver answers in full. Encoding then decides how much of the answer the transport carries. Over UDP the answers that do not fit in 512 bytes are dropped and the `TC` flag is set. The same question over TCP gets every answer.

```
use utopia_dns::message::{Message, Question, Record};
use utopia_dns::resolver::Memory;
use utopia_dns::{Handler, Protocol, Server, Zone};

# utopia_async::Builder::current_thread().build()?.block_on(async {
let records = (0..10)
    .map(|i| Record::new("big.example.test", Record::TYPE_TXT).with_ttl(60).with_rdata(format!("{i}{}", "x".repeat(99))))
    .collect();
let zone = Zone::new(
    "example.test",
    records,
    Record::new("example.test", Record::TYPE_SOA).with_ttl(60).with_rdata("ns1 hostmaster 1 7200 1800 1209600 3600"),
)?;
let server = Server::new(Memory::new(zone));
let query = Message::query(Question::new("big.example.test", Record::TYPE_TXT), Some(3), true).encode(None)?;

let udp = Message::decode(&server.handle(&query, "192.0.2.7", 53000, Protocol::Udp).await)?;
assert!(udp.header().truncated);
assert!(udp.answers().len() < 10);

let tcp = Message::decode(&server.handle(&query, "192.0.2.7", 53000, Protocol::Tcp).await)?;
assert!(!tcp.header().truncated);
assert_eq!(tcp.answers().len(), 10);
# Ok::<(), utopia_dns::Error>(())
# })?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

## When something fails

```flow
Any step | decoding a packet, resolving it, encoding the response, or binding a transport
! Error handlers | every handler added with `error` gets the `Error`, in the order they were added. The client still gets an answer when one can be made
```

The server never returns an error, and a failure in one packet does not touch the others. Without an error handler, a failure is dropped.

| Failure | The client gets | Error handlers |
|---|---|---|
| A packet shorter than a header | No answer | Yes |
| A header that decodes, with sections that do not | `FORMERR` | Yes |
| An opcode other than `QUERY` | `NOTIMP` | No |
| A query without a question | `FORMERR` | No |
| The resolver returns an error | `SERVFAIL` | Yes |
| The response cannot be encoded | `SERVFAIL` | Yes |
| A TCP frame of zero length or above `max_frame_size`, more than `max_buffer_size` unanswered, `idle_timeout` of silence, or an answer above 65535 bytes | The connection is closed | No |
| A transport cannot be bound | Nothing is served, and `start` returns | Yes |

The example's resolver is down. The client gets `SERVFAIL` and the error handler gets the reason:

```
use std::sync::{Arc, Mutex};
use utopia_dns::message::{Message, Question, Record, RCODE_SERVFAIL};
use utopia_dns::resolver::Resolver;
use utopia_dns::{Error, Handler, Protocol, Query, Server};

/// A resolver whose backing store is unreachable.
struct Down;

impl Resolver for Down {
    async fn resolve(&self, _: &Query) -> Result<Message, Error> {
        Err(Error::Exception(b"database unreachable".to_vec()))
    }
}

# utopia_async::Builder::current_thread().build()?.block_on(async {
let failures = Arc::new(Mutex::new(Vec::new()));
let seen = failures.clone();
let server = Server::new(Down).error(move |error| seen.lock().unwrap().push(error.to_string()));

let query = Message::query(Question::new("www.example.test", Record::TYPE_A), Some(5), true).encode(None)?;
let answer = Message::decode(&server.handle(&query, "192.0.2.7", 53000, Protocol::Tcp).await)?;

assert_eq!(answer.header().response_code, RCODE_SERVFAIL);
assert_eq!(answer.questions()[0].name(), b"www.example.test");
assert_eq!(*failures.lock().unwrap(), ["database unreachable"]);
# Ok::<(), Error>(())
# })?;
# Ok::<(), Box<dyn std::error::Error>>(())
```
