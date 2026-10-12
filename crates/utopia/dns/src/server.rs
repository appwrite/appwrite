//! The server: decode a query, resolve it, encode the response.

use std::future::Future;
use std::sync::Arc;
use std::time::Instant;

use utopia_telemetry::{Counter, Histogram, NoTelemetry, Telemetry, Value};

use crate::Error;
use crate::adapter::Adapter;
use crate::message::{Message, RCODE_FORMERR, RCODE_NOTIMP, RCODE_SERVFAIL};
use crate::query::{Protocol, Query};
use crate::resolver::Resolver;

/// What turns a packet into the packet to answer with: implemented by
/// [`Server`], called by [`Adapter`]s for every packet they receive.
pub trait Handler: Send + Sync + 'static {
    /// The response to `packet` from `ip`:`port`, empty to send nothing.
    fn handle(&self, packet: &[u8], ip: &str, port: u16, protocol: Protocol) -> impl Future<Output = Vec<u8>> + Send;
}

/// An error handler.
pub type ErrorHandler = Box<dyn Fn(&Error) + Send + Sync>;

/// A DNS server: decodes each packet, asks the resolver, and encodes the
/// response within what the transport carries.
///
/// - A packet whose header decodes but whose sections do not gets
///   `FORMERR`; one too short for a header gets no answer.
/// - Opcodes other than `QUERY` get `NOTIMP`; a query without a question
///   gets `FORMERR`.
/// - A resolver error, or a response that cannot be encoded, gets
///   `SERVFAIL`.
///
/// Errors go to the handlers added with [`Server::error`]. Telemetry
/// records the decode, resolve and encode durations (`dns.query.duration`,
/// seconds, by `phase` and `responseCode`) and counts queries and responses
/// (`dns.queries.total` by `type`, `dns.responses.total` by `type` and
/// `responseCode`).
///
/// ```
/// use utopia_dns::message::{Message, Question, Record};
/// use utopia_dns::resolver::Memory;
/// use utopia_dns::{Handler, Protocol, Server, Zone};
///
/// # tokio::runtime::Builder::new_current_thread().build().unwrap().block_on(async {
/// let zone = Zone::new(
///     "example.test",
///     vec![Record::new("www.example.test", Record::TYPE_A).with_ttl(60).with_rdata("192.0.2.1")],
///     Record::new("example.test", Record::TYPE_SOA)
///         .with_ttl(60)
///         .with_rdata("ns1.example.test hostmaster.example.test 1 7200 1800 1209600 3600"),
/// )?;
/// let server = Server::new(Memory::new(zone));
///
/// let query = Message::query(Question::new("www.example.test", Record::TYPE_A), Some(7), true);
/// let packet = server.handle(&query.encode(None)?, "127.0.0.1", 5300, Protocol::Udp).await;
/// let response = Message::decode(&packet)?;
/// assert_eq!(response.answers()[0].rdata(), b"192.0.2.1");
/// # Ok::<(), utopia_dns::Error>(())
/// # }).unwrap();
/// ```
///
/// ```php
/// use Utopia\DNS\Adapter\Native;
/// use Utopia\DNS\Message\Record;
/// use Utopia\DNS\Resolver\Memory;
/// use Utopia\DNS\Server;
/// use Utopia\DNS\Zone;
///
/// $zone = new Zone(
///     'example.test',
///     [new Record('www.example.test', Record::TYPE_A, ttl: 60, rdata: '192.0.2.1')],
///     new Record('example.test', Record::TYPE_SOA, ttl: 60, rdata: 'ns1.example.test hostmaster.example.test 1 7200 1800 1209600 3600'),
/// );
/// $server = new Server(new Native([new Native\Udp('127.0.0.1', 5300)]), new Memory($zone));
/// // $server->start(); serves until stopped; packets are answered by the protected onPacket().
/// echo get_class($server), "\n"; // Utopia\DNS\Server
/// ```
///
/// PHP: `Utopia\DNS\Server` (`onPacket()` is [`Handler::handle`]).
pub struct Server<R> {
    resolver: R,
    errors: Vec<ErrorHandler>,
    debug: bool,
    duration: Histogram,
    queries: Counter,
    responses: Counter,
}

/// The histogram buckets of `dns.query.duration`, in seconds.
const BUCKETS: [f64; 9] = [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0];

