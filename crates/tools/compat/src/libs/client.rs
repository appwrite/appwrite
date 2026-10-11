//! Compat adapter for `client`: maps `tests/compat/client/spec.json` operations onto `utopia-client`.
//!
//! URIs in arguments carry `${http}` and `${https}`, the fixture server's
//! authority for this runtime (services `client-http`, `client-https`);
//! results carry them back in place of that authority, as Adapter.php does.

use std::sync::{Arc, Mutex};

use serde_json::{Value, json};
use utopia_client::{
    Adapter, Anywhere, Backoff, Body, Client, Destinations, Error, Http, IpRange, Outcome as Attempt, Pool,
    PublicInternet, Request, Response, Retry, Sleep, Strategy, Tls, Uri, redirect,
};
use utopia_pools::{Pool as Connections, Stack};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value, float, float_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "uri.parse",
    "redirect.resolve",
    "redirect.remove_dot_segments",
    "redirect.origin",
    "redirect.without_sensitive_headers",
    "redirect.is_redirect",
    "iprange.contains",
    "destinations.allows",
    "backoff.delay",
    "exception.kind",
    "response.build",
    "client.new",
    "client.send",
    "client.stream",
    "pool.send",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("resolve", "Resolve a relative redirect location against a request URI"),
    ("public_internet", "Check a connected address against PublicInternet"),
    ("backoff", "Decide the delay after a 503 response"),
    ("round_trip", "Send a GET to the fixture server on a reused connection and read its JSON response"),
    ("raw_response", "Send a GET answered with a fixed response on a fresh connection"),
];

const RAW: &str = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\n\r\n{}";

pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use crate::bench::Bench;

    Ok(match name {
        "resolve" => {
            let base = Uri::parse("https://api.example.com/v1/users/42?expand=true").map_err(fault)?;
            Bench::sync(move || {
                black_box(
                    redirect::resolve(black_box(&base), black_box("../teams/7?page=2#top")).map(|u| u.to_string()).ok(),
                );
            })
        }
        "public_internet" => {
            let destinations = PublicInternet::new([IpRange::new("10.0.0.0/8").map_err(fault)?]);
            Bench::sync(move || {
                black_box(destinations.allows(black_box("93.184.216.34")));
            })
        }
        "backoff" => {
            let backoff = Backoff::new(3, 0.1, 10.0, 2.0).with_randomizer(|| 0.5);
            let request = Request::new("GET", "https://api.example.com/v1/users").map_err(fault)?;
            let response = Response::new(503).map_err(fault)?;
            Bench::sync(move || {
                black_box(backoff.delay(&request, 1, Attempt::Response(black_box(&response))));
            })
        }
        "round_trip" => {
            let client = Arc::new(Client::new(Http::new()).with_connection_reuse(true));
            let request =
                Request::new("GET", &format!("http://{}/echo", session.service("client-http")?)).map_err(fault)?;
            Bench::future(move || {
                let client = client.clone();
                let request = request.clone();
                async move {
                    black_box(client.send(request).await.ok().map(|r| r.bytes().len()));
                }
            })
        }
        "raw_response" => {
            let client = Arc::new(Client::new(Http::new()));
            let raw = php_std::encoding::rawurlencode(php_std::encoding::base64_encode(RAW.as_bytes()).as_bytes());
            let request = Request::new("GET", &format!("http://{}/raw?r={raw}", session.service("client-http")?))
                .map_err(fault)?;
            Bench::future(move || {
                let client = client.clone();
                let request = request.clone();
                async move {
                    black_box(client.send(request).await.ok().map(|r| r.status()));
                }
            })
        }
        _ => return Err(Fault::new(format!("client: unknown benchmark `{name}`"))),
    })
}

fn fault(e: impl std::fmt::Display) -> Fault {
    Fault::new(e.to_string())
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.message())
}

macro_rules! tri {
    ($e:expr) => {
        match $e {
            Ok(v) => v,
            Err(e) => return Ok(err(e)),
        }
    };
}

/// The sleeps a Retry asked for, recorded instead of slept.
#[derive(Clone, Default)]
struct Recorder(Arc<Mutex<Vec<f64>>>);

impl Sleep for Recorder {
    async fn sleep(&self, seconds: f64) {
        self.0.lock().unwrap().push(seconds);
    }
}

type Retrying = Retry<Http, Backoff, Recorder>;

