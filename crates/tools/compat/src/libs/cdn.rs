//! Compat adapter for `cdn`: maps `tests/compat/cdn/spec.json` operations onto `utopia-cdn`.
//!
//! Providers send through [`Recorded`] (the twin of
//! `tests/compat/cdn/Transport.php`), or through their default client
//! against the `cdn-http` fixture server ([`Fixture`]): no CDN is ever
//! called. [`CacheRecorder`] and [`ProviderRecorder`] are the test doubles
//! the PHP tests write inline (`tests/compat/cdn/Doubles.php`).

use std::collections::VecDeque;
use std::sync::{Arc, Mutex};

use serde_json::{Map, Value, json};
use utopia_balancer::{Balancer as Options, First};
use utopia_cdn::cache::{self, Adapter as _, Balancer, Cache};
use utopia_cdn::certificates::{self, Certificates, Challenge, FastlyTls, Proxy, Status};
use utopia_cdn::{BoxFuture, CdnOption, Error, domain};
use utopia_client::{Adapter as Client, Body, Client as HttpClient, Http, Request, Response, Sink};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "domain.validate",
    "domain.validate_paths",
    "log.new",
    "log.take",
    "cache.new",
    "cache.call",
    "cache.once",
    "option.new",
    "option.read",
    "option.filter",
    "certificates.new",
    "certificates.call",
    "certificates.once",
    "challenge.new",
    "exception.certificate",
    "exception.purge",
    "constants",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("validate", "Normalise and validate a mixed-case hostname"),
    ("validate_paths", "Check ten cache paths"),
    ("fastly_purge_paths", "Purge three paths through Fastly on a transport answering at once"),
    ("cloudflare_purge_keys", "Purge 40 cache tags through Cloudflare (two batches) on a transport answering at once"),
    ("fastly_tls_status", "Read an issued, activated Fastly TLS subscription"),
    ("balancer_purge_domain", "Fan a domain purge out over three recording adapters, one filtered away"),
];

