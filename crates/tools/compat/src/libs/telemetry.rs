//! Compat adapter for `telemetry`: maps `tests/compat/telemetry/spec.json`
//! operations onto `utopia-telemetry`.

use std::sync::{Arc, Mutex};
use std::time::Duration;

use serde_json::{Value as Json, json};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use utopia_telemetry::{
    BoxFuture, Counter, Error, Gauge, Histogram, HttpTransport, Kind, MemoryTelemetry, NoTelemetry, Number,
    ObservableGauge, OpenTelemetry, Telemetry, Transport, UpDownCounter, Value,
};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value, float, float_value};

pub const OPS: &[&str] = &[
    "telemetry.memory",
    "telemetry.none",
    "telemetry.otlp",
    "telemetry.collect",
    "instrument.new",
    "instrument.record",
    "instrument.observe",
    "source.new",
    "source.set",
    "memory.dump",
    "memory.observe",
    "otlp.payloads",
    "telemetry.replay",
    "transport.new",
    "transport.content_type",
    "transport.shutdown",
    "transport.force_flush",
    "transport.send",
    "transport.exchange",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("counter_memory", "Add to a counter of the in-memory (Test) backend with one attribute"),
    ("counter_none", "Add to a counter of the no-op backend"),
    ("counter_otlp", "Add to an OpenTelemetry counter with two attributes"),
    ("histogram_otlp", "Record a duration on an OpenTelemetry histogram with one attribute"),
];

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use crate::bench::Bench;

    Ok(match name {
        "counter_memory" => {
            let counter = MemoryTelemetry::new().counter("bench.counter", None, None);
            Bench::sync(move || counter.add(black_box(1), &[("route", Value::from("/v1/health"))]))
        }
        "counter_none" => {
            let counter = NoTelemetry.counter("bench.counter", None, None);
            Bench::sync(move || counter.add(black_box(1), &[("route", Value::from("/v1/health"))]))
        }
        "counter_otlp" => {
            let counter = otlp("namespace", "service", "instance").0.counter("bench.counter", None, None);
            Bench::sync(move || {
                counter.add(black_box(1), &[("route", Value::from("/v1/health")), ("method", Value::from("GET"))])
            })
        }
        "histogram_otlp" => {
            let histogram =
                otlp("namespace", "service", "instance").0.histogram("bench.duration", Some("s"), None, None);
            Bench::sync(move || histogram.record(black_box(0.002), &[("operation", Value::from("load"))]))
        }
        _ => return Err(Fault::new(format!("telemetry: no benchmark `{name}`"))),
    })
}

/// Keeps every payload instead of sending it.
#[derive(Default)]
struct Capture(Mutex<Vec<Vec<u8>>>);

impl Transport for Capture {
    fn content_type(&self) -> &str {
        "application/x-protobuf"
    }

    fn send(&self, payload: Vec<u8>) -> BoxFuture<'_, Result<Vec<u8>, Error>> {
        self.0.lock().unwrap_or_else(|e| e.into_inner()).push(payload);
        Box::pin(async { Ok(Vec::new()) })
    }

    fn shutdown(&self) -> bool {
        true
    }
}

fn otlp(namespace: &str, service: &str, instance: &str) -> (OpenTelemetry, Arc<Capture>) {
    let capture = Arc::new(Capture::default());
    (OpenTelemetry::with_transport(namespace, service, instance, capture.clone()), capture)
}

#[derive(Clone)]
enum Backend {
    Memory(MemoryTelemetry),
    None,
    Otlp(OpenTelemetry, Arc<Capture>),
}

impl Backend {
    fn telemetry(&self) -> &dyn Telemetry {
        match self {
            Backend::Memory(m) => m,
            Backend::None => &NoTelemetry,
            Backend::Otlp(o, _) => o,
        }
    }
}

#[derive(Clone)]
enum Instrument {
    Counter(Counter),
    UpDownCounter(UpDownCounter),
    Histogram(Histogram),
    Gauge(Gauge),
    Observable(ObservableGauge),
}

type Source = Arc<Mutex<Vec<Json>>>;

