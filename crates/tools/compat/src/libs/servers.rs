//! Compat adapter for `servers`: maps `tests/compat/servers/spec.json` operations onto `utopia-servers`.

use std::hint::black_box;
use std::sync::{Arc, Mutex};

use serde_json::{Map, Value, json};
use utopia_di::Container;
use utopia_servers::{Base, Check, Error, Hook, Param, ParamDefault, Resource};
use utopia_validators::{Text, Validator};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &["hook.run", "base.prepare", "base.run"];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("declare", "Declare a hook with three params, two injections, groups and a label"),
    ("prepare", "Resolve and validate three params of a hook from the request"),
];

/// Values default and validator factories were called with.
type Out = Arc<Mutex<Vec<Value>>>;

fn deps(resources: &[Resource]) -> Vec<Value> {
    resources.iter().map(|r| r.downcast_ref::<Value>().cloned().unwrap_or(Value::Null)).collect()
}

fn strings(v: Option<&Value>) -> Vec<String> {
    v.and_then(Value::as_array).into_iter().flatten().map(|s| s.as_str().unwrap_or_default().to_owned()).collect()
}

/// A param from its description (as Adapter.php's `param()`).
pub(crate) fn param(p: &Value, out: &Out) -> Result<(String, Param), Fault> {
    let a = Args(p);
    let default = p.get("default").cloned().unwrap_or(Value::Null);
    let default = if a.opt_bool("default_fn")?.unwrap_or(false) {
        let out = out.clone();
        ParamDefault::Factory(Arc::new(move |resources| {
            out.lock().unwrap_or_else(|e| e.into_inner()).extend(deps(resources));
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
            Check::Factory(Arc::new(move |resources| {
                out.lock().unwrap_or_else(|e| e.into_inner()).extend(deps(resources));
                Arc::from(super::http::validator(&inner).unwrap_or_else(|_| Box::new(Text::new(0))))
            }))
        }
        None => Check::Validator(Arc::from(super::http::validator(&spec)?)),
    };
    let injections = strings(p.get("injections"));
    let aliases = strings(p.get("aliases"));
    let param = Param::new(default, check, a.opt_str("description")?.unwrap_or(""))
        .optional(a.opt_bool("optional")?.unwrap_or(false))
        .injections(&injections.iter().map(String::as_str).collect::<Vec<_>>())
        .skip_validation(a.opt_bool("skip_validation")?.unwrap_or(false))
        .deprecated(a.opt_bool("deprecated")?.unwrap_or(false))
        .example(a.opt_str("example")?.unwrap_or(""))
        .model(a.opt_str("model")?)
        .aliases(&aliases.iter().map(String::as_str).collect::<Vec<_>>())
        .enumeration(p.get("enum").map(|e| Arc::new(e.clone()) as Resource));
    Ok((a.str("key")?.to_owned(), param))
}

fn params(hook: &Hook) -> Value {
    let mut out = Map::new();
    for (key, p) in hook.params() {
        out.insert(
            key.clone(),
            json!({
                "default": match &p.default { ParamDefault::Value(v) => v.clone(), ParamDefault::Factory(_) => json!("factory") },
                "validator": match &p.validator { Check::Validator(v) => json!(v.description()), Check::Factory(_) => json!("factory") },
                "description": p.description,
                "optional": p.optional,
                "injections": p.injections,
                "skipValidation": p.skip_validation,
                "deprecated": p.deprecated,
                "example": p.example,
                "model": p.model,
                "aliases": p.aliases,
                "enum": p.enumeration.as_ref().and_then(|e| e.downcast_ref::<Value>()).cloned(),
                "value": p.value,
                "order": p.order,
            }),
        );
    }
    if out.is_empty() { Value::Array(Vec::new()) } else { Value::Object(out) }
}

fn error(e: &Error) -> Value {
    json!({"$error": {"class": e.php_class(), "message": e.to_string(), "code": e.code()}})
}

fn map_or_list(map: Map<String, Value>) -> Value {
    if map.is_empty() { Value::Array(Vec::new()) } else { Value::Object(map) }
}

async fn hook(a: &Args<'_>) -> Result<Value, Fault> {
    let out: Out = Arc::new(Mutex::new(Vec::new()));
    let mut hook = Hook::new();
    let mut results = Vec::new();
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let (op, x) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        let text = |v: &Value| v.as_str().unwrap_or_default().to_owned();
        let result: Result<Value, Error> = match op.as_str() {
            "desc" => {
                hook.desc(&text(x));
                Ok(json!(true))
            }
            "groups" => {
                let g = strings(Some(x));
                hook.groups(&g.iter().map(String::as_str).collect::<Vec<_>>());
                Ok(json!(true))
            }
            "label" => {
                hook.label(&text(&x["key"]), x["value"].clone());
                Ok(json!(true))
            }
            "param" => {
                let (key, p) = param(x, &out)?;
                hook.param(&key, p);
                Ok(json!(true))
            }
            "inject" => hook.try_inject(&text(x)).map(|_| json!(true)),
            "action" => {
                hook.action(|_| async { Ok(()) });
                Ok(json!(true))
            }
            "set_param_value" => hook.set_param_value(&text(&x["key"]), x["value"].clone()).map(|_| json!(true)),
            "get" => Ok(match x.as_str() {
                Some("desc") => json!(hook.description()),
                Some("groups") => json!(hook.get_groups()),
                Some("params") => params(&hook),
                Some("injections") => map_or_list(
                    hook.injections()
                        .iter()
                        .map(|(k, i)| (k.clone(), json!({"name": i.name, "order": i.order})))
                        .collect(),
                ),
                Some("dependencies") => json!(hook.dependencies()),
                Some("params_values") => {
                    map_or_list(hook.params_values().into_iter().map(|(k, v)| (k.to_owned(), v.clone())).collect())
                }
                Some("action") => json!((hook.get_action())(Default::default()).await.is_ok()),
                _ => return Err(Fault::new(format!("unknown getter {x}"))),
            }),
            "label_of" => Ok(hook
                .get_label(&text(&x["key"]))
                .cloned()
                .unwrap_or_else(|| x.get("default").cloned().unwrap_or(Value::Null))),
            "param_value" => hook.param_value(&text(x)).cloned(),
            other => return Err(Fault::new(format!("unknown step {other}"))),
        };
        results.push(result.unwrap_or_else(|e| error(&e)));
    }
    Ok(Value::Array(results))
}

fn prepare(a: &Args<'_>) -> Result<Value, Fault> {
    let out: Out = Arc::new(Mutex::new(Vec::new()));
    let context = Arc::new(Container::new());
    for (name, value) in a.opt("resources").and_then(Value::as_object).into_iter().flatten() {
        let value = value.clone();
        context.set(name, &[], move |_| Ok(value.clone()));
    }
    let mut hook = Hook::new();
    for p in a.opt("params").and_then(Value::as_array).into_iter().flatten() {
        let (key, p) = param(p, &out)?;
        hook.param(&key, p);
    }
    let map = |k: &str| a.opt(k).and_then(Value::as_object).cloned().unwrap_or_default();
    let mut scope = Map::new();
    let error = match Base::new().prepare(&context, &mut hook, &map("values"), &map("request")) {
        Ok(container) => {
            for key in hook.params().keys() {
                let v = container.get::<Value>(key).map_err(|e| Fault::new(e.to_string()))?;
                scope.insert(key.clone(), (*v).clone());
            }
            Value::Null
        }
        Err(e) => error(&e)["$error"].clone(),
    };
    let values = map_or_list(hook.params_values().into_iter().map(|(k, v)| (k.to_owned(), v.clone())).collect());
    let factories = out.lock().unwrap_or_else(|e| e.into_inner()).clone();
    Ok(json!({"scope": map_or_list(scope), "values": values, "factories": factories, "error": error}))
}

fn base(a: &Args<'_>) -> Result<Value, Fault> {
    let mut server = Base::new();
    let mut results = Vec::new();
    for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
        let (op, x) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        results.push(match op.as_str() {
            "set_mode" => {
                server.set_mode(x.as_str().unwrap_or_default());
                Value::Null
            }
            "mode" => json!([server.mode(), server.is_production(), server.is_development(), server.is_stage()]),
            "hook" => {
                let hook = match x.as_str() {
                    Some("init") => server.init(),
                    Some("shutdown") => server.shutdown(),
                    Some("error") => server.error(),
                    Some("start") => server.on_start(),
                    Some("end") => server.on_end(),
                    _ => return Err(Fault::new(format!("unknown hook {x}"))),
                };
                json!(hook.get_groups())
            }
            "set_container" => {
                let container = Arc::new(Container::new());
                let x = x.clone();
                container.set("name", &[], move |_| Ok(x.clone()));
                server.set_container(container);
                json!(true)
            }
            "container" => match server.container() {
                Some(c) => (*c.get::<Value>("name").map_err(|e| Fault::new(e.to_string()))?).clone(),
                None => json!({"$error": {"class": "Error"}}),
            },
            "reset" => {
                server.reset();
                Value::Null
            }
            other => return Err(Fault::new(format!("unknown step {other}"))),
        });
    }
    Ok(Value::Array(results))
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "declare" => {
            let text: Arc<dyn Validator> = Arc::new(Text::new(128));
            Bench::sync(move || {
                let mut hook = Hook::new();
                hook.desc("Create document")
                    .groups(&["api", "database"])
                    .label("scope", json!("documents.write"))
                    .param("databaseId", Param::new(json!(""), Check::Validator(text.clone()), "Database ID."))
                    .param("collectionId", Param::new(json!(""), Check::Validator(text.clone()), "Collection ID."))
                    .param("documentId", Param::new(json!(""), Check::Validator(text.clone()), "Document ID."))
                    .inject("response")
                    .inject("dbForProject");
                black_box(hook);
            })
        }
        "prepare" => {
            let text: Arc<dyn Validator> = Arc::new(Text::new(128));
            let mut hook = Hook::new();
            hook.param("databaseId", Param::new(json!(""), Check::Validator(text.clone()), "Database ID."))
                .param("collectionId", Param::new(json!(""), Check::Validator(text.clone()), "Collection ID."))
                .param("documentId", Param::new(json!(""), Check::Validator(text), "Document ID.").optional(true));
            let context = Arc::new(Container::new());
            let request: Map<String, Value> =
                [("databaseId".to_owned(), json!("main")), ("collectionId".to_owned(), json!("users"))]
                    .into_iter()
                    .collect();
            let server = Base::new();
            let values = Map::new();
            Bench::sync(move || {
                black_box(server.prepare(&context, &mut hook, &values, &request).is_ok());
            })
        }
        _ => return Err(Fault::new(format!("servers: no benchmark `{name}`"))),
    })
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(Outcome::Ok(match op {
        "hook.run" => hook(&a).await?,
        "base.prepare" => prepare(&a)?,
        "base.run" => base(&a)?,
        _ => return Err(Fault::new(format!("servers: unknown operation `{op}`"))),
    }))
}
