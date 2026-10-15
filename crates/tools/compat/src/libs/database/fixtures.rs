//! The named fixtures the database cases refer to (`Fixtures.php`): global
//! filters, Structure formats, hooks and codecs, each logging what it is
//! given to `db.log`, the same on both runtimes.

use std::sync::Arc;

use async_trait::async_trait;
use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use serde_json::{Value as Json, json};
use utopia_database::collection::Collection;
use utopia_database::database::{Filter, Hook};
use utopia_database::document::{self, Document};
use utopia_database::event::{Domain, Event};
use utopia_database::filter::Codec;
use utopia_database::hook::relationships::Relationships;
use utopia_database::hook::{Decorator, Lifecycle, Named, Nested, Queries, Selective, Tenancy, Transform};
use utopia_database::query::Query;
use utopia_database::validator::structure::formats;
use utopia_database::value::{self, Array, ArrayKey, Str, Value};
use utopia_database::{Database, Error, Kind};
use utopia_query::schema::ColumnType;

use super::calls::Cb;
use super::codec;
use super::ctx::{R, fault, log};
use super::enums;
use super::obj::Obj;
use super::values;
use crate::php_object;

/// The PHP class whose static methods the global filter fixtures are: their
/// signatures (`Class::method`), which cache keys carry, name it.
const FIXTURES_PHP: &str = "Tests\\Compat\\Database\\FilterFixtures";

/// The global filter fixtures, by name, and the prefix of their methods.
const FILTERS: &[(&str, &str)] = &[
    ("encrypt", "encrypt"),
    ("encrypt_operator", "encryptOperator"),
    ("subQueryProbe", "subQueryProbe"),
    ("operator_double_decode", "operatorDoubleDecode"),
    ("joinedSeal", "joinedSeal"),
];

/// The fixtures every `db.open` registers, under their own names
/// (`FilterFixtures::CANONICAL`).
pub const CANONICAL: [&str; 4] = ["encrypt", "subQueryProbe", "operator_double_decode", "joinedSeal"];

/// The format fixtures (`Formats::get`).
const FORMATS: [&str; 8] = [
    "priceRange",
    "priceRangeNew",
    "email",
    "structureFormatProbe",
    "testformat",
    "updateAttributeIntegerOnly",
    "storedRange",
    "storedEnum",
];

/// Where `Core::callback` declares a callback's closure: the signature of
/// a global filter a case registers with callbacks (`file:line`).
pub const CALLBACK_SIGNATURE: &str = "/usr/src/code/tests/compat/database/ops/core.php:897";

/// A case starts with the process-wide registries as a fresh process has
/// them (`Core::fresh()`): no formats, the canonical filter fixtures
/// registered again.
pub fn fresh() {
    for name in FORMATS {
        formats::remove(name);
    }
    for name in super::objects::take_formats() {
        formats::remove(&name);
    }
    Database::reset_filters();
    register_canonical();
}

/// Registers the canonical global filter fixtures (`Core::filters()`).
pub fn register_canonical() {
    for name in CANONICAL {
        if let Ok(filter) = filter(name) {
            Database::add_filter(name, filter);
        }
    }
}

/// A global filter fixture by name.
pub fn filter(fixture: &str) -> R<Arc<dyn Filter>> {
    match FILTERS.iter().find(|(name, _)| *name == fixture) {
        Some((name, prefix)) => Ok(Arc::new(FixtureFilter { name: (*name).to_owned(), prefix })),
        None => fault(format!("no filter fixture `{fixture}`")),
    }
}

struct FixtureFilter {
    name: String,
    prefix: &'static str,
}

fn json_encode(value: &Value) -> Value {
    match php_std::json::encode(&value::to_plain(value), php_std::json::Flags::NONE, 512) {
        Ok(s) => Value::Str(Str::from(s)),
        Err(_) => Value::Bool(false),
    }
}