fn number(value: &Json) -> Result<Number, Fault> {
    match value.as_i64() {
        Some(i) if !value.is_f64() => Ok(Number::Int(i)),
        _ => float(value).map(Number::Float).ok_or_else(|| Fault::new("not a number")),
    }
}

fn number_json(n: Number) -> Json {
    match n {
        Number::Int(i) => json!(i),
        Number::Float(f) => float_value(f),
    }
}

fn attribute(value: &Json) -> Result<Value<'static>, Fault> {
    Ok(match value {
        Json::String(s) => Value::from(s.clone()),
        Json::Bool(b) => Value::Bool(*b),
        n @ Json::Number(_) => match number(n)? {
            Number::Int(i) => Value::Int(i),
            Number::Float(f) => Value::Float(f),
        },
        _ => return Err(Fault::new("unsupported attribute value")),
    })
}

/// `[[key, value], ...]` pairs, or an object.
fn attributes(value: Option<&Json>) -> Result<Vec<(String, Value<'static>)>, Fault> {
    if let Some(Json::Object(map)) = value {
        return map.iter().map(|(k, v)| Ok((k.clone(), attribute(v)?))).collect();
    }
    let Some(Json::Array(pairs)) = value else { return Ok(Vec::new()) };
    pairs
        .iter()
        .map(|pair| match pair.as_array().map(Vec::as_slice) {
            Some([Json::String(k), v]) => Ok((k.clone(), attribute(v)?)),
            _ => Err(Fault::new("attributes are [key, value] pairs")),
        })
        .collect()
}

fn attribute_json(v: &Value<'_>) -> Json {
    match v {
        Value::Str(s) => json!(s),
        Value::Int(i) => json!(i),
        Value::Float(f) => float_value(*f),
        Value::Bool(b) => json!(b),
    }
}

