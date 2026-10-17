//! Compat adapter for `platform`: maps `tests/compat/platform/spec.json` operations onto `utopia-platform`.

use std::hint::black_box;
use std::sync::{Arc, Mutex};

use serde_json::{Map, Value, json};
use utopia_cli::Cli;
use utopia_platform::{
    Action, ActionType, Callback, Declaration, Enum, Init, JobConfig, Module, Param, Platform, Service, ServiceType,
    WorkerParams,
};
use utopia_queue::adapter::{Adapter as QueueAdapter, ConsumerFactory, Runtime};
use utopia_queue::connection::BoxFuture;
use utopia_queue::{Consumer, Message, Queue, Server};
use utopia_servers::{Argument, Arguments, Check, ParamDefault, Resource};
use utopia_validators::Text;

use super::http::Out;
use crate::adapter::{Args, Fault, OpResult, Outcome, Session};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &["action.run", "service.run", "platform.run"];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("declare", "Declare an action: method, path, description, groups, a label, three params and two injections"),
    ("init", "Register a service of ten HTTP actions with two params and an injection each"),
];

/// What task and worker callbacks were called with, in order.
type Calls = Arc<Mutex<Vec<Value>>>;

/// A consumer worker initialization never receives from.
struct FakeConsumer;

impl Consumer for FakeConsumer {
    fn receive<'a>(&'a self, _: &'a Queue, _: i64, _: i64) -> BoxFuture<'a, utopia_queue::Result<Vec<Message>>> {
        Box::pin(async { Ok(Vec::new()) })
    }
    fn commit<'a>(&'a self, _: &'a Queue, _: &'a Message) -> BoxFuture<'a, utopia_queue::Result<()>> {
        Box::pin(async { Ok(()) })
    }
    fn reject<'a>(&'a self, _: &'a Queue, _: &'a Message) -> BoxFuture<'a, utopia_queue::Result<()>> {
        Box::pin(async { Ok(()) })
    }
    fn close(&self) -> BoxFuture<'_, ()> {
        Box::pin(async {})
    }
}

fn fake_consumers() -> ConsumerFactory {
    Arc::new(|_| Arc::new(FakeConsumer) as Arc<dyn Consumer>)
}

struct Ctx {
    out: Out,
    calls: Calls,
}

fn error_of(class: &str, message: String, code: i64) -> Value {
    json!({"$error": {"class": class, "message": message, "code": code}})
}

fn error(e: &utopia_platform::Error) -> Value {
    let code = match e {
        utopia_platform::Error::Http(e) => e.code(),
        utopia_platform::Error::Servers(e) => e.code(),
        _ => 0,
    };
    error_of(e.php_class(), e.to_string(), code)
}

fn servers_error(e: &utopia_servers::Error) -> Value {
    error_of(e.php_class(), e.to_string(), e.code())
}

fn map_or_list(map: Map<String, Value>) -> Value {
    if map.is_empty() { Value::Array(Vec::new()) } else { Value::Object(map) }
}

fn strings(v: Option<&Value>) -> Vec<String> {
    v.and_then(Value::as_array).into_iter().flatten().map(|s| s.as_str().unwrap_or_default().to_owned()).collect()
}

fn refs(list: &[String]) -> Vec<&str> {
    list.iter().map(String::as_str).collect()
}

fn dep_values(deps: &[Resource]) -> Vec<Value> {
    deps.iter().map(|d| d.downcast_ref::<Value>().cloned().unwrap_or(Value::Null)).collect()
}

/// A param's default and validator: `default_fn` and `{factory: spec}`
/// record what they are called with in `out` (as Adapter.php's `rule()`).
fn rule(p: &Value, out: &Out) -> Result<(ParamDefault, Check), Fault> {
    let default = p.get("default").cloned().unwrap_or(Value::Null);
    let default = if p.get("default_fn").and_then(Value::as_bool).unwrap_or(false) {
        let out = out.clone();
        ParamDefault::Factory(Arc::new(move |deps| {
            out.lock().unwrap_or_else(|e| e.into_inner()).extend(dep_values(deps));
            default.clone()
        }))
    } else {
        ParamDefault::Value(default)
    };
    let spec = p.get("validator").cloned().unwrap_or_else(|| json!({"any": true}));
    let check = match spec.get("factory") {
        Some(inner) => {
            super::http::validator(inner)?;
            let inner = inner.clone();
            let out = out.clone();
            Check::Factory(Arc::new(move |deps| {
                out.lock().unwrap_or_else(|e| e.into_inner()).extend(dep_values(deps));
                Arc::from(super::http::validator(&inner).unwrap_or_else(|_| Box::new(Text::new(0))))
            }))
        }
        None => Check::Validator(Arc::from(super::http::validator(&spec)?)),
    };
    Ok((default, check))
}