/// A configured client: plain or retrying, plus the retry's recorded sleeps.
enum Handle {
    Plain(Client<Http>),
    Retrying(Client<Retrying>, Recorder),
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(match op {
        "uri.parse" => {
            let uri = tri!(Uri::parse(a.str("uri")?));
            Outcome::ok(json!({
                "scheme": uri.scheme(),
                "userInfo": uri.user_info(),
                "host": uri.host(),
                "port": uri.port(),
                "path": uri.path(),
                "query": uri.query(),
                "fragment": uri.fragment(),
                "authority": uri.authority(),
                "string": uri.to_string(),
            }))
        }
        "redirect.resolve" => {
            let base = tri!(Uri::parse(a.str("base")?));
            Outcome::ok(tri!(redirect::resolve(&base, a.str("location")?)).to_string())
        }
        "redirect.remove_dot_segments" => Outcome::ok(redirect::remove_dot_segments(a.str("path")?)),
        "redirect.origin" => {
            let from = tri!(Uri::parse(a.str("from")?));
            let to = tri!(Uri::parse(a.str("to")?));
            Outcome::ok(json!({
                "same": redirect::is_same_origin(&from, &to),
                "strip": redirect::should_strip_sensitive_headers(&from, &to),
            }))
        }
        "redirect.without_sensitive_headers" => {
            let mut request = tri!(request(
                &json!({"method": "GET", "uri": "http://example.com/", "headers": a.value("headers")?}),
                session
            ));
            redirect::without_sensitive_headers(request.headers_mut());
            Outcome::ok(headers(request.headers(), session))
        }
        "redirect.is_redirect" => Outcome::ok(redirect::is_redirect(&tri!(response(args)))),
        "iprange.contains" => {
            let range = tri!(IpRange::new(a.str("range")?));
            let addresses = a.opt("addresses").and_then(Value::as_array).cloned().unwrap_or_default();
            Outcome::ok(addresses.iter().map(|x| range.contains(x.as_str().unwrap_or_default())).collect::<Vec<_>>())
        }
        "destinations.allows" => {
            let destinations = tri!(destinations(a.value("destinations")?, session)?);
            let allows: Vec<bool> =
                a.array("addresses")?.iter().map(|x| destinations.allows(x.as_str().unwrap_or_default())).collect();
            Outcome::ok(json!({"allows": allows, "proxy": destinations.permits_proxy()}))
        }
        "backoff.delay" => {
            let request = tri!(Request::new(a.str("method")?, "http://example.com/"));
            let backoff = backoff(a.opt("strategy").unwrap_or(&Value::Null));
            let attempt = a.i64("attempt")? as u32;
            let delay = match a.opt("error") {
                Some(class) => {
                    backoff.delay(&request, attempt, Attempt::Error(&exception(class.as_str().unwrap_or_default())?))
                }
                None => backoff.delay(&request, attempt, Attempt::Response(&tri!(response(a.value("response")?)))),
            };
            Outcome::ok(delay.map(float_value).unwrap_or(Value::Null))
        }
        "exception.kind" => {
            let error = exception(a.str("class")?)?;
            Outcome::ok(
                json!({"client": error.is_client(), "network": error.is_network(), "request": error.is_request()}),
            )
        }
        "response.build" => {
            let pairs = pairs(a.opt("headers"));
            let body = a.opt("body").and_then(bytes).unwrap_or_default();
            let built = tri!(Response::build(
                a.i64("status")? as u16,
                a.opt_str("reason")?.unwrap_or_default(),
                pairs.iter().map(|(n, v)| (n.as_str(), v.as_str())),
                body,
                a.opt_str("protocol")?.unwrap_or("1.1"),
            ));
            Outcome::ok(result(&built, session))
        }
        "client.new" => {
            let handle = tri!(client(args, session)?);
            Outcome::ok(session.handle(handle))
        }
        "client.send" | "client.stream" => {
            let span = match a.opt("span") {
                None => None,
                Some(tp) => {
                    utopia_span::Span::set_storage(Some(Arc::new(utopia_span::Memory::new())));
                    let tp = tp.as_str().filter(|t| !t.is_empty());
                    Some(utopia_span::Span::init("http.request", tp))
                }
            };
            let outcome = send(op == "client.stream", &a, session).await;
            match span {
                None => outcome?,
                Some(span) => {
                    let traceparent = span.traceparent();
                    span.finish();
                    utopia_span::Span::set_storage(None);
                    match outcome? {
                        Outcome::Ok(v) => Outcome::Ok(replace_traceparent(v, &traceparent)),
                        other => other,
                    }
                }
            }
        }
        "pool.send" => {
            let created = Arc::new(Mutex::new(0i64));
            let counter = created.clone();
            let connections = tri!(
                Connections::new(
                    Stack::new(),
                    "test",
                    a.i64("size")? as usize,
                    move || {
                        *counter.lock().unwrap() += 1;
                        async { Ok::<_, Error>(Http::new()) }
                    },
                    0.0,
                )
                .map_err(Error::from)
            );
            let pool = Pool::new(connections);
            let stream = a.opt_bool("stream")?.unwrap_or(false);
            let mut responses = Vec::new();
            for r in a.array("requests")? {
                let req = tri!(request(r, session));
                if stream {
                    let mut streamed = Vec::new();
                    let mut sink = |chunk: &[u8]| streamed.extend_from_slice(chunk);
                    let mut out = result(&tri!(pool.stream(req, &mut sink).await), session);
                    out["streamed"] = localize(&streamed, session);
                    responses.push(out);
                } else {
                    responses.push(result(&tri!(pool.send(req).await), session));
                }
            }
            let created = *created.lock().unwrap();
            Outcome::ok(json!({"responses": responses, "created": created}))
        }
        _ => return Err(Fault::new(format!("client: unknown op `{op}`"))),
    })
}

