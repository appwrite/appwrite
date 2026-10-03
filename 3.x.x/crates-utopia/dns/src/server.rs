use std::fmt;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Instant;

use parking_lot::Mutex;
use utopia_telemetry::adapters::NoneAdapter;
use utopia_telemetry::{Adapter as Telemetry, Attributes, Counter, Histogram};

use crate::adapter::{Adapter, PacketHandler};
use crate::error::Error;
use crate::message::Message;
use crate::protocol::Protocol;
use crate::query::Query;
use crate::resolver::Resolver;

type ErrorHandler = Arc<dyn Fn(&Error) + Send + Sync>;

struct Inner<R> {
    resolver: R,
    errors: Mutex<Vec<ErrorHandler>>,
    debug: AtomicBool,
    duration: Mutex<Option<Arc<dyn Histogram>>>,
    queries_total: Mutex<Option<Arc<dyn Counter>>>,
    responses_total: Mutex<Option<Arc<dyn Counter>>>,
}

/// DNS server. PHP `Utopia\DNS\Server`.
pub struct Server<A: Adapter, R: Resolver> {
    adapter: A,
    inner: Arc<Inner<R>>,
}

impl<A: Adapter, R: Resolver> fmt::Debug for Server<A, R> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Server").finish_non_exhaustive()
    }
}

impl<A: Adapter, R: Resolver> Server<A, R> {
    pub fn new(adapter: A, resolver: R) -> Self {
        let inner = Arc::new(Inner {
            resolver,
            errors: Mutex::new(Vec::new()),
            debug: AtomicBool::new(false),
            duration: Mutex::new(None),
            queries_total: Mutex::new(None),
            responses_total: Mutex::new(None),
        });
        let server = Self { adapter, inner };
        server.set_telemetry(&NoneAdapter);
        server
    }

    /// PHP `Server::setTelemetry`.
    pub fn set_telemetry(&self, telemetry: &dyn Telemetry) {
        let mut advisory = Attributes::new();
        advisory.insert(
            "ExplicitBucketBoundaries".into(),
            "0.001,0.005,0.01,0.025,0.05,0.1,0.25,0.5,1".into(),
        );
        *self.inner.duration.lock() =
            Some(telemetry.create_histogram("dns.query.duration", Some("s"), None, advisory));
        *self.inner.queries_total.lock() =
            Some(telemetry.create_counter("dns.queries.total", None, None, Attributes::new()));
        *self.inner.responses_total.lock() =
            Some(telemetry.create_counter("dns.responses.total", None, None, Attributes::new()));
    }

    /// PHP `Server::error`.
    pub fn error(&self, handler: impl Fn(&Error) + Send + Sync + 'static) -> &Self {
        self.inner.errors.lock().push(Arc::new(handler));
        self
    }

    /// PHP `Server::onWorkerStart`.
    pub fn on_worker_start(&self, handler: impl Fn(i64) + Send + Sync + 'static) -> &Self {
        self.adapter.on_worker_start(Arc::new(handler));
        self
    }

    /// PHP `Server::setDebug`.
    pub fn set_debug(&self, status: bool) -> &Self {
        self.inner.debug.store(status, Ordering::Relaxed);
        self
    }

    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    pub fn stop(&self) {
        self.adapter.stop();
    }

    fn wire(&self)
    where
        R: 'static,
    {
        let inner = Arc::clone(&self.inner);
        let handler: PacketHandler =
            Arc::new(move |buf, ip, port, proto| inner.on_packet(buf, ip, port, proto));
        self.adapter.on_packet(handler);
    }

    /// PHP `Server::start`.
    pub fn start(&self) -> crate::error::Result<()>
    where
        R: 'static,
    {
        self.wire();
        match self.adapter.start() {
            Ok(()) => Ok(()),
            Err(e) => {
                self.inner.handle_error(&e);
                Err(e)
            }
        }
    }

    /// Async start so tests can spawn the server and query it.
    pub async fn start_async(&self) -> crate::error::Result<()>
    where
        R: 'static,
    {
        self.wire();
        self.adapter.start_async().await
    }
}

impl<R: Resolver> Inner<R> {
    fn handle_error(&self, error: &Error) {
        for handler in self.errors.lock().iter() {
            handler(error);
        }
    }