fn json_decode_assoc(value: &Value) -> Value {
    match value {
        Value::Str(s) => php_std::json::decode(s.as_bytes(), Some(true), 512, php_std::json::Flags::NONE)
            .map(value::from_plain)
            .unwrap_or(Value::Null),
        _ => Value::Null,
    }
}

fn pairs(items: &[(&str, Value)]) -> Value {
    let mut a = Array::new();
    for (k, v) in items {
        a.set(ArrayKey::from(*k), v.clone());
    }
    Value::Array(a)
}

fn b64(value: &Value) -> Value {
    Value::Str(Str::from(BASE64.encode(value::to_text(value).as_bytes())))
}

fn b64_bytes(value: &Value) -> Value {
    match value {
        Value::Str(s) => Value::Str(Str::from(BASE64.encode(s.as_bytes()))),
        other => b64(other),
    }
}

/// `base64_decode($text, true)`: `false` when not valid base64.
fn b64_decode(text: &Value, strict: bool) -> Value {
    let bytes = match text {
        Value::Str(s) => s.as_bytes().to_vec(),
        other => value::to_text(other).into_bytes(),
    };
    let cleaned: Vec<u8> =
        if strict { bytes } else { bytes.into_iter().filter(|b| !b.is_ascii_whitespace()).collect() };
    match BASE64.decode(&cleaned) {
        Ok(out) => Value::Str(Str::from(out)),
        Err(_) => Value::Bool(false),
    }
}

fn invalid(message: &str) -> Error {
    Error::new(Kind::InvalidArgument, message)
}

fn is_scalar_or_null(v: &Value) -> bool {
    matches!(v, Value::Null | Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_))
}

/// `$payload['data']` of a decoded payload, if it is a string.
fn data_of(payload: &Value) -> Option<Value> {
    match payload {
        Value::Array(a) => match a.get_str("data") {
            Some(v @ Value::Str(_)) => Some(v.clone()),
            _ => None,
        },
        _ => None,
    }
}

#[async_trait]
impl Filter for FixtureFilter {
    async fn encode(&self, value: Value, _document: &Document, _database: &Database) -> utopia_database::Result<Value> {
        log(json!({"filter": self.name, "call": "encode"}));
        Ok(match self.prefix {
            "encrypt" => {
                if !is_scalar_or_null(&value) {
                    return Err(invalid("Encrypted filter input must be scalar or null"));
                }
                json_encode(&pairs(&[
                    ("data", b64_bytes(&value)),
                    ("method", Value::Str(Str::from("base64"))),
                    ("version", Value::Str(Str::from("v1"))),
                ]))
            }
            "encryptOperator" => match &value {
                Value::Str(_) => json_encode(&pairs(&[("data", b64_bytes(&value))])),
                _ => Value::Null,
            },
            "subQueryProbe" => Value::Null,
            "operatorDoubleDecode" => {
                if !is_scalar_or_null(&value) {
                    return Err(invalid("Operator filter input must be scalar or null"));
                }
                json_encode(&pairs(&[("data", b64_bytes(&value))]))
            }
            "joinedSeal" => match &value {
                Value::Str(_) => {
                    json_encode(&pairs(&[("data", b64_bytes(&value)), ("method", Value::Str(Str::from("base64")))]))
                }
                _ => value,
            },
            _ => value,
        })
    }