fn param(p: &Value, out: &Out) -> Result<(String, Param), Fault> {
    let a = Args(p);
    let (default, check) = rule(p, out)?;
    let injections = strings(p.get("injections"));
    let aliases = strings(p.get("aliases"));
    let mut param = Param::new(default, check, a.opt_str("description")?.unwrap_or(""))
        .optional(a.opt_bool("optional")?.unwrap_or(false))
        .injections(&refs(&injections))
        .skip_validation(a.opt_bool("skip_validation")?.unwrap_or(false))
        .deprecated(a.opt_bool("deprecated")?.unwrap_or(false))
        .example(a.opt_str("example")?.unwrap_or(""))
        .aliases(&refs(&aliases));
    if let Some(e) = p.get("enum") {
        let string = |v: &Value| v.as_str().unwrap_or_default().to_owned();
        param = param.enumeration(Enum {
            name: e.get("name").and_then(Value::as_str).map(str::to_owned),
            map: e
                .get("map")
                .and_then(Value::as_object)
                .map(|m| m.iter().map(|(k, v)| (k.clone(), string(v))).collect()),
            exclude: e.get("exclude").and_then(Value::as_array).map(|l| l.iter().map(string).collect()),
        });
    }
    Ok((a.str("key")?.to_owned(), param))
}

/// Applies one declaration step to an action.
fn declare(
    action: &mut Action,
    op: &str,
    x: &Value,
    out: &Out,
) -> Result<Result<Value, utopia_platform::Error>, Fault> {
    let text = |v: &Value| v.as_str().unwrap_or_default().to_owned();
    Ok(Ok(match op {
        "set_type" => {
            action.set_type(ActionType::new(text(x)));
            json!(true)
        }
        "desc" => {
            action.desc(&text(x));
            json!(true)
        }
        "groups" => {
            action.groups(&refs(&strings(Some(x))));
            json!(true)
        }
        "label" => {
            action.label(&text(&x["key"]), x["value"].clone());
            json!(true)
        }
        "param" => {
            let (key, p) = param(x, out)?;
            action.param(&key, p);
            json!(true)
        }
        "inject" => match action.try_inject(&text(x)) {
            Ok(_) => json!(true),
            Err(e) => return Ok(Err(e)),
        },
        "set_http_path" => {
            action.set_http_path(&text(x));
            json!(true)
        }
        "set_http_method" => {
            action.set_http_method(&text(x));
            json!(true)
        }
        "set_http_methods" => {
            action.set_http_methods(&refs(&strings(Some(x))));
            json!(true)
        }
        "http_alias" => {
            action.http_alias(&text(x));
            json!(true)
        }
        other => return Err(Fault::new(format!("unknown step {other}"))),
    }))
}

/// A callback for a service of `kind`: HTTP runs Harness statements, a
/// worker records `[id, []]`, any other records `[id, its arguments]`.
fn callback(kind: &str, spec: &Value, ctx: &Ctx) -> Callback {
    let id = spec.get("id").cloned().unwrap_or_else(|| json!(""));
    match kind {
        "http" => Callback::Http(super::http::statement_action(
            spec.get("statements").and_then(Value::as_array).cloned().unwrap_or_default(),
            &ctx.out,
        )),
        "Worker" => {
            let calls = ctx.calls.clone();
            Callback::worker(move |_scope| {
                calls.lock().unwrap_or_else(|e| e.into_inner()).push(json!([id, []]));
                async {}
            })
        }
        _ => {
            let calls = ctx.calls.clone();
            let fail = spec.get("fail").cloned();
            Callback::task(move |args: Arguments| {
                let mut map = Map::new();
                for (name, argument) in args.iter() {
                    let value = match argument {
                        Argument::Value(v) => v.clone(),
                        Argument::Resource(r) => match r.downcast_ref::<Value>() {
                            Some(v) => v.clone(),
                            None => r
                                .downcast_ref::<Arc<utopia_servers::Error>>()
                                .map(|e| json!({"$exception": servers_error(e)["$error"]}))
                                .unwrap_or(Value::Null),
                        },
                    };
                    map.insert(name.to_owned(), value);
                }
                calls.lock().unwrap_or_else(|e| e.into_inner()).push(json!([id, map_or_list(map)]));
                let failure = fail.as_ref().map(|f| {
                    utopia_servers::Error::exception(
                        f["message"].as_str().unwrap_or_default(),
                        f["code"].as_i64().unwrap_or(0),
                    )
                });
                async move { failure.map_or(Ok(()), Err) }
            })
        }
    }
}

