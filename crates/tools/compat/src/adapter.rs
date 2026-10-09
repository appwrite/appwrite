//! What a library adapter (`src/libs/<lib>.rs`) works with.
//!
//! An adapter maps the operations in `tests/compat/<lib>/spec.json` onto the
//! Rust crate, exactly as `tests/compat/<lib>/Adapter.php` maps them onto the
//! PHP library. Adapters are glue: they decode arguments, call the library and
//! encode the result. They hold no logic of their own.

use std::any::Any;
use std::collections::HashMap;

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use serde_json::{Map, Number, Value};

/// The result of one operation, as both runtimes report it.
#[derive(Debug, Clone, PartialEq)]
pub enum Outcome {
    /// The operation returned a value.
    Ok(Value),
    /// The library raised an error: the PHP exception class it corresponds to,
    /// and its message: a string, or `{"$bytes": base64}` when the message is
    /// not UTF-8 (it can quote the input). Rust errors report the PHP class
    /// name verbatim.
    Err { class: String, message: Value },
}

impl Outcome {
    pub fn ok(value: impl Into<Value>) -> Self {
        Outcome::Ok(value.into())
    }

    pub fn err(class: impl Into<String>, message: impl Into<String>) -> Self {
        Outcome::Err { class: class.into(), message: Value::String(message.into()) }
    }

    /// An error whose message is bytes (see [`bytes_value`]).
    pub fn err_bytes(class: impl Into<String>, message: &[u8]) -> Self {
        Outcome::Err { class: class.into(), message: bytes_value(message) }
    }

    /// The JSON form compared and recorded by the runner.
    pub fn to_json(&self) -> Value {
        match self {
            Outcome::Ok(v) => v.clone(),
            Outcome::Err { class, message } => {
                let mut e = Map::new();
                e.insert("class".into(), Value::String(class.clone()));
                e.insert("message".into(), message.clone());
                let mut o = Map::new();
                o.insert("$error".into(), Value::Object(e));
                Value::Object(o)
            }
        }
    }
}

/// A harness problem (unknown operation, malformed arguments, missing
/// service). Not a behaviour difference: the runner reports it separately and
/// stops the case.
#[derive(Debug, Clone, PartialEq)]
pub struct Fault(pub String);

impl Fault {
    pub fn new(message: impl Into<String>) -> Self {
        Fault(message.into())
    }
}

impl std::fmt::Display for Fault {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.0)
    }
}

pub type OpResult = Result<Outcome, Fault>;

/// Per-driver state: the namespace isolating this run's resources, the
/// service endpoints, and objects created by earlier operations (handles).
pub struct Session {
    /// Prefix every key, table, file or queue an operation creates must carry
    /// (cases write it as `${ns}`), so the runner can isolate and snapshot it.
    pub ns: String,
    /// Service endpoints for this runtime, e.g. `{"redis": "redis://localhost:9513"}`.
    pub services: Map<String, Value>,
    handles: HashMap<String, Box<dyn Any + Send>>,
    next: u64,
}

impl Default for Session {
    fn default() -> Self {
        Self::new("compat", Map::new())
    }
}

impl Session {
    pub fn new(ns: impl Into<String>, services: Map<String, Value>) -> Self {
        Self { ns: ns.into(), services, handles: HashMap::new(), next: 0 }
    }

    /// Drops every handle.
    pub fn reset(&mut self) {
        self.handles.clear();
    }

    /// Stores an object and returns its handle (`{"$handle": "h1"}`).
    pub fn handle<T: Any + Send>(&mut self, object: T) -> Value {
        self.next += 1;
        let id = format!("h{}", self.next);
        self.handles.insert(id.clone(), Box::new(object));
        let mut o = Map::new();
        o.insert("$handle".into(), Value::String(id));
        Value::Object(o)
    }

    fn handle_id(value: &Value) -> Result<&str, Fault> {
        value
            .get("$handle")
            .and_then(Value::as_str)
            .ok_or_else(|| Fault::new(format!("expected a handle, got {value}")))
    }

    pub fn get<T: Any>(&self, value: &Value) -> Result<&T, Fault> {
        let id = Self::handle_id(value)?;
        self.handles
            .get(id)
            .and_then(|b| b.downcast_ref::<T>())
            .ok_or_else(|| Fault::new(format!("handle {id} is missing or of another type")))
    }

    pub fn get_mut<T: Any>(&mut self, value: &Value) -> Result<&mut T, Fault> {
        let id = Self::handle_id(value)?.to_owned();
        self.handles
            .get_mut(&id)
            .and_then(|b| b.downcast_mut::<T>())
            .ok_or_else(|| Fault::new(format!("handle {id} is missing or of another type")))
    }

    /// Endpoint of a service this runtime was configured with.
    pub fn service(&self, name: &str) -> Result<&str, Fault> {
        self.services
            .get(name)
            .and_then(Value::as_str)
            .ok_or_else(|| Fault::new(format!("service `{name}` is not configured for this run")))
    }
}