    async fn decode(
        &self,
        value: Value,
        _document: &Document,
        _database: &Database,
        _attribute: &str,
    ) -> utopia_database::Result<Value> {
        log(json!({"filter": self.name, "call": "decode"}));
        Ok(match self.prefix {
            "encrypt" => {
                if matches!(value, Value::Null) {
                    return Ok(Value::Null);
                }
                if !matches!(value, Value::Str(_)) {
                    return Err(invalid("Encrypted filter value must be a string"));
                }
                let Some(data) = data_of(&json_decode_assoc(&value)) else {
                    return Err(invalid("Encrypted filter payload is invalid"));
                };
                match b64_decode(&data, true) {
                    Value::Bool(false) => return Err(invalid("Encrypted filter payload is not valid base64")),
                    v => v,
                }
            }
            "encryptOperator" => match data_of(&json_decode_assoc(&value)) {
                Some(data) => match b64_decode(&data, false) {
                    Value::Bool(false) => Value::Str(Str::from("")),
                    v => v,
                },
                None => Value::Null,
            },
            "subQueryProbe" => {
                Value::Array([Value::Str(Str::from("fanned")), Value::Str(Str::from("out"))].into_iter().collect())
            }
            "operatorDoubleDecode" => {
                if matches!(value, Value::Null) {
                    return Ok(Value::Null);
                }
                if !matches!(value, Value::Str(_)) {
                    return Err(invalid("Encoded operator filter value must be a string"));
                }
                let Some(data) = data_of(&json_decode_assoc(&value)) else {
                    return Err(invalid("Encoded operator filter payload is invalid"));
                };
                match b64_decode(&data, true) {
                    Value::Bool(false) => return Err(invalid("Encoded operator filter payload is not valid base64")),
                    v => v,
                }
            }
            "joinedSeal" => match data_of(&json_decode_assoc(&value)) {
                Some(data) => b64_decode(&data, true),
                None => value,
            },
            _ => value,
        })
    }

    fn signature(&self) -> String {
        let prefix = self.prefix;
        format!("{FIXTURES_PHP}::{prefix}Encode:{FIXTURES_PHP}::{prefix}Decode")
    }
}

/// A global filter of two callbacks (`Database::addFilter($name, $encode,
/// $decode)` with closures).
pub struct CallbackFilter {
    /// The encode callback.
    pub encode: Cb,
    /// The decode callback.
    pub decode: Cb,
}

#[async_trait]
impl Filter for CallbackFilter {
    async fn encode(&self, value: Value, document: &Document, _database: &Database) -> utopia_database::Result<Value> {
        self.encode
            .value(vec![codec::encode(&value), codec::encode_document(document), json!({"$database": true})])
            .await
    }

    async fn decode(
        &self,
        value: Value,
        document: &Document,
        _database: &Database,
        attribute: &str,
    ) -> utopia_database::Result<Value> {
        self.decode
            .value(vec![
                codec::encode(&value),
                codec::encode_document(document),
                json!({"$database": true}),
                Json::String(attribute.to_owned()),
            ])
            .await
    }

    fn signature(&self) -> String {
        format!("{CALLBACK_SIGNATURE}:{CALLBACK_SIGNATURE}")
    }
}

// ----- Formats -----

/// The unit tests' email format: a `Text` whose description and check are
/// an email's (`Fixture\EmailFormat`).
struct EmailFormat;

impl utopia_validators::Validator for EmailFormat {
    fn description(&self) -> String {
        "Value must be a valid email address".to_owned()
    }

    fn is_valid(&self, value: &utopia_validators::Value) -> bool {
        use php_std::filter::Value as F;
        let value = match value {
            utopia_validators::Value::String(s) => F::Str(s.as_bytes().to_vec()),
            utopia_validators::Value::Number(n) => match n.as_i64() {
                Some(i) => F::Int(i),
                None => F::Float(n.as_f64().unwrap_or(0.0)),
            },
            utopia_validators::Value::Bool(b) => F::Bool(*b),
            utopia_validators::Value::Null => F::Null,
            _ => return false,
        };
        php_std::filter::filter_var(&value, php_std::filter::FILTER_VALIDATE_EMAIL, &php_std::filter::Options::Flags(0))
            .is_ok_and(|f| !matches!(f.value, F::Bool(false) | F::Null))
    }
}

fn options_of(attribute: &Array) -> Array {
    match attribute.get_str("formatOptions") {
        Some(Value::Array(a)) => a.clone(),
        _ => Array::new(),
    }
}

fn numeric_or_zero(v: Option<&Value>) -> f64 {
    match v {
        Some(Value::Int(i)) => *i as f64,
        Some(Value::Float(f)) => *f,
        Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).trim().parse().unwrap_or(0.0),
        _ => 0.0,
    }
}

