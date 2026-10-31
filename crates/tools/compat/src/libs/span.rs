//! Compat adapter for `span`: maps `tests/compat/span/spec.json` operations onto `utopia-span`.

use std::hint::black_box;
use std::io::Write;
use std::sync::{Arc, Mutex};

use serde_json::{Map, Value as Json, json};
use utopia_span::exporter::{self, Request, SentryField, SentryLevel};
use utopia_span::{Auto, Exporter, Failure, Frame, Level, Memory, Pretty, Sentry, Span, Stdout, Storage, Task, Value};

use crate::adapter::{self, Args, Fault, OpResult, Outcome, Session, bytes_value, float_value};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "span.reset",
    "span.set_storage",
    "span.set_exporters",
    "span.recorder",
    "span.recorded",
    "span.recorded_is",
    "span.init",
    "span.current",
    "span.add",
    "span.current_traceparent",
    "span.new",
    "span.set",
    "span.get",
    "span.describe",
    "span.attributes",
    "span.set_error",
    "span.error",
    "span.action",
    "span.traceparent",
    "span.finish",
    "span.sleep",
    "span.duration_in",
    "span.script",
    "level.parse",
    "sentry.level",
    "storage.new",
    "storage.set",
    "storage.get",
    "storage.coroutine",
    "exporter.sample",
    "exporter.export",
    "sentry.new",
    "sentry.export",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("add", "Set an attribute on the current span"),
    ("set", "Set an attribute on a span held by the caller"),
    ("lifecycle", "Start a span, add three attributes and finish it without exporters"),
];

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "add" => {
            Span::set_exporters(Vec::new());
            Span::set_storage(Some(Arc::new(Memory::new())));
            Span::init("http.request", None);
            Bench::sync(|| Span::add(black_box("http.method"), black_box("GET")))
        }
        "set" => {
            let span = Span::new("http.request");
            Bench::sync(move || {
                span.set(black_box("http.response.status_code"), black_box(200));
            })
        }
        "lifecycle" => {
            Span::set_exporters(Vec::new());
            Span::set_storage(Some(Arc::new(Memory::new())));
            Bench::sync(|| {
                let span = Span::init("http.request", None);
                Span::add("http.method", "GET");
                Span::add("http.path", "/v1/users");
                Span::add("http.response.status_code", 200);
                span.finish();
            })
        }
        _ => return Err(Fault::new(format!("span: no benchmark `{name}`"))),
    })
}

/// Records the spans it exports (SpanTest::createExporter()).
struct Recorder {
    sampler: exporter::Sampler,
    exported: Mutex<Vec<Span>>,
}

impl Exporter for Recorder {
    fn sample(&self, span: &Span) -> bool {
        (self.sampler)(span)
    }

    fn export(&self, span: &Span) {
        self.exported.lock().unwrap().push(span.clone());
    }
}

/// A shared, writable buffer an exporter writes to.
#[derive(Clone, Default)]
struct Buffer(Arc<Mutex<Vec<u8>>>);

impl Write for Buffer {
    fn write(&mut self, b: &[u8]) -> std::io::Result<usize> {
        self.0.lock().unwrap().extend_from_slice(b);
        Ok(b.len())
    }

    fn flush(&mut self) -> std::io::Result<()> {
        Ok(())
    }
}

fn value(v: &Json) -> Result<Value, Fault> {
    Ok(match v {
        Json::Null => Value::Null,
        Json::Bool(b) => Value::Bool(*b),
        Json::Number(n) => match n.as_i64() {
            Some(i) => Value::Int(i),
            None => Value::Float(n.as_f64().ok_or_else(|| Fault::new("number"))?),
        },
        Json::String(s) => Value::from(s.as_str()),
        Json::Object(o) if o.contains_key("$float") => {
            Value::Float(adapter::float(v).ok_or_else(|| Fault::new("$float"))?)
        }
        Json::Object(_) => Value::from(adapter::bytes(v).ok_or_else(|| Fault::new("attribute values are scalars"))?),
        Json::Array(_) => return Err(Fault::new("attribute values are scalars")),
    })
}

fn json(v: &Value) -> Json {
    match v {
        Value::Null => Json::Null,
        Value::Bool(b) => Json::Bool(*b),
        Value::Int(i) => json!(i),
        Value::Float(f) => float_value(*f),
        Value::Str(s) => bytes_value(s.as_bytes()),
    }
}

