//! `Document` (and a `Collection` read as one): `new Document($input)`,
//! the static factories (`fromArray`, `fromRow`, `fromStorage`) and every
//! public method of `Utopia\Database\Document` and its `ArrayObject` base,
//! on any document value (a `Collection` or a custom document type
//! included). Methods only a `Collection` has are left to [`super::model`].
//!
//! Parameters are typed as PHP types them, in coercive mode (the harness's
//! `Core::invoke` is not strict): a scalar given for a `string` parameter is
//! cast, anything else is the `TypeError` PHP raises, with the line of
//! `ops/core.php` that made the call. Return types coerce the same way
//! (`getId()` of an integer `$id` is a string).

use serde_json::Value as Json;
use utopia_database::document::{self as doc, Document, SetType};
use utopia_database::permission::PermissionType;
use utopia_database::value::{Array, ArrayKey, Ext, Str, Value};
use utopia_database::{Error, Kind};

use crate::libs::database::args::Args;
use crate::libs::database::convert::IntoObj;
use crate::libs::database::ctx::{Fail, R, fault};
use crate::libs::database::enums::{self, PhpEnum};
use crate::libs::database::obj::Obj;

/// The harness file whose calls PHP's `TypeError`s name.
const CORE: &str = "/usr/src/code/tests/compat/database/ops/core.php";

/// The line of [`CORE`] that calls a constructor (`new $class(...$named)`).
const LINE_NEW: u32 = 608;

/// The line of [`CORE`] that calls an instance method.
const LINE_CALL: u32 = 609;

/// The line of [`CORE`] that calls a static method.
const LINE_STATIC: u32 = 610;

/// `new <class>(...)` for the classes of this module (`None`: not one).
pub async fn construct(class: &str, a: &Args) -> R<Option<Obj>> {
    if class != "Document" {
        return Ok(None);
    }
    let call = Call { method: "__construct", line: LINE_NEW };
    let input = match a.raw("input") {
        None => Array::new(),
        Some(_) => call.array(a, 1, "input")?,
    };
    Ok(Some(Document::new(input)?.into_obj()))
}

/// `<class>::<method>(...)` for the classes of this module.
pub async fn call_static(class: &str, method: &str, a: &Args) -> R<Option<Obj>> {
    if class != "Document" {
        return Ok(None);
    }
    let call = Call { method, line: LINE_STATIC };
    let document = match method {
        "fromArray" => Document::new(call.req_array(a, 1, "data")?)?,
        "fromRow" => Document::from_row(call.req_array(a, 1, "row")?)?,
        "fromStorage" => Document::from_storage(call.req_array(a, 1, "data")?)?,
        _ => return Ok(None),
    };
    Ok(Some(document.into_obj()))
}