/// Registers a Structure format fixture (`db.add_format`, `Formats::get`).
pub fn add_format(name: &str) -> R<()> {
    use utopia_validators::{Range, Text, Type, WhiteList};
    let (factory, kind): (formats::Factory, ColumnType) = match name {
        "priceRange" | "priceRangeNew" => (
            Arc::new(|attribute: &Array| {
                let options = options_of(attribute);
                let (min, max) = (numeric_or_zero(options.get_str("min")), numeric_or_zero(options.get_str("max")));
                Box::new(Range::with_format(
                    utopia_validators::php::Number::Float(min),
                    utopia_validators::php::Number::Float(max),
                    Type::Float,
                )) as Box<dyn utopia_validators::Validator>
            }),
            ColumnType::Integer,
        ),
        "email" => (
            Arc::new(|attribute: &Array| {
                log(json!({"format": "email", "attribute": codec::encode_array(attribute)}));
                Box::new(EmailFormat) as Box<dyn utopia_validators::Validator>
            }),
            ColumnType::String,
        ),
        "structureFormatProbe" => (
            Arc::new(|attribute: &Array| {
                log(json!({"format": "structureFormatProbe", "attribute": codec::encode_array(attribute)}));
                let size = match options_of(attribute).get_str("maximum") {
                    Some(Value::Int(i)) => (*i).max(0) as usize,
                    _ => 0,
                };
                Box::new(Text::new(size)) as Box<dyn utopia_validators::Validator>
            }),
            ColumnType::String,
        ),
        "testformat" => (
            Arc::new(|_: &Array| Box::new(Text::new(100)) as Box<dyn utopia_validators::Validator>),
            ColumnType::Integer,
        ),
        "updateAttributeIntegerOnly" => {
            (Arc::new(|_: &Array| Box::new(Text::new(0)) as Box<dyn utopia_validators::Validator>), ColumnType::Integer)
        }
        "storedRange" => (
            Arc::new(|attribute: &Array| {
                let options = options_of(attribute);
                let bound = |k: &str| options.get_str(k).map(value::to_long).unwrap_or(0);
                Box::new(Range::new(bound("min"), bound("max"))) as Box<dyn utopia_validators::Validator>
            }),
            ColumnType::Integer,
        ),
        "storedEnum" => (
            Arc::new(|attribute: &Array| {
                let elements: Vec<String> = match options_of(attribute).get_str("elements") {
                    Some(Value::Array(a)) => a.values().map(value::to_text).collect(),
                    _ => Vec::new(),
                };
                let list: Vec<&str> = elements.iter().map(String::as_str).collect();
                Box::new(WhiteList::strict(&list)) as Box<dyn utopia_validators::Validator>
            }),
            ColumnType::String,
        ),
        _ => return fault(format!("no format fixture `{name}`")),
    };
    formats::add(name, factory, kind);
    Ok(())
}

// ----- Hooks -----

/// A hook a case made (`Hooks::make`): what `addHook()` takes, and the
/// class `{"$hook": ...}` reports.
#[derive(Clone)]
pub struct HookObj {
    /// The hook.
    pub hook: Hook,
    /// The short class of the PHP hook (`Relationships`, `LogHook`, ...).
    pub class: &'static str,
}

php_object!(HookObj, self_class(), |h| json!({"$hook": h.class}));

fn self_class() -> &'static str {
    "Hook"
}