const TLS_STATUS_BODIES: [&str; 2] = [
    r#"{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_certificates":{"data":[{"id":"cert_1","type":"tls_certificate"}]},"tls_domains":{"data":[{"id":"example.com","type":"tls_domain"}]}}}],"included":[{"id":"cert_1","type":"tls_certificate","attributes":{"not_after":"2027-02-01T00:00:00Z"}}]}"#,
    r#"{"data":[{"id":"act_1","type":"tls_activation","relationships":{"tls_certificate":{"data":{"type":"tls_certificate","id":"cert_1"}}}}]}"#,
];

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    use crate::bench::Bench;

    Ok(match name {
        "validate" => Bench::sync(|| {
            black_box(domain::validate(black_box("Shop.Example.COM")).ok());
        }),
        "validate_paths" => {
            let paths: Vec<String> = (1..=10).map(|i| format!("/assets/app-{i}.js")).collect();
            Bench::sync(move || {
                black_box(domain::validate_paths(black_box(&paths)).ok());
            })
        }
        "fastly_purge_paths" => {
            let fastly = Rc::new(
                cache::Fastly::new("token", "domain-").with_service_id("service").with_client(Answering::new(&[])),
            );
            let paths: Rc<Vec<String>> =
                Rc::new(vec!["/index.html".into(), "/hello world.png".into(), "/a?b=c".into()]);
            Bench::future(move || {
                let (fastly, paths) = (fastly.clone(), paths.clone());
                async move {
                    black_box(fastly.purge_paths("example.com", &paths).await.ok());
                }
            })
        }
        "cloudflare_purge_keys" => {
            let cloudflare = Rc::new(cache::Cloudflare::new("zone", "token").with_client(Answering::new(&[])));
            let keys: Rc<Vec<String>> = Rc::new((1..=40).map(|i| format!("tag-{i}")).collect());
            Bench::future(move || {
                let (cloudflare, keys) = (cloudflare.clone(), keys.clone());
                async move {
                    black_box(cloudflare.purge_keys(&keys).await.ok());
                }
            })
        }
        "fastly_tls_status" => {
            let tls = Rc::new(FastlyTls::new("token", "config").with_client(Answering::new(&TLS_STATUS_BODIES)));
            Bench::future(move || {
                let tls = tls.clone();
                async move {
                    black_box(certificates::Provider::certificate_status(&*tls, "example.com", None).await.ok());
                }
            })
        }
        "balancer_purge_domain" => {
            let mut options = Options::new(First);
            options
                .add_option(CdnOption::new(Arc::new(Nothing), CdnOption::PROVIDER_FASTLY, true))
                .add_option(CdnOption::new(Arc::new(Nothing), CdnOption::PROVIDER_FASTLY, false))
                .add_option(CdnOption::new(Arc::new(Nothing), CdnOption::PROVIDER_CLOUDFLARE, false))
                .add_filter(|option: &CdnOption| !option.is_edge());
            let balancer = Rc::new(Balancer::new(options));
            Bench::future(move || {
                let balancer = balancer.clone();
                async move {
                    black_box(balancer.purge_domain("Example.com").await.ok());
                }
            })
        }
        _ => return Err(Fault::new(format!("cdn: no benchmark `{name}`"))),
    })
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(match op {
        "domain.validate" => match domain::validate(a.str("domain")?) {
            Ok(d) => Outcome::ok(d),
            Err(e) => Outcome::err(e.php_class(), e.message()),
        },
        "domain.validate_paths" => {
            let paths = strings(a.array("paths")?)?;
            match domain::validate_paths(&paths) {
                Ok(()) => Outcome::Ok(a.value("paths")?.clone()),
                Err(e) => Outcome::err(e.php_class(), e.message()),
            }
        }
        "log.new" => Outcome::Ok(session.handle(Log::default())),
        "log.take" => {
            let log = session.get::<Log>(a.value("log")?)?;
            Outcome::Ok(Value::Array(std::mem::take(&mut *log.0.lock().unwrap_or_else(|e| e.into_inner()))))
        }
        "cache.new" => {
            let handle = cache_new(&a, session)?;
            Outcome::Ok(session.handle(handle))
        }
        "cache.call" => {
            let handle = session.get::<CacheHandle>(a.value("cache")?)?.clone();
            Outcome::Ok(cache_call(&handle, &a).await?)
        }
        "cache.once" => {
            let handle = cache_new(&a, session)?;
            Outcome::Ok(cache_call(&handle, &a).await?)
        }
        "option.new" => {
            let adapter = session.get::<CacheHandle>(a.value("adapter")?)?.adapter()?;
            let option = CdnOption::new(adapter, a.str("provider")?, a.opt_bool("edge")?.unwrap_or(false));
            Outcome::Ok(session.handle(option))
        }
        "option.read" => {
            let option = session.get::<CdnOption>(a.value("option")?)?;
            let adapter = session.get::<CacheHandle>(a.value("adapter")?)?.adapter()?;
            Outcome::Ok(json!({
                "provider": option.provider(),
                "edge": option.is_edge(),
                "adapter": std::ptr::addr_eq(Arc::as_ptr(option.adapter()), Arc::as_ptr(&adapter)),
            }))
        }
        "option.filter" => {
            let mut balancer = balancer(&a, session)?;
            let position = |b: &Options<CdnOption, First>, o: &CdnOption| {
                b.options().iter().position(|x| std::ptr::eq(x, o)).map_or(Value::Null, Value::from)
            };
            let filtered: Vec<Value> = balancer.filtered().into_iter().map(|o| position(&balancer, o)).collect();
            let pick = balancer.pick().map_err(|e| Fault::new(e.to_string()))?;
            Outcome::Ok(json!({ "filtered": filtered, "run": pick.map_or(Value::Null, Value::from) }))
        }
        "certificates.new" => match provider_new(&a, session)? {
            Ok(handle) => Outcome::Ok(session.handle(handle)),
            Err(e) => Outcome::err(e.php_class(), e.message()),
        },
        "certificates.call" => {
            let handle = session.get::<ProviderHandle>(a.value("provider")?)?.clone();
            Outcome::Ok(provider_call(&handle, &a).await?)
        }
        "certificates.once" => match provider_new(&a, session)? {
            Ok(handle) => Outcome::Ok(provider_call(&handle, &a).await?),
            Err(e) => Outcome::err(e.php_class(), e.message()),
        },
        "challenge.new" => Outcome::Ok(challenge_value(&challenge(args)?)),
        "exception.certificate" => {
            let status = status(a.str("status")?)?;
            let challenges = a.opt("challenges").and_then(Value::as_array).map_or(&[][..], Vec::as_slice);
            let challenges = challenges.iter().map(challenge).collect::<Result<Vec<_>, _>>()?;
            let warnings = strings(a.opt("warnings").and_then(Value::as_array).map_or(&[][..], Vec::as_slice))?;
            Outcome::Ok(error(&Error::certificate(a.str("message")?, status, challenges, warnings)))
        }
        "exception.purge" => {
            let errors = strings(a.opt("errors").and_then(Value::as_array).map_or(&[][..], Vec::as_slice))?;
            let errors = errors.into_iter().map(Error::Runtime).collect();
            Outcome::Ok(error(&Error::purge(a.str("message")?, errors)))
        }
        "constants" => Outcome::Ok(json!({
            "cloudflare": {
                "PATHS_PER_PURGE": cache::Cloudflare::PATHS_PER_PURGE,
                "KEYS_PER_PURGE": cache::Cloudflare::KEYS_PER_PURGE,
            },
            "fastly": { "KEYS_PER_PURGE": cache::Fastly::KEYS_PER_PURGE },
            "option": {
                "ADAPTER": CdnOption::ADAPTER,
                "PROVIDER": CdnOption::PROVIDER,
                "EDGE": CdnOption::EDGE,
                "PROVIDER_FASTLY": CdnOption::PROVIDER_FASTLY,
                "PROVIDER_CLOUDFLARE": CdnOption::PROVIDER_CLOUDFLARE,
            },
            "status": Status::ALL.iter().map(|s| s.as_str()).collect::<Vec<_>>(),
            "fastly_tls": { "CHALLENGE_MANAGED_DNS": FastlyTls::CHALLENGE_MANAGED_DNS },
        })),
        _ => return Err(Fault::new(format!("cdn: unknown operation `{op}`"))),
    })
}