    #[allow(clippy::too_many_lines)]
    fn on_packet(&self, buffer: &[u8], ip: &str, port: u16, protocol: Protocol) -> Vec<u8> {
        let max_response_size = protocol.max_response_size();
        let mut question_type: Option<u16> = None;
        let mut response_code: Option<u8> = None;

        let out = (|| {
            let decode_start = Instant::now();
            let message = match Message::decode(buffer) {
                Ok(m) => m,
                Err(Error::PartialDecoding { header, message }) => {
                    self.handle_error(&Error::partial(header.clone(), message.clone()));
                    let response = Message::response(
                        &header,
                        Message::RCODE_FORMERR,
                        Vec::new(),
                        Vec::new(),
                        Vec::new(),
                        Vec::new(),
                        false,
                        false,
                        false,
                    )
                    .ok()?;
                    response_code = Some(response.header.response_code);
                    return response.encode(Some(max_response_size)).ok();
                }
                Err(e) => {
                    self.handle_error(&e);
                    return Some(Vec::new());
                }
            };
            record_duration(&self.duration, decode_start, &[("phase", "decode")]);

            if message.header.opcode != 0 {
                let response = Message::response(
                    &message.header,
                    Message::RCODE_NOTIMP,
                    Vec::new(),
                    Vec::new(),
                    Vec::new(),
                    Vec::new(),
                    false,
                    false,
                    false,
                )
                .ok()?;
                return response.encode(Some(max_response_size)).ok();
            }

            let Some(question) = message.questions.first().cloned() else {
                let response = Message::response(
                    &message.header,
                    Message::RCODE_FORMERR,
                    Vec::new(),
                    Vec::new(),
                    Vec::new(),
                    Vec::new(),
                    false,
                    false,
                    false,
                )
                .ok()?;
                return response.encode(Some(max_response_size)).ok();
            };
            question_type = Some(question.type_code);
            if let Some(c) = self.queries_total.lock().as_ref() {
                let mut attrs = Attributes::new();
                attrs.insert("type".into(), question.type_code.to_string());
                c.add(1.0, &attrs);
            }

            let resolve_start = Instant::now();
            let response =
                match self
                    .resolver
                    .resolve(&Query::new(message.clone(), ip, port, protocol))
                {
                    Ok(r) => r,
                    Err(e) => {
                        self.handle_error(&e);
                        Message::response(
                            &message.header,
                            Message::RCODE_SERVFAIL,
                            message.questions.clone(),
                            Vec::new(),
                            Vec::new(),
                            Vec::new(),
                            false,
                            false,
                            false,
                        )
                        .ok()?
                    }
                };
            response_code = Some(response.header.response_code);
            record_duration(
                &self.duration,
                resolve_start,
                &[
                    ("phase", "resolve"),
                    ("responseCode", &response.header.response_code.to_string()),
                ],
            );

            let encode_start = Instant::now();
            let encoded = match response.encode(Some(max_response_size)) {
                Ok(b) => b,
                Err(e) => {
                    self.handle_error(&e);
                    let fallback = Message::response(
                        &message.header,
                        Message::RCODE_SERVFAIL,
                        message.questions.clone(),
                        Vec::new(),
                        Vec::new(),
                        Vec::new(),
                        false,
                        false,
                        false,
                    )
                    .ok()?;
                    response_code = Some(fallback.header.response_code);
                    fallback.encode(Some(max_response_size)).ok()?
                }
            };
            record_duration(
                &self.duration,
                encode_start,
                &[
                    ("phase", "encode"),
                    ("responseCode", &response_code.unwrap_or(0).to_string()),
                ],
            );
            Some(encoded)
        })();

        if let Some(ty) = question_type {
            if let Some(c) = self.responses_total.lock().as_ref() {
                let mut attrs = Attributes::new();
                attrs.insert("type".into(), ty.to_string());
                attrs.insert(
                    "responseCode".into(),
                    response_code.map_or(String::new(), |c| c.to_string()),
                );
                c.add(1.0, &attrs);
            }
        }
        let _ = self.debug.load(Ordering::Relaxed);
        out.unwrap_or_default()
    }
}

fn record_duration(
    slot: &Mutex<Option<Arc<dyn Histogram>>>,
    start: Instant,
    pairs: &[(&str, &str)],
) {
    if let Some(h) = slot.lock().as_ref() {
        let mut attrs = Attributes::new();
        for (k, v) in pairs {
            attrs.insert((*k).to_string(), (*v).to_string());
        }
        h.record(start.elapsed().as_secs_f64(), &attrs);
    }
}
