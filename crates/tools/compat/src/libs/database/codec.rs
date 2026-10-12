//! The database's values across the wire: the twin of
//! `tests/compat/database/Values.php` (read its docs for the tags).
//!
//! [`decode`] turns an argument into a [`Value`] as PHP's `Codec::decode`
//! and `Values::decode` together do; [`encode`] turns a result back into
//! JSON as `Values::encode` and `Codec::encode` do.

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use serde_json::{Map, Value as Json};
use utopia_database::document::Document;
use utopia_database::operator::{Operator, OperatorMethod};
use utopia_database::query::{Method, Query};
use utopia_database::value::{Array, ArrayKey, Ext, KeyRef, Str, Value};

use crate::adapter::{Fault, bytes_value, float_value};

/// An argument as a value (`Codec::decode` then `Values::decode`).
pub fn decode(json: &Json) -> Result<Value, Fault> {
    Ok(match json {
        Json::Null => Value::Null,
        Json::Bool(b) => Value::Bool(*b),
        Json::Number(n) => match n.as_i64() {
            Some(i) => Value::Int(i),
            None => Value::Float(n.as_f64().unwrap_or(0.0)),
        },
        Json::String(s) => Value::Str(Str::from(s.as_str())),
        Json::Array(items) => Value::Array(items.iter().map(decode).collect::<Result<Array, Fault>>()?),
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
                        _ => return Err(Fault::new("invalid $float")),
                    }));
                }
            }
            let document_tag = o.len() == 1 || (o.len() == 2 && o.contains_key("$class"));
            if document_tag && let Some(attrs) = o.get("$document") {
                return Ok(Value::from(match attrs {
                    Json::Object(m) if m.is_empty() => Document::empty(),
                    other => document_of(decode_array(other)?)?,
                }));
            }
            if o.len() == 1 {
                if let Some(q) = o.get("$query") {
                    let (method, attribute, values) = fields(q)?;
                    return Ok(Value::from(Query::from_array(Method::from_name(&method), attribute, values)));
                }
                if let Some(op) = o.get("$operator") {
                    let (method, attribute, values) = fields(op)?;
                    return Ok(Value::from(Operator::from_array(
                        OperatorMethod::from_name(&method),
                        attribute,
                        values,
                    )));
                }
                if let Some(obj) = o.get("$object") {
                    return Ok(Value::Object(decode_array(obj)?));
                }
            }
            Value::Array(
                o.iter().map(|(k, v)| Ok((ArrayKey::normalize(Str::from(k.as_str())), decode(v)?))).collect::<Result<
                    Array,
                    Fault,
                >>(
                )?,
            )
        }
    })
}

/// An argument that must be an array (`Values::decodeArray`): `{}` is empty.
pub fn decode_array(json: &Json) -> Result<Array, Fault> {
    match decode(json)? {
        Value::Array(a) => Ok(a),
        Value::Object(a) if a.is_empty() => Ok(Array::new()),
        other => Err(Fault::new(format!("expected an array, got {}", other.type_name()))),
    }
}

fn document_of(attrs: Array) -> Result<Document, Fault> {
    Document::new(attrs).map_err(|e| Fault::new(format!("document: {}", e.message())))
}

fn fields(json: &Json) -> Result<(String, String, Array), Fault> {
    let a = decode_array(json)?;
    let text = |k: &str| -> String {
        match a.get_str(k) {
            Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            Some(Value::Int(i)) => i.to_string(),
            _ => String::new(),
        }
    };
    let values = match a.get_str("values") {
        Some(Value::Array(v)) => v.clone(),
        _ => Array::new(),
    };
    Ok((text("method"), text("attribute"), values))
}

/// A document argument: a `$document` tag or a plain array (`Values::document`).
pub fn document(json: &Json) -> Result<Document, Fault> {
    match decode(json)? {
        Value::Ext(Ext::Document(d)) => Ok(*d),
        Value::Object(a) if a.is_empty() => Ok(Document::empty()),
        Value::Array(a) => document_of(a),
        other => Err(Fault::new(format!("expected a document, got {}", other.type_name()))),
    }
}

/// Documents from a list argument (`Values::documents`).
pub fn documents(json: &Json) -> Result<Vec<Document>, Fault> {
    list(json)?.into_iter().map(document).collect()
}

/// Queries from a list argument (`Values::queries`): `$query` tags, arrays
/// parsed with `Query::parseQuery`, strings with `Query::parse`. A parse
/// error is the operation's error, as in PHP.
pub fn queries(json: &Json) -> Result<Result<Vec<Query>, utopia_database::Error>, Fault> {
    let mut out = Vec::new();
    for q in list(json)? {
        let parsed = match q {
            Json::String(s) => Query::parse(s),
            other => match decode(other)? {
                Value::Ext(Ext::Query(q)) => Ok(*q),
                Value::Array(a) => Query::parse_query(&a),
                Value::Object(a) if a.is_empty() => Query::parse_query(&Array::new()),
                v => return Err(Fault::new(format!("expected a query, got {}", v.type_name()))),
            },
        };
        match parsed {
            Ok(q) => out.push(q),
            Err(e) => return Ok(Err(e)),
        }
    }
    Ok(Ok(out))
}

/// The items of a list argument (`null` and `{}` are empty).
pub fn list(json: &Json) -> Result<Vec<&Json>, Fault> {
    match json {
        Json::Null => Ok(Vec::new()),
        Json::Object(o) if o.is_empty() => Ok(Vec::new()),
        Json::Array(items) => Ok(items.iter().collect()),
        Json::Object(o) => Ok(o.values().collect()),
        other => Err(Fault::new(format!("expected a list, got {other}"))),
    }
}

/// A result as JSON (`Values::encode` then `Codec::encode`).
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
        Value::Ext(Ext::Query(q)) => tag(
            "$query",
            serde_json::json!({
                "method": q.method().as_str(),
                "attribute": q.attribute(),
                "values": encode_array(&q.values_array()),
            }),
        ),
        Value::Ext(Ext::Operator(o)) => tag(
            "$operator",
            serde_json::json!({
                "method": o.method().as_str(),
                "attribute": o.attribute(),
                "values": encode_array(&o.values_array()),
            }),
        ),
    }
}

/// A document result: `{"$document": {...}}` (plus `$class` for a custom type).
pub fn encode_document(d: &Document) -> Json {
    let attrs = if d.is_empty() { Json::Object(Map::new()) } else { encode_array(d.as_array()) };
    let mut o = Map::new();
    o.insert("$document".into(), attrs);
    if d.class() != utopia_database::document::CLASS {
        o.insert("$class".into(), Json::String(d.class().to_owned()));
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
        let key = match k {
            KeyRef::Int(i) => i.to_string(),
            KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
        };
        m.insert(key, encode(v));
    }
    Json::Object(m)
}

fn tag(name: &str, value: Json) -> Json {
    let mut o = Map::new();
    o.insert(name.into(), value);
    Json::Object(o)
}

/// An error result in the driver's form.
pub fn error(e: &utopia_database::Error) -> crate::adapter::Outcome {
    crate::adapter::Outcome::err(e.php_class(), e.message())
}

/// A result or an error, as the driver reports them.
pub fn outcome(r: utopia_database::Result<Value>) -> crate::adapter::Outcome {
    match r {
        Ok(v) => crate::adapter::Outcome::Ok(encode(&v)),
        Err(e) => error(&e),
    }
}