/// The Rust type name `removeHook(<class>)` names for a fixture name or PHP
/// class (`Hooks::className`).
pub fn hook_class(name: &str) -> String {
    let short = name.rsplit('\\').next().unwrap_or(name);
    match short {
        "Relationships" => std::any::type_name::<Relationships>().to_owned(),
        "Permissions" => std::any::type_name::<utopia_database::hook::Permissions>().to_owned(),
        "Tenancy" => std::any::type_name::<Tenancy>().to_owned(),
        "Log" | "LogHook" => std::any::type_name::<LogHook>().to_owned(),
        "NamedLog" | "NamedLogHook" => std::any::type_name::<LogHook>().to_owned(),
        "SelectiveLog" | "SelectiveLogHook" => std::any::type_name::<LogHook>().to_owned(),
        "Transform" | "TransformHook" => std::any::type_name::<TransformHook>().to_owned(),
        "Nested" | "NestedHook" => std::any::type_name::<NestedHook>().to_owned(),
        "Decorator" | "DecoratorHook" => std::any::type_name::<DecoratorHook>().to_owned(),
        "Queries" | "QueriesHook" => std::any::type_name::<QueriesHook>().to_owned(),
        "Invalidator" => std::any::type_name::<utopia_database::cache::Invalidator>().to_owned(),
        _ => name.to_owned(),
    }
}

/// A hook fixture from its spec: `"<name>"` or `{"$hook": "<name>",
/// ...parameters}` (`Hooks::make`).
pub fn make_hook(spec: &Json) -> R<HookObj> {
    let (name, params) = match spec {
        Json::String(name) => (name.as_str(), serde_json::Map::new()),
        Json::Object(o) => match o.get("$hook").and_then(Json::as_str) {
            Some(name) => (name, o.clone()),
            None => return fault("a hook is \"<name>\" or {\"$hook\": \"<name>\", ...}"),
        },
        _ => return fault("a hook is \"<name>\" or {\"$hook\": \"<name>\", ...}"),
    };
    let text = |k: &str| params.get(k).and_then(Json::as_str).map(str::to_owned);
    Ok(match name {
        "Relationships" => HookObj {
            hook: Hook::Relationships(Arc::new(Relationships::new(
                params.get("prepare").map(|v| codec::decode(v).map(|v| v.truthy()).unwrap_or(true)).unwrap_or(true),
            ))),
            class: "Relationships",
        },
        "Permissions" => {
            HookObj { hook: Hook::write(Arc::new(utopia_database::hook::Permissions::new())), class: "Permissions" }
        }
        "Tenancy" => HookObj {
            hook: Hook::write(Arc::new(match text("column") {
                Some(c) => Tenancy::new(c),
                None => Tenancy::default(),
            })),
            class: "Tenancy",
        },
        "Log" => {
            let events = match params.get("events") {
                Some(list) => {
                    Some(codec::list(list)?.into_iter().map(values::enum_of::<Event>).collect::<R<Vec<Event>>>()?)
                }
                None => None,
            };
            let class = match (&events, text("name")) {
                (Some(_), _) => "SelectiveLogHook",
                (None, Some(_)) => "NamedLogHook",
                (None, None) => "LogHook",
            };
            HookObj { hook: Hook::lifecycle(Arc::new(LogHook { name: text("name"), events })), class }
        }
        "Transform" => HookObj {
            hook: Hook::transform(Arc::new(TransformHook {
                table: text("table").unwrap_or_default(),
                event: match params.get("event") {
                    Some(e) => values::enum_of::<Event>(e)?,
                    None => Event::All,
                },
                append: text("append").unwrap_or_default(),
                prepend: text("prepend").unwrap_or_default(),
            })),
            class: "TransformHook",
        },
        "Nested" => HookObj {
            hook: Hook::nested(Arc::new(NestedHook { marker: text("marker").unwrap_or_default() })),
            class: "NestedHook",
        },
        "Decorator" => HookObj {
            hook: Hook::decorator(Arc::new(DecoratorHook {
                attribute: text("attribute").unwrap_or_default(),
                value: codec::decode(params.get("value").unwrap_or(&Json::Null))?,
            })),
            class: "DecoratorHook",
        },
        "Queries" => HookObj {
            hook: Hook::queries(Arc::new(QueriesHook {
                limit: params
                    .get("limit")
                    .map(|v| codec::decode(v).map(|v| value::to_long(&v)).unwrap_or(25))
                    .unwrap_or(25),
            })),
            class: "QueriesHook",
        },
        _ => return fault(format!("no hook fixture `{name}`")),
    })
}

