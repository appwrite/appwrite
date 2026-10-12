//! Runs a program recorded from a PHP test (the Rust twin of
//! tests/compat/query/Replay.php): numbers objects in the order they appear
//! in results, decodes arguments, calls back into the program when the
//! library invokes a test closure or test object, and exports results as
//! the PHP replay does.
//!
//! Areas map PHP classes onto the crate by implementing [`Object`] for the
//! crate's types (a builder, a query, a statement), and by the `construct`,
//! `call_static`, `constant` and `enum_case` functions of their module.

use std::any::Any;
use std::collections::HashMap;
use std::sync::{Arc, Mutex, TryLockError};

use php_std::{ArrayKey, Str};
use serde_json::{Map, Value as Json, json};
use utopia_query::{Array, Error, Object as Ext, Query, Value};

use crate::adapter::{Fault, bytes_value, float_value};

/// An object of the program: a library object (a builder, a query, a
/// statement, an AST node) as the crate has it.
pub trait Object: Any + Send {
    /// The PHP class, relative to `Utopia\Query\` (`Builder\MySQL`), or
    /// absolute with a leading `\` (`\ArrayObject`).
    fn class(&self) -> String;
    /// Whether the object is a value (a query, a statement, an AST node, an
    /// error): compared by its state and numbered anew each time it appears.
    /// Other objects (builders, schemas) keep one number.
    fn is_value(&self) -> bool {
        false
    }
    /// A value's public properties, in PHP's declaration order.
    fn props(&self) -> Vec<(&'static str, Ret)> {
        Vec::new()
    }
    /// Calls `method`; `None` when the object has no such method.
    fn call(&mut self, method: &str, args: &mut Args) -> Option<Outcome>;
    /// Reads property `property` (a value's public property by default).
    fn get(&self, property: &str) -> Option<Ret> {
        self.props().into_iter().find(|(name, _)| *name == property).map(|(_, v)| v)
    }
    /// The parent classes, relative like [`Object::class`].
    fn parents(&self) -> &'static [&'static str] {
        &[]
    }
    /// The interfaces the PHP class implements, relative, in any order.
    fn interfaces(&self) -> &'static [&'static str] {
        &[]
    }
    /// A copy (`clone $object`).
    fn clone_object(&self) -> Option<Box<dyn Object>>;
    /// The object as [`Any`].
    fn as_any(&self) -> &dyn Any;
    /// The object as mutable [`Any`].
    fn as_any_mut(&mut self) -> &mut dyn Any;
}

/// What a call returns.
pub enum Ret {
    /// A PHP value (nested queries are value objects, `NullsPosition` an enum).
    Value(Value),
    /// An object: a value object, or a new entity.
    Obj(Box<dyn Object>),
    /// The receiver (`return $this`).
    This,
    /// An entity already numbered.
    Existing(i64),
    /// An enum case: class (relative) and case name.
    Enum(String, &'static str),
    /// A PHP list of results.
    List(Vec<Ret>),
    /// A PHP associative array of results, in order.
    Map(Vec<(ArrayKey, Ret)>),
    /// A test closure passed back.
    Fn(i64),
    /// A test object passed back.
    Impl(i64),
}

impl Ret {
    /// A string.
    pub fn str(s: impl AsRef<[u8]>) -> Ret {
        Ret::Value(Value::Str(Str::copy_from(s.as_ref())))
    }

    /// An optional string (`?string`).
    pub fn opt_str(s: Option<impl AsRef<[u8]>>) -> Ret {
        match s {
            Some(s) => Ret::str(s),
            None => Ret::Value(Value::Null),
        }
    }

    /// An integer.
    pub fn int(i: i64) -> Ret {
        Ret::Value(Value::Int(i))
    }

    /// A boolean.
    pub fn bool(b: bool) -> Ret {
        Ret::Value(Value::Bool(b))
    }

    /// `null`.
    pub fn null() -> Ret {
        Ret::Value(Value::Null)
    }

    /// A list of strings.
    pub fn strings<S: AsRef<[u8]>>(items: impl IntoIterator<Item = S>) -> Ret {
        Ret::List(items.into_iter().map(Ret::str).collect())
    }

    /// An object.
    pub fn obj(object: impl Object) -> Ret {
        Ret::Obj(Box::new(object))
    }

    /// An optional object.
    pub fn opt_obj<T: Object>(object: Option<T>) -> Ret {
        match object {
            Some(o) => Ret::obj(o),
            None => Ret::null(),
        }
    }

    /// A list of PHP values.
    pub fn values(values: &[Value]) -> Ret {
        Ret::List(values.iter().cloned().map(Ret::Value).collect())
    }
}

/// Why a call failed.
pub enum Failure {
    /// The library's error.
    Lib(Error),
    /// An engine error PHP would raise (`TypeError`, `Error`): class and message.
    Engine(String, String),
    /// A harness problem: stops the program.
    Fault(String),
}

impl From<Error> for Failure {
    fn from(e: Error) -> Self {
        Failure::Lib(e)
    }
}

impl From<Fault> for Failure {
    fn from(f: Fault) -> Self {
        Failure::Fault(f.0)
    }
}

/// The result of a call.
pub type Outcome = Result<Ret, Failure>;

/// A harness problem.
pub fn fault(message: impl Into<String>) -> Failure {
    Failure::Fault(message.into())
}

type Slot = Arc<Mutex<Box<dyn Object>>>;

struct Queue {
    list: Vec<Json>,
    pos: usize,
}

#[derive(Default)]
struct State {
    slots: Vec<Slot>,
    /// Objects being called: data address and number.
    locked: Vec<(usize, i64)>,
    out: Vec<Json>,
    queues: Vec<Queue>,
    declared: HashMap<i64, Vec<String>>,
}

/// The engine of one program run.
pub struct Engine {
    state: Mutex<State>,
}

/// A handle on the running program, for callbacks the library keeps.
#[derive(Clone)]
pub struct Cx(Arc<Engine>);

/// An object the library lends to a callback (`&mut` the builder in
/// `when()`): events of the callback on its number act on it.
pub struct Lent<'a> {
    id: i64,
    object: &'a mut dyn Object,
}

