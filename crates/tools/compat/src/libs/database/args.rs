//! An operation's named arguments, decoded for the parameters they go to
//! (`Core::argument`, `Core::tags`, `Objects::resolve`). See the README
//! (`database`: the core vocabulary) for the forms each type takes.

use std::sync::Arc;

use php_std::datetime::{DateTime, TzInfo};
use serde_json::{Map, Value as Json};
use utopia_database::collection::{self, Collection};
use utopia_database::document::Document;
use utopia_database::query::Query;
use utopia_database::value::{self, Array, Ext, Value};
use utopia_database::{Error, Kind};

use super::codec;
use super::ctx::{Ctx, Fail, R, fault};
use super::enums::{self, PhpEnum};
use super::obj::{EnumCase, Obj};
use super::values;

/// Resolves the argument tags (`Core::tags`), recursively: `{"$now":
/// <seconds>, "format": "<DateTime::format>"}` is the string of now plus the
/// seconds, `{"$repeat": "<text>", "times": <n>}` the repeated text.
/// `{"$datetime": "<string>"}` stays a tag (the typed getters read it).
/// A callback (`{"steps": [...]}`) is left as it is: its steps resolve
/// their tags when they run.
pub fn tags(json: &Json) -> R<Json> {
    Ok(match json {
        Json::Array(items) => Json::Array(items.iter().map(tags).collect::<R<_>>()?),
        Json::Object(o) => {
            if o.len() == 1 && o.get("steps").is_some_and(Json::is_array) {
                return Ok(json.clone());
            }
            if o.len() == 2 && o.contains_key("$repeat") && o.contains_key("times") {
                let text = value::to_text(&codec::decode(&o["$repeat"])?);
                let times = value::to_long(&codec::decode(&o["times"])?).max(0) as usize;
                return Ok(Json::String(text.repeat(times)));
            }
            if o.len() == 2 && o.contains_key("$now") && o.contains_key("format") {
                let seconds = value::to_long(&codec::decode(&o["$now"])?);
                let format = value::to_text(&codec::decode(&o["format"])?);
                return Ok(Json::String(now_plus(seconds, &format)?));
            }
            Json::Object(o.iter().map(|(k, v)| Ok((k.clone(), tags(v)?))).collect::<R<_>>()?)
        }
        other => other.clone(),
    })
}

/// `(new DateTime())->modify("+N seconds")->format($format)`.
fn now_plus(seconds: i64, format: &str) -> R<String> {
    let modifier = format!("{}{seconds} seconds", if seconds < 0 { "" } else { "+" });
    let mut now = parse_datetime("now")?;
    now.modify(modifier.as_bytes()).map_err(|e| Fail::Error(Error::new(Kind::Exception, e.message())))?;
    Ok(String::from_utf8_lossy(&now.format(format.as_bytes())).into_owned())
}

/// `new DateTime($text)` (relative forms count from now).
pub fn parse_datetime(text: &str) -> R<DateTime> {
    DateTime::new(text.as_bytes(), None, &TzInfo::utc())
        .map_err(|e| Fail::Error(Error::new(Kind::DateMalformedString, e.message())))
}

/// The named arguments of one call.
#[derive(Clone)]
pub struct Args {
    /// The session.
    pub cx: Arc<Ctx>,
    json: Map<String, Json>,
}

impl Args {
    /// The arguments of a call (a JSON object; `null` and `{}` are none),
    /// their tags resolved.
    /// `this` keeps its tags: `Objects::target()` decodes it as sent.
    pub fn new(cx: &Arc<Ctx>, json: &Json) -> R<Args> {
        let json = match json {
            Json::Object(o) => Json::Object(
                o.iter()
                    .map(|(k, v)| Ok((k.clone(), if k == "this" { v.clone() } else { tags(v)? })))
                    .collect::<R<Map<String, Json>>>()?,
            ),
            other => tags(other)?,
        };
        let json = match json {
            Json::Object(o) => o,
            Json::Null => Map::new(),
            Json::Array(a) if a.is_empty() => Map::new(),
            other => return fault(format!("arguments are an object, not {other}")),
        };
        Ok(Args { cx: cx.clone(), json })
    }