/// An action built from its description (as Adapter.php's `build()`).
fn build(kind: &str, spec: &Value, ctx: &Ctx) -> Result<Action, Fault> {
    let s = Args(spec);
    let mut action = Action::new();
    if let Some(t) = s.opt_str("type")? {
        action.set_type(ActionType::new(t.to_owned()));
    }
    if let Some(d) = s.opt_str("desc")? {
        action.desc(d);
    }
    if let Some(g) = s.opt("groups") {
        action.groups(&refs(&strings(Some(g))));
    }
    let http = s.opt("http").cloned().unwrap_or(Value::Null);
    if let Some(m) = http.get("method").and_then(Value::as_str) {
        action.set_http_method(m);
    }
    if let Some(m) = http.get("methods") {
        action.set_http_methods(&refs(&strings(Some(m))));
    }
    if let Some(p) = http.get("path").and_then(Value::as_str) {
        action.set_http_path(p);
    }
    for alias in strings(http.get("aliases")) {
        action.http_alias(&alias);
    }
    for p in s.opt("params").and_then(Value::as_array).into_iter().flatten() {
        let (key, p) = param(p, &ctx.out)?;
        action.param(&key, p);
    }
    for name in strings(s.opt("inject")) {
        action.try_inject(&name).map_err(|e| Fault::new(e.to_string()))?;
    }
    for (k, v) in s.opt("labels").and_then(Value::as_object).into_iter().flatten() {
        action.label(k, v.clone());
    }
    if let Some(cb) = s.opt("callback") {
        action.callback(callback(kind, cb, ctx));
    }
    Ok(action)
}

fn enumeration(e: Option<&Enum>) -> Value {
    match e {
        None => Value::Null,
        Some(e) => json!({
            "name": e.name,
            "map": e.map.as_ref().map(|m| map_or_list(m.iter().map(|(k, v)| (k.clone(), json!(v))).collect())),
            "exclude": e.exclude,
        }),
    }
}

fn default_json(d: &ParamDefault) -> Value {
    match d {
        ParamDefault::Value(v) => v.clone(),
        ParamDefault::Factory(_) => json!("factory"),
    }
}

fn check_json(c: &Check) -> Value {
    match c {
        Check::Validator(v) => json!(v.description()),
        Check::Factory(_) => json!("factory"),
    }
}

/// An action's param, as Adapter.php's `param()` describes it.
fn action_param(p: &Param) -> Value {
    json!({
        "default": default_json(&p.default),
        "validator": check_json(&p.validator),
        "description": p.description,
        "optional": p.optional,
        "injections": p.injections,
        "skipValidation": p.skip_validation,
        "deprecated": p.deprecated,
        "example": p.example,
        "aliases": p.aliases,
        "enum": enumeration(p.enumeration.as_deref()),
    })
}

/// A hook's param (with `model`), as Adapter.php's `param()` describes it.
fn hook_param(p: &utopia_servers::Param) -> Value {
    json!({
        "default": default_json(&p.default),
        "validator": check_json(&p.validator),
        "description": p.description,
        "optional": p.optional,
        "injections": p.injections,
        "skipValidation": p.skip_validation,
        "deprecated": p.deprecated,
        "example": p.example,
        "model": p.model,
        "aliases": p.aliases,
        "enum": enumeration(p.enumeration.as_ref().and_then(|e| e.downcast_ref::<Enum>())),
    })
}

fn route_param(p: &utopia_http::Param) -> Value {
    json!({
        "default": match &p.default { utopia_http::ParamDefault::Value(v) => v.clone(), utopia_http::ParamDefault::Factory(_) => json!("factory") },
        "validator": match &p.validator { utopia_http::Check::Validator(v) => json!(v.description()), utopia_http::Check::Factory(_) => json!("factory") },
        "description": p.description,
        "optional": p.optional,
        "injections": p.injections,
        "skipValidation": p.skip_validation,
        "deprecated": p.deprecated,
        "example": p.example,
        "model": p.model,
        "aliases": p.aliases,
        "enum": enumeration(p.enumeration.as_ref().and_then(|e| e.downcast_ref::<Enum>())),
    })
}