fn pairs(attributes: &[(Box<str>, Value)]) -> Json {
    Json::Array(attributes.iter().map(|(k, v)| json!([k, json(v)])).collect())
}

fn sampler(spec: &Json) -> Result<exporter::Sampler, Fault> {
    let kind = spec.get("kind").and_then(Json::as_str).ok_or_else(|| Fault::new("sampler kind"))?;
    Ok(match kind {
        "all" => Box::new(|_| true),
        "none" => Box::new(|_| false),
        "error" => Box::new(|s| s.error().is_some()),
        "action" => {
            let action = spec.get("action").and_then(Json::as_str).unwrap_or_default().to_owned();
            Box::new(move |s| s.action() == action)
        }
        "duration_over" => {
            let seconds = spec.get("seconds").and_then(Json::as_f64).unwrap_or_default();
            Box::new(move |s| s.get("span.duration").as_float().is_some_and(|d| d > seconds))
        }
        _ => return Err(Fault::new(format!("unknown sampler {kind}"))),
    })
}

fn storage(kind: &str) -> Result<Arc<dyn Storage>, Fault> {
    Ok(match kind {
        "memory" => Arc::new(Memory::new()),
        "auto" => Arc::new(Auto::new()),
        "coroutine" => Arc::new(Task::new()),
        _ => return Err(Fault::new(format!("unknown storage {kind}"))),
    })
}

fn failure(spec: &Json) -> Result<Failure, Fault> {
    let a = Args(spec);
    let message = match a.opt("message") {
        Some(m) => adapter::bytes(m).ok_or_else(|| Fault::new("message"))?,
        None => Vec::new(),
    };
    let mut trace = Vec::new();
    for frame in a.opt("trace").and_then(Json::as_array).into_iter().flatten() {
        let f = Args(frame);
        trace.push(Frame {
            file: f.opt_str("file")?.map(str::to_owned),
            line: f.opt_i64("line")?.map(|l| l as u32),
            function: f.str("function")?.to_owned(),
            class: f.opt_str("class")?.map(str::to_owned),
            call: f.opt_str("type")?.map(str::to_owned),
        });
    }
    let mut failure = Failure::new(a.str("class")?, message)
        .code(a.opt_i64("code")?.unwrap_or(0))
        .at(a.opt_str("file")?.unwrap_or("unknown.php"), a.opt_i64("line")?.unwrap_or(0) as u32)
        .trace(trace);
    if let Some(previous) = a.opt("previous") {
        failure = failure.previous(self::failure(previous)?);
    }
    Ok(failure)
}

fn describe(error: Option<&Failure>) -> Json {
    match error {
        None => Json::Null,
        Some(e) => json!({
            "class": e.class(),
            "message": bytes_value(e.message_bytes()),
            "code": e.code_value(),
            "previous": describe(e.cause()),
        }),
    }
}

/// A span from its description: {action?, base?: {trace_id, span_id, started_at}, attributes: [{key, value}...], error?}.
fn build(spec: &Json) -> Result<Span, Fault> {
    let a = Args(spec);
    let span = match a.opt_str("action")? {
        Some(action) => Span::new(action),
        None => Span::default(),
    };
    if let Some(base) = a.opt("base") {
        for (field, key) in [
            ("trace_id", "span.trace_id"),
            ("span_id", "span.id"),
            ("started_at", "span.started_at"),
            ("finished_at", "span.finished_at"),
        ] {
            if let Some(v) = base.get(field).filter(|v| !v.is_null()) {
                span.set(key, value(v)?);
            }
        }
    }
    for attribute in a.opt("attributes").and_then(Json::as_array).into_iter().flatten() {
        let key = attribute.get("key").and_then(Json::as_str).ok_or_else(|| Fault::new("attribute key"))?;
        span.set(key, value(attribute.get("value").unwrap_or(&Json::Null))?);
    }
    if let Some(error) = a.opt("error") {
        span.set_error(failure(error)?);
    }
    Ok(span)
}