async fn send(stream: bool, a: &Args<'_>, session: &mut Session) -> OpResult {
    let req = tri!(request(a.value("request")?, session));
    let fresh;
    let handle = match a.opt("client") {
        Some(client) => session.get::<Handle>(client)?,
        None => {
            fresh = Handle::Plain(Client::new(Http::new()));
            &fresh
        }
    };
    let mut streamed = Vec::new();
    let mut sink = |chunk: &[u8]| streamed.extend_from_slice(chunk);
    let (sent, sleeps) = match handle {
        Handle::Plain(c) => (if stream { c.stream(req, &mut sink).await } else { c.send(req).await }, None),
        Handle::Retrying(c, recorder) => {
            (if stream { c.stream(req, &mut sink).await } else { c.send(req).await }, Some(recorder.clone()))
        }
    };
    let mut out = result(&tri!(sent), session);
    if stream {
        out["streamed"] = localize(&streamed, session);
    }
    if let Some(sleeps) = sleeps {
        let list = sleeps.0.lock().unwrap().clone();
        if !list.is_empty() {
            out["sleeps"] = Value::Array(list.into_iter().map(float_value).collect());
        }
    }
    Ok(Outcome::ok(out))
}

fn replace_traceparent(value: Value, traceparent: &str) -> Value {
    match value {
        Value::String(s) => Value::String(s.replace(traceparent, "${traceparent}")),
        Value::Array(list) => Value::Array(list.into_iter().map(|v| replace_traceparent(v, traceparent)).collect()),
        Value::Object(map) => {
            Value::Object(map.into_iter().map(|(k, v)| (k, replace_traceparent(v, traceparent))).collect())
        }
        other => other,
    }
}

fn client(args: &Value, session: &Session) -> Result<Result<Handle, Error>, Fault> {
    let a = Args(args);
    let settings = a.opt("with").and_then(Value::as_array).cloned().unwrap_or_default();
    let initial = match a.opt("destinations") {
        Some(d) => match destinations(d, session)? {
            Ok(d) => Some(d),
            Err(e) => return Ok(Err(e)),
        },
        None => None,
    };
    Ok(match a.opt("retry") {
        Some(strategy) => {
            let recorder = Recorder::default();
            let adapter = Retry::new(Http::new(), backoff(strategy)).with_sleep(recorder.clone());
            configure(Client::new(adapter), initial, &settings, session)?.map(|c| Handle::Retrying(c, recorder))
        }
        None => configure(Client::new(Http::new()), initial, &settings, session)?.map(Handle::Plain),
    })
}

/// A destinations policy as an object both `with_destinations` and checks use.
#[derive(Debug, Clone)]
struct Policy(Arc<dyn Destinations>);

impl Destinations for Policy {
    fn allows(&self, address: &str) -> bool {
        self.0.allows(address)
    }

    fn permits_proxy(&self) -> bool {
        self.0.permits_proxy()
    }
}