fn strings(values: &[Value]) -> Result<Vec<String>, Fault> {
    values
        .iter()
        .map(|v| v.as_str().map(str::to_owned).ok_or_else(|| Fault::new("expected a list of strings")))
        .collect()
}

fn status(name: &str) -> Result<Status, Fault> {
    Status::parse(name).ok_or_else(|| Fault::new(format!("`{name}` is not a certificate status")))
}

fn challenge(value: &Value) -> Result<Challenge, Fault> {
    let a = Args(value);
    Ok(Challenge {
        kind: a.str("type")?.into(),
        record_type: a.str("record_type")?.into(),
        record_name: a.str("record_name")?.into(),
        values: strings(a.array("values")?)?,
    })
}

fn challenge_value(challenge: &Challenge) -> Value {
    json!({
        "type": challenge.kind,
        "record_type": challenge.record_type,
        "record_name": challenge.record_name,
        "values": challenge.values,
    })
}

/// An error as `Adapter.php` reports one: class and message, the failures
/// of a purge, the status, challenges and warnings of a certificate.
fn error(e: &Error) -> Value {
    let mut out = Map::new();
    out.insert("class".into(), Value::from(e.php_class()));
    out.insert("message".into(), Value::from(e.message()));
    match e {
        Error::Purge { errors, .. } => {
            out.insert("errors".into(), Value::Array(errors.iter().map(error).collect()));
            out.insert("previous".into(), errors.first().map_or(Value::Null, error));
        }
        Error::Certificate(blocked) => {
            out.insert("status".into(), Value::from(blocked.status.as_str()));
            out.insert("blocked".into(), Value::from(blocked.is_blocked()));
            out.insert("challenges".into(), Value::Array(blocked.challenges.iter().map(challenge_value).collect()));
            out.insert("warnings".into(), json!(blocked.warnings));
        }
        _ => {}
    }
    Value::Object(out)
}

/// The shared call log of the recording test doubles.
#[derive(Clone, Default)]
struct Log(Arc<Mutex<Vec<Value>>>);

impl Log {
    fn append(&self, entry: Value) {
        self.0.lock().unwrap_or_else(|e| e.into_inner()).push(entry);
    }
}

/// The cache adapter the PHP tests write inline.
struct CacheRecorder {
    name: String,
    log: Log,
    supports_keys: bool,
    fails: bool,
}

impl CacheRecorder {
    fn record(&self, operation: &str, args: Value) -> Result<(), Error> {
        self.log.append(json!({ "call": format!("{}:{operation}", self.name), "args": args }));
        if self.fails {
            return Err(Error::Runtime(format!("{} purge failed.", self.name)));
        }
        Ok(())
    }
}