fn sentry(
    options: &Json,
    transport: impl exporter::Transport + 'static,
) -> Result<Result<Sentry, utopia_span::Error>, Fault> {
    let o = Args(options);
    let mut sentry = match Sentry::new(o.str("dsn")?) {
        Ok(s) => s.transport(transport),
        Err(e) => return Ok(Err(e)),
    };
    if let Some(s) = o.opt("sampler") {
        let s = sampler(s)?;
        sentry = sentry.sampler(s);
    }
    if let Some(v) = o.opt_str("environment")? {
        sentry = sentry.environment(v);
    }
    if let Some(v) = o.opt_str("release")? {
        sentry = sentry.release(v);
    }
    if let Some(v) = o.opt_str("server_name")? {
        sentry = sentry.server_name(v);
    }
    if let Some(rules) = o.opt("classifier") {
        let field = |name: &str| match name {
            "tag" => Ok(SentryField::Tag),
            "extra" => Ok(SentryField::Extra),
            "context" => Ok(SentryField::Context),
            _ => Err(Fault::new("unknown field")),
        };
        let mut prefixes = Vec::new();
        for rule in rules.get("prefixes").and_then(Json::as_array).into_iter().flatten() {
            let prefix = rule.get(0).and_then(Json::as_str).unwrap_or_default().to_owned();
            prefixes.push((prefix, field(rule.get(1).and_then(Json::as_str).unwrap_or_default())?));
        }
        let default = field(rules.get("default").and_then(Json::as_str).unwrap_or("context"))?;
        sentry = sentry.classifier(move |key| {
            prefixes.iter().find(|(p, _)| key.starts_with(p.as_str())).map_or(default, |(_, f)| *f)
        });
    }
    Ok(Ok(sentry))
}

fn exporter(
    kind: &str,
    options: &Json,
    out: Buffer,
    err: Buffer,
) -> Result<Result<Box<dyn Exporter>, utopia_span::Error>, Fault> {
    let o = Args(options);
    let frames = o.opt_i64("max_trace_frames")?.map(|n| usize::try_from(n).map_err(|_| Fault::new("negative frames")));
    let sampler = o.opt("sampler").map(sampler).transpose()?;
    Ok(Ok(match kind {
        "none" => Box::new(exporter::None),
        "stdout" => {
            let mut e = Stdout::new().writers(Box::new(out), Box::new(err));
            if let Some(f) = frames {
                e = e.max_trace_frames(f?);
            }
            if let Some(s) = sampler {
                e = e.sampler(s);
            }
            Box::new(e)
        }
        "pretty" => {
            let mut e = Pretty::new().writers(Box::new(out), Box::new(err));
            if let Some(f) = frames {
                e = e.max_trace_frames(f?);
            }
            if let Some(w) = o.opt_i64("width")? {
                e = e.width(usize::try_from(w).map_err(|_| Fault::new("negative width"))?);
            }
            if let Some(s) = sampler {
                e = e.sampler(s);
            }
            Box::new(e)
        }
        "sentry" => match sentry(options, |_r: Request| {})? {
            Ok(s) => Box::new(s),
            Err(e) => return Ok(Err(e)),
        },
        _ => return Err(Fault::new(format!("unknown exporter {kind}"))),
    }))
}

fn span_arg(session: &Session, args: &Args<'_>, key: &str) -> Result<Span, Fault> {
    session.get::<Span>(args.value(key)?).cloned()
}

