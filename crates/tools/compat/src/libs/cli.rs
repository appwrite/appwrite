//! Compat adapter for `cli`: maps `tests/compat/cli/spec.json` operations onto `utopia-cli`.

use std::hint::black_box;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex};

use serde_json::{Map, Value, json};
use utopia_cli::{Adapter, Argument, Arguments, Cli, Error, Generic, Hook, Task};
use utopia_di::Container;
use utopia_servers::{Check, Param};
use utopia_validators::{Boolean, Text};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &["cli.run", "task.run", "generic.run"];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("parse", "Parse a command line with five arguments, one repeated"),
    ("run", "Run a task: resolve and validate two params and an injected resource, then call it"),
];

/// What callbacks were called with, in order.
type Calls = Arc<Mutex<Vec<Value>>>;

fn error(e: &Error) -> Value {
    json!({"$error": {"class": e.php_class(), "message": e.to_string(), "code": e.code()}})
}

/// A resource as JSON: a value, or an error as `{"$exception": {...}}`.
fn resource(r: &utopia_servers::Resource) -> Value {
    if let Some(v) = r.downcast_ref::<Value>() {
        return v.clone();
    }
    if let Some(e) = r.downcast_ref::<Arc<Error>>() {
        return json!({"$exception": error(e)["$error"]});
    }
    Value::Null
}

fn map_or_list(map: Map<String, Value>) -> Value {
    if map.is_empty() { Value::Array(Vec::new()) } else { Value::Object(map) }
}

fn strings(v: Option<&Value>) -> Vec<String> {
    v.and_then(Value::as_array).into_iter().flatten().map(|s| s.as_str().unwrap_or_default().to_owned()).collect()
}

/// Declares a hook's description, groups, labels, params and injections (as
/// Adapter.php's `declare()`).
fn declare(hook: &mut Hook, spec: &Value) -> Result<Result<(), Error>, Fault> {
    let s = Args(spec);
    if let Some(d) = s.opt_str("desc")? {
        hook.desc(d);
    }
    if let Some(groups) = s.opt("groups") {
        let g = strings(Some(groups));
        hook.groups(&g.iter().map(String::as_str).collect::<Vec<_>>());
    }
    for (k, v) in s.opt("labels").and_then(Value::as_object).into_iter().flatten() {
        hook.label(k, v.clone());
    }
    for p in s.opt("params").and_then(Value::as_array).into_iter().flatten() {
        let pa = Args(p);
        let vspec = p.get("validator").cloned().unwrap_or_else(|| json!({"any": true}));
        let check = match vspec.get("factory") {
            Some(inner) => {
                super::http::validator(inner)?;
                let inner = inner.clone();
                Check::Factory(Arc::new(move |_| {
                    Arc::from(super::http::validator(&inner).unwrap_or_else(|_| Box::new(Text::new(0))))
                }))
            }
            None => Check::Validator(Arc::from(super::http::validator(&vspec)?)),
        };
        let injections = strings(p.get("injections"));
        let aliases = strings(p.get("aliases"));
        let param = Param::new(
            p.get("default").cloned().unwrap_or(Value::Null),
            check,
            pa.opt_str("description")?.unwrap_or(""),
        )
        .optional(pa.opt_bool("optional")?.unwrap_or(false))
        .injections(&injections.iter().map(String::as_str).collect::<Vec<_>>())
        .skip_validation(pa.opt_bool("skip_validation")?.unwrap_or(false))
        .aliases(&aliases.iter().map(String::as_str).collect::<Vec<_>>());
        hook.param(pa.str("key")?, param);
    }
    for name in strings(s.opt("inject")) {
        if let Err(e) = hook.try_inject(&name) {
            return Ok(Err(e));
        }
    }
    Ok(Ok(()))
}

/// Configures a hook: a callback recording `[id, its arguments]` that
/// fails with `fail` ({message, code}) when given, then [`declare`].
fn hook(hook: &mut Hook, spec: &Value, calls: &Calls) -> Result<Result<(), Error>, Fault> {
    let id = spec.get("id").cloned().unwrap_or_else(|| json!(""));
    let fail = spec.get("fail").cloned();
    let calls = calls.clone();
    hook.action(move |args: Arguments| {
        let mut map = Map::new();
        for (name, argument) in args.iter() {
            map.insert(
                name.to_owned(),
                match argument {
                    Argument::Value(v) => v.clone(),
                    Argument::Resource(r) => resource(r),
                },
            );
        }
        calls.lock().unwrap_or_else(|e| e.into_inner()).push(json!([id, map_or_list(map)]));
        let failure = fail
            .as_ref()
            .map(|f| Error::exception(f["message"].as_str().unwrap_or_default(), f["code"].as_i64().unwrap_or(0)));
        async move { failure.map_or(Ok(()), Err) }
    });
    declare(hook, spec)
}