    /// The arguments as sent (tags resolved).
    pub fn json(&self) -> &Map<String, Json> {
        &self.json
    }

    /// The names given, in order.
    pub fn names(&self) -> impl Iterator<Item = &str> {
        self.json.keys().map(String::as_str)
    }

    /// The argument as sent (`None`: absent).
    pub fn raw(&self, key: &str) -> Option<&Json> {
        self.json.get(key)
    }

    /// Whether the argument is present (even as `null`).
    pub fn has(&self, key: &str) -> bool {
        self.json.contains_key(key)
    }

    /// Whether the argument is present and not `null`.
    pub fn given(&self, key: &str) -> bool {
        self.json.get(key).is_some_and(|j| !j.is_null())
    }

    fn missing<T>(&self, key: &str) -> R<T> {
        fault(format!("missing argument `{key}`"))
    }

    /// `{"$obj": <name>}` replaced by the stored object's wire form,
    /// anywhere (`Objects::resolve`, for parameters that take values).
    pub fn resolve(&self, json: &Json) -> R<Json> {
        resolve(&self.cx, json)
    }

    // ----- Generic -----

    /// The argument as an object (`Values::decode`): a stored `$obj`, a
    /// value object, an enum, a `DateTime`, or a value. `None` when absent
    /// or `null`.
    pub fn obj(&self, key: &str) -> R<Option<Obj>> {
        match self.json.get(key) {
            None | Some(Json::Null) => Ok(None),
            Some(j) => decode_obj(&self.cx, j).map(Some),
        }
    }

    /// A required object.
    pub fn req_obj(&self, key: &str) -> R<Obj> {
        match self.obj(key)? {
            Some(o) => Ok(o),
            None => self.missing(key),
        }
    }

    /// A `mixed` value (`null` when absent).
    pub fn value(&self, key: &str) -> R<Value> {
        match self.json.get(key) {
            None => Ok(Value::Null),
            Some(j) => self.decode_value(j),
        }
    }

    /// A value from JSON, `$obj` resolved.
    pub fn decode_value(&self, json: &Json) -> R<Value> {
        decode_value(&self.cx, json)
    }

    /// A value, `None` when absent (`Some(Null)` when `null`).
    pub fn opt_value(&self, key: &str) -> R<Option<Value>> {
        if !self.has(key) {
            return Ok(None);
        }
        self.value(key).map(Some)
    }

    // ----- Scalars -----

    /// A required string (`(string)`-cast like a PHP `string` parameter).
    pub fn string(&self, key: &str) -> R<String> {
        match self.opt_string(key)? {
            Some(s) => Ok(s),
            None => self.missing(key),
        }
    }

    /// A string, `default` when absent or `null`.
    pub fn string_or(&self, key: &str, default: &str) -> R<String> {
        Ok(self.opt_string(key)?.unwrap_or_else(|| default.to_owned()))
    }

    /// A string, `None` when absent or `null`.
    pub fn opt_string(&self, key: &str) -> R<Option<String>> {
        Ok(match self.value(key)? {
            Value::Null => None,
            v => Some(value::to_text(&v)),
        })
    }

    /// A required integer.
    pub fn int(&self, key: &str) -> R<i64> {
        match self.opt_int(key)? {
            Some(i) => Ok(i),
            None => self.missing(key),
        }
    }

    /// An integer, `default` when absent or `null`.
    pub fn int_or(&self, key: &str, default: i64) -> R<i64> {
        Ok(self.opt_int(key)?.unwrap_or(default))
    }

    /// An integer, `None` when absent or `null`.
    pub fn opt_int(&self, key: &str) -> R<Option<i64>> {
        Ok(match self.value(key)? {
            Value::Null => None,
            v => Some(value::to_long(&v)),
        })
    }

    /// A float, `None` when absent or `null`.
    pub fn opt_float(&self, key: &str) -> R<Option<f64>> {
        Ok(match self.value(key)? {
            Value::Null => None,
            Value::Int(i) => Some(i as f64),
            Value::Float(f) => Some(f),
            v => Some(value::to_text(&v).trim().parse().unwrap_or(0.0)),
        })
    }

