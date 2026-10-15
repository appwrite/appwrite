//! The database's values across the wire: the twin of
//! `tests/compat/database/Values.php` (read its docs for the tags).
//!
//! [`decode`] turns an argument into a [`Value`] as PHP's `Codec::decode`
//! and `Values::decode` together do, for the tags a plain value holds
//! (documents, queries, operators, `stdClass`, BSON); the tags of other
//! objects (`$value`, `$enum`, `$datetime`) are decoded by the typed
//! arguments ([`super::args`]). [`encode`] turns a value back into JSON as
//! `Values::encode` and `Codec::encode` do.

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use serde_json::{Map, Value as Json, json};
use utopia_database::document::Document;
use utopia_database::operator::{Operator, OperatorType};
use utopia_database::query::Query;
use utopia_database::value::{self, Array, ArrayKey, BsonValue, Ext, KeyRef, Str, Value};
use utopia_database::{Error, Kind};

use crate::adapter::{Fault, bytes_value, float_value};

/// Why a decode failed: a malformed tag (a fault), or what PHP's decode
/// throws (`new Query()` with an unknown method, `OperatorType::from()`).
#[derive(Debug)]
pub enum DecodeError {
    /// A harness problem.
    Fault(Fault),
    /// The library's (or the engine's) error.
    Error(Error),
}

impl From<Fault> for DecodeError {
    fn from(f: Fault) -> Self {
        DecodeError::Fault(f)
    }
}

impl From<Error> for DecodeError {
    fn from(e: Error) -> Self {
        DecodeError::Error(e)
    }
}

type D<T> = Result<T, DecodeError>;

fn fault<T>(message: impl Into<String>) -> D<T> {
    Err(DecodeError::Fault(Fault::new(message)))
}

/// An argument as a value (`Codec::decode` then `Values::decode`).
pub fn decode(json: &Json) -> D<Value> {
    Ok(match json {
        Json::Null => Value::Null,
        Json::Bool(b) => Value::Bool(*b),
        Json::Number(n) => match n.as_i64() {
            Some(i) => Value::Int(i),
            None => match n.as_u64() {
                // Beyond i64: PHP's json_decode makes it a float.
                Some(u) => Value::Float(u as f64),
                None => Value::Float(n.as_f64().unwrap_or(0.0)),
            },
        },
        Json::String(s) => Value::Str(Str::from(s.as_str())),
        Json::Array(items) => Value::Array(items.iter().map(decode).collect::<D<Array>>()?),
        Json::Object(o) if o.is_empty() => Value::Object(Array::new()),
        Json::Object(o) => {
            if o.len() == 1 {
                if let Some(b) = o.get("$bytes").and_then(Json::as_str) {
                    let raw = BASE64.decode(b).map_err(|_| Fault::new("invalid $bytes"))?;
                    return Ok(Value::Str(Str::from(raw)));
                }
                if let Some(f) = o.get("$float").and_then(Json::as_str) {
                    return Ok(Value::Float(match f {
                        "INF" => f64::INFINITY,
                        "-INF" => f64::NEG_INFINITY,
                        "NAN" => f64::NAN,
                        _ => return fault("invalid $float"),
                    }));
                }
            }
            let document_tag = o.len() == 1 || (o.len() == 2 && o.contains_key("$class"));
            if document_tag && let Some(attrs) = o.get("$document") {
                let document = match attrs {
                    Json::Object(m) if m.is_empty() => Document::empty(),
                    other => Document::new(decode_array(other)?)?,
                };
                return Ok(Value::from(match o.get("$class").and_then(Json::as_str) {
                    Some(class) => document.with_class(full_class(class)),
                    None => document,
                }));
            }
            if o.len() == 1 {
                if let Some(q) = o.get("$query") {
                    return Ok(Value::from(query_tag(q)?));
                }
                if let Some(op) = o.get("$operator") {
                    return Ok(Value::from(operator_tag(op)?));
                }
                if let Some(obj) = o.get("$object") {
                    return Ok(Value::Object(decode_array(obj)?));
                }
                if let Some(bson) = o.get("$bson") {
                    return decode_bson(bson);
                }
            }
            Value::Array(
                o.iter()
                    .map(|(k, v)| Ok((ArrayKey::normalize(Str::from(k.as_str())), decode(v)?)))
                    .collect::<D<Array>>()?,
            )
        }
    })
}