fn data_address(object: &dyn Object) -> usize {
    object as *const dyn Object as *const () as usize
}

/// Runs `program` with the test objects `impls` declares; returns the outputs.
pub fn run(program: &[Json], impls: &Json) -> Result<Json, Fault> {
    let cx = Cx(Arc::new(Engine { state: Mutex::new(State::default()) }));
    if let Some(map) = impls.as_object() {
        let mut state = cx.state();
        for (k, v) in map {
            let interfaces = v.as_array().map(|a| a.iter().filter_map(|s| s.as_str().map(str::to_owned)).collect());
            state.declared.insert(k.parse().unwrap_or(0), interfaces.unwrap_or_default());
        }
    }
    let mut lent = None;
    for event in program {
        cx.event(event, &mut lent)?;
    }
    let out = std::mem::take(&mut cx.state().out);
    Ok(Json::Array(out))
}

impl Cx {
    fn state(&self) -> std::sync::MutexGuard<'_, State> {
        self.0.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn push(&self, output: Json) {
        self.state().out.push(output);
    }

    fn event(&self, event: &Json, lent: &mut Option<Lent<'_>>) -> Result<(), Fault> {
        let cb = event.get("cb").and_then(Json::as_array).cloned().unwrap_or_default();
        self.state().queues.push(Queue { list: cb, pos: 0 });
        let output = match self.exec(event, lent) {
            Ok((ret, receiver)) => self.export(ret, receiver),
            Err(Failure::Lib(e)) => Ok(self.error_output(&e)),
            Err(Failure::Engine(class, message)) => Ok(self.engine_error(&class, &message)),
            Err(Failure::Fault(f)) => Err(Fault::new(f)),
        };
        let queue = self.state().queues.pop();
        let output = output?;
        let mut state = self.state();
        if let Some(queue) = queue
            && queue.list.len() > queue.pos
        {
            state.out.push(json!({"$cb_missing": queue.list.len() - queue.pos}));
        }
        state.out.push(output);
        Ok(())
    }

    /// The output of a library error, numbering the error object.
    fn error_output(&self, error: &Error) -> Json {
        let id = self.register(Box::new(ErrorObject::from_error(error)));
        json!({"$error": {"class": error.php_class(), "message": error.to_string()}, "id": id})
    }

    fn engine_error(&self, class: &str, message: &str) -> Json {
        let id = self.register(Box::new(ErrorObject {
            class: class.to_owned(),
            message: message.to_owned(),
            code: 0,
            previous: None,
        }));
        json!({"$error": {"class": class, "message": message}, "id": id})
    }

    fn register(&self, object: Box<dyn Object>) -> i64 {
        let mut state = self.state();
        state.slots.push(Arc::new(Mutex::new(object)));
        state.slots.len() as i64
    }

    fn slot(&self, id: i64) -> Result<Slot, Failure> {
        let state = self.state();
        usize::try_from(id - 1)
            .ok()
            .and_then(|i| state.slots.get(i))
            .cloned()
            .ok_or_else(|| fault(format!("unknown object {id}")))
    }

    /// Runs `f` on object `id` (or on the lent object with that number).
    fn with_object<R>(
        &self,
        id: i64,
        lent: &mut Option<Lent<'_>>,
        f: impl FnOnce(&mut dyn Object) -> R,
    ) -> Result<R, Failure> {
        if let Some(l) = lent.as_mut()
            && l.id == id
        {
            return Ok(f(&mut *l.object));
        }
        let slot = self.slot(id)?;
        let mut guard = match slot.try_lock() {
            Ok(g) => g,
            Err(TryLockError::Poisoned(p)) => p.into_inner(),
            Err(TryLockError::WouldBlock) => return Err(fault(format!("object {id} is in use (re-entrant call)"))),
        };
        let address = data_address(&**guard);
        self.state().locked.push((address, id));
        let result = f(&mut **guard);
        let mut state = self.state();
        if let Some(i) = state.locked.iter().rposition(|(a, i)| *a == address && *i == id) {
            state.locked.remove(i);
        }
        Ok(result)
    }