    /// A required boolean (truthiness, like a PHP `bool` parameter).
    pub fn bool(&self, key: &str) -> R<bool> {
        match self.opt_bool(key)? {
            Some(b) => Ok(b),
            None => self.missing(key),
        }
    }

    /// A boolean, `default` when absent or `null`.
    pub fn bool_or(&self, key: &str, default: bool) -> R<bool> {
        Ok(self.opt_bool(key)?.unwrap_or(default))
    }

    /// A boolean, `None` when absent or `null`.
    pub fn opt_bool(&self, key: &str) -> R<Option<bool>> {
        Ok(match self.value(key)? {
            Value::Null => None,
            v => Some(v.truthy()),
        })
    }

    /// A list of strings (empty when absent or `null`).
    pub fn strings(&self, key: &str) -> R<Vec<String>> {
        Ok(self.opt_strings(key)?.unwrap_or_default())
    }

    /// A list of strings, `None` when absent or `null`.
    pub fn opt_strings(&self, key: &str) -> R<Option<Vec<String>>> {
        Ok(match self.value(key)? {
            Value::Null => None,
            Value::Array(a) => Some(a.values().map(value::to_text).collect()),
            Value::Object(a) => Some(a.values().map(value::to_text).collect()),
            v => Some(vec![value::to_text(&v)]),
        })
    }

    /// An array (`Values::decodeArray`; empty when absent or `null`).
    pub fn array(&self, key: &str) -> R<Array> {
        Ok(match self.value(key)? {
            Value::Null => Array::new(),
            Value::Array(a) => a,
            Value::Object(a) => a,
            other => return fault(format!("`{key}` is an array, not {}", other.type_name())),
        })
    }

    // ----- Library values -----

    /// A required document: a `$document` tag, a plain array or a stored
    /// document (`Values::document`).
    pub fn document(&self, key: &str) -> R<Document> {
        match self.opt_document(key)? {
            Some(d) => Ok(d),
            None => self.missing(key),
        }
    }

    /// A document, `None` when absent or `null`.
    pub fn opt_document(&self, key: &str) -> R<Option<Document>> {
        match self.json.get(key) {
            None | Some(Json::Null) => Ok(None),
            Some(j) => document_of(&self.cx, j).map(Some),
        }
    }

    /// An `array<Document>` (`Values::documents`; empty when absent).
    pub fn documents(&self, key: &str) -> R<Vec<Document>> {
        match self.json.get(key) {
            None | Some(Json::Null) => Ok(Vec::new()),
            Some(j) => {
                if let Some(Obj::List(items)) = stored_obj(&self.cx, j)? {
                    return items.into_iter().map(obj_document).collect();
                }
                if let Some(Obj::Value(Value::Array(a))) = stored_obj(&self.cx, j)? {
                    return a.into_iter().map(|(_, v)| document_value(v)).collect();
                }
                codec::list(j)?.into_iter().map(|item| document_of(&self.cx, item)).collect()
            }
        }
    }

    /// A `Collection` parameter: a `$document` tag (with `$class`
    /// `Collection` or not), a plain array, or a stored collection or
    /// document (`Collection::fromDocument`).
    pub fn collection(&self, key: &str) -> R<Collection> {
        let document = self.document(key)?;
        Ok(Collection::from_document(&document)?)
    }

    /// Queries (`Values::queries`: `$query` tags, arrays, strings, stored
    /// queries; empty when absent). A parse error is the operation's error.
    pub fn queries(&self, key: &str) -> R<Vec<Query>> {
        match self.json.get(key) {
            None | Some(Json::Null) => Ok(Vec::new()),
            Some(j) => {
                if let Some(obj) = stored_obj(&self.cx, j)? {
                    return match obj {
                        Obj::List(items) => items.into_iter().map(obj_query).collect(),
                        Obj::Value(Value::Array(a)) => a.into_iter().map(|(_, v)| obj_query(Obj::Value(v))).collect(),
                        _ => fault(format!("`{key}` is not a list of queries")),
                    };
                }
                codec::list(j)?.into_iter().map(|q| query_of(&self.cx, q)).collect()
            }
        }
    }