fn describe(action: &Action) -> Value {
    let params = map_or_list(action.params().iter().map(|(k, p)| (k.clone(), action_param(p))).collect());
    let options: Vec<Value> = action
        .options()
        .iter()
        .map(|(k, d)| {
            json!([
                k,
                match d {
                    Declaration::Param(_) => "param",
                    Declaration::Injection(_) => "injection",
                }
            ])
        })
        .collect();
    json!({
        "type": action.kind().as_str(),
        "desc": action.description(),
        "groups": action.get_groups(),
        "callback": action.get_callback().is_some(),
        "params": params,
        "injections": action.injections(),
        "labels": map_or_list(action.labels().iter().map(|(k, v)| (k.clone(), v.clone())).collect()),
        "options": options,
        "path": match action.try_http_path() {
            Ok(p) => json!(p),
            Err(e) => error(&e),
        },
        "method": action.http_method(),
        "methods": action.http_methods(),
        "aliases": action.http_aliases(),
    })
}

fn action(a: &Args<'_>) -> Result<Value, Fault> {
    let out: Out = Arc::new(Mutex::new(Vec::new()));
    let mut action = Action::new();
    let mut results = Vec::new();
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let (op, x) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        results.push(if op == "get" {
            describe(&action)
        } else {
            declare(&mut action, op, x, &out)?.unwrap_or_else(|e| error(&e))
        });
    }
    Ok(Value::Array(results))
}

fn service_of(spec: &Value, ctx: &Ctx) -> Result<Service, Fault> {
    let kind = spec.get("type").and_then(Value::as_str).unwrap_or("http").to_owned();
    let mut service = Service::new(ServiceType::new(kind.clone()));
    for (key, action) in spec.get("actions").and_then(Value::as_object).into_iter().flatten() {
        service.add_action(key, build(&kind, action, ctx)?);
    }
    Ok(service)
}

fn service(a: &Args<'_>) -> Result<Value, Fault> {
    let ctx = Ctx { out: Arc::new(Mutex::new(Vec::new())), calls: Arc::new(Mutex::new(Vec::new())) };
    let mut service = Service::new(ServiceType::new(a.opt_str("type")?.unwrap_or("http").to_owned()));
    let mut results = Vec::new();
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let (op, x) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        results.push(match op.as_str() {
            "set_type" => {
                service.set_type(ServiceType::new(x.as_str().unwrap_or_default().to_owned()));
                json!(true)
            }
            "type" => json!(service.kind().as_str()),
            "add_action" => {
                let kind = service.kind().as_str().to_owned();
                let action = build(&kind, x.get("action").unwrap_or(&Value::Null), &ctx)?;
                service.add_action(x["key"].as_str().unwrap_or_default(), action);
                json!(true)
            }
            "remove_action" => {
                service.remove_action(x.as_str().unwrap_or_default());
                json!(true)
            }
            "action" => service.action(x.as_str().unwrap_or_default()).map(describe).unwrap_or(Value::Null),
            "actions" => json!(service.actions().keys().collect::<Vec<_>>()),
            other => return Err(Fault::new(format!("unknown step {other}"))),
        });
    }
    Ok(Value::Array(results))
}

fn module(spec: &Value, ctx: &Ctx) -> Result<Module, Fault> {
    let mut module = Module::new();
    for (key, s) in spec.get("services").and_then(Value::as_object).into_iter().flatten() {
        module.add_service(key, service_of(s, ctx)?);
    }
    Ok(module)
}

fn init(spec: &Value) -> Init {
    let p = spec.get("params").cloned().unwrap_or(Value::Null);
    match spec.get("type").and_then(Value::as_str).unwrap_or_default() {
        "http" => Init::Http,
        "Task" => Init::Task,
        "GraphQL" => Init::GraphQL,
        "Worker" => Init::Worker(WorkerParams {
            worker_name: p.get("workerName").and_then(Value::as_str).map(str::to_owned),
            workers: strings(p.get("workers")),
            jobs: p
                .get("jobs")
                .and_then(Value::as_object)
                .into_iter()
                .flatten()
                .map(|(k, c)| {
                    (
                        k.clone(),
                        JobConfig {
                            queue: c.get("queue").and_then(Value::as_str).map(str::to_owned),
                            coroutines: c.get("coroutines").and_then(Value::as_i64),
                            prefetch: c.get("prefetch").and_then(Value::as_u64).map(|n| n as usize),
                        },
                    )
                })
                .collect(),
            queue_name: p.get("queueName").and_then(Value::as_str).map(str::to_owned),
            consumer: p.get("consumer").and_then(Value::as_bool).unwrap_or(false).then(fake_consumers),
            workers_num: p.get("workersNum").and_then(Value::as_u64).unwrap_or(0) as usize,
            namespace: p.get("namespace").and_then(Value::as_str).map(str::to_owned),
        }),
        other => Init::Other(ServiceType::new(other.to_owned())),
    }
}