fn configure<A: Adapter>(
    mut client: Client<A>,
    initial: Option<Policy>,
    settings: &[Value],
    session: &Session,
) -> Result<Result<Client<A>, Error>, Fault> {
    if let Some(d) = initial {
        client = client.with_destinations(d);
    }
    for setting in settings {
        let name = setting.get(0).and_then(Value::as_str).ok_or_else(|| Fault::new("setting name"))?;
        let value = setting.get(1).unwrap_or(&Value::Null);
        let list = |i: usize| value.get(i).unwrap_or(&Value::Null);
        let next = match name {
            "destinations" => match destinations(value, session)? {
                Ok(d) => Ok(client.with_destinations(d)),
                Err(e) => Err(e),
            },
            "timeout" => client.with_timeout(float(value).ok_or_else(|| Fault::new("timeout"))?),
            "connect_timeout" => client.with_connect_timeout(float(value).ok_or_else(|| Fault::new("timeout"))?),
            "ssl_verification" => Ok(client.with_ssl_verification(value.as_bool().unwrap_or(true))),
            "custom_ca" => Ok(client.with_custom_ca(value.as_str().unwrap_or_default())),
            "certificate" => Ok(client.with_certificate(
                list(0).as_str().unwrap_or_default(),
                list(1).as_str().unwrap_or_default(),
                list(2).as_str(),
            )),
            "min_tls" => Ok(client.with_min_tls_version(match value.as_str().unwrap_or_default() {
                "V1_0" => Tls::V1_0,
                "V1_1" => Tls::V1_1,
                "V1_2" => Tls::V1_2,
                "V1_3" => Tls::V1_3,
                other => return Err(Fault::new(format!("unknown TLS version {other}"))),
            })),
            "connection_reuse" => Ok(client.with_connection_reuse(value.as_bool().unwrap_or(true))),
            "follow_redirects" => client.with_follow_redirects(
                list(0).as_bool().unwrap_or(true),
                list(1).as_i64().unwrap_or(i64::from(redirect::MAX_HOPS)),
            ),
            "headers" => {
                let mut headers: Vec<(String, Vec<String>)> = Vec::new();
                for (n, v) in value.as_object().ok_or_else(|| Fault::new("headers must be an object"))? {
                    let values = match v {
                        Value::Array(list) => list.iter().map(|x| x.as_str().unwrap_or_default().to_owned()).collect(),
                        other => vec![other.as_str().unwrap_or_default().to_owned()],
                    };
                    headers.push((n.clone(), values));
                }
                Ok(client.with_headers(headers))
            }
            "base_uri" => client.with_base_uri(&globalize(value.as_str().unwrap_or_default(), session)?),
            "basic_auth" => {
                Ok(client.with_basic_auth(list(0).as_str().unwrap_or_default(), list(1).as_str().unwrap_or_default()))
            }
            "bearer_auth" => Ok(client.with_bearer_auth(value.as_str().unwrap_or_default())),
            "trace" => Ok(client.with_trace_propagation(value.as_bool().unwrap_or(true))),
            other => return Err(Fault::new(format!("unknown setting {other}"))),
        };
        client = match next {
            Ok(c) => c,
            Err(e) => return Ok(Err(e)),
        };
    }
    Ok(Ok(client))
}

fn destinations(d: &Value, session: &Session) -> Result<Result<Policy, Error>, Fault> {
    match d.get("type").and_then(Value::as_str) {
        Some("anywhere") => Ok(Ok(Policy(Arc::new(Anywhere)))),
        Some("public") => {
            let mut also = Vec::new();
            for range in d.get("also").and_then(Value::as_array).cloned().unwrap_or_default() {
                match IpRange::new(&globalize(range.as_str().unwrap_or_default(), session)?) {
                    Ok(r) => also.push(r),
                    Err(e) => return Ok(Err(e)),
                }
            }
            Ok(Ok(Policy(Arc::new(PublicInternet::new(also)))))
        }
        _ => Err(Fault::new(format!("unknown destinations {d}"))),
    }
}

fn backoff(b: &Value) -> Backoff {
    let get = |k: &str, d: f64| b.get(k).and_then(float).unwrap_or(d);
    let random = get("random", 0.5);
    Backoff::new(
        b.get("max_attempts").and_then(Value::as_i64).unwrap_or(3),
        get("base_delay", 0.1),
        get("max_delay", 10.0),
        get("multiplier", 2.0),
    )
    .with_randomizer(move || random)
}