    fn exec(&self, event: &Json, lent: &mut Option<Lent<'_>>) -> Result<(Ret, Option<i64>), Failure> {
        let raw_args = event.get("args").cloned().unwrap_or(Json::Array(Vec::new()));
        if let Some(class) = event.get("new").and_then(Json::as_str) {
            let mut args = Args::new(self, &raw_args);
            let ret = super::construct(class, &mut args)
                .unwrap_or_else(|| Err(fault(format!("no constructor for {class}"))))?;
            return Ok((ret, None));
        }
        if let Some(method) = event.get("call").and_then(Json::as_str) {
            let on = event.get("on").cloned().unwrap_or(Json::Null);
            if let Some(case) = on.get("$enum").and_then(Json::as_str) {
                let mut args = Args::new(self, &raw_args);
                let (class, name) = split_enum(case)?;
                let ret = enum_call(&class, name, method, &mut args)?;
                return Ok((ret, None));
            }
            let id = object_id(&on)?;
            let mut args = Args::new(self, &raw_args);
            let ret = self.with_object(id, lent, |object| {
                let class = object.class();
                object.call(method, &mut args).unwrap_or_else(|| Err(fault(format!("{class} has no method {method}"))))
            })??;
            return Ok((ret, Some(id)));
        }
        if let Some(method) = event.get("static").and_then(Json::as_str) {
            let class = event.get("class").and_then(Json::as_str).unwrap_or_default();
            let mut args = Args::new(self, &raw_args);
            let ret = super::call_static(class, method, &mut args)
                .unwrap_or_else(|| Err(fault(format!("no static method {class}::{method}"))))?;
            return Ok((ret, None));
        }
        if let Some(property) = event.get("get").and_then(Json::as_str) {
            let on = event.get("on").cloned().unwrap_or(Json::Null);
            if let Some(case) = on.get("$enum").and_then(Json::as_str) {
                let (class, name) = split_enum(case)?;
                return Ok((enum_get(&class, name, property)?, None));
            }
            let id = object_id(&on)?;
            let ret = self.with_object(id, lent, |object| {
                let class = object.class();
                object.get(property).ok_or_else(|| fault(format!("{class} has no property {property}")))
            })??;
            return Ok((ret, Some(id)));
        }
        if let Some(name) = event.get("const").and_then(Json::as_str) {
            let class = event.get("class").and_then(Json::as_str).unwrap_or_default();
            let ret = super::constant(class, name).ok_or_else(|| fault(format!("no constant {class}::{name}")))?;
            return Ok((ret, None));
        }
        if let Some(class) = event.get("is").and_then(Json::as_str) {
            let on = event.get("on").cloned().unwrap_or(Json::Null);
            let id = object_id(&on)?;
            let is = self.with_object(id, lent, |object| {
                object.class() == class || object.parents().contains(&class) || object.interfaces().contains(&class)
            })?;
            return Ok((Ret::bool(is), None));
        }
        if let Some(on) = event.get("implements") {
            let id = object_id(on)?;
            let mut interfaces = self.with_object(id, lent, |object| object.interfaces().to_vec())?;
            interfaces.sort_by_key(|i| Replay::absolute(i));
            interfaces.dedup();
            return Ok((Ret::strings(interfaces), None));
        }
        if let Some(pair) = event.get("same").and_then(Json::as_array) {
            let a = object_id(pair.first().unwrap_or(&Json::Null))?;
            let b = object_id(pair.get(1).unwrap_or(&Json::Null))?;
            let shape_a = self.with_object(a, lent, |o| (o.is_value(), shape_object(o)))?;
            let shape_b =
                if a == b { shape_a.clone() } else { self.with_object(b, lent, |o| (o.is_value(), shape_object(o)))? };
            let same = if shape_a.0 && shape_b.0 { shape_a.1 == shape_b.1 } else { a == b };
            return Ok((Ret::bool(same), None));
        }
        if let Some(on) = event.get("clone") {
            let id = object_id(on)?;
            let copy = self.with_object(id, lent, |object| object.clone_object())?;
            return Ok((Ret::Obj(copy.ok_or_else(|| fault("object cannot be cloned"))?), None));
        }
        if let Some(on) = event.get("class") {
            let id = object_id(on)?;
            let class = self.with_object(id, lent, |object| object.class())?;
            return Ok((Ret::str(class), None));
        }
        Err(fault(format!("unknown event: {event}")))
    }

    /// Exports a result, numbering the objects in it.
    fn export(&self, ret: Ret, receiver: Option<i64>) -> Result<Json, Fault> {
        Ok(match ret {
            Ret::Value(v) => self.export_value(&v),
            Ret::Obj(object) => self.export_object(object),
            Ret::This => {
                let id = receiver.ok_or_else(|| Fault::new("`this` without a receiver"))?;
                let slot = self.slot(id).map_err(|_| Fault::new("unknown receiver"))?;
                let copy = {
                    let guard = slot.lock().unwrap_or_else(|e| e.into_inner());
                    if guard.is_value() { guard.clone_object() } else { None }
                };
                match copy {
                    Some(copy) => self.export_object(copy),
                    None => {
                        let class = slot.lock().unwrap_or_else(|e| e.into_inner()).class();
                        json!({"$object": class, "id": id})
                    }
                }
            }
            Ret::Existing(id) => {
                let slot = self.slot(id).map_err(|_| Fault::new("unknown object"))?;
                let class = slot.lock().unwrap_or_else(|e| e.into_inner()).class();
                json!({"$object": class, "id": id})
            }
            Ret::Enum(class, case) => json!({"$enum": format!("{class}::{case}")}),
            Ret::List(items) => {
                let mut out = Vec::with_capacity(items.len());
                for item in items {
                    out.push(self.export(item, receiver)?);
                }
                Json::Array(out)
            }
            Ret::Map(entries) => {
                let list = entries.iter().enumerate().all(|(i, (k, _))| *k == ArrayKey::Int(i as i64));
                if list {
                    let mut out = Vec::with_capacity(entries.len());
                    for (_, item) in entries {
                        out.push(self.export(item, receiver)?);
                    }
                    Json::Array(out)
                } else {
                    let mut out = Map::new();
                    for (k, item) in entries {
                        out.insert(key_string(&k), self.export(item, receiver)?);
                    }
                    Json::Object(out)
                }
            }
            Ret::Fn(k) => json!({"$fn": k}),
            Ret::Impl(k) => json!({"$impl": k}),
        })
    }

    fn export_object(&self, object: Box<dyn Object>) -> Json {
        let class = object.class();
        if object.is_value() {
            let props = object.props();
            let id = self.register(object);
            let mut out = Map::new();
            for (name, value) in props {
                out.insert(name.to_owned(), self.export(value, None).unwrap_or(Json::Null));
            }
            json!({"$value": class, "id": id, "props": Json::Object(out)})
        } else {
            let id = self.register(object);
            json!({"$object": class, "id": id})
        }
    }

    /// Exports a PHP value; nested queries are value objects.
    pub fn export_value(&self, value: &Value) -> Json {
        match value {
            Value::Null => Json::Null,
            Value::Bool(b) => Json::Bool(*b),
            Value::Int(i) => json!(i),
            Value::Float(f) => float_value(*f),
            Value::Str(s) => bytes_value(s.as_bytes()),
            Value::Array(a) => self.export_array(a),
            Value::Object(a) => {
                let mut out = Map::new();
                for (k, v) in a.iter() {
                    out.insert(key_string(&k.to_owned()), self.export_value(v));
                }
                json!({"$std": Json::Object(out)})
            }
            Value::Ext(Ext::Query(q)) => self.export_object(Box::new((**q).clone())),
            Value::Ext(Ext::Nulls(n)) => json!({"$enum": format!("NullsPosition::{}", n.name())}),
            Value::Ext(Ext::Row { class, fields }) => {
                self.export_object(Box::new(RowObject { class: class.clone(), fields: fields.clone() }))
            }
        }
    }

