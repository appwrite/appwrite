//! Compat adapter for `di`: maps `tests/compat/di/spec.json` operations onto `utopia-di`.

use std::hint::black_box;
use std::sync::Arc;
use std::sync::atomic::{AtomicI64, Ordering};

use serde_json::{Value, json};
use utopia_di::{Container, Dependencies, Error};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &["di.new", "di.set", "di.get", "di.has", "di.script"];

/// Ids of the containers a script works on, in dependency order.
const IDS: &[&str] = &["a", "b", "c", "d", "e"];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("get", "Resolve a built dependency"),
    ("parent", "Resolve a dependency built by the parent container"),
    ("build", "Build a dependency from two resolved dependencies"),
];

/// What `new \stdClass()` stands for in the benchmarks.
struct Connection;

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "get" => {
            let container = Container::new();
            container.set("db", &[], |_| Ok(Connection));
            let _ = container.get::<Connection>("db");
            Bench::sync(move || {
                black_box(container.get::<Connection>(black_box("db")).is_ok());
            })
        }
        "parent" => {
            let parent = Arc::new(Container::new());
            parent.set("db", &[], |_| Ok(Connection));
            let child = Container::with_parent(parent);
            let _ = child.get::<Connection>("db");
            Bench::sync(move || {
                black_box(child.get::<Connection>(black_box("db")).is_ok());
            })
        }
        "build" => {
            let container = Container::new();
            container.set("host", &[], |_| Ok("db.internal"));
            container.set("port", &[], |_| Ok(5432_i64));
            let _ = container.get_any("host");
            let _ = container.get_any("port");
            Bench::sync(move || {
                container.set("dsn", &["host", "port"], |deps| Ok((*deps.get::<&str>(0)?, *deps.get::<i64>(1)?)));
                black_box(container.get::<(&str, i64)>("dsn").is_ok());
            })
        }
        _ => return Err(Fault::new(format!("di: no benchmark `{name}`"))),
    })
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

/// PHP's `(string)` of a JSON dependency value.
fn text(v: &Value) -> String {
    match v {
        Value::String(s) => s.clone(),
        Value::Null => String::new(),
        Value::Bool(b) => if *b { "1" } else { "" }.to_owned(),
        other => other.to_string(),
    }
}

fn values(deps: &Dependencies<'_>) -> Result<Vec<Value>, Error> {
    (0..deps.len()).map(|i| deps.get::<Value>(i).cloned()).collect()
}

fn set(container: &Container, id: &str, factory: &Value, dependencies: &[&str]) -> Result<(), Fault> {
    let kind = factory.get("kind").and_then(Value::as_str).ok_or_else(|| Fault::new("factory needs a kind"))?;
    let field = |k: &str| factory.get(k).cloned().unwrap_or(Value::Null);
    let calls = AtomicI64::new(0);
    match kind {
        "value" => {
            let value = field("value");
            container.set(id, dependencies, move |_| Ok(value.clone()));
        }
        "counter" => {
            container.set(id, dependencies, move |_| Ok(json!(calls.fetch_add(1, Ordering::Relaxed) + 1)));
        }
        "format" => {
            let template = field("template").as_str().map(str::to_owned).ok_or_else(|| Fault::new("template"))?;
            container.set(id, dependencies, move |deps| {
                let mut out = template.clone();
                for (i, v) in values(deps)?.iter().enumerate() {
                    out = out.replace(&format!("{{{i}}}"), &text(v));
                }
                Ok(Value::String(out))
            });
        }
        "list" => {
            container.set(id, dependencies, |deps| Ok(Value::Array(values(deps)?)));
        }
        "sum" => {
            container
                .set(id, dependencies, |deps| Ok(json!(values(deps)?.iter().filter_map(Value::as_i64).sum::<i64>())));
        }
        "fail" => {
            let message = text(&field("message"));
            container.set(id, dependencies, move |_| Err::<Value, _>(Error::Container(message.clone())));
        }
        "trace" => {
            let id_owned = id.to_owned();
            container.set(id, dependencies, move |deps| {
                let parts: Vec<String> = values(deps)?.iter().map(text).collect();
                let n = calls.fetch_add(1, Ordering::Relaxed) + 1;
                Ok(Value::String(format!("{id_owned}#{n}({})", parts.join(","))))
            });
        }
        _ => return Err(Fault::new(format!("unknown factory {kind}"))),
    }
    Ok(())
}

fn get(container: &Container, id: &str) -> Outcome {
    match container.get::<Value>(id) {
        Ok(v) => Outcome::Ok((*v).clone()),
        Err(e) => err(e),
    }
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let container =
        |s: &Session, key: &str| -> Result<Arc<Container>, Fault> { s.get::<Arc<Container>>(a.value(key)?).cloned() };
    Ok(match op {
        "di.new" => {
            let c = match a.opt("parent") {
                Some(_) => Container::with_parent(container(session, "parent")?),
                None => Container::new(),
            };
            Outcome::Ok(session.handle(Arc::new(c)))
        }
        "di.set" => {
            let c = container(session, "container")?;
            let deps: Vec<&str> = match a.opt("dependencies") {
                Some(d) => {
                    d.as_array().ok_or_else(|| Fault::new("dependencies"))?.iter().filter_map(Value::as_str).collect()
                }
                None => Vec::new(),
            };
            set(&c, a.str("id")?, a.value("factory")?, &deps)?;
            Outcome::ok(true)
        }
        "di.get" => get(&*container(session, "container")?, a.str("id")?),
        "di.has" => Outcome::ok(container(session, "container")?.has(a.str("id")?)),
        "di.script" => {
            let root = Arc::new(Container::new());
            let child = Arc::new(Container::with_parent(root.clone()));
            let containers = [
                root.clone(),
                child.clone(),
                Arc::new(Container::with_parent(child)),
                Arc::new(Container::with_parent(root)),
            ];
            let mut results = Vec::new();
            for call in a.array("calls")? {
                let c = Args(call);
                let index = usize::try_from(c.i64("container")?).map_err(|_| Fault::new("container"))?;
                let target = containers.get(index).ok_or_else(|| Fault::new("no such container"))?;
                let id = c.str("id")?;
                results.push(match c.str("op")? {
                    "set" => {
                        let at = IDS
                            .iter()
                            .position(|i| *i == id)
                            .ok_or_else(|| Fault::new(format!("scripted ids are {}", IDS.join(", "))))?;
                        let deps: Vec<&str> = match c.opt("dependencies") {
                            Some(d) => d
                                .as_array()
                                .ok_or_else(|| Fault::new("dependencies"))?
                                .iter()
                                .map(|o| {
                                    o.as_u64().and_then(|o| IDS.get(at + o as usize).copied()).unwrap_or("missing")
                                })
                                .collect(),
                            None => Vec::new(),
                        };
                        set(target, id, &json!({"kind": "trace"}), &deps)?;
                        Value::Bool(true)
                    }
                    "get" => get(target, id).to_json(),
                    "has" => Value::Bool(target.has(id)),
                    other => return Err(Fault::new(format!("unknown call {other}"))),
                });
            }
            Outcome::Ok(Value::Array(results))
        }
        _ => return Err(Fault::new(format!("di: unknown operation `{op}`"))),
    })
}