    /// One query (a `Query` parameter).
    pub fn query(&self, key: &str) -> R<Query> {
        match self.json.get(key) {
            None | Some(Json::Null) => self.missing(key),
            Some(j) => query_of(&self.cx, j),
        }
    }

    /// A `DateTime` parameter: a string for `new DateTime()`, a
    /// `{"$datetime": ...}` tag or a stored `DateTime`; `None` when absent
    /// or `null`.
    pub fn datetime(&self, key: &str) -> R<Option<DateTime>> {
        match self.json.get(key) {
            None | Some(Json::Null) => Ok(None),
            Some(j) => match decode_obj(&self.cx, j)? {
                Obj::Object(o) => match o.downcast_ref::<DateTime>() {
                    Some(d) => Ok(Some(d.clone())),
                    None => fault(format!("`{key}` is not a DateTime")),
                },
                Obj::Value(v) => parse_datetime(&value::to_text(&v)).map(Some),
                _ => fault(format!("`{key}` is not a DateTime")),
            },
        }
    }

    /// An enum parameter: a `$enum` tag, a stored case or a backing value
    /// (`Type::from($value)`).
    pub fn enum_<E: PhpEnum>(&self, key: &str) -> R<Option<E>> {
        match self.json.get(key) {
            None | Some(Json::Null) => Ok(None),
            Some(j) => {
                if let Some(Obj::Enum(e)) = stored_obj(&self.cx, j)? {
                    return match enums::from_case::<E>(&e) {
                        Some(c) => Ok(Some(c)),
                        None => fault(format!("`{}::{}` is not a case of {}", e.class, e.case, E::CLASS)),
                    };
                }
                values::enum_of::<E>(j).map(Some)
            }
        }
    }

    /// A required enum parameter.
    pub fn req_enum<E: PhpEnum>(&self, key: &str) -> R<E> {
        match self.enum_(key)? {
            Some(e) => Ok(e),
            None => self.missing(key),
        }
    }

    /// An object of type `T`: a stored one (`$obj`), or a `$value` tag of
    /// its class. `None` when absent or `null`.
    pub fn typed<T: Clone + 'static>(&self, key: &str) -> R<Option<T>> {
        match self.json.get(key) {
            None | Some(Json::Null) => Ok(None),
            Some(j) => typed_of::<T>(&self.cx, j).map(Some),
        }
    }

    /// A required object of type `T`.
    pub fn req<T: Clone + 'static>(&self, key: &str) -> R<T> {
        match self.typed(key)? {
            Some(t) => Ok(t),
            None => self.missing(key),
        }
    }

    /// A list of objects of type `T` (empty when absent).
    pub fn list<T: Clone + 'static>(&self, key: &str) -> R<Vec<T>> {
        match self.json.get(key) {
            None | Some(Json::Null) => Ok(Vec::new()),
            Some(j) => {
                if let Some(obj) = stored_obj(&self.cx, j)? {
                    return match obj {
                        Obj::List(items) => items.iter().map(|o| downcast::<T>(o)).collect(),
                        _ => fault(format!("`{key}` is not a list")),
                    };
                }
                codec::list(j)?.into_iter().map(|item| typed_of::<T>(&self.cx, item)).collect()
            }
        }
    }
}

/// The stored object a `{"$obj": <name>}` names, if `json` is one.
pub fn stored_obj(cx: &Ctx, json: &Json) -> R<Option<Obj>> {
    match json {
        Json::Object(o) if o.len() == 1 => match o.get("$obj").and_then(Json::as_str) {
            Some(name) => cx.stored(name).map(Some),
            None => Ok(None),
        },
        _ => Ok(None),
    }
}

/// `{"$obj": <name>}` replaced by the stored object's wire form, anywhere.
pub fn resolve(cx: &Ctx, json: &Json) -> R<Json> {
    if let Some(obj) = stored_obj(cx, json)? {
        return Ok(obj.into_result().encode());
    }
    Ok(match json {
        Json::Object(o) if o.len() == 1 && o.get("steps").is_some_and(Json::is_array) => json.clone(),
        Json::Object(o) => Json::Object(o.iter().map(|(k, v)| Ok((k.clone(), resolve(cx, v)?))).collect::<R<_>>()?),
        Json::Array(items) => Json::Array(items.iter().map(|v| resolve(cx, v)).collect::<R<_>>()?),
        other => other.clone(),
    })
}

