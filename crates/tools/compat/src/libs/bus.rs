//! Compat adapter for `bus`: maps `tests/compat/bus/spec.json` operations onto `utopia-bus`.

use std::hint::black_box;
use std::sync::{Arc, Mutex};

use serde_json::{Map, Value, json};
use utopia_bus::{Bus, Event, Failure, Listener, Resource};
use utopia_span::{Memory, Span, Value as SpanValue};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &["bus.dispatch", "listener.inspect"];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("dispatch", "Dispatch an event to three listeners with one dependency each"),
    ("miss", "Dispatch an event no listener handles"),
];

/// The events of `Adapter.php`, named by their PHP classes.
struct Deployed;
impl Event for Deployed {
    fn name(&self) -> &str {
        "Tests\\Compat\\Bus\\Deployed"
    }
}
struct Cancelled;
impl Event for Cancelled {
    fn name(&self) -> &str {
        "Tests\\Compat\\Bus\\Cancelled"
    }
}
struct Created;
impl Event for Created {
    fn name(&self) -> &str {
        "Tests\\Compat\\Bus\\Created"
    }
}

type Calls = Arc<Mutex<Vec<Value>>>;

/// A resolved dependency as the PHP side passes it: a string.
fn dep(r: &Resource) -> Value {
    r.downcast_ref::<String>().map(|s| Value::String(s.clone())).unwrap_or(Value::Null)
}

/// The listener classes of `Adapter.php`: name and event types.
fn listener(spec: &Value, calls: &Calls) -> Result<Listener, Fault> {
    let a = Args(spec);
    let name = a.str("class")?;
    let mut listener = Listener::new(name);
    listener = match name {
        "clock" | "mailer" => listener.on::<Deployed>(),
        "refunds" => listener.on::<Cancelled>(),
        "audit" => listener.on::<Deployed>().on::<Cancelled>().on::<Created>(),
        "twice" => listener.on::<Created>().on::<Created>(),
        other => return Err(Fault::new(format!("unknown listener \"{other}\""))),
    };
    for i in a.opt("injections").and_then(Value::as_array).into_iter().flatten() {
        listener = listener.inject(i.as_str().unwrap_or_default());
    }
    if let Some(behaviour) = a.opt("behaviour").filter(|b| b.is_object()) {
        let calls = calls.clone();
        let name = name.to_owned();
        let throw = behaviour.get("throw").cloned();
        listener = listener.callback(move |event, deps| {
            calls.lock().unwrap_or_else(|e| e.into_inner()).push(json!([
                name,
                event.name(),
                deps.iter().map(dep).collect::<Vec<_>>()
            ]));
            match &throw {
                Some(t) => Err(Failure::new(t.get("message").and_then(Value::as_str).unwrap_or_default())
                    .with_code(t.get("code").and_then(Value::as_i64).unwrap_or(0))),
                None => Ok(()),
            }
        });
    }
    Ok(listener)
}

fn span_value(v: &SpanValue) -> Value {
    match v {
        SpanValue::Null => Value::Null,
        SpanValue::Bool(b) => Value::Bool(*b),
        SpanValue::Int(i) => Value::from(*i),
        SpanValue::Float(f) => crate::adapter::float_value(*f),
        SpanValue::Str(s) => crate::adapter::bytes_value(s.as_bytes()),
    }
}

fn dispatch(a: &Args<'_>) -> Result<Value, Fault> {
    Span::set_storage(Some(Arc::new(Memory::new())));
    let span = Span::init("compat", None);
    let calls: Calls = Arc::new(Mutex::new(Vec::new()));
    let mut bus = Bus::new();
    match a.opt("resolver") {
        None => {
            bus.set_resolver(|name| Ok(Arc::new(format!("{name}:resolved")) as Resource));
        }
        Some(Value::String(s)) if s == "suffix" => {
            bus.set_resolver(|name| Ok(Arc::new(format!("{name}:resolved")) as Resource));
        }
        Some(Value::String(s)) if s == "none" => {}
        Some(Value::Object(o)) if o.contains_key("fail") => {
            let fail: Vec<String> =
                o["fail"].as_array().into_iter().flatten().filter_map(Value::as_str).map(str::to_owned).collect();
            bus.set_resolver(move |name| {
                if fail.iter().any(|f| f == name) {
                    Err(Failure::new(format!("cannot resolve {name}")))
                } else {
                    Ok(Arc::new(name.to_owned()) as Resource)
                }
            });
        }
        Some(_) => return Err(Fault::new("unknown resolver")),
    }
    for spec in a.opt("listeners").and_then(Value::as_array).into_iter().flatten() {
        bus.subscribe(listener(spec, &calls)?);
    }
    let mut error = Value::Null;
    for event in a.opt("events").and_then(Value::as_array).into_iter().flatten() {
        let result = match event.as_str() {
            Some("deployed") => bus.dispatch(&Deployed),
            Some("cancelled") => bus.dispatch(&Cancelled),
            Some("created") => bus.dispatch(&Created),
            _ => return Err(Fault::new(format!("unknown event {event}"))),
        };
        if let Err(e) = result {
            error = json!({"class": e.php_class(), "message": e.to_string()});
            break;
        }
    }
    let mut attributes = Map::new();
    for (key, value) in span.attributes() {
        // listener.<name>.<attribute>, grouped by listener.
        let mut parts = key.splitn(3, '.');
        if let (Some("listener"), Some(name), Some(attribute)) = (parts.next(), parts.next(), parts.next()) {
            let group = attributes.entry(name.to_owned()).or_insert_with(|| Value::Object(Map::new()));
            if let Value::Object(group) = group {
                group.insert(attribute.to_owned(), span_value(&value));
            }
        }
    }
    Span::set_storage(None);
    let calls = calls.lock().unwrap_or_else(|e| e.into_inner()).clone();
    // An empty PHP array encodes as a list.
    let span = if attributes.is_empty() { Value::Array(Vec::new()) } else { Value::Object(attributes) };
    Ok(json!({"calls": calls, "error": error, "span": span}))
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "dispatch" => {
            let mut bus = Bus::new();
            bus.set_resolver(|name| Ok(Arc::new(name.to_owned()) as Resource));
            for _ in 0..3 {
                bus.subscribe(Listener::new("bench").on::<Deployed>().inject("db").callback(|_, deps| {
                    black_box(&deps[0]);
                    Ok(())
                }));
            }
            Bench::sync(move || {
                black_box(bus.dispatch(black_box(&Deployed)).is_ok());
            })
        }
        "miss" => {
            let mut bus = Bus::new();
            bus.set_resolver(|name| Ok(Arc::new(name.to_owned()) as Resource));
            bus.subscribe(Listener::new("clock").on::<Deployed>().callback(|_, _| Ok(())));
            Bench::sync(move || {
                black_box(bus.dispatch(black_box(&Created)).is_ok());
            })
        }
        _ => return Err(Fault::new(format!("bus: no benchmark `{name}`"))),
    })
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(match op {
        "bus.dispatch" => Outcome::Ok(dispatch(&a)?),
        "listener.inspect" => {
            let calls: Calls = Arc::new(Mutex::new(Vec::new()));
            let l = listener(args, &calls)?;
            let callback = match l.get_callback() {
                Ok(_) => Value::Bool(true),
                Err(e) => json!({"$error": {"class": e.php_class(), "message": e.to_string()}}),
            };
            Outcome::Ok(json!({"injections": l.injections(), "callback": callback}))
        }
        _ => return Err(Fault::new(format!("bus: unknown operation `{op}`"))),
    })
}