fn pairs_json(attributes: &[(String, Value<'static>)]) -> Json {
    Json::Array(attributes.iter().map(|(k, v)| json!([k, attribute_json(v)])).collect())
}

/// What the Test backend holds, as `dump()` in Adapter.php reports it.
fn memory_dump(m: &MemoryTelemetry) -> Json {
    let kind = |k: Kind| {
        Json::Array(
            m.names(k)
                .into_iter()
                .map(|n| {
                    let values = m.values(k, &n).unwrap_or_default();
                    json!([n, values.into_iter().map(number_json).collect::<Vec<_>>()])
                })
                .collect(),
        )
    };
    let observable: Vec<Json> =
        m.names(Kind::ObservableGauge).into_iter().map(|n| json!([n, m.callbacks(&n).unwrap_or(0)])).collect();
    json!({
        "counters": kind(Kind::Counter),
        "upDownCounters": kind(Kind::UpDownCounter),
        "histograms": kind(Kind::Histogram),
        "gauges": kind(Kind::Gauge),
        "observableGauges": observable,
    })
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn transport(endpoint: &str, a: &Args<'_>) -> Result<HttpTransport, Error> {
    let mut t = HttpTransport::new(endpoint)?;
    if let Ok(Some(ct)) = a.opt_str("content_type") {
        t = t.with_content_type(ct);
    }
    if let Ok(headers) = attributes(a.opt("headers")) {
        for (k, v) in headers {
            t = t.with_header(&k, v.as_str().unwrap_or_default());
        }
    }
    if let Ok(Some(timeout)) = a.opt_f64("timeout") {
        t = t.with_timeout(Duration::from_secs_f64(timeout.max(0.0)));
    }
    if let Ok(Some(size)) = a.opt_i64("pool_size") {
        t = t.with_pool_size(size.max(0) as usize);
    }
    if let Ok(Some(size)) = a.opt_i64("socket_buffer_size") {
        t = t.with_socket_buffer_size(size.max(0) as usize);
    }
    Ok(t)
}

fn send_json(result: Result<Vec<u8>, Error>) -> Json {
    match result {
        Ok(body) => bytes_value(&body),
        Err(e) => err(e).to_json(),
    }
}

pub async fn call(op: &str, args: &Json, session: &mut Session) -> OpResult {
    let a = Args(args);
    let backend = |s: &Session| -> Result<Backend, Fault> { s.get::<Backend>(a.value("telemetry")?).cloned() };
    let result = match op {
        "telemetry.memory" => Outcome::Ok(session.handle(Backend::Memory(MemoryTelemetry::new()))),
        "telemetry.none" => Outcome::Ok(session.handle(Backend::None)),
        "telemetry.otlp" => {
            let (t, capture) = otlp(
                a.opt_str("namespace")?.unwrap_or("namespace"),
                a.opt_str("service")?.unwrap_or("service"),
                a.opt_str("instance")?.unwrap_or("instance"),
            );
            Outcome::Ok(session.handle(Backend::Otlp(t, capture)))
        }
        "telemetry.collect" => Outcome::ok(backend(session)?.telemetry().collect().await),
        "instrument.new" => {
            let b = backend(session)?;
            let t = b.telemetry();
            let (name, unit, description) = (a.str("name")?, a.opt_str("unit")?, a.opt_str("description")?);
            let boundaries: Option<Vec<f64>> = match a.opt("boundaries") {
                Some(Json::Array(items)) => Some(items.iter().filter_map(float).collect()),
                _ => None,
            };
            let instrument = match a.str("kind")? {
                "counter" => Instrument::Counter(t.counter(name, unit, description)),
                "up_down_counter" => Instrument::UpDownCounter(t.up_down_counter(name, unit, description)),
                "histogram" => Instrument::Histogram(t.histogram(name, unit, description, boundaries.as_deref())),
                "gauge" => Instrument::Gauge(t.gauge(name, unit, description)),
                "observable_gauge" => Instrument::Observable(t.observable_gauge(name, unit, description)),
                other => return Err(Fault::new(format!("unknown instrument kind `{other}`"))),
            };
            Outcome::Ok(session.handle(instrument))
        }
        "instrument.record" => {
            let value = number(a.value("value")?)?;
            let owned = attributes(a.opt("attributes"))?;
            let attrs: Vec<(&str, Value<'_>)> = owned.iter().map(|(k, v)| (k.as_str(), v.clone())).collect();
            match session.get::<Instrument>(a.value("instrument")?)? {
                Instrument::Counter(c) => c.add(value, &attrs),
                Instrument::UpDownCounter(c) => c.add(value, &attrs),
                Instrument::Histogram(h) => h.record(value, &attrs),
                Instrument::Gauge(g) => g.record(value, &attrs),
                Instrument::Observable(_) => return Err(Fault::new("not a synchronous instrument")),
            }
            Outcome::Ok(Json::Null)
        }
        "instrument.observe" => {
            let source = session.get::<Source>(a.value("source")?)?.clone();
            let Instrument::Observable(gauge) = session.get::<Instrument>(a.value("instrument")?)? else {
                return Err(Fault::new("not an observable gauge"));
            };
            gauge.observe(move |observer| {
                let observations = source.lock().unwrap_or_else(|e| e.into_inner()).clone();
                for o in observations {
                    let (Ok(value), Ok(owned)) = (number(&o["value"]), attributes(o.get("attributes"))) else {
                        continue;
                    };
                    let attrs: Vec<(&str, Value<'_>)> = owned.iter().map(|(k, v)| (k.as_str(), v.clone())).collect();
                    observer.observe(value, &attrs);
                }
            });
            Outcome::Ok(Json::Null)
        }
        "source.new" => {
            let observations = a.opt("observations").and_then(Json::as_array).cloned().unwrap_or_default();
            Outcome::Ok(session.handle::<Source>(Arc::new(Mutex::new(observations))))
        }
        "source.set" => {
            let observations = a.array("observations")?.clone();
            *session.get::<Source>(a.value("source")?)?.lock().unwrap_or_else(|e| e.into_inner()) = observations;
            Outcome::Ok(Json::Null)
        }
        "memory.dump" => {
            let Backend::Memory(m) = backend(session)? else { return Err(Fault::new("not a Test backend")) };
            Outcome::Ok(memory_dump(&m))
        }
        "memory.observe" => {
            let Backend::Memory(m) = backend(session)? else { return Err(Fault::new("not a Test backend")) };
            match m.observe(a.str("name")?).await {
                None => Outcome::Ok(Json::Null),
                Some(observed) => Outcome::Ok(Json::Array(
                    observed.iter().map(|o| json!([number_json(o.value), pairs_json(&o.attributes)])).collect(),
                )),
            }
        }
        "otlp.payloads" => {
            let Backend::Otlp(_, capture) = backend(session)? else {
                return Err(Fault::new("not an OpenTelemetry backend"));
            };
            let payloads = capture.0.lock().unwrap_or_else(|e| e.into_inner()).clone();
            Outcome::Ok(Json::Array(payloads.iter().map(|p| otlp_decode::request(p)).collect::<Result<_, _>>()?))
        }
        "telemetry.replay" => {
            let (backend, capture) = match a.str("backend")? {
                "memory" => (Backend::Memory(MemoryTelemetry::new()), None),
                "none" => (Backend::None, None),
                _ => {
                    let (t, c) = otlp("namespace", "service", "instance");
                    (Backend::Otlp(t, c.clone()), Some(c))
                }
            };
            let t = backend.telemetry();
            for record in a.array("records")? {
                let r = Args(record);
                let (name, unit, description) = (r.str("name")?, r.opt_str("unit")?, r.opt_str("description")?);
                let value = number(r.value("value")?)?;
                let owned = attributes(r.opt("attributes"))?;
                let attrs: Vec<(&str, Value<'_>)> = owned.iter().map(|(k, v)| (k.as_str(), v.clone())).collect();
                match r.str("kind")? {
                    "counter" => t.counter(name, unit, description).add(value, &attrs),
                    "up_down_counter" => t.up_down_counter(name, unit, description).add(value, &attrs),
                    "histogram" => t.histogram(name, unit, description, None).record(value, &attrs),
                    "gauge" => t.gauge(name, unit, description).record(value, &attrs),
                    other => return Err(Fault::new(format!("unknown instrument kind `{other}`"))),
                }
            }
            let collected = [t.collect().await, t.collect().await];
            let held = match (&backend, capture) {
                (Backend::Memory(m), _) => memory_dump(m),
                (_, Some(c)) => {
                    let payloads = c.0.lock().unwrap_or_else(|e| e.into_inner()).clone();
                    Json::Array(payloads.iter().map(|p| otlp_decode::request(p)).collect::<Result<_, _>>()?)
                }
                _ => json!([]),
            };
            Outcome::Ok(json!({ "collected": collected, "held": held }))
        }
        "transport.new" => match transport(a.str("endpoint")?, &a) {
            Ok(t) => Outcome::Ok(session.handle(Arc::new(t))),
            Err(e) => err(e),
        },
        "transport.content_type" => {
            Outcome::ok(session.get::<Arc<HttpTransport>>(a.value("transport")?)?.content_type().to_owned())
        }
        "transport.shutdown" => Outcome::ok(session.get::<Arc<HttpTransport>>(a.value("transport")?)?.shutdown()),
        "transport.force_flush" => Outcome::ok(session.get::<Arc<HttpTransport>>(a.value("transport")?)?.force_flush()),
        "transport.send" => {
            let t = session.get::<Arc<HttpTransport>>(a.value("transport")?)?.clone();
            let payload = crate::adapter::bytes(a.value("payload")?).ok_or_else(|| Fault::new("payload"))?;
            match t.send(payload).await {
                Ok(body) => Outcome::Ok(bytes_value(&body)),
                Err(e) => err(e),
            }
        }
        "transport.exchange" => Outcome::Ok(exchange(&a).await?),
        _ => return Err(Fault::new(format!("telemetry: unknown operation `{op}`"))),
    };
    Ok(result)
}

/// See `exchange()` in Adapter.php: a scripted collector on a local port and
/// a transport sending to it.
async fn exchange(a: &Args<'_>) -> Result<Json, Fault> {
    let script: Vec<Json> =
        a.opt("script").and_then(Json::as_array).cloned().unwrap_or_else(|| vec![json!({"status": 200, "body": ""})]);
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.map_err(|e| Fault::new(e.to_string()))?;
    let port = listener.local_addr().map_err(|e| Fault::new(e.to_string()))?.port();
    let requests: Arc<Mutex<Vec<Json>>> = Arc::default();
    let server = {
        let requests = requests.clone();
        tokio::spawn(async move {
            while let Ok((mut socket, _)) = listener.accept().await {
                let (requests, script) = (requests.clone(), script.clone());
                tokio::spawn(async move {
                    let mut buffer = Vec::new();
                    let mut chunk = vec![0u8; 65536];
                    loop {
                        let read = match tokio::time::timeout(Duration::from_secs(5), socket.read(&mut chunk)).await {
                            Ok(Ok(n)) if n > 0 => n,
                            _ => return,
                        };
                        buffer.extend_from_slice(&chunk[..read]);
                        while let Some(request) = take_request(&mut buffer) {
                            let step = {
                                let mut r = requests.lock().unwrap_or_else(|e| e.into_inner());
                                r.push(request);
                                script[r.len().min(script.len()) - 1].clone()
                            };
                            if step["reset"].as_bool().unwrap_or(false) {
                                // A zero linger resets the connection and never blocks.
                                #[allow(deprecated)]
                                let _ = socket.set_linger(Some(Duration::ZERO));
                                return;
                            }
                            if let Some(ms) = step["hang_ms"].as_u64() {
                                tokio::time::sleep(Duration::from_millis(ms)).await;
                                return;
                            }
                            if let Some(ms) = step["delay_ms"].as_u64() {
                                tokio::time::sleep(Duration::from_millis(ms)).await;
                            }
                            let body = step["body"].as_str().unwrap_or("");
                            let status = step["status"].as_u64().unwrap_or(200);
                            let reply = format!("HTTP/1.1 {status} X\r\nContent-Length: {}\r\n\r\n{body}", body.len());
                            if socket.write_all(reply.as_bytes()).await.is_err() {
                                return;
                            }
                        }
                    }
                });
            }
        })
    };
    let default = format!("http://127.0.0.1:{port}/v1/metrics");
    let endpoint = a.opt_str("endpoint")?.unwrap_or(&default);
    let t = match transport(endpoint, a) {
        Ok(t) => Arc::new(t),
        Err(e) => return Ok(err(e).to_json()),
    };
    let payloads: Vec<Vec<u8>> = a
        .array("sends")?
        .iter()
        .map(|p| crate::adapter::bytes(p).ok_or_else(|| Fault::new("payload")))
        .collect::<Result<_, _>>()?;
    let concurrent = a.opt_bool("concurrent")?.unwrap_or(false);
    let results: Vec<Json> = if concurrent {
        futures_util::future::join_all(payloads.into_iter().map(|p| {
            let t = t.clone();
            async move { send_json(t.send(p).await) }
        }))
        .await
    } else {
        let mut out = Vec::new();
        for p in payloads {
            out.push(send_json(t.send(p).await));
        }
        out
    };
    t.shutdown();
    server.abort();
    let mut requests = requests.lock().unwrap_or_else(|e| e.into_inner()).clone();
    if concurrent {
        requests.sort_by(|x, y| x["payload"].as_str().cmp(&y["payload"].as_str()));
    }
    Ok(json!({ "results": results, "requests": requests }))
}

/// One complete HTTP request off the front of `buffer`: its body and its
/// compared headers (lowercased, sorted), or `None` while it is arriving.
fn take_request(buffer: &mut Vec<u8>) -> Option<Json> {
    let end = buffer.windows(4).position(|w| w == b"\r\n\r\n")?;
    let head = String::from_utf8_lossy(&buffer[..end]).into_owned();
    let mut headers = Vec::new();
    let mut length = 0usize;
    for line in head.split("\r\n").skip(1) {
        let (name, value) = line.split_once(':').unwrap_or((line, ""));
        let (name, value) = (name.trim().to_ascii_lowercase(), value.trim().to_owned());
        if name == "content-length" {
            length = value.parse().unwrap_or(0);
        }
        if ["content-type", "content-length", "authorization", "x-custom-header"].contains(&name.as_str()) {
            headers.push((name, value));
        }
    }
    if buffer.len() < end + 4 + length {
        return None;
    }
    let payload = buffer[end + 4..end + 4 + length].to_vec();
    buffer.drain(..end + 4 + length);
    headers.sort();
    Some(json!({
        "payload": bytes_value(&payload),
        "headers": headers.into_iter().map(|(k, v)| json!([k, v])).collect::<Vec<_>>(),
    }))
}

/// Decodes OTLP export requests into the shape `decode()` in Adapter.php
/// builds from the PHP protobuf classes.
mod otlp_decode {
    use serde_json::{Map, Value as Json, json};

    use crate::adapter::{Fault, float_value};

    enum Field<'a> {
        Varint(u64),
        Fixed64([u8; 8]),
        Len(&'a [u8]),
        Fixed32,
    }

    fn varint(b: &[u8], i: &mut usize) -> Result<u64, Fault> {
        let mut v = 0u64;
        for shift in (0..64).step_by(7) {
            let byte = *b.get(*i).ok_or_else(|| Fault::new("truncated varint"))?;
            *i += 1;
            v |= u64::from(byte & 0x7f) << shift;
            if byte < 0x80 {
                return Ok(v);
            }
        }
        Err(Fault::new("varint too long"))
    }

    fn fields(b: &[u8]) -> Result<Vec<(u64, Field<'_>)>, Fault> {
        let mut out = Vec::new();
        let mut i = 0;
        while i < b.len() {
            let key = varint(b, &mut i)?;
            let field = match key & 7 {
                0 => Field::Varint(varint(b, &mut i)?),
                1 => {
                    let s: [u8; 8] =
                        b.get(i..i + 8).ok_or_else(|| Fault::new("truncated"))?.try_into().unwrap_or_default();
                    i += 8;
                    Field::Fixed64(s)
                }
                2 => {
                    let n = varint(b, &mut i)? as usize;
                    let s = b.get(i..i + n).ok_or_else(|| Fault::new("truncated"))?;
                    i += n;
                    Field::Len(s)
                }
                5 => {
                    i += 4;
                    Field::Fixed32
                }
                _ => return Err(Fault::new("unsupported wire type")),
            };
            out.push((key >> 3, field));
        }
        Ok(out)
    }

    fn msgs(b: &[u8], n: u64) -> Result<Vec<&[u8]>, Fault> {
        Ok(fields(b)?
            .into_iter()
            .filter_map(|(f, v)| if let (true, Field::Len(s)) = (f == n, v) { Some(s) } else { None })
            .collect())
    }

    fn msg(b: &[u8], n: u64) -> Result<Option<&[u8]>, Fault> {
        Ok(msgs(b, n)?.pop())
    }

    fn string(b: &[u8], n: u64) -> Result<String, Fault> {
        Ok(msg(b, n)?.map(|s| String::from_utf8_lossy(s).into_owned()).unwrap_or_default())
    }

    fn uint(b: &[u8], n: u64) -> Result<u64, Fault> {
        Ok(fields(b)?
            .into_iter()
            .filter(|(f, _)| *f == n)
            .fold(0, |acc, (_, v)| if let Field::Varint(x) = v { x } else { acc }))
    }

    fn fixed(b: &[u8], n: u64) -> Result<Option<[u8; 8]>, Fault> {
        Ok(fields(b)?
            .into_iter()
            .filter(|(f, _)| *f == n)
            .fold(None, |acc, (_, v)| if let Field::Fixed64(x) = v { Some(x) } else { acc }))
    }

    fn double(b: &[u8], n: u64) -> Result<Json, Fault> {
        Ok(float_value(fixed(b, n)?.map_or(0.0, f64::from_le_bytes)))
    }

    fn key_values(b: &[u8], n: u64) -> Result<Json, Fault> {
        let mut out = Vec::new();
        for kv in msgs(b, n)? {
            let key = string(kv, 1)?;
            let value = match msg(kv, 2)? {
                None => Json::Null,
                Some(any) => {
                    let mut v = Json::Null;
                    for (f, field) in fields(any)? {
                        v = match (f, field) {
                            (1, Field::Len(s)) => json!(String::from_utf8_lossy(s)),
                            (2, Field::Varint(x)) => json!(x != 0),
                            (3, Field::Varint(x)) => json!(x as i64),
                            (4, Field::Fixed64(x)) => float_value(f64::from_le_bytes(x)),
                            _ => v,
                        };
                    }
                    v
                }
            };
            out.push(json!([key, value]));
        }
        Ok(Json::Array(out))
    }

    fn number_point(p: &[u8]) -> Result<Json, Fault> {
        let mut value = Json::Null;
        for (f, field) in fields(p)? {
            match (f, field) {
                (4, Field::Fixed64(x)) => value = float_value(f64::from_le_bytes(x)),
                (6, Field::Fixed64(x)) => value = json!(i64::from_le_bytes(x)),
                _ => {}
            }
        }
        Ok(json!({
            "attributes": key_values(p, 7)?,
            "value": value,
            "start": fixed(p, 2)?.is_some_and(|x| u64::from_le_bytes(x) != 0),
            "time": fixed(p, 3)?.is_some_and(|x| u64::from_le_bytes(x) != 0),
            "exemplars": msgs(p, 5)?.len(),
        }))
    }

    fn packed(b: &[u8], n: u64) -> Result<Vec<[u8; 8]>, Fault> {
        let mut out = Vec::new();
        for s in msgs(b, n)? {
            out.extend(s.chunks_exact(8).map(|c| <[u8; 8]>::try_from(c).unwrap_or_default()));
        }
        Ok(out)
    }

    fn histogram_point(p: &[u8]) -> Result<Json, Fault> {
        Ok(json!({
            "attributes": key_values(p, 9)?,
            "count": fixed(p, 4)?.map_or(0, u64::from_le_bytes),
            "sum": double(p, 5)?,
            "min": double(p, 11)?,
            "max": double(p, 12)?,
            "buckets": packed(p, 6)?.into_iter().map(u64::from_le_bytes).collect::<Vec<_>>(),
            "bounds": packed(p, 7)?.into_iter().map(|x| float_value(f64::from_le_bytes(x))).collect::<Vec<_>>(),
            "start": fixed(p, 2)?.is_some_and(|x| u64::from_le_bytes(x) != 0),
            "time": fixed(p, 3)?.is_some_and(|x| u64::from_le_bytes(x) != 0),
            "exemplars": msgs(p, 8)?.len(),
        }))
    }

    fn metric(m: &[u8]) -> Result<Json, Fault> {
        let mut out = Map::new();
        out.insert("name".into(), json!(string(m, 1)?));
        out.insert("unit".into(), json!(string(m, 3)?));
        out.insert("description".into(), json!(string(m, 2)?));
        if let Some(sum) = msg(m, 7)? {
            out.insert("data".into(), json!("sum"));
            out.insert("monotonic".into(), json!(uint(sum, 3)? != 0));
            out.insert("temporality".into(), json!(uint(sum, 2)?));
            out.insert(
                "points".into(),
                Json::Array(msgs(sum, 1)?.into_iter().map(number_point).collect::<Result<_, _>>()?),
            );
        } else if let Some(gauge) = msg(m, 5)? {
            out.insert("data".into(), json!("gauge"));
            out.insert(
                "points".into(),
                Json::Array(msgs(gauge, 1)?.into_iter().map(number_point).collect::<Result<_, _>>()?),
            );
        } else if let Some(h) = msg(m, 9)? {
            out.insert("data".into(), json!("histogram"));
            out.insert("temporality".into(), json!(uint(h, 2)?));
            out.insert(
                "points".into(),
                Json::Array(msgs(h, 1)?.into_iter().map(histogram_point).collect::<Result<_, _>>()?),
            );
        } else {
            out.insert("data".into(), json!(""));
        }
        Ok(Json::Object(out))
    }

    pub fn request(payload: &[u8]) -> Result<Json, Fault> {
        let mut resources = Vec::new();
        for rm in msgs(payload, 1)? {
            let mut scopes = Vec::new();
            for sm in msgs(rm, 2)? {
                let scope = msg(sm, 1)?;
                scopes.push(json!({
                    "name": scope.map(|s| string(s, 1)).transpose()?,
                    "version": scope.map(|s| string(s, 2)).transpose()?,
                    "schema_url": string(sm, 3)?,
                    "metrics": msgs(sm, 2)?.into_iter().map(metric).collect::<Result<Vec<_>, _>>()?,
                }));
            }
            resources.push(json!({
                "resource": msg(rm, 1)?.map_or(Ok(json!([])), |r| key_values(r, 1))?,
                "schema_url": string(rm, 3)?,
                "scopes": scopes,
            }));
        }
        Ok(Json::Array(resources))
    }
}