/// `$this-><method>(...)` for the objects of this module.
pub async fn invoke(this: &mut Obj, method: &str, a: &Args) -> R<Option<Obj>> {
    let Obj::Value(Value::Ext(Ext::Document(d))) = this else {
        return Ok(None);
    };
    let d: &mut Document = d;
    let call = Call { method, line: LINE_CALL };
    let result = match method {
        // ----- Reads -----
        "getId" => call.returned_string(d.get(doc::ID), Some(""))?.into_obj(),
        "getSequence" => call.returned_string(d.get(doc::SEQUENCE), None)?.into_obj(),
        "getCollection" => call.returned_string(d.get(doc::COLLECTION), Some(""))?.into_obj(),
        "getCreatedAt" => call.returned_string(d.get(doc::CREATED_AT), None)?.into_obj(),
        "getUpdatedAt" => call.returned_string(d.get(doc::UPDATED_AT), None)?.into_obj(),
        "getTenant" => d.tenant().into_obj(),
        "getPermissions" => d.permissions()?.into_obj(),
        "getPermissionsByType" => {
            let kind = permission_type(&call, a)?;
            d.permissions_by_type(kind)?.into_obj()
        }
        "getAttributes" => d.attributes().into_obj(),
        "getAttribute" => {
            let name = call.req_string(a, 1, "name")?;
            match d.get(&name) {
                Some(v) => v.clone().into_obj(),
                None => a.value("default")?.into_obj(),
            }
        }
        "getArray" => d.array(&call.req_string(a, 1, "key")?).into_obj(),
        "getDocuments" => d.documents(&call.req_string(a, 1, "key")?)?.into_obj(),
        "getDocument" => d.document(&call.req_string(a, 1, "key")?)?.into_obj(),
        "isEmpty" => d.is_empty().into_obj(),
        "isSet" => d.is_set(&call.req_string(a, 1, "key")?).into_obj(),
        "getArrayCopy" => d.array_copy().into_obj(),
        "only" => {
            let keys = keys(&call, a)?;
            d.only(&keys.iter().map(String::as_str).collect::<Vec<_>>()).into_obj()
        }
        "except" => {
            let keys = keys(&call, a)?;
            d.except(&keys.iter().map(String::as_str).collect::<Vec<_>>()).into_obj()
        }

        // ----- Writes (fluent: `return $this`) -----
        "setAttribute" => {
            let key = call.req_string(a, 1, "key")?;
            if !a.has("value") {
                return call.missing("value");
            }
            let value = a.value("value")?;
            let how = set_type(&call, a)?;
            d.set_with(key, value, how)?;
            fluent(d)
        }
        "setAttributes" => {
            let attributes = call.req_array(a, 1, "attributes")?;
            d.set_many(attributes)?;
            fluent(d)
        }
        "removeAttribute" => {
            let key = call.req_string(a, 1, "key")?;
            d.remove(&key);
            fluent(d)
        }

        // ----- ArrayObject -----
        "count" => d.len().into_obj(),
        "offsetExists" => d.as_array().get(&offset(a, "key")?).is_some().into_obj(),
        "offsetGet" => d.as_array().get(&offset(a, "key")?).cloned().into_obj(),
        "offsetSet" => {
            let value = a.value("value")?;
            match a.raw("key") {
                None | Some(Json::Null) => push(d.as_array_mut(), value)?,
                Some(_) => {
                    d.as_array_mut().set(offset(a, "key")?, value);
                }
            }
            Obj::null()
        }
        "offsetUnset" => {
            d.as_array_mut().remove(&offset(a, "key")?);
            Obj::null()
        }
        "append" => {
            if !a.has("value") {
                return call.missing("value");
            }
            push(d.as_array_mut(), a.value("value")?)?;
            Obj::null()
        }
        "exchangeArray" => {
            let next = match call.required(a, "array")? {
                Value::Array(items) => items,
                Value::Ext(Ext::Document(other)) => other.as_array().clone(),
                Value::Object(props) => props,
                other => {
                    return Err(Fail::php(
                        "TypeError",
                        format!(
                            "ArrayObject::exchangeArray(): Argument #1 ($array) must be of type array, {} given",
                            value_name(&other)
                        ),
                    ));
                }
            };
            let previous = std::mem::replace(d.as_array_mut(), next);
            Value::Array(previous).into_obj()
        }
        _ => return Ok(None),
    };
    Ok(Some(result))
}

/// The document a fluent method returns (`$this`).
fn fluent(d: &Document) -> Obj {
    d.clone().into_obj()
}

/// `$array[] = $value` on a document's storage.
fn push(attrs: &mut Array, value: Value) -> R<()> {
    attrs
        .push(value)
        .map_err(|_| Fail::php("Error", "Cannot add element to the array as the next element is already occupied"))
}

/// The name PHP gives a value's type in a `TypeError`
/// (`zend_zval_value_name`).
fn value_name(v: &Value) -> &str {
    match v {
        Value::Bool(true) => "true",
        Value::Bool(false) => "false",
        other => other.debug_type(),
    }
}

/// A key of `ArrayObject`'s `offset*()` methods (`mixed $key`), as the
/// engine normalises an array offset.
fn offset(a: &Args, name: &str) -> R<ArrayKey> {
    Ok(match a.value(name)? {
        Value::Str(s) => ArrayKey::normalize(s),
        Value::Int(i) => ArrayKey::Int(i),
        Value::Bool(b) => ArrayKey::Int(i64::from(b)),
        Value::Float(f) if f.is_finite() => ArrayKey::Int(php_std::format::dval_to_lval(f)),
        Value::Null => ArrayKey::Str(Str::EMPTY),
        other => {
            return Err(Fail::php(
                "TypeError",
                format!("Cannot access offset of type {} on ArrayObject", other.debug_type()),
            ));
        }
    })
}

/// The keys of `only()` and `except()` (`array $keys`): what `array_flip()`
/// keeps of them, strings and integers.
fn keys(call: &Call<'_>, a: &Args) -> R<Vec<String>> {
    Ok(call
        .req_array(a, 1, "keys")?
        .values()
        .filter_map(|k| match k {
            Value::Str(s) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
            Value::Int(i) => Some(i.to_string()),
            _ => None,
        })
        .collect())
}

/// `setAttribute()`'s `SetType $type = SetType::Assign`.
fn set_type(call: &Call<'_>, a: &Args) -> R<SetType> {
    match a.raw("type") {
        None => Ok(SetType::Assign),
        Some(Json::Null) => Err(call.type_error(3, "type", "Utopia\\Database\\SetType", &Value::Null)),
        Some(_) => enum_param::<SetType>(call, a, 3, "type", "Utopia\\Database\\SetType"),
    }
}

/// `getPermissionsByType()`'s `PermissionType|string $type`: a case, or a
/// string (which the harness makes a case first, `PermissionType::from()`).
fn permission_type(call: &Call<'_>, a: &Args) -> R<String> {
    match a.raw("type") {
        None => call.missing("type"),
        Some(Json::Null) => Err(call.type_error(1, "type", "Utopia\\Database\\PermissionType|string", &Value::Null)),
        Some(_) => Ok(enum_param::<PermissionType>(call, a, 1, "type", "Utopia\\Database\\PermissionType|string")?
            .as_str()
            .to_owned()),
    }
}