fn labels(keys: &[String], get: impl Fn(&str) -> Option<Value>) -> Value {
    map_or_list(keys.iter().map(|k| (k.clone(), get(k).unwrap_or(Value::Null))).collect())
}

fn queue_hook(hook: &utopia_queue::Hook, keys: &[String]) -> Value {
    json!({
        "desc": hook.description(),
        "groups": hook.group_names(),
        "params": hook.params().keys().collect::<Vec<_>>(),
        "dependencies": hook.injections(),
        "labels": labels(keys, |k| hook.get_label(k).cloned()),
    })
}

async fn platform(a: &Args<'_>) -> Result<Value, Fault> {
    let ctx = Ctx { out: Arc::new(Mutex::new(Vec::new())), calls: Arc::new(Mutex::new(Vec::new())) };
    let empty = json!({});
    let mut platform = Platform::new(module(a.opt("core").unwrap_or(&empty), &ctx)?);
    let mut results = Vec::new();
    let take = |calls: &Calls| std::mem::take(&mut *calls.lock().unwrap_or_else(|e| e.into_inner()));
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let (op, x) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        let keys = strings(Some(x));
        results.push(match op.as_str() {
            "add_module" => {
                platform.add_module(module(x, &ctx)?);
                json!(true)
            }
            "add_service" => {
                platform.add_service(x["key"].as_str().unwrap_or_default(), service_of(&x["service"], &ctx)?);
                json!(true)
            }
            "remove_service" => {
                platform.remove_service(x.as_str().unwrap_or_default());
                json!(true)
            }
            "service" => match platform.service(x.as_str().unwrap_or_default()) {
                Ok(s) => json!({"type": s.kind().as_str(), "actions": s.actions().keys().collect::<Vec<_>>()}),
                Err(e) => error(&e),
            },
            "services" => json!(platform.services().keys().collect::<Vec<_>>()),
            "set_cli" => match Cli::new(&keys) {
                Ok(cli) => {
                    platform.set_cli(cli);
                    json!(true)
                }
                Err(e) => servers_error(&e),
            },
            "set_worker" => {
                platform.set_worker(Server::new(QueueAdapter::with_factory(fake_consumers(), 1, Runtime::Workers)));
                json!(true)
            }
            "init" => match platform.init(init(x)) {
                Ok(()) => Value::Null,
                Err(e) => error(&e),
            },
            "cli" => match platform.cli() {
                None => Value::Null,
                Some(cli) => {
                    let tasks: Vec<Value> = cli
                        .tasks()
                        .iter()
                        .map(|(key, task)| {
                            json!({
                                "key": key,
                                "name": task.name(),
                                "desc": task.description(),
                                "groups": task.get_groups(),
                                "params": map_or_list(task.params().iter().map(|(k, p)| (k.clone(), hook_param(p))).collect()),
                                "dependencies": task.dependencies(),
                                "labels": labels(&keys, |k| task.get_label(k).cloned()),
                            })
                        })
                        .collect();
                    json!({"tasks": tasks, "args": map_or_list(cli.args().iter().map(|(k, v)| (k.clone(), v.clone())).collect())})
                }
            },
            "run_cli" => {
                take(&ctx.calls);
                match platform.cli() {
                    Some(cli) => {
                        let error = match cli.run().await {
                            Ok(()) => Value::Null,
                            Err(e) => servers_error(&e)["$error"].clone(),
                        };
                        json!({"calls": take(&ctx.calls), "error": error})
                    }
                    None => Value::Null,
                }
            }
            "worker" => match platform.worker() {
                None => Value::Null,
                Some(worker) => {
                    let jobs: Vec<Value> = worker
                        .jobs()
                        .into_iter()
                        .map(|queue| {
                            let mut job = worker.get_job(queue).map(|j| queue_hook(j, &keys)).unwrap_or(Value::Null);
                            if let Value::Object(o) = &mut job {
                                let mut out = Map::new();
                                out.insert("queue".into(), json!(queue));
                                out.insert("coroutines".into(), json!(worker.coroutines(queue)));
                                out.insert("prefetch".into(), json!(worker.prefetch(queue)));
                                out.extend(std::mem::take(o));
                                return Value::Object(out);
                            }
                            job
                        })
                        .collect();
                    let hooks = |list: &[utopia_queue::Hook]| list.iter().map(|h| queue_hook(h, &keys)).collect::<Vec<_>>();
                    json!({"jobs": jobs, "start": hooks(worker.worker_start_hooks()), "stop": hooks(worker.worker_stop_hooks())})
                }
            },
            "worker_hooks" => {
                take(&ctx.calls);
                match platform.worker() {
                    Some(worker) => {
                        let list =
                            if x.as_str() == Some("start") { worker.worker_start_hooks() } else { worker.worker_stop_hooks() };
                        for hook in list {
                            if let Some(action) = hook.get_action() {
                                action(Default::default()).await.map_err(|e| Fault::new(e.to_string()))?;
                            }
                        }
                        Value::Array(take(&ctx.calls))
                    }
                    None => Value::Null,
                }
            }
            "routes" => {
                let http = platform.http();
                let mut routes = Vec::new();
                for method in utopia_http::METHODS {
                    for (template, id) in http.router().routes(method).into_iter().flatten() {
                        let Some(route) = http.route(*id) else { continue };
                        routes.push(json!({
                            "method": method,
                            "template": template,
                            "path": route.path(),
                            "methods": route.methods(),
                            "desc": route.description(),
                            "groups": route.get_groups(),
                            "params": map_or_list(route.params().iter().map(|(k, p)| (k.clone(), route_param(p))).collect()),
                            "dependencies": route.injections(),
                            "labels": labels(&keys, |k| route.get_label(k).cloned()),
                        }));
                    }
                }
                Value::Array(routes)
            }
            "request" => {
                let http = platform.http();
                super::http::dispatch(http, x, &ctx.out).await?
            }
            other => return Err(Fault::new(format!("unknown step {other}"))),
        });
    }
    Ok(Value::Array(results))
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let value = match op {
        "action.run" => action(&a)?,
        "service.run" => service(&a)?,
        "platform.run" => platform(&a).await?,
        _ => return Err(Fault::new(format!("unknown op {op}"))),
    };
    Ok(Outcome::ok(value))
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    let noop = || Callback::http(async |_| {});
    Ok(match name {
        "declare" => {
            let text: Arc<dyn utopia_validators::Validator> = Arc::new(Text::new(128));
            Bench::sync(move || {
                let mut action = Action::new();
                action
                    .set_http_method(utopia_platform::HTTP_REQUEST_METHOD_POST)
                    .set_http_path("/v1/databases/:databaseId/collections")
                    .desc("Create collection")
                    .groups(&["api", "database"])
                    .label("scope", json!("collections.write"))
                    .param("databaseId", Param::new(json!(""), Check::Validator(text.clone()), "Database ID."))
                    .param("collectionId", Param::new(json!(""), Check::Validator(text.clone()), "Collection ID."))
                    .param("name", Param::new(json!(""), Check::Validator(text.clone()), "Collection name."))
                    .inject("response")
                    .inject("dbForProject")
                    .callback(noop());
                black_box(action);
            })
        }
        "init" => {
            let text: Arc<dyn utopia_validators::Validator> = Arc::new(Text::new(128));
            let mut service = Service::new(ServiceType::HTTP);
            for i in 0..10 {
                let mut action = Action::new();
                action
                    .set_http_method("GET")
                    .set_http_path(&format!("/v1/bench/{i}/:id"))
                    .groups(&["api"])
                    .param("id", Param::new(json!(""), Check::Validator(text.clone()), "ID."))
                    .param("search", Param::new(json!(""), Check::Validator(text.clone()), "Search.").optional(true))
                    .inject("response")
                    .callback(noop());
                service.add_action(&format!("action{i}"), action);
            }
            let mut platform = Platform::new(Module::new());
            platform.add_service("bench", service);
            Bench::sync(move || {
                platform.set_http(utopia_http::Http::default());
                black_box(platform.init(Init::Http).ok());
            })
        }
        _ => return Err(Fault::new(format!("unknown benchmark {name}"))),
    })
}