/// The class a tag names, as PHP's `::class` spells it (`Values::className`):
/// relative to `Utopia\Database`, or to `Utopia` with a `Query\` prefix; a
/// class outside the library (`Tests\E2E\...`) as it is.
pub fn full_class(name: &str) -> String {
    if name.starts_with("Tests\\") || name.starts_with("Utopia\\") {
        name.to_owned()
    } else if name.starts_with("Query\\") {
        format!("Utopia\\{name}")
    } else {
        format!("Utopia\\Database\\{name}")
    }
}

/// The tag name of a class (`Values::shortName`): `None` outside the library.
pub fn short_class(class: &str) -> Option<&str> {
    class
        .strip_prefix("Utopia\\Database\\")
        .or_else(|| class.strip_prefix("Utopia\\").filter(|c| c.starts_with("Query\\")))
}

/// `new Query(method, attribute, values, alias)` from a `$query` tag.
pub fn query_tag(json: &Json) -> D<Query> {
    let fields = decode_array(json)?;
    let text = |k: &str| fields.get_str(k).map(value::to_text).unwrap_or_default();
    let values = match fields.get_str("values") {
        Some(Value::Array(v)) => v.clone(),
        _ => Array::new(),
    };
    Ok(Query::named(&text("method"), text("attribute"), value::to_query_array(&values), text("alias"))?)
}

/// `new Operator(OperatorType::from(method), attribute, values)` from an
/// `$operator` tag.
pub fn operator_tag(json: &Json) -> D<Operator> {
    let fields = decode_array(json)?;
    let text = |k: &str| fields.get_str(k).map(value::to_text).unwrap_or_default();
    let values = match fields.get_str("values") {
        Some(Value::Array(v)) => v.clone(),
        _ => Array::new(),
    };
    let method = text("method");
    let method = OperatorType::try_from_value(&method).ok_or_else(|| {
        Error::new(
            Kind::ValueError,
            format!("\"{method}\" is not a valid backing value for enum Utopia\\Database\\OperatorType"),
        )
    })?;
    Ok(Operator::from_array(method, text("attribute"), values))
}

/// An argument that must be an array (`Values::decodeArray`): `{}` is empty.
pub fn decode_array(json: &Json) -> D<Array> {
    match decode(json)? {
        Value::Array(a) => Ok(a),
        Value::Object(a) if a.is_empty() => Ok(Array::new()),
        other => fault(format!("expected an array, got {}", other.type_name())),
    }
}

/// A document argument: a `$document` tag or a plain array (`Values::document`).
pub fn document(json: &Json) -> D<Document> {
    match decode(json)? {
        Value::Ext(Ext::Document(d)) => Ok(*d),
        Value::Object(a) if a.is_empty() => Ok(Document::empty()),
        Value::Array(a) => Ok(Document::new(a)?),
        other => fault(format!("expected a document, got {}", other.type_name())),
    }
}

/// The items of a list argument (`Values::list`: `null` and `{}` are empty,
/// an object's values in order).
pub fn list(json: &Json) -> Result<Vec<&Json>, Fault> {
    match json {
        Json::Null => Ok(Vec::new()),
        Json::Object(o) if o.is_empty() => Ok(Vec::new()),
        Json::Array(items) => Ok(items.iter().collect()),
        Json::Object(o) => Ok(o.values().collect()),
        other => Err(Fault::new(format!("expected a list, got {other}"))),
    }
}

/// A value as JSON (`Values::encode` then `Codec::encode`).
pub fn encode(value: &Value) -> Json {
    match value {
        Value::Null => Json::Null,
        Value::Bool(b) => Json::Bool(*b),
        Value::Int(i) => Json::from(*i),
        Value::Float(f) => float_value(*f),
        Value::Str(s) => bytes_value(s.as_bytes()),
        Value::Array(a) => encode_array(a),
        Value::Object(a) if a.is_empty() => Json::Object(Map::new()),
        Value::Object(a) => tag("$object", encode_array(a)),
        Value::Ext(Ext::Document(d)) => encode_document(d),
        Value::Ext(Ext::Query(q)) => encode_query(q),
        Value::Ext(Ext::Operator(o)) => tag(
            "$operator",
            json!({
                "method": o.method().as_str(),
                "attribute": o.attribute(),
                "values": encode_array(o.raw_values()),
            }),
        ),
        Value::Ext(Ext::Bson(b)) => tag(
            "$bson",
            match &**b {
                BsonValue::UtcDateTime(ms) => json!({ "$date": { "$numberLong": ms.to_string() } }),
                BsonValue::ObjectId(bytes) => {
                    json!({ "$oid": bytes.iter().map(|b| format!("{b:02x}")).collect::<String>() })
                }
                BsonValue::Regex { pattern, flags } => {
                    json!({ "$regularExpression": { "pattern": pattern, "options": flags } })
                }
            },
        ),
    }
}