impl cache::Adapter for CacheRecorder {
    fn purge_paths<'a>(&'a self, domain: &'a str, paths: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async move { self.record("paths", json!([domain, paths])) })
    }

    fn purge_domain<'a>(&'a self, domain: &'a str) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async move { self.record("domain", json!([domain])) })
    }

    fn purge_keys<'a>(&'a self, keys: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async move {
            if !self.supports_keys {
                return Err(Error::UnsupportedOperation(format!("{} cannot purge keys.", self.name)));
            }
            self.record("keys", json!([keys]))
        })
    }

    fn purge_zone<'a>(&'a self) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async move { self.record("zone", json!([])) })
    }
}

/// A cache adapter for the benchmarks that does nothing.
struct Nothing;

impl cache::Adapter for Nothing {
    fn purge_paths<'a>(&'a self, _domain: &'a str, _paths: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async { Ok(()) })
    }

    fn purge_domain<'a>(&'a self, _domain: &'a str) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async { Ok(()) })
    }

    fn purge_keys<'a>(&'a self, _keys: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async { Ok(()) })
    }

    fn purge_zone<'a>(&'a self) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async { Ok(()) })
    }
}

/// The certificate provider the PHP tests write inline.
struct ProviderRecorder {
    name: String,
    log: Log,
    status: Status,
    instant: bool,
    date: Option<String>,
    renew: bool,
}

impl certificates::Provider for ProviderRecorder {
    fn issue_certificate<'a>(
        &'a self,
        _cert_name: &'a str,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Option<String>, Error>> {
        Box::pin(async move {
            self.log.append(Value::from(format!("{}:issue:{domain}", self.name)));
            Ok(self.date.clone())
        })
    }

    fn is_instant_generation<'a>(
        &'a self,
        _domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(async move { Ok(self.instant) })
    }

    fn certificate_status<'a>(
        &'a self,
        _domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<Status, Error>> {
        Box::pin(async move { Ok(self.status) })
    }

    fn is_renew_required<'a>(
        &'a self,
        _domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(async move { Ok(self.renew) })
    }

    fn delete_certificate<'a>(
        &'a self,
        domain: &'a str,
        _domain_type: Option<&'a str>,
    ) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async move {
            self.log.append(Value::from(format!("{}:delete:{domain}", self.name)));
            Ok(())
        })
    }
}

/// One queued answer of [`Recorded`].
enum Answer {
    Response(Response),
    Error(String),
}

#[derive(Default)]
struct Tape {
    answers: VecDeque<Answer>,
    requests: Vec<Value>,
}

/// A recorded transport: answers each request with the next queued response
/// (a 500 without a body once the queue is empty) and keeps every request.
#[derive(Clone, Default)]
struct Recorded {
    tape: Arc<Mutex<Tape>>,
    options: utopia_client::Options,
}

impl Recorded {
    fn queue(&self, responses: &[Value]) -> Result<(), Fault> {
        let mut tape = self.tape.lock().unwrap_or_else(|e| e.into_inner());
        for r in responses {
            if let Some(message) = r.get("error") {
                tape.answers.push_back(Answer::Error(message.as_str().unwrap_or_default().to_owned()));
                continue;
            }
            tape.answers.push_back(Answer::Response(response(r)?));
        }
        Ok(())
    }

    fn take(&self) -> Value {
        Value::Array(std::mem::take(&mut self.tape.lock().unwrap_or_else(|e| e.into_inner()).requests))
    }

    fn answer(&self, request: &Request) -> Result<Response, utopia_client::Error> {
        let mut headers: Vec<(String, String)> = request
            .headers()
            .iter()
            .filter(|(name, _)| !name.eq_ignore_ascii_case("host"))
            .map(|(name, values)| (name.to_ascii_lowercase(), values.join(", ")))
            .collect();
        headers.sort_by(|a, b| a.0.cmp(&b.0));
        let body = match request.get_body() {
            Body::Bytes(b) => bytes_value(b),
            _ => Value::from(""),
        };
        let mut tape = self.tape.lock().unwrap_or_else(|e| e.into_inner());
        tape.requests.push(json!({
            "method": request.method(),
            "url": request.uri().to_string(),
            "headers": headers.into_iter().map(|(n, v)| (n, Value::String(v))).collect::<Map<_, _>>(),
            "body": body,
        }));
        match tape.answers.pop_front() {
            Some(Answer::Response(r)) => Ok(r),
            Some(Answer::Error(message)) => Err(utopia_client::Error::Network(message)),
            None => Response::new(500),
        }
    }
}

