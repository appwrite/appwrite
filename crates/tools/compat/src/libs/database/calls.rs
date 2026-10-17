//! Callbacks an operation passes to the library (`Core::callback`,
//! `Core::run`): `true` (records its calls) or `{"steps": [...]}`, steps
//! run by a small interpreter at each call. See the README (`database`:
//! callbacks).
//!
//! ```ignore
//! let calls = Calls::new(&a.cx);
//! let cb = calls.callback("callback", a.raw("callback"))?;
//! let result = db.skip_relationships(cb.run(Vec::new())).await;
//! calls.finish(result.map(...))
//! ```

use std::collections::HashMap;
use std::sync::{Arc, Mutex, Weak};

use serde_json::{Map, Value as Json, json};
use utopia_database::{Error, Kind};

use super::ctx::{Ctx, Fail, R, fault, lock};
use super::obj::Obj;
use super::{args, codec};
use crate::adapter::Fault;

/// One call of a callback: its arguments (encoded) and its steps' results.
struct Invocation {
    args: Vec<Json>,
    steps: Vec<Json>,
}

struct Record {
    callbacks: Vec<(String, Vec<Invocation>)>,
    fault: Option<Fault>,
}

/// The callbacks one operation passes to the library, and what they
/// recorded.
#[derive(Clone)]
pub struct Calls {
    /// Held weakly: callbacks live on in hooks and listeners the session
    /// keeps, and must not keep the session (and its connections) alive.
    cx: Weak<Ctx>,
    record: Arc<Mutex<Record>>,
}

impl Calls {
    /// Callbacks running their steps on `cx`.
    pub fn new(cx: &Arc<Ctx>) -> Self {
        Self { cx: Arc::downgrade(cx), record: Arc::new(Mutex::new(Record { callbacks: Vec::new(), fault: None })) }
    }

    /// The callback for parameter `name` from its argument: `None` for an
    /// absent or `null` one, else `true` / `{}` (records its calls) or
    /// `{"steps": [...]}` (also runs the steps at each call).
    pub fn callback(&self, name: &str, spec: Option<&Json>) -> R<Option<Cb>> {
        let steps: Vec<Json> = match spec {
            None | Some(Json::Null) => return Ok(None),
            Some(Json::Bool(true)) => Vec::new(),
            Some(Json::Object(o)) if o.is_empty() => Vec::new(),
            Some(Json::Object(o)) => match o.get("steps") {
                Some(Json::Array(steps)) => steps.clone(),
                _ => return fault("a callback is null, true or {\"steps\": [...]}"),
            },
            _ => return fault("a callback is null, true or {\"steps\": [...]}"),
        };
        let mut record = lock(&self.record);
        record.callbacks.push((name.to_owned(), Vec::new()));
        Ok(Some(Cb { calls: self.clone(), slot: record.callbacks.len() - 1, steps: steps.into() }))
    }

    /// A required callback.
    pub fn required(&self, name: &str, spec: Option<&Json>) -> R<Cb> {
        match self.callback(name, spec)? {
            Some(cb) => Ok(cb),
            None => fault(format!("missing callback `{name}`")),
        }
    }

    /// The operation's result: an error as is; with callbacks, the result
    /// and the calls they received (`Called`). A fault a step raised wins
    /// over everything.
    pub fn finish(self, result: R<Obj>) -> R<Obj> {
        let mut record = lock(&self.record);
        if let Some(f) = record.fault.take() {
            return Err(Fail::Fault(f));
        }
        let callbacks = std::mem::take(&mut record.callbacks);
        drop(record);
        let result = result?;
        if callbacks.is_empty() {
            return Ok(result);
        }
        let mut calls = Map::new();
        for (name, invocations) in callbacks {
            let list = invocations.into_iter().map(|i| json!({"args": i.args, "steps": i.steps})).collect();
            calls.insert(name, Json::Array(list));
        }
        Ok(Obj::Called(Box::new(result), Json::Object(calls)))
    }

    fn fail(&self, f: Fault) -> Error {
        let message = f.0.clone();
        lock(&self.record).fault.get_or_insert(f);
        Error::new(Kind::Exception, format!("compat fault: {message}"))
    }
}

/// A callback of [`Calls`].
#[derive(Clone)]
pub struct Cb {
    calls: Calls,
    slot: usize,
    steps: Arc<[Json]>,
}

/// An error a step raised, as the library sees it.
pub fn error_of(class: &str, message: &[u8]) -> Option<Error> {
    Kind::from_php_class(class).map(|kind| Error::from_bytes(kind, message.to_vec()))
}