/// A value from JSON, `$obj` resolved.
pub fn decode_value(cx: &Ctx, json: &Json) -> R<Value> {
    if let Some(obj) = stored_obj(cx, json)? {
        return match obj.into_result() {
            Obj::Value(v) => Ok(v),
            Obj::List(items) => Ok(Value::Array(items.into_iter().map(obj_value).collect::<R<Array>>()?)),
            other => fault(format!("a stored {} is not a value", other.class().unwrap_or("object"))),
        };
    }
    let mut refs = Vec::new();
    let json = refs_of(cx, json, &mut refs)?;
    Ok(codec::decode_with(&json, refs)?)
}

/// `json` with each stored value it names replaced by `{"$ref": <n>}`
/// (`refs[n]`), other stored objects by their wire form.
fn refs_of(cx: &Ctx, json: &Json, refs: &mut Vec<Value>) -> R<Json> {
    if let Some(obj) = stored_obj(cx, json)? {
        return Ok(match obj.into_result() {
            Obj::Value(v) => {
                refs.push(v);
                serde_json::json!({ "$ref": refs.len() - 1 })
            }
            other => other.encode(),
        });
    }
    Ok(match json {
        Json::Object(o) if o.len() == 1 && o.get("steps").is_some_and(Json::is_array) => json.clone(),
        Json::Object(o) => {
            Json::Object(o.iter().map(|(k, v)| Ok((k.clone(), refs_of(cx, v, refs)?))).collect::<R<_>>()?)
        }
        Json::Array(items) => Json::Array(items.iter().map(|v| refs_of(cx, v, refs)).collect::<R<_>>()?),
        other => other.clone(),
    })
}

fn obj_value(o: Obj) -> R<Value> {
    match o {
        Obj::Value(v) => Ok(v),
        other => fault(format!("a stored {} is not a value", other.class().unwrap_or("object"))),
    }
}

/// An object from JSON (`Values::decode` on `Objects::resolve`d input).
pub fn decode_obj(cx: &Ctx, json: &Json) -> R<Obj> {
    if let Some(obj) = stored_obj(cx, json)? {
        return Ok(obj);
    }
    if let Json::Object(o) = json {
        if o.len() == 2
            && let (Some(Json::String(class)), Some(fields)) = (o.get("$value"), o.get("fields"))
        {
            return values::decode(class, fields);
        }
        if o.len() == 1 {
            if let Some(Json::String(tag)) = o.get("$enum") {
                return match enums::parse_tag(tag) {
                    Some(e) => Ok(Obj::Enum(e)),
                    None => fault(format!("an enum is <name>::<Case>, not `{tag}`")),
                };
            }
            if let Some(Json::String(text)) = o.get("$datetime") {
                return Ok(Obj::object(parse_datetime(text)?));
            }
        }
    }
    Ok(Obj::Value(decode_value(cx, json)?))
}

/// An object from JSON without stored objects (a field of a wire form).
pub fn decode_obj_static(json: &Json) -> R<Obj> {
    if let Json::Object(o) = json {
        if o.len() == 2
            && let (Some(Json::String(class)), Some(fields)) = (o.get("$value"), o.get("fields"))
        {
            return values::decode(class, fields);
        }
        if o.len() == 1 {
            if let Some(Json::String(tag)) = o.get("$enum") {
                return match enums::parse_tag(tag) {
                    Some(e) => Ok(Obj::Enum(e)),
                    None => fault(format!("an enum is <name>::<Case>, not `{tag}`")),
                };
            }
            if let Some(Json::String(text)) = o.get("$datetime") {
                return Ok(Obj::object(parse_datetime(text)?));
            }
        }
    }
    Ok(Obj::Value(codec::decode(json)?))
}