fn exception(class: &str) -> Result<Error, Fault> {
    let m = "failure".to_owned();
    Ok(match class {
        "RequestException" => Error::Request(m),
        "AdapterInitializationException" => Error::AdapterInitialization(m),
        "AdapterPreconditionException" => Error::AdapterPrecondition(m),
        "InvalidResponseException" => Error::InvalidResponse(m),
        "InvalidUriException" => Error::InvalidUri(m),
        "NetworkException" => Error::Network(m),
        "ConnectionException" => Error::Connection(m),
        "DestinationException" => Error::Destination(m),
        "DnsException" => Error::Dns(m),
        "ProtocolException" => Error::Protocol(m),
        "ProxyException" => Error::Proxy(m),
        "TimeoutException" => Error::Timeout(m),
        "TlsException" => Error::Tls(m),
        other => return Err(Fault::new(format!("unknown exception {other}"))),
    })
}

fn pairs(value: Option<&Value>) -> Vec<(String, String)> {
    value
        .and_then(Value::as_array)
        .map(|list| {
            list.iter()
                .map(|p| {
                    (
                        p.get(0).and_then(Value::as_str).unwrap_or_default().to_owned(),
                        p.get(1).and_then(Value::as_str).unwrap_or_default().to_owned(),
                    )
                })
                .collect()
        })
        .unwrap_or_default()
}

fn request(r: &Value, session: &Session) -> Result<Request, Error> {
    let a = Args(r);
    let method = a.str("method").unwrap_or("GET");
    let uri = match a.opt("raw").and_then(bytes) {
        Some(raw) => format!(
            "http://${{http}}/raw?r={}",
            php_std::encoding::rawurlencode(php_std::encoding::base64_encode(&raw).as_bytes())
        ),
        None => a.str("uri").unwrap_or_default().to_owned(),
    };
    let uri = globalize(&uri, session).unwrap_or_default();
    let mut request = Request::new(method, &uri)?;
    for (name, value) in pairs(a.opt("headers")) {
        request = request.added_header(&name, &value)?;
    }
    if let Some(file) = a.opt("file").and_then(Value::as_str) {
        request = request.body(Body::File(file.into()));
    } else if let Some(body) = a.opt("body") {
        let bytes = match body.get("repeat") {
            Some(unit) => unit
                .as_str()
                .unwrap_or_default()
                .repeat(body.get("times").and_then(Value::as_u64).unwrap_or(0) as usize)
                .into_bytes(),
            None => bytes(body).unwrap_or_default(),
        };
        request = request.body(bytes);
    }
    Ok(request)
}

fn response(r: &Value) -> Result<Response, Error> {
    let mut response = Response::new(r.get("status").and_then(Value::as_u64).unwrap_or(200) as u16)?;
    for (name, value) in pairs(r.get("headers")) {
        response = response.with_header(&name, &value)?;
    }
    Ok(response)
}

fn headers(headers: &utopia_client::Headers, session: &Session) -> Value {
    Value::Array(
        headers
            .iter()
            .map(|(name, values)| {
                json!([name, values.iter().map(|v| localize(v.as_bytes(), session)).collect::<Vec<_>>()])
            })
            .collect(),
    )
}

fn result(response: &Response, session: &Session) -> Value {
    json!({
        "protocol": response.protocol(),
        "status": response.status(),
        "reason": response.reason(),
        "headers": headers(response.headers(), session),
        "body": localize(response.bytes(), session),
    })
}

fn globalize(value: &str, session: &Session) -> Result<String, Fault> {
    Ok(value
        .replace("${http}", session.service("client-http")?)
        .replace("${https}", session.service("client-https")?)
        .replace("${other-tls}", session.service("client-other-tls")?)
        .replace("${other}", session.service("client-other")?)
        .replace("${range}", session.service("client-range")?))
}

fn localize(value: &[u8], session: &Session) -> Value {
    let mut out = value.to_vec();
    for (service, placeholder) in
        [("client-http", "${http}"), ("client-https", "${https}"), ("client-other", "${other}")]
    {
        if let Ok(authority) = session.service(service) {
            out = replace(&out, authority.as_bytes(), placeholder.as_bytes());
        }
    }
    bytes_value(&out)
}

fn replace(haystack: &[u8], needle: &[u8], with: &[u8]) -> Vec<u8> {
    if needle.is_empty() {
        return haystack.to_vec();
    }
    let mut out = Vec::with_capacity(haystack.len());
    let mut i = 0;
    while i < haystack.len() {
        if haystack[i..].starts_with(needle) {
            out.extend_from_slice(with);
            i += needle.len();
        } else {
            out.push(haystack[i]);
            i += 1;
        }
    }
    out
}