    fn export_array(&self, a: &Array) -> Json {
        if a.is_list() {
            Json::Array(a.values().map(|v| self.export_value(v)).collect())
        } else {
            let mut out = Map::new();
            for (k, v) in a.iter() {
                out.insert(key_string(&k.to_owned()), self.export_value(v));
            }
            Json::Object(out)
        }
    }

    /// The library calling back into the test: the next invocation the
    /// current event expects, its events run, its return value (as JSON of
    /// the program, to decode with [`Cx::decoded`]).
    pub fn invoke(
        &self,
        callee: &str,
        args: Vec<Ret>,
        lent: Option<(usize, &mut dyn Object)>,
    ) -> Result<Json, Failure> {
        // Export the arguments; a lent object keeps its number (or gets one).
        let mut exported = Vec::with_capacity(args.len());
        let mut lent_id = None;
        let lent_index = lent.as_ref().map(|(i, _)| *i);
        let lent_object = lent.map(|(_, o)| o);
        for (index, arg) in args.into_iter().enumerate() {
            if Some(index) == lent_index
                && let Some(object) = lent_object.as_deref()
            {
                let address = data_address(object);
                let known = self.state().locked.iter().rev().find(|(a, _)| *a == address).map(|(_, id)| *id);
                let id = match known {
                    Some(id) => id,
                    None => match object.clone_object() {
                        Some(copy) => self.register(copy),
                        None => return Err(fault("lent object cannot be copied")),
                    },
                };
                lent_id = Some(id);
                exported.push(if object.is_value() {
                    json!({"$value": object.class(), "id": id, "props": {}})
                } else {
                    json!({"$object": object.class(), "id": id})
                });
                continue;
            }
            exported.push(self.export(arg, None)?);
        }
        let record = {
            let mut state = self.state();
            state.out.push(json!({"$cb": callee, "args": exported}));
            let top = state.queues.last_mut();
            match top {
                Some(queue) => {
                    let record = queue.list.get(queue.pos).cloned();
                    match record {
                        Some(record) if callee_of(&record) == callee => {
                            queue.pos += 1;
                            Some(record)
                        }
                        _ => None,
                    }
                }
                None => None,
            }
        };
        let Some(record) = record else {
            self.push(json!({"$cb_unexpected": callee}));
            return Ok(Json::Null);
        };
        let mut nested = match (lent_id, lent_object) {
            (Some(id), Some(object)) => Some(Lent { id, object }),
            _ => None,
        };
        if let Some(events) = record.get("do").and_then(Json::as_array) {
            for event in events {
                self.event(event, &mut nested)?;
            }
        }
        Ok(record.get("ret").cloned().unwrap_or(Json::Null))
    }

    /// A program value (a callback's return value) as arguments to decode.
    pub fn decoded<'a>(&'a self, value: &'a Json) -> Args<'a> {
        Args { cx: self, positional: vec![value], named: Vec::new() }
    }

    /// The interfaces a test object was declared with.
    pub fn declared(&self, k: i64) -> Vec<String> {
        self.state().declared.get(&k).cloned().unwrap_or_default()
    }
}

fn callee_of(record: &Json) -> String {
    match record.get("fn") {
        Some(k) => format!("fn:{k}"),
        None => format!(
            "impl:{}:{}",
            record.get("impl").cloned().unwrap_or(Json::Null),
            record.get("m").and_then(Json::as_str).unwrap_or_default()
        ),
    }
}

fn key_string(k: &ArrayKey) -> String {
    match k {
        ArrayKey::Int(i) => i.to_string(),
        ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
    }
}

fn object_id(value: &Json) -> Result<i64, Failure> {
    value.get("$o").and_then(Json::as_i64).ok_or_else(|| fault(format!("expected an object, got {value}")))
}

fn split_enum(case: &str) -> Result<(String, &str), Failure> {
    let (class, name) = case.rsplit_once("::").ok_or_else(|| fault(format!("bad enum {case}")))?;
    Ok((class.to_owned(), name))
}

fn enum_call(class: &str, case: &str, method: &str, args: &mut Args) -> Outcome {
    super::core::enum_call(class, case, method, args)
        .or_else(|| super::builder::enum_call(class, case, method, args))
        .or_else(|| super::schema::enum_call(class, case, method, args))
        .or_else(|| super::ast::enum_call(class, case, method, args))
        .or_else(|| super::tokenizer::enum_call(class, case, method, args))
        .or_else(|| super::classifier::enum_call(class, case, method, args))
        .unwrap_or_else(|| Err(fault(format!("no method {class}::{case}->{method}"))))
}

fn enum_get(class: &str, case: &str, property: &str) -> Outcome {
    if property == "name" {
        return Ok(Ret::str(case));
    }
    enum_call(class, case, &format!("->{property}"), &mut Args::empty())
}

/// The exported form of an object without numbers, to compare values.
fn shape_object(object: &dyn Object) -> Json {
    let cx = Cx(Arc::new(Engine { state: Mutex::new(State::default()) }));
    let copy = object.clone_object();
    match copy {
        Some(copy) if object.is_value() => strip_ids(cx.export_object(copy)),
        _ => json!({"$object": object.class()}),
    }
}

fn strip_ids(value: Json) -> Json {
    match value {
        Json::Object(map) => {
            Json::Object(map.into_iter().filter(|(k, _)| k != "id").map(|(k, v)| (k, strip_ids(v))).collect())
        }
        Json::Array(items) => Json::Array(items.into_iter().map(strip_ids).collect()),
        other => other,
    }
}

/// Names of classes as the PHP replay sorts `class_implements()`.
struct Replay;

impl Replay {
    fn absolute(relative: &str) -> String {
        match relative.strip_prefix('\\') {
            Some(abs) => abs.to_owned(),
            None => format!("Utopia\\Query\\{relative}"),
        }
    }
}