/// A lifecycle hook logging every event it receives (`LogHook`,
/// `NamedLogHook`, `SelectiveLogHook`).
pub struct LogHook {
    name: Option<String>,
    events: Option<Vec<Event>>,
}

impl Named for LogHook {
    fn name(&self) -> &str {
        self.name.as_deref().unwrap_or_default()
    }
}

impl Selective for LogHook {
    fn handles(&self, event: Event) -> bool {
        self.events.as_ref().is_none_or(|events| events.contains(&event))
    }
}

#[async_trait]
impl Lifecycle for LogHook {
    async fn handle(&self, event: &Domain) -> utopia_database::Result<()> {
        let name = self.name.as_ref().map(|n| Json::String(n.clone())).unwrap_or(Json::Null);
        log(json!({"hook": name, "event": values::event(event)}));
        Ok(())
    }

    fn named(&self) -> Option<&dyn Named> {
        // A selective hook is named only when given a name; a named one always.
        self.name.as_ref().map(|_| self as &dyn Named)
    }

    fn selective(&self) -> Option<&dyn Selective> {
        self.events.as_ref().map(|_| self as &dyn Selective)
    }
}

/// A transform of the statements of one event naming a table.
pub struct TransformHook {
    table: String,
    event: Event,
    append: String,
    prepend: String,
}

impl Transform for TransformHook {
    fn transform(&self, event: Event, query: String) -> utopia_database::Result<String> {
        if (self.event != Event::All && event != self.event) || !query.contains(&self.table) {
            return Ok(query);
        }
        log(json!({"transform": event.as_str(), "query": query}));
        Ok(format!("{}{query}{}", self.prepend, self.append))
    }
}

/// NestedPreparationTests' nested hook.
pub struct NestedHook {
    marker: String,
}

#[async_trait]
impl Nested for NestedHook {
    async fn prepare(&self, collection: &Collection, mut document: Document) -> utopia_database::Result<Document> {
        log(json!({"nested": [collection.id(), document.id()]}));
        if document.id() == self.marker {
            document.set(document::ID, Value::Str(Str::from(utopia_database::id::unique()?)))?;
        }
        Ok(document)
    }
}

/// A decorator setting one attribute on every document it is given.
pub struct DecoratorHook {
    attribute: String,
    value: Value,
}

#[async_trait]
impl Decorator for DecoratorHook {
    async fn decorate(
        &self,
        event: Event,
        collection: &Collection,
        mut document: Document,
    ) -> utopia_database::Result<Document> {
        log(json!({"decorate": event.as_str(), "collection": collection.id(), "document": document.id()}));
        document.set(self.attribute.as_str(), self.value.clone())?;
        Ok(document)
    }
}

/// A query rewrite adding a limit to reads without one.
pub struct QueriesHook {
    limit: i64,
}

#[async_trait]
impl Queries for QueriesHook {
    async fn rewrite(
        &self,
        event: Event,
        collection: &Collection,
        mut queries: Vec<Query>,
    ) -> utopia_database::Result<Vec<Query>> {
        log(json!({"rewrite": event.as_str(), "collection": collection.id(), "queries": queries.len()}));
        if queries.iter().any(|q| q.method() == utopia_query::Method::Limit) {
            return Ok(queries);
        }
        queries.push(Query::from(utopia_query::Query::limit(self.limit)));
        Ok(queries)
    }
}

// ----- Codecs -----

/// A codec fixture (`Codecs::make`).
#[derive(Clone)]
pub struct CodecObj(pub Arc<dyn Codec>);

php_object!(CodecObj, "Filter\\Codec", |_c| json!({"$codec": true}));

struct Reversed(String);
struct Rot13(String);
struct Prefixed(String);

fn map_str(value: Value, f: impl Fn(&[u8]) -> Vec<u8>) -> Value {
    match value {
        Value::Str(s) => Value::Str(Str::from(f(s.as_bytes()))),
        other => other,
    }
}

