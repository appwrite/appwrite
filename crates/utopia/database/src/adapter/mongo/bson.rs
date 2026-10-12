//! Database values to BSON and back, as PHP's MongoDB extension converts
//! PHP values (`MongoDB\BSON\Document::fromPHP`, `toPHP`), and the array
//! shapes utopia-php/mongo and the adapter make of what it decodes.

use utopia_mongo::bson::oid::ObjectId;
use utopia_mongo::bson::raw::CString;
use utopia_mongo::bson::spec::BinarySubtype;
use utopia_mongo::bson::{Binary, Bson, DateTime as BsonDateTime, Document as BsonDocument, Regex};

use crate::error::{Error, Kind, Result};
use crate::value::{Array, ArrayKey, BsonValue, Ext, KeyRef, Str, Value};

/// A key of a PHP array as a BSON field name.
fn key(k: KeyRef<'_>) -> Result<String> {
    match k {
        KeyRef::Int(i) => Ok(i.to_string()),
        KeyRef::Str(s) => text(s, ""),
    }
}

/// A PHP string as BSON text: it must be UTF-8.
fn text(s: &[u8], path: &str) -> Result<String> {
    String::from_utf8(s.to_vec()).map_err(|_| {
        Error::new(
            Kind::Database,
            format!("Detected invalid UTF-8 for field path \"{path}\": {}", String::from_utf8_lossy(s)),
        )
    })
}

/// A PHP array as BSON: a list (keys `0..n` in order) is an array,
/// anything else a document.
pub(crate) fn array(a: &Array) -> Result<Bson> {
    if a.is_list() {
        return Ok(Bson::Array(a.values().map(value).collect::<Result<_>>()?));
    }
    Ok(Bson::Document(document(a)?))
}

/// A PHP array (or a `stdClass`'s properties) as a BSON document.
pub(crate) fn document(a: &Array) -> Result<BsonDocument> {
    let mut out = BsonDocument::new();
    for (k, v) in a.iter() {
        out.insert(key(k)?, value(v)?);
    }
    Ok(out)
}

/// A PHP value as BSON: integers are `int32` when they fit, `int64`
/// otherwise; a `stdClass` and an object's array form are documents.
pub(crate) fn value(v: &Value) -> Result<Bson> {
    Ok(match v {
        Value::Null => Bson::Null,
        Value::Bool(b) => Bson::Boolean(*b),
        Value::Int(i) => utopia_mongo::client::int(*i),
        Value::Float(f) => Bson::Double(*f),
        Value::Str(s) => Bson::String(text(s.as_bytes(), "")?),
        Value::Array(a) => array(a)?,
        Value::Object(a) => Bson::Document(document(a)?),
        Value::Ext(Ext::Bson(b)) => match &**b {
            BsonValue::UtcDateTime(ms) => Bson::DateTime(BsonDateTime::from_millis(*ms)),
            BsonValue::ObjectId(bytes) => Bson::ObjectId(ObjectId::from_bytes(*bytes)),
            BsonValue::Regex { pattern, flags } => {
                let cstring = |s: &str| {
                    CString::try_from(s)
                        .map_err(|_| Error::new(Kind::Database, "BSON regex strings cannot contain null bytes"))
                };
                Bson::RegularExpression(Regex { pattern: cstring(pattern)?, options: cstring(flags)? })
            }
        },
        // A Document is an ArrayObject: its storage is what the extension
        // encodes. Queries and operators have no public properties.
        Value::Ext(Ext::Document(d)) => Bson::Document(document(d.as_array())?),
        Value::Ext(Ext::Query(_) | Ext::Operator(_)) => Bson::Document(BsonDocument::new()),
    })
}

/// `(string) $value`, with the BSON objects' `__toString()`: an ObjectId's
/// hex, a UTCDateTime's milliseconds, a Regex as `/pattern/flags`.
pub(crate) fn php_string(value: &Value) -> Str {
    match value {
        Value::Ext(Ext::Bson(b)) => Str::from(match &**b {
            BsonValue::ObjectId(bytes) => hex::encode(bytes),
            BsonValue::UtcDateTime(ms) => ms.to_string(),
            BsonValue::Regex { pattern, flags } => format!("/{pattern}/{flags}"),
        }),
        other => other.to_php_string().unwrap_or(Str::EMPTY),
    }
}

/// A BSON value as PHP decodes it: documents are `stdClass`, arrays
/// lists, every integer a PHP integer (utopia-php/mongo unwraps `Int64`).
pub(crate) fn decode(b: &Bson) -> Value {
    match b {
        Bson::Null | Bson::Undefined => Value::Null,
        Bson::Boolean(v) => Value::Bool(*v),
        Bson::Int32(i) => Value::Int(i64::from(*i)),
        Bson::Int64(i) => Value::Int(*i),
        Bson::Double(f) => Value::Float(*f),
        Bson::String(s) => Value::Str(Str::from(s.as_str())),
        Bson::Array(items) => Value::Array(items.iter().map(decode).collect()),
        Bson::Document(d) => Value::Object(properties(d)),
        Bson::DateTime(d) => Value::from(BsonValue::UtcDateTime(d.timestamp_millis())),
        Bson::ObjectId(o) => Value::from(BsonValue::ObjectId(o.bytes())),
        Bson::RegularExpression(r) => {
            Value::from(BsonValue::Regex { pattern: r.pattern.to_string(), flags: r.options.to_string() })
        }
        Bson::Binary(Binary { bytes, subtype }) if *subtype == BinarySubtype::Generic => {
            Value::Str(Str::from(bytes.clone()))
        }
        other => Value::Str(Str::from(other.to_string())),
    }
}

/// A BSON document's fields as a `stdClass`'s properties (numeric names
/// become integer keys, as PHP's array view of an object has them).
pub(crate) fn properties(d: &BsonDocument) -> Array {
    d.iter().map(|(k, v)| (ArrayKey::normalize(Str::from(k.as_str())), decode(v))).collect()
}

/// `Client::toArray($obj)`: a `stdClass` tree as arrays, empty objects
/// kept as `stdClass` (they re-encode as `{}`).
pub(crate) fn to_array(value: &Value) -> Array {
    let fields = match value {
        Value::Object(a) | Value::Array(a) => a,
        Value::Null => return Array::new(),
        other => return Array::from_list(vec![other.clone()]),
    };
    fields
        .iter()
        .map(|(k, v)| {
            let v = match v {
                Value::Object(a) if a.is_empty() => v.clone(),
                Value::Object(_) | Value::Array(_) => Value::Array(to_array(v)),
                other => other.clone(),
            };
            (k.to_owned(), v)
        })
        .collect()
}

/// The adapter's `convertStdClassToArray()`: every non-empty `stdClass`
/// in `value` as an array (empty ones kept), recursively through arrays.
pub(crate) fn std_class_to_array(value: Value) -> Value {
    match value {
        Value::Object(a) if a.is_empty() => Value::Object(a),
        Value::Object(a) | Value::Array(a) => {
            Value::Array(a.into_iter().map(|(k, v)| (k, std_class_to_array(v))).collect())
        }
        other => other,
    }
}