/// The arguments of a call: positional and named, as the program has them.
pub struct Args<'a> {
    cx: &'a Cx,
    positional: Vec<&'a Json>,
    named: Vec<(&'a str, &'a Json)>,
}

static NULL: Json = Json::Null;

impl<'a> Args<'a> {
    fn new(cx: &'a Cx, raw: &'a Json) -> Self {
        let mut positional = Vec::new();
        let mut named = Vec::new();
        match raw {
            Json::Array(items) => positional.extend(items.iter()),
            Json::Object(map) => {
                // A `$map` of pairs: named arguments mixed with positions.
                if let Some(pairs) = map.get("$map").and_then(Json::as_array) {
                    for pair in pairs {
                        match (pair.get(0), pair.get(1)) {
                            (Some(Json::String(k)), Some(v)) => named.push((k.as_str(), v)),
                            (Some(_), Some(v)) => positional.push(v),
                            _ => {}
                        }
                    }
                } else {
                    for (k, v) in map {
                        if k.parse::<usize>().is_ok() {
                            positional.push(v);
                        } else {
                            named.push((k.as_str(), v));
                        }
                    }
                }
            }
            _ => {}
        }
        Args { cx, positional, named }
    }

    /// No arguments.
    pub fn empty() -> Args<'static> {
        Args {
            cx: EMPTY_CX_STATIC.get_or_init(|| Cx(Arc::new(Engine { state: Mutex::new(State::default()) }))),
            positional: Vec::new(),
            named: Vec::new(),
        }
    }

    /// The engine.
    pub fn cx(&self) -> &Cx {
        self.cx
    }

    /// How many arguments were given (positional and named).
    pub fn len(&self) -> usize {
        self.positional.len() + self.named.len()
    }

    /// Whether no argument was given.
    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }

    /// Argument `index`, or the named argument `name`; `None` when not given.
    pub fn raw(&self, index: usize, name: &str) -> Option<&'a Json> {
        self.named.iter().find(|(n, _)| *n == name).map(|(_, v)| *v).or_else(|| self.positional.get(index).copied())
    }

    /// Whether argument `index` / `name` was given.
    pub fn has(&self, index: usize, name: &str) -> bool {
        self.raw(index, name).is_some()
    }

    /// The positional arguments from `index` on (a variadic parameter).
    pub fn rest(&self, index: usize) -> Vec<&'a Json> {
        self.positional.iter().skip(index).copied().collect()
    }

    fn type_error(&self, name: &str, expected: &str, got: &Json) -> Failure {
        Failure::Engine(
            "TypeError".into(),
            format!("argument ${name} must be of type {expected}, {} given", json_type(got)),
        )
    }

    /// A string argument (PHP coerces scalars to strings).
    pub fn str(&self, index: usize, name: &str) -> Result<String, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        self.string_of(raw, name)
    }

    fn string_of(&self, raw: &Json, name: &str) -> Result<String, Failure> {
        match raw {
            Json::String(s) => Ok(s.clone()),
            Json::Number(n) => Ok(match n.as_i64() {
                Some(i) => i.to_string(),
                None => php_std::number::to_string(n.as_f64().unwrap_or(0.0)),
            }),
            Json::Bool(b) => Ok(if *b { "1".into() } else { String::new() }),
            Json::Object(o) if o.contains_key("$bytes") => {
                let bytes = crate::adapter::bytes(raw).unwrap_or_default();
                Ok(String::from_utf8_lossy(&bytes).into_owned())
            }
            other => Err(self.type_error(name, "string", other)),
        }
    }

    /// A string argument, or `default` when not given.
    pub fn str_or(&self, index: usize, name: &str, default: &str) -> Result<String, Failure> {
        match self.raw(index, name) {
            None => Ok(default.to_owned()),
            Some(raw) => self.string_of(raw, name),
        }
    }

    /// A `?string` argument (`None` for null or not given).
    pub fn opt_str(&self, index: usize, name: &str) -> Result<Option<String>, Failure> {
        match self.raw(index, name) {
            None | Some(Json::Null) => Ok(None),
            Some(raw) => self.string_of(raw, name).map(Some),
        }
    }

    /// An `int` argument.
    pub fn int(&self, index: usize, name: &str) -> Result<i64, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        self.int_of(raw, name)
    }

    fn int_of(&self, raw: &Json, name: &str) -> Result<i64, Failure> {
        match raw {
            Json::Number(n) => match n.as_i64() {
                Some(i) => Ok(i),
                None => Err(self.type_error(name, "int", raw)),
            },
            Json::Bool(b) => Ok(*b as i64),
            other => Err(self.type_error(name, "int", other)),
        }
    }

    /// An `int` argument, or `default`.
    pub fn int_or(&self, index: usize, name: &str, default: i64) -> Result<i64, Failure> {
        match self.raw(index, name) {
            None => Ok(default),
            Some(raw) => self.int_of(raw, name),
        }
    }

    /// A `?int` argument.
    pub fn opt_int(&self, index: usize, name: &str) -> Result<Option<i64>, Failure> {
        match self.raw(index, name) {
            None | Some(Json::Null) => Ok(None),
            Some(raw) => self.int_of(raw, name).map(Some),
        }
    }

    /// A `float` argument (integers widen).
    pub fn float(&self, index: usize, name: &str) -> Result<f64, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        crate::adapter::float(raw).ok_or_else(|| self.type_error(name, "float", raw))
    }

    /// A `float` argument, or `default`.
    pub fn float_or(&self, index: usize, name: &str, default: f64) -> Result<f64, Failure> {
        match self.raw(index, name) {
            None => Ok(default),
            Some(raw) => crate::adapter::float(raw).ok_or_else(|| self.type_error(name, "float", raw)),
        }
    }

    /// A `bool` argument (PHP coerces scalars).
    pub fn bool(&self, index: usize, name: &str) -> Result<bool, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        self.bool_of(raw, name)
    }

    fn bool_of(&self, raw: &Json, name: &str) -> Result<bool, Failure> {
        match raw {
            Json::Bool(b) => Ok(*b),
            Json::Number(n) => Ok(n.as_f64().unwrap_or(0.0) != 0.0),
            Json::String(s) => Ok(!(s.is_empty() || s == "0")),
            other => Err(self.type_error(name, "bool", other)),
        }
    }

    /// A `bool` argument, or `default`.
    pub fn bool_or(&self, index: usize, name: &str, default: bool) -> Result<bool, Failure> {
        match self.raw(index, name) {
            None => Ok(default),
            Some(raw) => self.bool_of(raw, name),
        }
    }

    /// A `mixed` argument as a PHP value (`null` when not given).
    pub fn value(&self, index: usize, name: &str) -> Result<Value, Failure> {
        match self.raw(index, name) {
            None => Ok(Value::Null),
            Some(raw) => self.php(raw),
        }
    }

    /// An `array` argument.
    pub fn array(&self, index: usize, name: &str) -> Result<Array, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        match self.php(raw)? {
            Value::Array(a) => Ok(a),
            _ => Err(self.type_error(name, "array", raw)),
        }
    }

    /// An `array` argument, or empty when not given.
    pub fn array_or_empty(&self, index: usize, name: &str) -> Result<Array, Failure> {
        if self.has(index, name) { self.array(index, name) } else { Ok(Array::default()) }
    }

    /// A `?array` argument.
    pub fn opt_array(&self, index: usize, name: &str) -> Result<Option<Array>, Failure> {
        match self.raw(index, name) {
            None | Some(Json::Null) => Ok(None),
            Some(_) => self.array(index, name).map(Some),
        }
    }

    /// An `array` of strings argument.
    pub fn strings(&self, index: usize, name: &str) -> Result<Vec<String>, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        self.strings_of(raw, name)
    }

    fn strings_of(&self, raw: &Json, name: &str) -> Result<Vec<String>, Failure> {
        match raw {
            Json::Array(items) => items.iter().map(|i| self.string_of(i, name)).collect(),
            Json::Object(map) if !map.contains_key("$map") => map.values().map(|i| self.string_of(i, name)).collect(),
            Json::Object(map) => map
                .get("$map")
                .and_then(Json::as_array)
                .map(|pairs| pairs.iter().map(|p| self.string_of(p.get(1).unwrap_or(&NULL), name)).collect())
                .unwrap_or(Ok(Vec::new())),
            other => Err(self.type_error(name, "array", other)),
        }
    }

    /// A `?array` of strings argument.
    pub fn opt_strings(&self, index: usize, name: &str) -> Result<Option<Vec<String>>, Failure> {
        match self.raw(index, name) {
            None | Some(Json::Null) => Ok(None),
            Some(raw) => self.strings_of(raw, name).map(Some),
        }
    }

    /// An `array` of strings argument, or empty when not given.
    pub fn strings_or_empty(&self, index: usize, name: &str) -> Result<Vec<String>, Failure> {
        match self.raw(index, name) {
            None => Ok(Vec::new()),
            Some(raw) => self.strings_of(raw, name),
        }
    }

    /// The list items of an `array` argument (each a program value).
    pub fn items(&self, index: usize, name: &str) -> Result<Vec<&'a Json>, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        Ok(match raw {
            Json::Array(items) => items.iter().collect(),
            Json::Object(map) => match map.get("$map").and_then(Json::as_array) {
                Some(pairs) => pairs.iter().filter_map(|p| p.get(1)).collect(),
                None => map.values().collect(),
            },
            _ => return Err(self.type_error(name, "array", raw)),
        })
    }

    /// The keyed items of an `array` argument.
    pub fn entries(&self, index: usize, name: &str) -> Result<Vec<(ArrayKey, &'a Json)>, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        Ok(match raw {
            Json::Array(items) => items.iter().enumerate().map(|(i, v)| (ArrayKey::Int(i as i64), v)).collect(),
            Json::Object(map) => match map.get("$map").and_then(Json::as_array) {
                Some(pairs) => pairs.iter().filter_map(|p| Some((json_key(p.get(0)?), p.get(1)?))).collect(),
                None => map.iter().map(|(k, v)| (ArrayKey::normalize(Str::from(k.as_str())), v)).collect(),
            },
            _ => return Err(self.type_error(name, "array", raw)),
        })
    }

    /// A `Query` argument.
    pub fn query(&self, index: usize, name: &str) -> Result<Query, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        self.query_of(raw)
    }

    /// A program value that must be a query.
    pub fn query_of(&self, raw: &Json) -> Result<Query, Failure> {
        let id = object_id(raw)?;
        let slot = self.cx.slot(id)?;
        let guard = slot.lock().unwrap_or_else(|e| e.into_inner());
        guard.as_any().downcast_ref::<Query>().cloned().ok_or_else(|| fault(format!("object {id} is not a query")))
    }

    /// An `array` of `Query` argument.
    pub fn queries(&self, index: usize, name: &str) -> Result<Vec<Query>, Failure> {
        self.items(index, name)?.into_iter().map(|raw| self.query_of(raw)).collect()
    }

    /// The object number of argument `index` / `name`.
    pub fn object_id(&self, index: usize, name: &str) -> Result<i64, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        object_id(raw)
    }

    /// Runs `f` on the object argument `index` / `name` (locked meanwhile).
    pub fn with<R>(&self, index: usize, name: &str, f: impl FnOnce(&mut dyn Object) -> R) -> Result<R, Failure> {
        let id = self.object_id(index, name)?;
        self.with_id(id, f)
    }

    /// Runs `f` on object `id`.
    pub fn with_id<R>(&self, id: i64, f: impl FnOnce(&mut dyn Object) -> R) -> Result<R, Failure> {
        let slot = self.cx.slot(id)?;
        let mut guard = match slot.try_lock() {
            Ok(g) => g,
            Err(TryLockError::Poisoned(p)) => p.into_inner(),
            Err(TryLockError::WouldBlock) => return Err(fault(format!("object {id} is in use"))),
        };
        Ok(f(&mut **guard))
    }

    /// A copy of the object argument `index` / `name`, of type `T`.
    pub fn object<T: Clone + 'static>(&self, index: usize, name: &str) -> Result<T, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        self.object_of(raw)
    }

    /// A copy of a program value that must be an object of type `T`.
    pub fn object_of<T: Clone + 'static>(&self, raw: &Json) -> Result<T, Failure> {
        let id = object_id(raw)?;
        self.with_id(id, |o| o.as_any().downcast_ref::<T>().cloned())?
            .ok_or_else(|| fault(format!("object {id} is not a {}", std::any::type_name::<T>())))
    }

    /// An optional object argument of type `T` (`None` for null or not given).
    pub fn opt_object<T: Clone + 'static>(&self, index: usize, name: &str) -> Result<Option<T>, Failure> {
        match self.raw(index, name) {
            None | Some(Json::Null) => Ok(None),
            Some(raw) => self.object_of(raw).map(Some),
        }
    }

    /// A test closure argument.
    pub fn closure(&self, index: usize, name: &str) -> Result<Callback, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        let k = raw.get("$fn").and_then(Json::as_i64).ok_or_else(|| fault(format!("expected a closure, got {raw}")))?;
        Ok(Callback { cx: self.cx.clone(), callee: format!("fn:{k}") })
    }

    /// A test object argument: its number and declared interfaces.
    pub fn test_object(&self, index: usize, name: &str) -> Result<Option<(i64, Vec<String>)>, Failure> {
        let raw = self.raw(index, name).ok_or_else(|| fault(format!("missing argument ${name}")))?;
        Ok(raw.get("$impl").and_then(Json::as_i64).map(|k| (k, self.cx.declared(k))))
    }

    /// An enum argument `{"$enum": "Class::Case"}`: class (relative) and case.
    pub fn enum_case(&self, index: usize, name: &str) -> Result<Option<(String, String)>, Failure> {
        match self.raw(index, name) {
            None | Some(Json::Null) => Ok(None),
            Some(raw) => {
                let case = raw.get("$enum").and_then(Json::as_str).ok_or_else(|| self.type_error(name, "enum", raw))?;
                let (class, name) = split_enum(case)?;
                Ok(Some((class, name.to_owned())))
            }
        }
    }

    /// A program value as a PHP value: tagged objects decoded, queries as
    /// nested query values.
    pub fn php(&self, raw: &Json) -> Result<Value, Failure> {
        Ok(match raw {
            Json::Null => Value::Null,
            Json::Bool(b) => Value::Bool(*b),
            Json::Number(n) => match n.as_i64() {
                Some(i) if !n.is_f64() => Value::Int(i),
                _ => Value::Float(n.as_f64().unwrap_or(0.0)),
            },
            Json::String(s) => Value::Str(Str::from(s.as_str())),
            Json::Array(items) => {
                Value::Array(Array::from_list(items.iter().map(|i| self.php(i)).collect::<Result<Vec<_>, _>>()?))
            }
            Json::Object(map) => {
                if map.len() == 1 {
                    let (k, v) = map.iter().next().expect("one entry");
                    match k.as_str() {
                        "$bytes" => return Ok(Value::Str(Str::from(crate::adapter::bytes(raw).unwrap_or_default()))),
                        "$float" => return Ok(Value::Float(crate::adapter::float(raw).unwrap_or(0.0))),
                        "$o" => {
                            let id = v.as_i64().unwrap_or(0);
                            return self.with_id(id, |o| {
                                if let Some(q) = o.as_any().downcast_ref::<Query>() {
                                    Ok(Value::from(q.clone()))
                                } else if let Some(row) = o.as_any().downcast_ref::<RowObject>() {
                                    Ok(Value::Ext(Ext::Row { class: row.class.clone(), fields: row.fields.clone() }))
                                } else {
                                    Err(fault(format!("object {id} ({}) cannot be a value", o.class())))
                                }
                            })?;
                        }
                        "$enum" => {
                            let (class, case) = split_enum(v.as_str().unwrap_or_default())?;
                            if class == "NullsPosition" {
                                let n = utopia_query::NullsPosition::from_name(case)
                                    .ok_or_else(|| fault(format!("bad case {case}")))?;
                                return Ok(Value::from(n));
                            }
                            return Err(fault(format!("enum {class} cannot be a value")));
                        }
                        "$std" => {
                            let mut a = Array::default();
                            if let Some(props) = v.as_object() {
                                for (pk, pv) in props {
                                    a.set(ArrayKey::Str(Str::from(pk.as_str())), self.php(pv)?);
                                }
                            }
                            return Ok(Value::Object(a));
                        }
                        "$arrayobject" => {
                            let fields = match self.php(v)? {
                                Value::Array(a) => a,
                                _ => Array::default(),
                            };
                            return Ok(Value::Ext(Ext::Row { class: "ArrayObject".into(), fields }));
                        }
                        "$generator" => return self.php(v),
                        "$map" => {
                            let mut a = Array::default();
                            for pair in v.as_array().into_iter().flatten() {
                                let key = json_key(pair.get(0).unwrap_or(&NULL));
                                a.set(key, self.php(pair.get(1).unwrap_or(&NULL))?);
                            }
                            return Ok(Value::Array(a));
                        }
                        "$fn" | "$impl" | "$throwable" => return Err(fault(format!("{k} cannot be a value"))),
                        _ => {}
                    }
                }
                let mut a = Array::with_capacity(map.len());
                for (k, v) in map {
                    a.set(ArrayKey::normalize(Str::from(k.as_str())), self.php(v)?);
                }
                Value::Array(a)
            }
        })
    }
}