impl Cb {
    /// Calls the callback with `args` (encoded, as the steps' `$arg` see
    /// them): records the call, runs the steps, and returns what the
    /// callback returns (`$return`, or a `return: true` step's result;
    /// `None` without one). A step's uncaught error is the callback's.
    pub async fn invoke(&self, args: Vec<Json>) -> Result<Option<Obj>, Error> {
        let calls = &self.calls;
        // A callback called after its operation finished (one the library
        // keeps) records nowhere: its operation reported its calls already.
        let index = {
            let mut record = lock(&calls.record);
            record.callbacks.get_mut(self.slot).map(|(_, list)| {
                list.push(Invocation { args: args.clone(), steps: Vec::new() });
                list.len() - 1
            })
        };
        let push = |result: Json| {
            if let Some(index) = index
                && let Some((_, list)) = lock(&calls.record).callbacks.get_mut(self.slot)
                && let Some(invocation) = list.get_mut(index)
            {
                invocation.steps.push(result);
            }
        };
        let Some(cx) = calls.cx.upgrade() else {
            return Err(calls.fail(Fault::new("a callback ran after its session ended")));
        };
        let mut locals: HashMap<String, Json> = HashMap::new();
        let mut returned = None;
        for step in self.steps.iter() {
            let Some(op) = step.get("op").and_then(Json::as_str) else {
                return Err(calls.fail(Fault::new("a step is {\"op\": ..., \"args\": {...}}")));
            };
            let step_args = match locals_of(step.get("args").unwrap_or(&Json::Null), &locals, &args) {
                Ok(Json::Null) => Json::Object(Map::new()),
                Ok(a) => a,
                Err(f) => return Err(calls.fail(f)),
            };
            if op == "$throw" {
                push(Json::Null);
                let class = step_args.get("class").and_then(Json::as_str).unwrap_or("Exception").to_owned();
                let message = step_args.get("message").and_then(Json::as_str).unwrap_or("").to_owned();
                return Err(match Kind::from_php_class(&class) {
                    Some(kind) => Error::new(kind, message),
                    None => calls.fail(Fault::new(format!("$throw: no error kind for {class}"))),
                });
            }
            if op == "$return" {
                // `Values::decode()` as PHP's interpreter does: a
                // `{"$obj": <name>}` in the value stays that array.
                push(Json::Null);
                let value =
                    args::tags(step_args.get("value").unwrap_or(&Json::Null)).and_then(|j| args::decode_obj_static(&j));
                match value {
                    Ok(v) => returned = Some(v),
                    Err(Fail::Fault(f)) => return Err(calls.fail(f)),
                    Err(Fail::Error(e)) => return Err(e),
                    Err(Fail::Php { class, message }) => {
                        return Err(error_of(&class, &message)
                            .unwrap_or_else(|| calls.fail(Fault::new(format!("no error kind for {class}")))));
                    }
                }
                continue;
            }
            match super::dispatch(op, &step_args, &cx).await {
                Err(Fail::Fault(f)) => return Err(calls.fail(f)),
                Err(fail) => {
                    let (class, message) = fail.class_and_message().unwrap_or_default();
                    push(json!({"$error": {"class": class, "message": crate::adapter::bytes_value(&message)}}));
                    if !step.get("catch").and_then(Json::as_bool).unwrap_or(false) {
                        return Err(match fail {
                            Fail::Error(e) => e,
                            _ => error_of(&class, &message).unwrap_or_else(|| {
                                calls.fail(Fault::new(format!("a step raised {class}, which no error kind stands for")))
                            }),
                        });
                    }
                }
                Ok(obj) => {
                    let encoded = obj.encode();
                    push(encoded.clone());
                    if let Some(bind) = step.get("bind").and_then(Json::as_str) {
                        locals.insert(bind.to_owned(), encoded);
                    }
                    if step.get("return").and_then(Json::as_bool).unwrap_or(false) {
                        returned = Some(obj.into_result());
                    }
                }
            }
        }
        Ok(returned)
    }

    /// [`Cb::invoke`], its return value as a value (`null` without one).
    pub async fn value(&self, args: Vec<Json>) -> Result<utopia_database::Value, Error> {
        match self.invoke(args).await? {
            None => Ok(utopia_database::Value::Null),
            Some(Obj::Value(v)) => Ok(v),
            Some(other) => {
                // An object returned as a value: its wire form decoded.
                codec::decode(&other.encode()).map_err(|_| self.calls.fail(Fault::new("a callback returned an object")))
            }
        }
    }
}

/// `Core::locals`: `{"$local": <bind>, "path"?}` is an earlier step's
/// result, `{"$arg": <n>, "path"?}` the callback's n-th argument, anywhere
/// in a step's arguments.
fn locals_of(value: &Json, locals: &HashMap<String, Json>, args: &[Json]) -> Result<Json, Fault> {
    match value {
        Json::Object(o) => {
            let path = o.get("path").and_then(Json::as_str).unwrap_or("");
            if let Some(Json::String(name)) = o.get("$local") {
                let bound = locals.get(name).ok_or_else(|| Fault::new(format!("`$local` to unknown step `{name}`")))?;
                return pointer(bound, path);
            }
            if let Some(n) = o.get("$arg").and_then(Json::as_u64) {
                let arg =
                    args.get(n as usize).ok_or_else(|| Fault::new(format!("the callback has no argument {n}")))?;
                return pointer(arg, path);
            }
            // A nested callback's steps name its own locals and arguments:
            // they are resolved when it runs.
            Ok(Json::Object(
                o.iter()
                    .map(|(k, v)| match v {
                        Json::Array(_) if k == "steps" => Ok((k.clone(), v.clone())),
                        _ => Ok((k.clone(), locals_of(v, locals, args)?)),
                    })
                    .collect::<Result<_, Fault>>()?,
            ))
        }
        Json::Array(items) => {
            Ok(Json::Array(items.iter().map(|v| locals_of(v, locals, args)).collect::<Result<_, _>>()?))
        }
        other => Ok(other.clone()),
    }
}

/// A JSON pointer into an encoded value (`Core::pointer`).
pub fn pointer(value: &Json, path: &str) -> Result<Json, Fault> {
    if path.is_empty() {
        return Ok(value.clone());
    }
    let mut current = value;
    for segment in path.split('/').skip(1) {
        let segment = segment.replace("~1", "/").replace("~0", "~");
        current = match current {
            Json::Object(o) => o.get(&segment),
            Json::Array(items) => segment.parse::<usize>().ok().and_then(|i| items.get(i)),
            _ => None,
        }
        .ok_or_else(|| Fault::new(format!("nothing at `{path}`")))?;
    }
    Ok(current.clone())
}