/// A resource factory (as Adapter.php's `factory()`).
fn set_resource(cli: &Cli, r: &Value, built: &Arc<AtomicI64>) {
    let name = r["name"].as_str().unwrap_or_default();
    let deps = strings(r.get("dependencies"));
    let deps: Vec<&str> = deps.iter().map(String::as_str).collect();
    let value = r.get("value").cloned().unwrap_or(Value::Null);
    if r.get("counter").and_then(Value::as_bool).unwrap_or(false) {
        let built = built.clone();
        cli.set_resource(name, &deps, move |_| Ok(json!(built.fetch_add(1, Ordering::SeqCst) + 1)));
    } else if !deps.is_empty() {
        let n = deps.len();
        cli.set_resource(name, &deps, move |d| {
            let mut list = vec![value.clone()];
            for i in 0..n {
                list.push(match d.get::<Value>(i) {
                    Ok(v) => (*v).clone(),
                    Err(_) => {
                        d.get::<Arc<Error>>(i).map(|e| json!({"$exception": error(e)["$error"]})).unwrap_or(Value::Null)
                    }
                });
            }
            Ok(Value::Array(list))
        });
    } else {
        cli.set_resource(name, &deps, move |_| Ok(value.clone()));
    }
}

fn container(values: &Value) -> Arc<Container> {
    let container = Arc::new(Container::new());
    for (name, value) in values.as_object().into_iter().flatten() {
        let value = value.clone();
        container.set(name, &[], move |_| Ok(value.clone()));
    }
    container
}

async fn cli(a: &Args<'_>) -> Result<Value, Fault> {
    let calls: Calls = Arc::new(Mutex::new(Vec::new()));
    let built = Arc::new(AtomicI64::new(0));
    let argv = match a.opt_str("command")? {
        Some(command) => {
            ["app".to_owned(), command.to_owned()].into_iter().chain(strings(a.opt("arguments"))).collect()
        }
        None => strings(a.opt("argv")),
    };
    let parent = a.opt("container").map(container);
    let mut cli = match Cli::with_container(Arc::new(Generic), &argv, parent) {
        Ok(cli) => cli,
        Err(e) => return Ok(json!([error(&e)])),
    };
    let mut results = Vec::new();
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let (op, x) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        let result: Result<Value, Error> = match op.as_str() {
            "task" => {
                let name = x["name"].as_str().unwrap_or_default();
                hook(cli.task(name), x, &calls)?.map(|()| json!(true))
            }
            "bare_task" => Ok(json!(cli.task(x.as_str().unwrap_or_default()).name())),
            "init" => hook(cli.init(), x, &calls)?.map(|()| json!(true)),
            "shutdown" => hook(cli.shutdown(), x, &calls)?.map(|()| json!(true)),
            "error" => hook(cli.error(), x, &calls)?.map(|()| json!(true)),
            "set_resource" => {
                set_resource(&cli, x, &built);
                Ok(Value::Null)
            }
            "resource" => cli.resource(x.as_str().unwrap_or_default()).map(|r| resource(&r)),
            "resources" => {
                let names = strings(Some(x));
                cli.resources(&names.iter().map(String::as_str).collect::<Vec<_>>())
                    .map(|m| map_or_list(m.iter().map(|(k, r)| (k.clone(), resource(r))).collect()))
            }
            "reset" => {
                cli.reset();
                Ok(Value::Null)
            }
            "set_container" => {
                cli.set_container(container(x));
                Ok(json!(true))
            }
            "has" => Ok(json!(cli.container().has(x.as_str().unwrap_or_default()))),
            "tasks" => Ok(Value::Array(cli.tasks().values().map(|t| json!([t.name(), t.description()])).collect())),
            "task_keys" => Ok(json!(cli.tasks().keys().collect::<Vec<_>>())),
            "args" => Ok(map_or_list(cli.args().iter().map(|(k, v)| (k.clone(), v.clone())).collect())),
            "match" => Ok(cli.matched().map(|t| json!(t.name())).unwrap_or(Value::Null)),
            "parse" => {
                let argv = strings(Some(x));
                cli.parse(&argv).map(|args| map_or_list(args.into_iter().collect()))
            }
            "run" => {
                calls.lock().unwrap_or_else(|e| e.into_inner()).clear();
                let error = match cli.run().await {
                    Ok(()) => Value::Null,
                    Err(e) => error(&e)["$error"].clone(),
                };
                let calls = std::mem::take(&mut *calls.lock().unwrap_or_else(|e| e.into_inner()));
                Ok(json!({"calls": calls, "error": error}))
            }
            other => return Err(Fault::new(format!("unknown step {other}"))),
        };
        results.push(result.unwrap_or_else(|e| error(&e)));
    }
    Ok(Value::Array(results))
}