static EMPTY_CX_STATIC: std::sync::OnceLock<Cx> = std::sync::OnceLock::new();

fn json_key(k: &Json) -> ArrayKey {
    match k {
        Json::Number(n) => ArrayKey::Int(n.as_i64().unwrap_or(0)),
        Json::String(s) => ArrayKey::normalize(Str::from(s.as_str())),
        _ => ArrayKey::Int(0),
    }
}

fn json_type(v: &Json) -> &'static str {
    match v {
        Json::Null => "null",
        Json::Bool(_) => "bool",
        Json::Number(n) if n.is_f64() => "float",
        Json::Number(_) => "int",
        Json::String(_) => "string",
        Json::Array(_) => "array",
        Json::Object(_) => "object",
    }
}

/// A test closure the library may call.
#[derive(Clone)]
pub struct Callback {
    cx: Cx,
    callee: String,
}

impl Callback {
    /// A callback to a test object's method.
    pub fn method(cx: &Cx, k: i64, method: &str) -> Callback {
        Callback { cx: cx.clone(), callee: format!("impl:{k}:{method}") }
    }

    /// Invokes it with `args`; returns its return value to decode.
    pub fn invoke(&self, args: Vec<Ret>) -> Result<Json, Failure> {
        self.cx.invoke(&self.callee, args, None)
    }