fn rot13(bytes: &[u8]) -> Vec<u8> {
    bytes
        .iter()
        .map(|&b| match b {
            b'a'..=b'z' => (b - b'a' + 13) % 26 + b'a',
            b'A'..=b'Z' => (b - b'A' + 13) % 26 + b'A',
            _ => b,
        })
        .collect()
}

impl Codec for Reversed {
    fn name(&self) -> &str {
        &self.0
    }
    fn encode(&self, value: Value) -> utopia_database::Result<Value> {
        Ok(map_str(value, |b| b.iter().rev().copied().collect()))
    }
    fn decode(&self, value: Value) -> utopia_database::Result<Value> {
        Ok(map_str(value, |b| b.iter().rev().copied().collect()))
    }
    fn signature(&self) -> String {
        "Tests\\Compat\\Database\\Fixture\\Codec\\Reversed".to_owned()
    }
}

impl Codec for Rot13 {
    fn name(&self) -> &str {
        &self.0
    }
    fn encode(&self, value: Value) -> utopia_database::Result<Value> {
        Ok(map_str(value, rot13))
    }
    fn decode(&self, value: Value) -> utopia_database::Result<Value> {
        Ok(map_str(value, rot13))
    }
    fn signature(&self) -> String {
        "Tests\\Compat\\Database\\Fixture\\Codec\\Rot13".to_owned()
    }
}

impl Codec for Prefixed {
    fn name(&self) -> &str {
        "text"
    }
    fn encode(&self, value: Value) -> utopia_database::Result<Value> {
        Ok(map_str(value, |b| [self.0.as_bytes(), b].concat()))
    }
    fn decode(&self, value: Value) -> utopia_database::Result<Value> {
        Ok(map_str(value, |b| b.strip_prefix(self.0.as_bytes()).unwrap_or(b).to_vec()))
    }
    fn signature(&self) -> String {
        format!("Tests\\Compat\\Database\\Fixture\\Codec\\Prefixed:{}", self.0)
    }
}

/// A codec fixture from its spec: `"<fixture>"` or `{"$codec": "<fixture>",
/// ...constructor arguments}` (`Codecs::make`).
pub fn make_codec(spec: &Json) -> R<CodecObj> {
    let (name, params) = match spec {
        Json::String(name) => (name.as_str(), serde_json::Map::new()),
        Json::Object(o) => match o.get("$codec").and_then(Json::as_str) {
            Some(name) => (name, o.clone()),
            None => return fault("a codec is \"<fixture>\" or {\"$codec\": \"<fixture>\", ...}"),
        },
        _ => return fault("a codec is \"<fixture>\" or {\"$codec\": \"<fixture>\", ...}"),
    };
    let text = |k: &str, default: &str| params.get(k).and_then(Json::as_str).unwrap_or(default).to_owned();
    Ok(CodecObj(match name {
        "reversed" => Arc::new(Reversed(text("name", "reversed"))),
        "rot13" => Arc::new(Rot13(text("name", "rot13"))),
        "prefixed" => Arc::new(Prefixed(text("prefix", ""))),
        _ => return fault(format!("no codec fixture `{name}`")),
    }))
}

/// An object holding an enum, for cases that store one.
pub fn event_obj(event: Event) -> Obj {
    enums::obj(event)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The callback signature names the line `Core::callback` declares its
    /// closure on.
    #[test]
    fn callback_signature_names_core_php_closure() {
        let path = concat!(env!("CARGO_MANIFEST_DIR"), "/../../../tests/compat/database/ops/core.php");
        let source = std::fs::read_to_string(path).expect("ops/core.php");
        let line: usize = CALLBACK_SIGNATURE.rsplit(':').next().and_then(|l| l.parse().ok()).expect("a line");
        let text = source.lines().nth(line - 1).unwrap_or_default();
        assert!(text.contains("return function (mixed ...$args)"), "core.php:{line} is `{text}`");
    }
}