/// Typed access to an operation's arguments (a JSON object).
pub struct Args<'a>(pub &'a Value);

impl<'a> Args<'a> {
    pub fn value(&self, key: &str) -> Result<&'a Value, Fault> {
        self.0.get(key).ok_or_else(|| Fault::new(format!("missing argument `{key}`")))
    }

    pub fn opt(&self, key: &str) -> Option<&'a Value> {
        self.0.get(key).filter(|v| !v.is_null())
    }

    pub fn str(&self, key: &str) -> Result<&'a str, Fault> {
        self.value(key)?.as_str().ok_or_else(|| Fault::new(format!("argument `{key}` must be a string")))
    }

    pub fn opt_str(&self, key: &str) -> Result<Option<&'a str>, Fault> {
        self.opt(key)
            .map(|v| v.as_str().ok_or_else(|| Fault::new(format!("argument `{key}` must be a string"))))
            .transpose()
    }

    pub fn i64(&self, key: &str) -> Result<i64, Fault> {
        self.value(key)?.as_i64().ok_or_else(|| Fault::new(format!("argument `{key}` must be an integer")))
    }

    pub fn opt_i64(&self, key: &str) -> Result<Option<i64>, Fault> {
        self.opt(key)
            .map(|v| v.as_i64().ok_or_else(|| Fault::new(format!("argument `{key}` must be an integer"))))
            .transpose()
    }

    pub fn f64(&self, key: &str) -> Result<f64, Fault> {
        float(self.value(key)?).ok_or_else(|| Fault::new(format!("argument `{key}` must be a number")))
    }

    pub fn opt_f64(&self, key: &str) -> Result<Option<f64>, Fault> {
        self.opt(key)
            .map(|v| float(v).ok_or_else(|| Fault::new(format!("argument `{key}` must be a number"))))
            .transpose()
    }

    pub fn bool(&self, key: &str) -> Result<bool, Fault> {
        self.value(key)?.as_bool().ok_or_else(|| Fault::new(format!("argument `{key}` must be a boolean")))
    }

    pub fn opt_bool(&self, key: &str) -> Result<Option<bool>, Fault> {
        self.opt(key)
            .map(|v| v.as_bool().ok_or_else(|| Fault::new(format!("argument `{key}` must be a boolean"))))
            .transpose()
    }

    pub fn array(&self, key: &str) -> Result<&'a Vec<Value>, Fault> {
        self.value(key)?.as_array().ok_or_else(|| Fault::new(format!("argument `{key}` must be a list")))
    }

    pub fn bytes(&self, key: &str) -> Result<Vec<u8>, Fault> {
        bytes(self.value(key)?).ok_or_else(|| Fault::new(format!("argument `{key}` must be a string or $bytes")))
    }
}

/// A float argument: a JSON number or `{"$float": "INF" | "-INF" | "NAN"}`.
pub fn float(value: &Value) -> Option<f64> {
    if let Some(f) = value.as_f64() {
        return Some(f);
    }
    match value.get("$float").and_then(Value::as_str)? {
        "INF" => Some(f64::INFINITY),
        "-INF" => Some(f64::NEG_INFINITY),
        "NAN" => Some(f64::NAN),
        _ => None,
    }
}

/// Encodes a float the way the PHP driver does: finite floats stay JSON
/// floats (so `1.0` is not `1`), others become `{"$float": ...}`.
pub fn float_value(f: f64) -> Value {
    match Number::from_f64(f) {
        Some(n) => Value::Number(n),
        None => {
            let tag = if f.is_nan() {
                "NAN"
            } else if f > 0.0 {
                "INF"
            } else {
                "-INF"
            };
            let mut o = Map::new();
            o.insert("$float".into(), Value::String(tag.into()));
            Value::Object(o)
        }
    }
}

/// A byte-string argument: a JSON string (its UTF-8 bytes) or `{"$bytes": "<base64>"}`.
pub fn bytes(value: &Value) -> Option<Vec<u8>> {
    match value {
        Value::String(s) => Some(s.as_bytes().to_vec()),
        Value::Object(o) => o.get("$bytes").and_then(Value::as_str).and_then(|b| BASE64.decode(b).ok()),
        _ => None,
    }
}

/// Encodes bytes the way the PHP driver encodes PHP strings: valid UTF-8 as a
/// JSON string, anything else as `{"$bytes": "<base64>"}`.
pub fn bytes_value(bytes: &[u8]) -> Value {
    match std::str::from_utf8(bytes) {
        Ok(s) => Value::String(s.to_owned()),
        Err(_) => {
            let mut o = Map::new();
            o.insert("$bytes".into(), Value::String(BASE64.encode(bytes)));
            Value::Object(o)
        }
    }
}