/// The value an object stands for as a document, if it is one.
fn document_value(v: Value) -> R<Document> {
    match v {
        Value::Ext(Ext::Document(d)) => Ok(*d),
        Value::Object(a) if a.is_empty() => Ok(Document::empty()),
        Value::Array(a) => Ok(Document::new(a)?),
        other => fault(format!("expected a document, got {}", other.type_name())),
    }
}

/// A stored object as a document.
pub fn obj_document(o: Obj) -> R<Document> {
    match o.into_result() {
        Obj::Value(v) => document_value(v),
        other => fault(format!("a stored {} is not a document", other.class().unwrap_or("object"))),
    }
}

/// A document argument: a `$document` tag, a plain array or a stored one.
pub fn document_of(cx: &Ctx, json: &Json) -> R<Document> {
    if let Some(obj) = stored_obj(cx, json)? {
        return obj_document(obj);
    }
    Ok(codec::document(&resolve(cx, json)?)?)
}

/// A stored object as a query.
fn obj_query(o: Obj) -> R<Query> {
    match o.into_result() {
        Obj::Value(Value::Ext(Ext::Query(q))) => Ok(Query::from(*q)),
        Obj::Value(Value::Str(s)) => Ok(Query::parse(&String::from_utf8_lossy(s.as_bytes()), false)?),
        Obj::Value(Value::Array(a)) => Ok(Query::parse_query(&value::to_query_array(&a), false)?),
        other => fault(format!("a stored {} is not a query", other.class().unwrap_or("object"))),
    }
}

/// A query argument: a `$query` tag, an array (`Query::parseQuery`), a
/// string (`Query::parse`) or a stored query.
pub fn query_of(cx: &Ctx, json: &Json) -> R<Query> {
    if let Some(obj) = stored_obj(cx, json)? {
        return obj_query(obj);
    }
    match json {
        Json::String(s) => Ok(Query::parse(s, false)?),
        other => match decode_value(cx, other)? {
            Value::Ext(Ext::Query(q)) => Ok(Query::from(*q)),
            Value::Array(a) => Ok(Query::parse_query(&value::to_query_array(&a), false)?),
            Value::Str(s) => Ok(Query::parse_bytes(s.as_bytes(), false)?),
            // `Values::queries` hands anything else to `parseQuery(array)`.
            v => Err(Fail::Error(Error::new(
                Kind::TypeError,
                format!(
                    "Utopia\\Database\\Query::parseQuery(): Argument #1 ($query) must be of type array, {} given, called in /usr/src/code/tests/compat/database/Values.php on line 230",
                    zend_type(&v)
                ),
            ))),
        },
    }
}

/// A value's type as a `TypeError` names it (`zend_zval_value_name()`):
/// `true` and `false` for booleans, else `get_debug_type()`.
pub fn zend_type(v: &Value) -> &str {
    match v {
        Value::Bool(true) => "true",
        Value::Bool(false) => "false",
        other => other.debug_type(),
    }
}

/// An object downcast to `T`.
pub fn downcast<T: Clone + 'static>(obj: &Obj) -> R<T> {
    match obj.downcast_ref::<T>() {
        Some(t) => Ok(t.clone()),
        None => fault(format!(
            "expected a {}, got {:?}",
            std::any::type_name::<T>().rsplit("::").next().unwrap_or("value"),
            obj
        )),
    }
}

/// An object of type `T` from JSON: stored, or decoded from its tag.
pub fn typed_of<T: Clone + 'static>(cx: &Ctx, json: &Json) -> R<T> {
    let obj = decode_obj(cx, json)?.into_result();
    downcast::<T>(&obj)
}

/// A collection from a value (`Collection::fromDocument`).
pub fn collection_of(cx: &Ctx, json: &Json) -> R<Collection> {
    Ok(Collection::from_document(&document_of(cx, json)?)?)
}

/// A collection as a value: its definition document, of class `Collection`.
pub fn collection_value(c: &Collection) -> Value {
    Value::from(c.to_document().with_class(collection::CLASS))
}

/// An enum case object (for results).
pub fn enum_case(class: &str, case: &str) -> Obj {
    Obj::Enum(EnumCase { class: class.to_owned(), case: case.to_owned() })
}