    /// Invokes it lending `object` as argument `index` (the rest are `args`
    /// with a placeholder at `index`).
    pub fn invoke_lent(&self, args: Vec<Ret>, index: usize, object: &mut dyn Object) -> Result<Json, Failure> {
        self.cx.invoke(&self.callee, args, Some((index, object)))
    }

    /// The engine, to decode the return value.
    pub fn cx(&self) -> &Cx {
        &self.cx
    }
}

/// A library error as an object (what a `catch` block holds).
#[derive(Clone)]
pub struct ErrorObject {
    /// The PHP class.
    pub class: String,
    /// The message.
    pub message: String,
    /// The code.
    pub code: i64,
    /// The wrapped error: class, message, code.
    pub previous: Option<(String, String, i64)>,
}

impl ErrorObject {
    fn from_error(error: &Error) -> Self {
        ErrorObject {
            class: error.php_class().to_owned(),
            message: error.to_string(),
            code: error.code(),
            previous: error.previous().map(|p| match p.downcast_ref::<Foreign>() {
                Some(f) => (f.class.clone(), f.message.clone(), f.code),
                None => ("Exception".into(), p.to_string(), 0),
            }),
        }
    }
}

/// An exception of a class outside the library, passed by a test.
#[derive(Debug, Clone)]
pub struct Foreign {
    /// The PHP class.
    pub class: String,
    /// The message.
    pub message: String,
    /// The code.
    pub code: i64,
}