/// A queued `{status, headers, body}` (or `{status, json}`) as a response.
fn response(r: &Value) -> Result<Response, Fault> {
    let status = r.get("status").and_then(Value::as_u64).unwrap_or(200);
    let mut response = u16::try_from(status)
        .ok()
        .and_then(|s| Response::new(s).ok())
        .ok_or_else(|| Fault::new(format!("a queued response needs a status in 100..=599, got {status}")))?;
    if let Some(Value::Object(headers)) = r.get("headers") {
        for (name, value) in headers {
            response = response
                .with_header(name, value.as_str().unwrap_or_default())
                .map_err(|e| Fault::new(e.to_string()))?;
        }
    }
    let body = match r.get("json") {
        Some(value) => php_std::json::encode(
            &php_std::types::Value::<php_std::types::Never>::from_json(value),
            php_std::json::Flags::NONE,
            512,
        )
        .map_err(|e| Fault::new(e.message().to_owned()))?
        .into_bytes(),
        None => r.get("body").and_then(Value::as_str).unwrap_or_default().as_bytes().to_vec(),
    };
    Ok(response.with_body(body))
}

impl Client for Recorded {
    async fn send(&self, request: Request) -> Result<Response, utopia_client::Error> {
        self.answer(&request)
    }

    async fn stream<S: Sink>(&self, request: Request, sink: &mut S) -> Result<Response, utopia_client::Error> {
        let response = self.answer(&request)?;
        sink.write(response.bytes());
        Ok(response.with_body(Vec::new()))
    }

    fn options(&self) -> &utopia_client::Options {
        &self.options
    }

    fn with_options(&self, options: utopia_client::Options) -> Self {
        Recorded { tape: self.tape.clone(), options }
    }
}

/// A transport for the benchmarks: answers every request at once with the
/// next of its bodies (`{"success":true}` when it has none).
struct Answering {
    bodies: Vec<&'static str>,
    next: std::sync::atomic::AtomicUsize,
    options: utopia_client::Options,
}

impl Answering {
    fn new(bodies: &[&'static str]) -> Self {
        Answering { bodies: bodies.to_vec(), next: Default::default(), options: Default::default() }
    }

    fn answer(&self) -> Result<Response, utopia_client::Error> {
        let body = if self.bodies.is_empty() {
            r#"{"success":true}"#
        } else {
            let i = self.next.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
            self.bodies[i % self.bodies.len()]
        };
        Ok(Response::new(200)?.with_body(body))
    }
}

impl Client for Answering {
    async fn send(&self, _request: Request) -> Result<Response, utopia_client::Error> {
        self.answer()
    }

    async fn stream<S: Sink>(&self, _request: Request, _sink: &mut S) -> Result<Response, utopia_client::Error> {
        self.answer()
    }

    fn options(&self) -> &utopia_client::Options {
        &self.options
    }

    fn with_options(&self, options: utopia_client::Options) -> Self {
        Answering { bodies: self.bodies.clone(), next: Default::default(), options }
    }
}

/// The `cdn-http` fixture server: queues responses for this run's
/// namespace and reads back the requests it received.
#[derive(Clone)]
struct Fixture {
    endpoint: String,
    ns: String,
}

impl Fixture {
    fn base(&self) -> String {
        format!("{}/{}", self.endpoint, self.ns)
    }

    async fn call(&self, path: &str, body: Option<String>) -> Result<Value, Fault> {
        let client = HttpClient::new(Http::new());
        let unreachable = |e: utopia_client::Error| {
            Fault::new(format!("the cdn-http fixture server is not reachable (tests/compat/cdn/services.sh up): {e}"))
        };
        let mut request =
            Request::new(if body.is_some() { "POST" } else { "GET" }, &format!("{}{path}", self.endpoint))
                .map_err(unreachable)?;
        if let Some(body) = body {
            request = request.header("Content-Type", "application/json").map_err(unreachable)?.body(body);
        }
        let response = client.send(request).await.map_err(unreachable)?;
        serde_json::from_slice(response.bytes()).map_err(|e| Fault::new(format!("cdn-http answered with {e}")))
    }

    async fn queue(&self, responses: &[Value]) -> Result<(), Fault> {
        self.call(&format!("/_queue/{}", self.ns), Some(Value::from(responses.to_vec()).to_string())).await.map(|_| ())
    }