/// A query (`{"$query": {"method", "attribute", "values", "alias"?}}`).
pub fn encode_query(q: &utopia_query::Query) -> Json {
    let mut o = Map::new();
    o.insert("method".into(), Json::String(q.method().as_str().to_owned()));
    o.insert("attribute".into(), Json::String(q.attribute().to_owned()));
    o.insert("values".into(), encode_query_array(q.values()));
    if !q.alias().is_empty() {
        o.insert("alias".into(), Json::String(q.alias().to_owned()));
    }
    tag("$query", Json::Object(o))
}

/// A query value as JSON.
pub fn encode_query_value(v: &utopia_query::Value) -> Json {
    match v {
        utopia_query::Value::Ext(utopia_query::Object::Query(q)) => encode_query(q),
        other => encode(&value::from_query(other)),
    }
}

/// Query values as JSON (a list, or an object).
pub fn encode_query_array(a: &utopia_query::Array) -> Json {
    if a.is_list() {
        return Json::Array(a.values().map(encode_query_value).collect());
    }
    let mut m = Map::with_capacity(a.len());
    for (k, v) in a.iter() {
        m.insert(key_text(k), encode_query_value(v));
    }
    Json::Object(m)
}

fn key_text(k: KeyRef<'_>) -> String {
    match k {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// A `{"$bson": <canonical extended JSON>}` value: the BSON types the
/// MongoDB adapter carries.
fn decode_bson(json: &Json) -> D<Value> {
    let bad = || Fault::new(format!("unsupported $bson value: {json}"));
    let o = json.as_object().ok_or_else(bad)?;
    if let Some(date) = o.get("$date") {
        let ms = date.get("$numberLong").and_then(Json::as_str).and_then(|s| s.parse().ok()).ok_or_else(bad)?;
        return Ok(Value::from(BsonValue::UtcDateTime(ms)));
    }
    if let Some(Json::String(hex)) = o.get("$oid") {
        let mut bytes = [0u8; 12];
        for (i, b) in bytes.iter_mut().enumerate() {
            *b = u8::from_str_radix(hex.get(i * 2..i * 2 + 2).ok_or_else(bad)?, 16).map_err(|_| bad())?;
        }
        return Ok(Value::from(BsonValue::ObjectId(bytes)));
    }
    if let Some(re) = o.get("$regularExpression") {
        let text = |k: &str| re.get(k).and_then(Json::as_str).unwrap_or("").to_owned();
        return Ok(Value::from(BsonValue::Regex { pattern: text("pattern"), flags: text("options") }));
    }
    Err(DecodeError::Fault(bad()))
}

/// A document: `{"$document": {...}}`, plus `$class` for a subclass.
pub fn encode_document(d: &Document) -> Json {
    let attrs = if d.is_empty() { Json::Object(Map::new()) } else { encode_array(d.as_array()) };
    let mut o = Map::new();
    o.insert("$document".into(), attrs);
    if d.class() != utopia_database::document::CLASS {
        o.insert("$class".into(), Json::String(short_class(d.class()).unwrap_or(d.class()).to_owned()));
    }
    Json::Object(o)
}

/// Documents as a JSON list.
pub fn encode_documents(docs: &[Document]) -> Json {
    Json::Array(docs.iter().map(encode_document).collect())
}

/// An array as PHP's `json_encode` shapes it: a list, or an object.
pub fn encode_array(a: &Array) -> Json {
    if a.is_list() {
        return Json::Array(a.values().map(encode).collect());
    }
    let mut m = Map::with_capacity(a.len());
    for (k, v) in a.iter() {
        m.insert(key_text(k), encode(v));
    }
    Json::Object(m)
}

/// `{"<name>": value}`.
pub fn tag(name: &str, value: Json) -> Json {
    let mut o = Map::new();
    o.insert(name.into(), value);
    Json::Object(o)
}

/// An exception as a value (`{"$error": {"class", "message"}}`).
pub fn error(e: &Error) -> Json {
    json!({"$error": {"class": e.php_class(), "message": bytes_value(e.message_bytes())}})
}