/// An enum parameter: a `$enum` tag or stored case, or a backing value
/// (`Type::from($value)`, an integer cast to its string); anything else is
/// a `TypeError`.
fn enum_param<E: PhpEnum>(call: &Call<'_>, a: &Args, position: usize, name: &str, ty: &str) -> R<E> {
    if let Some(Json::Number(n)) = a.raw(name) {
        let text = n.to_string();
        return match E::from_backing(&text) {
            Some(e) => Ok(e),
            None => Err(Fail::Error(Error::new(
                Kind::ValueError,
                format!(
                    "\"{text}\" is not a valid backing value for enum {}",
                    crate::libs::database::codec::full_class(E::CLASS)
                ),
            ))),
        };
    }
    if let Some(Json::String(_)) = a.raw(name) {
        return a.req_enum::<E>(name);
    }
    match a.obj(name)? {
        Some(Obj::Enum(e)) => match enums::from_case::<E>(&e) {
            Some(c) => Ok(c),
            None => fault(format!("`{}::{}` is not a case of {}", e.class, e.case, E::CLASS)),
        },
        Some(Obj::Value(v)) => Err(call.type_error(position, name, ty, &v)),
        _ => fault(format!("`{name}` is not a case of {}", E::CLASS)),
    }
}

/// One call of a `Document` method: what its `TypeError`s name.
struct Call<'a> {
    /// The method (`__construct` for `new`).
    method: &'a str,
    /// The line of [`CORE`] that made the call.
    line: u32,
}

impl Call<'_> {
    /// A required argument absent (`Core::invoke`'s fault).
    fn missing<T>(&self, name: &str) -> R<T> {
        fault(format!("{}::{}: missing `{name}`", doc::CLASS, self.method))
    }

    /// A required argument's value.
    fn required(&self, a: &Args, name: &str) -> R<Value> {
        if !a.has(name) {
            return self.missing(name);
        }
        a.value(name)
    }

    /// The `TypeError` of an argument of the wrong type.
    fn type_error(&self, position: usize, name: &str, ty: &str, given: &Value) -> Fail {
        Fail::php(
            "TypeError",
            format!(
                "{}::{}(): Argument #{position} (${name}) must be of type {ty}, {} given, called in {CORE} on line {}",
                doc::CLASS,
                self.method,
                value_name(given),
                self.line
            ),
        )
    }

    /// An `array` argument (present).
    fn array(&self, a: &Args, position: usize, name: &str) -> R<Array> {
        match a.value(name)? {
            Value::Array(items) => Ok(items),
            other => Err(self.type_error(position, name, "array", &other)),
        }
    }

    /// A required `array` argument.
    fn req_array(&self, a: &Args, position: usize, name: &str) -> R<Array> {
        if !a.has(name) {
            return self.missing(name);
        }
        self.array(a, position, name)
    }

    /// A required `string` argument: scalars cast, as PHP's coercive mode
    /// does.
    fn req_string(&self, a: &Args, position: usize, name: &str) -> R<String> {
        let value = self.required(a, name)?;
        match &value {
            Value::Str(s) => Ok(String::from_utf8_lossy(s.as_bytes()).into_owned()),
            Value::Int(_) | Value::Float(_) | Value::Bool(_) => match value.to_php_string() {
                Ok(s) => Ok(String::from_utf8_lossy(s.as_bytes()).into_owned()),
                Err(_) => Err(self.type_error(position, name, "string", &value)),
            },
            _ => Err(self.type_error(position, name, "string", &value)),
        }
    }

    /// What a method typed `string` (with `default`) or `?string` (without)
    /// returns for an attribute: scalars cast, as PHP's coercive mode does;
    /// anything else is the return `TypeError`.
    fn returned_string(&self, value: Option<&Value>, default: Option<&str>) -> R<Option<Value>> {
        let ty = if default.is_some() { "string" } else { "?string" };
        Ok(match value {
            None => default.map(|d| Value::Str(Str::from(d))),
            Some(v @ Value::Str(_)) => Some(v.clone()),
            Some(v @ (Value::Int(_) | Value::Float(_) | Value::Bool(_))) => {
                Some(Value::Str(v.to_php_string().map_err(|_| self.return_error(ty, v))?))
            }
            Some(other) => return Err(self.return_error(ty, other)),
        })
    }

    /// The `TypeError` of a return value of the wrong type.
    fn return_error(&self, ty: &str, given: &Value) -> Fail {
        Fail::php(
            "TypeError",
            format!(
                "{}::{}(): Return value must be of type {ty}, {} returned",
                doc::CLASS,
                self.method,
                value_name(given)
            ),
        )
    }
}