    async fn take(&self) -> Result<Value, Fault> {
        self.call(&format!("/_take/{}", self.ns), None).await
    }
}

/// Where a provider's requests go.
#[derive(Clone)]
enum Wire {
    Recorded(Recorded),
    Fixture(Fixture),
}

impl Wire {
    async fn queue(&self, responses: &[Value]) -> Result<(), Fault> {
        match self {
            Wire::Recorded(r) => r.queue(responses),
            Wire::Fixture(f) => f.queue(responses).await,
        }
    }

    async fn take(&self) -> Result<Value, Fault> {
        match self {
            Wire::Recorded(r) => Ok(r.take()),
            Wire::Fixture(f) => f.take().await,
        }
    }
}

/// The transport an operation asks for, and the API base to use.
fn wire(a: &Args<'_>, session: &Session, default_base: &str) -> Result<(Wire, String), Fault> {
    match a.opt_str("transport")?.unwrap_or("recorded") {
        "recorded" => {
            Ok((Wire::Recorded(Recorded::default()), a.opt_str("api_base")?.unwrap_or(default_base).to_owned()))
        }
        "http" => {
            let fixture = Fixture { endpoint: session.service("cdn-http")?.to_owned(), ns: session.ns.clone() };
            let base = fixture.base();
            Ok((Wire::Fixture(fixture), base))
        }
        other => Err(Fault::new(format!("unknown transport `{other}`"))),
    }
}

/// A cache adapter, or the facade over one, and its transport.
#[derive(Clone)]
struct CacheHandle {
    adapter: Arc<dyn cache::Adapter>,
    facade: bool,
    wire: Option<Wire>,
}

impl CacheHandle {
    fn adapter(&self) -> Result<Arc<dyn cache::Adapter>, Fault> {
        if self.facade {
            return Err(Fault::new("a Cache facade is not an adapter"));
        }
        Ok(self.adapter.clone())
    }
}

fn balancer(a: &Args<'_>, session: &Session) -> Result<Options<CdnOption, First>, Fault> {
    let mut balancer = Options::new(First);
    for handle in a.array("options")? {
        balancer.add_option(session.get::<CdnOption>(handle)?.clone());
    }
    for filter in a.opt("filters").and_then(Value::as_array).map_or(&[][..], Vec::as_slice) {
        if let Some(edge) = filter.get("edge").and_then(Value::as_bool) {
            balancer.add_filter(move |o: &CdnOption| o.is_edge() == edge);
        } else if let Some(provider) = filter.get("provider").and_then(Value::as_str) {
            let provider = provider.to_owned();
            balancer.add_filter(move |o: &CdnOption| o.provider() == provider);
        } else {
            return Err(Fault::new("a filter needs `edge` or `provider`"));
        }
    }
    Ok(balancer)
}

fn cache_new(a: &Args<'_>, session: &Session) -> Result<CacheHandle, Fault> {
    let plain = |adapter: Arc<dyn cache::Adapter>, wire: Option<Wire>| CacheHandle { adapter, facade: false, wire };
    Ok(match a.str("type")? {
        "cloudflare" => {
            let (wire, base) = wire(a, session, "https://api.cloudflare.com/client/v4")?;
            let adapter = cache::Cloudflare::new(a.str("zone")?, a.str("token")?).with_api_base(base);
            match &wire {
                Wire::Recorded(r) => plain(Arc::new(adapter.with_client(r.clone())), Some(wire)),
                Wire::Fixture(_) => plain(Arc::new(adapter), Some(wire)),
            }
        }
        "fastly" => {
            let (wire, base) = wire(a, session, "https://api.fastly.com")?;
            let mut adapter = cache::Fastly::new(a.str("token")?, a.str("prefix")?)
                .with_soft_purge(a.opt_bool("soft")?.unwrap_or(false))
                .with_api_base(base);
            if let Some(service) = a.opt_str("service")? {
                adapter = adapter.with_service_id(service);
            }
            match &wire {
                Wire::Recorded(r) => plain(Arc::new(adapter.with_client(r.clone())), Some(wire)),
                Wire::Fixture(_) => plain(Arc::new(adapter), Some(wire)),
            }
        }
        "recorder" => plain(
            Arc::new(CacheRecorder {
                name: a.str("name")?.into(),
                log: session.get::<Log>(a.value("log")?)?.clone(),
                supports_keys: a.opt_bool("supports_keys")?.unwrap_or(true),
                fails: a.opt_bool("fails")?.unwrap_or(false),
            }),
            None,
        ),
        "balancer" => plain(Arc::new(Balancer::new(balancer(a, session)?)), None),
        "facade" => {
            let inner = session.get::<CacheHandle>(a.value("adapter")?)?;
            CacheHandle { adapter: inner.adapter()?, facade: true, wire: inner.wire.clone() }
        }
        other => return Err(Fault::new(format!("unknown cache type `{other}`"))),
    })
}

/// One call: its result or error, and the requests the provider sent.
async fn record<T: Into<Value>>(
    wire: Option<&Wire>,
    a: &Args<'_>,
    invoke: impl Future<Output = Result<T, Error>>,
) -> Result<Value, Fault> {
    if let Some(wire) = wire {
        wire.queue(a.opt("responses").and_then(Value::as_array).map_or(&[][..], Vec::as_slice)).await?;
    }
    let mut out = Map::new();
    match invoke.await {
        Ok(value) => out.insert("result".into(), value.into()),
        Err(e) => out.insert("error".into(), error(&e)),
    };
    if let Some(wire) = wire {
        out.insert("requests".into(), wire.take().await?);
    }
    Ok(Value::Object(out))
}

async fn cache_call(handle: &CacheHandle, a: &Args<'_>) -> Result<Value, Fault> {
    let method = a.str("method")?;
    let domain = a.opt_str("domain")?.unwrap_or_default();
    let paths = strings(a.opt("paths").and_then(Value::as_array).map_or(&[][..], Vec::as_slice))?;
    let keys = strings(a.opt("keys").and_then(Value::as_array).map_or(&[][..], Vec::as_slice))?;
    let adapter = &handle.adapter;
    let wire = handle.wire.as_ref();
    let unit = |r: Result<(), Error>| r.map(|()| Value::Null);
    if handle.facade {
        let cache = Cache::new(adapter.clone());
        return match method {
            "purgePaths" => record(wire, a, async { unit(cache.purge_paths(domain, &paths).await) }).await,
            "purgeDomain" => record(wire, a, async { unit(cache.purge_domain(domain).await) }).await,
            "purgeKeys" => record(wire, a, async { unit(cache.purge_keys(&keys).await) }).await,
            "purgeZone" => record(wire, a, async { unit(cache.purge_zone().await) }).await,
            other => Err(Fault::new(format!("unknown cache method `{other}`"))),
        };
    }
    match method {
        "purgePaths" => record(wire, a, async { unit(adapter.purge_paths(domain, &paths).await) }).await,
        "purgeDomain" => record(wire, a, async { unit(adapter.purge_domain(domain).await) }).await,
        "purgeKeys" => record(wire, a, async { unit(adapter.purge_keys(&keys).await) }).await,
        "purgeZone" => record(wire, a, async { unit(adapter.purge_zone().await) }).await,
        other => Err(Fault::new(format!("unknown cache method `{other}`"))),
    }
}

/// A certificate provider, or the facade over one, and its transport.
#[derive(Clone)]
struct ProviderHandle {
    provider: Arc<dyn certificates::Provider>,
    facade: bool,
    wire: Option<Wire>,
}

fn provider_new(a: &Args<'_>, session: &Session) -> Result<Result<ProviderHandle, Error>, Fault> {
    let plain = |provider: Arc<dyn certificates::Provider>, wire: Option<Wire>| {
        Ok(ProviderHandle { provider, facade: false, wire })
    };
    let handle = |value: &Value| -> Result<Arc<dyn certificates::Provider>, Fault> {
        let handle = session.get::<ProviderHandle>(value)?;
        if handle.facade {
            return Err(Fault::new("a Certificates facade is not a provider"));
        }
        Ok(handle.provider.clone())
    };
    Ok(match a.str("type")? {
        "cloudflare" => {
            let (wire, base) = wire(a, session, "https://api.cloudflare.com/client/v4")?;
            let provider = certificates::Cloudflare::new(a.str("zone")?, a.str("token")?).with_api_base(base);
            match &wire {
                Wire::Recorded(r) => plain(Arc::new(provider.with_client(r.clone())), Some(wire)),
                Wire::Fixture(_) => plain(Arc::new(provider), Some(wire)),
            }
        }
        "fastly" => {
            let (wire, base) = wire(a, session, "https://api.fastly.com")?;
            let provider = certificates::Fastly::new(a.str("token")?, a.str("service")?)
                .with_certificate_authority(a.opt_str("ca")?.unwrap_or("certainly"))
                .with_api_base(base)
                .with_tls_configuration_id(a.opt_str("tls_config")?.unwrap_or_default())
                .with_deployment_polling(
                    a.opt_i64("poll_attempts")?.unwrap_or(10),
                    a.opt_i64("poll_interval")?.unwrap_or(5000),
                );
            match (provider, &wire) {
                (Err(e), _) => Err(e),
                (Ok(p), Wire::Recorded(r)) => plain(Arc::new(p.with_client(r.clone())), Some(wire)),
                (Ok(p), Wire::Fixture(_)) => plain(Arc::new(p), Some(wire)),
            }
        }
        "fastly_tls" => {
            let (wire, base) = wire(a, session, "https://api.fastly.com")?;
            let provider = FastlyTls::new(a.str("token")?, a.str("tls_config")?)
                .with_certificate_authority(a.opt_str("ca")?.unwrap_or("certainly"))
                .with_api_base(base);
            match &wire {
                Wire::Recorded(r) => plain(Arc::new(provider.with_client(r.clone())), Some(wire)),
                Wire::Fixture(_) => plain(Arc::new(provider), Some(wire)),
            }
        }
        "recorder" => plain(
            Arc::new(ProviderRecorder {
                name: a.str("name")?.into(),
                log: session.get::<Log>(a.value("log")?)?.clone(),
                status: status(a.opt_str("status")?.unwrap_or("issued"))?,
                instant: a.opt_bool("instant")?.unwrap_or(false),
                date: a.opt_str("date")?.map(str::to_owned),
                renew: a.opt_bool("renew")?.unwrap_or(false),
            }),
            None,
        ),
        "proxy" => {
            let custom = a.opt("custom").and_then(Value::as_array).map_or(&[][..], Vec::as_slice);
            let custom = custom.iter().map(handle).collect::<Result<Vec<_>, _>>()?;
            match Proxy::new(a.str("app_domain")?, handle(a.value("app")?)?, handle(a.value("network")?)?, custom) {
                Ok(proxy) => plain(Arc::new(proxy), None),
                Err(e) => Err(e),
            }
        }
        "facade" => {
            let inner = session.get::<ProviderHandle>(a.value("provider")?)?;
            Ok(ProviderHandle { provider: handle(a.value("provider")?)?, facade: true, wire: inner.wire.clone() })
        }
        other => return Err(Fault::new(format!("unknown provider type `{other}`"))),
    })
}

async fn provider_call(handle: &ProviderHandle, a: &Args<'_>) -> Result<Value, Fault> {
    let method = a.str("method")?;
    let domain = a.str("domain")?;
    let kind = a.opt_str("domain_type")?;
    let cert = a.opt_str("cert_name")?.unwrap_or("cert");
    let wire = handle.wire.as_ref();
    let p = &handle.provider;
    if handle.facade {
        let c = Certificates::new(p.clone());
        return match method {
            "issueCertificate" => record(wire, a, async { c.issue_certificate(cert, domain, kind).await }).await,
            "isInstantGeneration" => record(wire, a, async { c.is_instant_generation(domain, kind).await }).await,
            "getCertificateStatus" => {
                record(wire, a, async { c.certificate_status(domain, kind).await.map(|s| s.as_str()) }).await
            }
            "isRenewRequired" => record(wire, a, async { c.is_renew_required(domain, kind).await }).await,
            "deleteCertificate" => {
                record(wire, a, async { c.delete_certificate(domain, kind).await.map(|()| Value::Null) }).await
            }
            other => Err(Fault::new(format!("unknown provider method `{other}`"))),
        };
    }
    match method {
        "issueCertificate" => record(wire, a, p.issue_certificate(cert, domain, kind)).await,
        "isInstantGeneration" => record(wire, a, p.is_instant_generation(domain, kind)).await,
        "getCertificateStatus" => {
            record(wire, a, async { p.certificate_status(domain, kind).await.map(|s| s.as_str()) }).await
        }
        "isRenewRequired" => record(wire, a, p.is_renew_required(domain, kind)).await,
        "deleteCertificate" => {
            record(wire, a, async { p.delete_certificate(domain, kind).await.map(|()| Value::Null) }).await
        }
        other => Err(Fault::new(format!("unknown provider method `{other}`"))),
    }
}