impl std::fmt::Display for Foreign {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for Foreign {}

impl Object for ErrorObject {
    fn class(&self) -> String {
        Replay::relative(&self.class)
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![("message", Ret::str(&self.message)), ("code", Ret::int(self.code))]
    }
    fn call(&mut self, method: &str, _args: &mut Args) -> Option<Outcome> {
        Some(Ok(match method {
            "getMessage" => Ret::str(&self.message),
            "getCode" => Ret::int(self.code),
            "getPrevious" => match &self.previous {
                Some((class, message, code)) => Ret::obj(ErrorObject {
                    class: class.clone(),
                    message: message.clone(),
                    code: *code,
                    previous: None,
                }),
                None => Ret::null(),
            },
            _ => return None,
        }))
    }
    fn parents(&self) -> &'static [&'static str] {
        match self.class.as_str() {
            "Utopia\\Query\\Exception\\ValidationException" | "Utopia\\Query\\Exception\\UnsupportedException" => {
                &["Exception", "\\Exception"]
            }
            "Utopia\\Query\\Exception" => &["\\Exception"],
            _ => &[],
        }
    }
    fn interfaces(&self) -> &'static [&'static str] {
        &["\\Throwable", "\\Stringable"]
    }
    fn clone_object(&self) -> Option<Box<dyn Object>> {
        Some(Box::new(self.clone()))
    }
    fn as_any(&self) -> &dyn Any {
        self
    }
    fn as_any_mut(&mut self) -> &mut dyn Any {
        self
    }
}

impl Replay {
    fn relative(class: &str) -> String {
        match class.strip_prefix("Utopia\\Query\\") {
            Some(rel) => rel.to_owned(),
            None => format!("\\{class}"),
        }
    }
}

/// An `ArrayObject` (or another `ArrayAccess` row) passed as a value.
#[derive(Clone)]
pub struct RowObject {
    /// The PHP class.
    pub class: String,
    /// The fields.
    pub fields: Array,
}

impl Object for RowObject {
    fn class(&self) -> String {
        format!("\\{}", self.class)
    }
    fn call(&mut self, _method: &str, _args: &mut Args) -> Option<Outcome> {
        None
    }
    fn clone_object(&self) -> Option<Box<dyn Object>> {
        Some(Box::new(self.clone()))
    }
    fn as_any(&self) -> &dyn Any {
        self
    }
    fn as_any_mut(&mut self) -> &mut dyn Any {
        self
    }
}

/// Decodes the `$throwable` of a program (an exception a test made).
pub fn foreign(raw: &Json) -> Option<Foreign> {
    let t = raw.get("$throwable")?;
    let class = t.get("class").and_then(Json::as_str).unwrap_or_default();
    Some(Foreign {
        class: class.trim_start_matches('\\').to_owned(),
        message: t.get("message").and_then(Json::as_str).unwrap_or_default().to_owned(),
        code: t.get("code").and_then(Json::as_i64).unwrap_or(0),
    })
}

/// Implements [`Object`]'s boilerplate (`clone_object`, `as_any`) for a `Clone` type.
#[macro_export]
macro_rules! query_object_boilerplate {
    () => {
        fn clone_object(&self) -> Option<Box<dyn $crate::libs::query::engine::Object>> {
            Some(Box::new(self.clone()))
        }
        fn as_any(&self) -> &dyn std::any::Any {
            self
        }
        fn as_any_mut(&mut self) -> &mut dyn std::any::Any {
            self
        }
    };
}
