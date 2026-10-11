//! Compat adapter for `registry`: maps `tests/compat/registry/spec.json` operations onto `utopia-registry`.

use std::hint::black_box;
use std::sync::atomic::{AtomicI64, Ordering};

use serde_json::{Value, json};
use utopia_registry::{Error, Registry};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "registry.new",
    "registry.set",
    "registry.get",
    "registry.push",
    "registry.has",
    "registry.context",
    "registry.script",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] =
    &[("get", "Read a cached resource"), ("get_fresh", "Build a new instance of a resource on read")];

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    let mut registry = Registry::new();
    Ok(match name {
        "get" => {
            registry.set("db", || vec![String::from("connection")]);
            let _ = registry.get::<Vec<String>>("db");
            Bench::sync(move || {
                black_box(registry.get::<Vec<String>>(black_box("db")).is_ok());
            })
        }
        "get_fresh" => {
            registry.set_fresh("db", || vec![String::from("connection")]);
            Bench::sync(move || {
                black_box(registry.get::<Vec<String>>(black_box("db")).is_ok());
            })
        }
        _ => return Err(Fault::new(format!("registry: no benchmark `{name}`"))),
    })
}

/// What the adapter's factories return: a value, or a mutable list (PHP's
/// `ArrayObject`).
enum Item {
    Value(Value),
    List(Vec<Value>),
}

impl Item {
    fn to_json(&self) -> Value {
        match self {
            Item::Value(v) => v.clone(),
            Item::List(items) => Value::Array(items.clone()),
        }
    }
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn set(registry: &mut Registry, name: &str, factory: &Value, fresh: bool) -> Result<(), Fault> {
    let kind = factory.get("kind").and_then(Value::as_str).ok_or_else(|| Fault::new("factory needs a kind"))?;
    let value = factory.get("value").cloned().unwrap_or(Value::Null);
    let make: Box<dyn Fn() -> Item + Send + Sync> = match kind {
        "counter" => {
            let calls = AtomicI64::new(0);
            Box::new(move || Item::Value(json!(calls.fetch_add(1, Ordering::Relaxed) + 1)))
        }
        "value" => Box::new(move || Item::Value(value.clone())),
        "list" => {
            let items = value.as_array().cloned().ok_or_else(|| Fault::new("a list factory needs a list"))?;
            Box::new(move || Item::List(items.clone()))
        }
        _ => return Err(Fault::new(format!("unknown factory {kind}"))),
    };
    if fresh {
        registry.set_fresh(name, make);
    } else {
        registry.set(name, make);
    }
    Ok(())
}

fn get<'r>(registry: &'r mut Registry, name: &str, fresh: bool) -> Result<&'r mut Item, Error> {
    if fresh { registry.get_fresh::<Item>(name) } else { registry.get::<Item>(name) }
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    if op == "registry.new" {
        return Ok(Outcome::Ok(session.handle(Registry::new())));
    }
    if op == "registry.script" {
        let mut registry = Registry::new();
        let mut results = Vec::new();
        for call in a.array("calls")? {
            let c = Args(call);
            let name = c.str("name")?;
            results.push(match c.str("op")? {
                "set" | "set_fresh" => {
                    set(&mut registry, name, &json!({"kind": "counter"}), c.str("op")? == "set_fresh")?;
                    Value::Null
                }
                "get" | "get_fresh" => match get(&mut registry, name, c.str("op")? == "get_fresh") {
                    Ok(item) => item.to_json(),
                    Err(e) => err(e).to_json(),
                },
                "has" => Value::Bool(registry.has(name)),
                "context" => {
                    registry.context(name);
                    Value::Null
                }
                other => return Err(Fault::new(format!("unknown call {other}"))),
            });
        }
        return Ok(Outcome::Ok(Value::Array(results)));
    }
    let registry = session.get_mut::<Registry>(a.value("registry")?)?;
    let name = a.str("name")?;
    Ok(match op {
        "registry.set" => {
            set(registry, name, a.value("factory")?, a.opt_bool("fresh")?.unwrap_or(false))?;
            Outcome::Ok(Value::Null)
        }
        "registry.get" => match get(registry, name, a.opt_bool("fresh")?.unwrap_or(false)) {
            Ok(item) => Outcome::Ok(item.to_json()),
            Err(e) => err(e),
        },
        "registry.push" => match get(registry, name, false) {
            Ok(Item::List(items)) => {
                items.push(a.value("value")?.clone());
                Outcome::Ok(Value::Null)
            }
            Ok(Item::Value(_)) => return Err(Fault::new("not a list resource")),
            Err(e) => err(e),
        },
        "registry.has" => Outcome::ok(registry.has(name)),
        "registry.context" => {
            registry.context(name);
            Outcome::Ok(Value::Null)
        }
        _ => return Err(Fault::new(format!("registry: unknown operation `{op}`"))),
    })
}