impl<R: Resolver> Server<R> {
    /// A server answering with `resolver`, without telemetry.
    ///
    /// PHP: `new Server($adapter, $resolver)` (the adapter is given to [`Server::start`]).
    pub fn new(resolver: R) -> Server<R> {
        let mut server = Server {
            resolver,
            errors: Vec::new(),
            debug: false,
            duration: NoTelemetry.histogram("dns.query.duration", Some("s"), None, Some(&BUCKETS)),
            queries: NoTelemetry.counter("dns.queries.total", None, None),
            responses: NoTelemetry.counter("dns.responses.total", None, None),
        };
        server.set_telemetry(&NoTelemetry);
        server
    }

    /// Records metrics on `telemetry`.
    ///
    /// PHP: `setTelemetry()`.
    pub fn set_telemetry(&mut self, telemetry: &dyn Telemetry) {
        self.duration = telemetry.histogram("dns.query.duration", Some("s"), None, Some(&BUCKETS));
        self.queries = telemetry.counter("dns.queries.total", None, None);
        self.responses = telemetry.counter("dns.responses.total", None, None);
    }

    /// Adds an error handler.
    ///
    /// PHP: `error()`.
    pub fn error(mut self, handler: impl Fn(&Error) + Send + Sync + 'static) -> Self {
        self.errors.push(Box::new(handler));
        self
    }

    /// Sets debug mode (kept for callers; nothing reads it).
    ///
    /// PHP: `setDebug()`.
    pub fn set_debug(mut self, debug: bool) -> Self {
        self.debug = debug;
        self
    }

    /// Whether debug mode is on.
    pub fn debug(&self) -> bool {
        self.debug
    }

    /// The resolver.
    pub fn resolver(&self) -> &R {
        &self.resolver
    }

    fn report(&self, error: &Error) {
        for handler in &self.errors {
            handler(error);
        }
    }

    /// Serves with `adapter` until it stops; an error starting it goes to
    /// the error handlers.
    ///
    /// PHP: `start()`.
    pub async fn start<A: Adapter>(self, adapter: A) {
        let server = Arc::new(self);
        if let Err(error) = adapter.start(server.clone()).await {
            server.report(&error);
        }
    }

    /// Encodes the response, or `SERVFAIL` when it cannot be encoded.
    fn encode(&self, response: &mut Message, query: &Message, max: usize) -> Vec<u8> {
        match response.encode(Some(max)) {
            Ok(packet) => packet,
            Err(error) => {
                self.report(&error);
                *response = servfail(query);
                response.encode(Some(max)).unwrap_or_default()
            }
        }
    }
}

fn servfail(query: &Message) -> Message {
    Message::response(query.header(), RCODE_SERVFAIL)
        .questions(query.questions().to_vec())
        .build()
        .unwrap_or_else(|_| query.clone())
}

impl<R: Resolver> Handler for Server<R> {
    async fn handle(&self, packet: &[u8], ip: &str, port: u16, protocol: Protocol) -> Vec<u8> {
        let max = protocol.max_response_size();
        let started = Instant::now();
        let message = match Message::decode(packet) {
            Ok(message) => message,
            Err(error) => {
                self.report(&error);
                let Error::PartialDecoding { header, .. } = error else {
                    return Vec::new();
                };
                return Message::response(&header, RCODE_FORMERR)
                    .build()
                    .and_then(|r| r.encode(Some(max)))
                    .unwrap_or_default();
            }
        };
        self.duration.record(started.elapsed().as_secs_f64(), &[("phase", Value::from("decode"))]);
        if message.header().opcode != 0 {
            return Message::response(message.header(), RCODE_NOTIMP)
                .build()
                .and_then(|r| r.encode(Some(max)))
                .unwrap_or_default();
        }
        let Some(question) = message.questions().first() else {
            return Message::response(message.header(), RCODE_FORMERR)
                .build()
                .and_then(|r| r.encode(Some(max)))
                .unwrap_or_default();
        };
        let ty = i64::from(question.ty);
        self.queries.add(1, &[("type", Value::Int(ty))]);

        let started = Instant::now();
        let query = Query { message, ip: ip.to_owned(), port, protocol };
        let mut response = match self.resolver.resolve(&query).await {
            Ok(response) => response,
            Err(error) => {
                self.report(&error);
                servfail(&query.message)
            }
        };
        let code = i64::from(response.header().response_code);
        self.duration.record(
            started.elapsed().as_secs_f64(),
            &[("phase", Value::from("resolve")), ("responseCode", Value::Int(code))],
        );

        let started = Instant::now();
        let packet = self.encode(&mut response, &query.message, max);
        let code = i64::from(response.header().response_code);
        self.duration.record(
            started.elapsed().as_secs_f64(),
            &[("phase", Value::from("encode")), ("responseCode", Value::Int(code))],
        );
        self.responses.add(1, &[("type", Value::Int(ty)), ("responseCode", Value::Int(code))]);
        packet
    }
}