async fn task(a: &Args<'_>) -> Result<Value, Fault> {
    let mut task = Task::new(a.opt_str("name")?.unwrap_or(""));
    let mut results = Vec::new();
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let (op, x) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        let text = |v: &Value| v.as_str().unwrap_or_default().to_owned();
        let result: Result<Value, Error> = match op.as_str() {
            "desc" => {
                task.desc(&text(x));
                Ok(json!(true))
            }
            "label" => {
                task.label(&text(&x["key"]), x["value"].clone());
                Ok(json!(true))
            }
            "param" => declare(&mut task, &json!({"params": [x]}))?.map(|()| json!(true)),
            "inject" => task.try_inject(&text(x)).map(|_| json!(true)),
            "action" => {
                task.action(|_| async { Ok(()) });
                Ok(json!(true))
            }
            "get" => Ok(match x.as_str() {
                Some("name") => json!(task.name()),
                Some("desc") => json!(task.description()),
                Some("params") => json!(task.params().keys().collect::<Vec<_>>()),
                Some("dependencies") => json!(task.dependencies()),
                Some("action") => match (task.get_action())(Arguments::new()).await {
                    Ok(()) => json!(true),
                    Err(e) => error(&e),
                },
                _ => return Err(Fault::new(format!("unknown getter {x}"))),
            }),
            "label_of" => Ok(task
                .get_label(&text(&x["key"]))
                .cloned()
                .unwrap_or_else(|| x.get("default").cloned().unwrap_or(Value::Null))),
            other => return Err(Fault::new(format!("unknown step {other}"))),
        };
        results.push(result.unwrap_or_else(|e| error(&e)));
    }
    Ok(Value::Array(results))
}

async fn generic(a: &Args<'_>) -> Result<Value, Fault> {
    let adapter = Generic::new();
    let calls: Calls = Arc::new(Mutex::new(Vec::new()));
    let record = |name: &str| calls.lock().unwrap_or_else(|e| e.into_inner()).push(json!([name, []]));
    let mut results = Vec::new();
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        results.push(match step.as_str() {
            Some("workers") => json!(adapter.workers()),
            Some("start") => {
                // Generic calls the work without arguments; the worker number
                // Rust passes is 0.
                adapter
                    .start(&|_worker| {
                        record("start");
                        Box::pin(async {})
                    })
                    .await;
                json!(true)
            }
            Some("stop") => {
                adapter.stop();
                json!(true)
            }
            Some("on_worker_start") => {
                let calls = calls.clone();
                adapter.on_worker_start(Arc::new(move |_| {
                    calls.lock().unwrap_or_else(|e| e.into_inner()).push(json!(["worker_start", []]));
                }));
                json!(true)
            }
            Some("on_worker_stop") => {
                let calls = calls.clone();
                adapter.on_worker_stop(Arc::new(move |_| {
                    calls.lock().unwrap_or_else(|e| e.into_inner()).push(json!(["worker_stop", []]));
                }));
                json!(true)
            }
            Some("on_job") => {
                adapter.on_job(&mut || record("job"));
                json!(true)
            }
            _ => return Err(Fault::new(format!("unknown step {step}"))),
        });
    }
    let calls = calls.lock().unwrap_or_else(|e| e.into_inner()).clone();
    Ok(json!({"results": results, "calls": calls}))
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let value = match op {
        "cli.run" => cli(&a).await?,
        "task.run" => task(&a).await?,
        "generic.run" => generic(&a).await?,
        _ => return Err(Fault::new(format!("unknown op {op}"))),
    };
    Ok(Outcome::ok(value))
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "parse" => Bench::sync(|| {
            let cli = Cli::new(&[
                "app",
                "migrate",
                "--project=console",
                "--dry-run=false",
                "--limit=100",
                "--only=users",
                "--only=teams",
            ]);
            black_box(cli.ok());
        }),
        "run" => {
            let mut cli = Cli::new(&["app", "build", "--email=me@example.com", "--commit=false"])
                .map_err(|e| Fault::new(e.to_string()))?;
            cli.set_resource("db", &[], |_| Ok(String::from("primary")));
            cli.task("build")
                .param("email", Param::new(Value::Null, Text::new(64), "Email."))
                .param("commit", Param::new(json!(false), Boolean { loose: true }, "Commit changes.").optional(true))
                .inject("db")
                .action(|args| {
                    black_box(&args);
                    async { Ok(()) }
                });
            let cli = Arc::new(cli);
            Bench::future(move || {
                let cli = cli.clone();
                async move {
                    black_box(cli.run().await.ok());
                }
            })
        }
        _ => return Err(Fault::new(format!("unknown benchmark {name}"))),
    })
}