fn err(e: utopia_span::Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn level(name: &str) -> Result<Level, Outcome> {
    Level::parse(name.as_bytes()).ok_or_else(|| {
        Outcome::err("ValueError", format!("\"{name}\" is not a valid backing value for enum Utopia\\Span\\Level"))
    })
}

pub async fn call(op: &str, args: &Value_, session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(match op {
        "span.reset" => {
            Span::set_exporters(Vec::new());
            Span::set_storage(Some(Arc::new(Memory::new())));
            Outcome::Ok(Json::Null)
        }
        "span.set_storage" => {
            Span::set_storage(a.opt_str("kind")?.map(storage).transpose()?);
            Outcome::Ok(Json::Null)
        }
        "span.set_exporters" => {
            let mut exporters: Vec<Arc<dyn Exporter>> = Vec::new();
            for h in a.opt("exporters").and_then(Json::as_array).into_iter().flatten() {
                exporters.push(session.get::<Arc<Recorder>>(h)?.clone());
            }
            Span::set_exporters(exporters);
            Outcome::Ok(Json::Null)
        }
        "span.recorder" => {
            let s = match a.opt("sampler") {
                Some(s) => sampler(s)?,
                None => Box::new(|_: &Span| true),
            };
            Outcome::Ok(session.handle(Arc::new(Recorder { sampler: s, exported: Mutex::new(Vec::new()) })))
        }
        "span.recorded" => {
            let recorder = session.get::<Arc<Recorder>>(a.value("recorder")?)?;
            let exported = recorder.exported.lock().unwrap();
            Outcome::Ok(Json::Array(
                exported
                    .iter()
                    .map(|s| {
                        json!({
                            "action": s.action(),
                            "name": json(&s.get("name")),
                            "error": s.error().map(|e| bytes_value(e.message_bytes())),
                        })
                    })
                    .collect(),
            ))
        }
        "span.recorded_is" => {
            let span = span_arg(session, &a, "span")?;
            let recorder = session.get::<Arc<Recorder>>(a.value("recorder")?)?;
            let index = a.i64("index")? as usize;
            Outcome::ok(recorder.exported.lock().unwrap().get(index).is_some_and(|s| *s == span))
        }
        "span.init" => {
            let span = Span::init(a.str("action")?, a.opt_str("traceparent")?);
            Outcome::Ok(session.handle(span))
        }
        "span.current" => match Span::current() {
            None => Outcome::Ok(Json::Null),
            Some(current) => match a.opt("span") {
                Some(_) => Outcome::ok(current == span_arg(session, &a, "span")?),
                None => Outcome::ok(true),
            },
        },
        "span.add" => {
            Span::add(a.str("key")?, value(a.value("value")?)?);
            Outcome::Ok(Json::Null)
        }
        "span.current_traceparent" => Outcome::Ok(Span::current_traceparent().map_or(Json::Null, Json::String)),
        "span.new" => {
            let span = match a.opt_str("action")? {
                Some(action) => Span::new(action),
                None => Span::default(),
            };
            Outcome::Ok(session.handle(span))
        }
        "span.set" => {
            let span = span_arg(session, &a, "span")?;
            let same = std::ptr::eq(span.set(a.str("key")?, value(a.value("value")?)?), &span);
            Outcome::ok(same)
        }
        "span.get" => Outcome::Ok(json(&span_arg(session, &a, "span")?.get(a.str("key")?))),
        "span.describe" => {
            let v = span_arg(session, &a, "span")?.get(a.str("key")?);
            Outcome::Ok(match &v {
                Value::Str(s) => json!({
                    "type": "string",
                    "length": s.len(),
                    "hex": !s.is_empty() && s.as_bytes().iter().all(u8::is_ascii_hexdigit),
                }),
                Value::Null => json!({"type": "null"}),
                Value::Bool(_) => json!({"type": "bool"}),
                Value::Int(_) => json!({"type": "int"}),
                Value::Float(_) => json!({"type": "float"}),
            })
        }
        "span.attributes" => Outcome::Ok(span_arg(session, &a, "span")?.with_attributes(pairs)),
        "span.set_error" => {
            let span = span_arg(session, &a, "span")?;
            let same = std::ptr::eq(span.set_error(failure(a.value("error")?)?), &span);
            Outcome::ok(same)
        }
        "span.error" => Outcome::Ok(describe(span_arg(session, &a, "span")?.error().as_deref())),
        "span.action" => Outcome::ok(span_arg(session, &a, "span")?.action()),
        "span.traceparent" => Outcome::ok(span_arg(session, &a, "span")?.traceparent()),
        "span.finish" => {
            let span = span_arg(session, &a, "span")?;
            let level = match a.opt_str("level")? {
                Some(name) => match level(name) {
                    Ok(l) => Some(l),
                    Err(outcome) => return Ok(outcome),
                },
                None => None,
            };
            let error = a.opt("error").map(failure).transpose()?;
            span.finish_with(level, error);
            Outcome::Ok(Json::Null)
        }
        "span.sleep" => {
            std::thread::sleep(std::time::Duration::from_micros(a.i64("us")? as u64));
            Outcome::Ok(Json::Null)
        }
        "span.duration_in" => {
            let d = span_arg(session, &a, "span")?.get("span.duration");
            Outcome::ok(
                d.as_float().is_some_and(|d| d > a.f64("min").unwrap_or(0.0) && d < a.f64("max").unwrap_or(0.0)),
            )
        }
        "span.script" => {
            let span = Span::new(a.opt_str("action")?.unwrap_or("unknown"));
            let mut results = Vec::new();
            for call in a.array("calls")? {
                let c = Args(call);
                results.push(match c.str("op")? {
                    "set" => Json::Bool(std::ptr::eq(span.set(c.str("key")?, value(c.value("value")?)?), &span)),
                    "get" => json(&span.get(c.str("key")?)),
                    other => return Err(Fault::new(format!("unknown call {other}"))),
                });
            }
            let attributes: Vec<(Box<str>, Value)> = span
                .attributes()
                .into_iter()
                .filter(|(k, _)| !matches!(&**k, "span.trace_id" | "span.id" | "span.started_at"))
                .collect();
            Outcome::Ok(json!({"results": results, "attributes": pairs(&attributes)}))
        }
        "level.parse" => Outcome::Ok(Level::parse(a.str("name")?.as_bytes()).map_or(Json::Null, |l| json!(l.as_str()))),
        "sentry.level" => match level(a.str("level")?) {
            Ok(l) => Outcome::ok(SentryLevel::from_span(l).as_str()),
            Err(outcome) => outcome,
        },
        "storage.new" => Outcome::Ok(session.handle(storage(a.str("kind")?)?)),
        "storage.set" => {
            let span = match a.opt("span") {
                Some(_) => Some(span_arg(session, &a, "span")?),
                None => None,
            };
            session.get::<Arc<dyn Storage>>(a.value("storage")?)?.set(span);
            Outcome::Ok(Json::Null)
        }
        "storage.get" => {
            let current = session.get::<Arc<dyn Storage>>(a.value("storage")?)?.get();
            match current {
                None => Outcome::Ok(Json::Null),
                Some(current) => match a.opt("span") {
                    Some(_) => Outcome::ok(current == span_arg(session, &a, "span")?),
                    None => Outcome::ok(true),
                },
            }
        }
        "storage.coroutine" => {
            let storage = storage(a.str("kind")?)?;
            let span = Span::new("task");
            let inside = Task::sync_scope(|| {
                storage.set(Some(span.clone()));
                storage.get().is_some_and(|s| s == span)
            });
            let outside = storage.get().map(|s| s == span);
            Outcome::Ok(json!({"inside": inside, "outside": outside}))
        }
        "exporter.sample" => {
            let span = build(a.value("span")?)?;
            let options = a.opt("options").cloned().unwrap_or(Json::Object(Map::new()));
            match exporter(a.str("exporter")?, &options, Buffer::default(), Buffer::default())? {
                Ok(e) => Outcome::ok(e.sample(&span)),
                Err(e) => err(e),
            }
        }
        "exporter.export" => {
            let span = build(a.value("span")?)?;
            let (out, error) = (Buffer::default(), Buffer::default());
            let options = a.opt("options").cloned().unwrap_or(Json::Object(Map::new()));
            match exporter(a.str("exporter")?, &options, out.clone(), error.clone())? {
                Ok(e) => {
                    e.export(&span);
                    let out = out.0.lock().unwrap().clone();
                    let error = error.0.lock().unwrap().clone();
                    Outcome::Ok(json!({"stdout": bytes_value(&out), "stderr": bytes_value(&error)}))
                }
                Err(e) => err(e),
            }
        }
        "sentry.new" => match Sentry::new(a.str("dsn")?) {
            Ok(_) => Outcome::ok(true),
            Err(e) => err(e),
        },
        "sentry.export" => {
            let sent: Arc<Mutex<Option<Request>>> = Arc::default();
            let log = sent.clone();
            let exporter = match sentry(args, move |r: Request| *log.lock().unwrap() = Some(r))? {
                Ok(s) => s,
                Err(e) => return Ok(err(e)),
            };
            exporter.export(&build(a.value("span")?)?);
            let request = sent.lock().unwrap().take();
            Outcome::Ok(match request {
                None => Json::Null,
                Some(r) => {
                    let header = |name: &str| r.headers.iter().find(|(n, _)| *n == name).map(|(_, v)| v.clone());
                    let envelope: Vec<Json> = r
                        .body
                        .split(|&b| b == b'\n')
                        .map(|line| {
                            php_std::json::decode_value(line, php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE)
                                .unwrap_or(Json::Null)
                        })
                        .collect();
                    json!({
                        "method": r.method,
                        "uri": r.url,
                        "content_type": header("Content-Type"),
                        "auth": header("X-Sentry-Auth"),
                        "envelope": envelope,
                    })
                }
            })
        }
        _ => return Err(Fault::new(format!("span: unknown operation `{op}`"))),
    })
}

type Value_ = Json;
