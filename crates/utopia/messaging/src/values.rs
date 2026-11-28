//! Reading provider answers the way the PHP adapters read them: nested
//! offsets with `??` (absent and `null` are the same), and strings passed
//! to `string` parameters with PHP's coercions.

use php_std::types::{ArrayKey, numeric_key};
use php_std::{Array, Str, Value};

use crate::error::Error;

/// An offset: `['name']` or `[0]`.
#[derive(Debug, Clone, Copy)]
pub(crate) enum K<'a> {
    /// A string offset.
    S(&'a str),
    /// An integer offset.
    I(i64),
}

/// `$value[k1][k2]... ?? null`: the value at the path, `None` when an
/// offset is absent, the value at it is `null`, or the value is neither an
/// array nor a string (a string's offsets are its bytes).
pub(crate) fn at(value: &Value, path: &[K<'_>]) -> Option<Value> {
    let mut current = value.clone();
    for key in path {
        current = match &current {
            Value::Array(array) => get(array, *key)?.clone(),
            Value::Str(s) => string_offset(s, *key)?,
            _ => return None,
        };
    }
    (!matches!(current, Value::Null)).then_some(current)
}

fn get<'a>(array: &'a Array, key: K<'_>) -> Option<&'a Value> {
    match key {
        K::I(i) => array.get(&ArrayKey::Int(i)),
        K::S(s) => match numeric_key(s.as_bytes()) {
            Some(i) => array.get(&ArrayKey::Int(i)),
            None => array.get_str(s),
        },
    }
}

/// `isset($string[$key])`: a byte of the string for an integer offset in
/// range (negative from the end), nothing otherwise.
fn string_offset(s: &Str, key: K<'_>) -> Option<Value> {
    let offset = match key {
        K::I(i) => i,
        K::S(text) => numeric_key(text.as_bytes())?,
    };
    let bytes = s.as_bytes();
    let len = i64::try_from(bytes.len()).ok()?;
    let index = if offset < 0 { offset + len } else { offset };
    if !(0..len).contains(&index) {
        return None;
    }
    let index = usize::try_from(index).ok()?;
    Some(Value::Str(Str::copy_from(&bytes[index..=index])))
}

/// The string of a PHP scalar: what `(string)` gives, and what a `string`
/// parameter receives in coercive mode. `None` for `null` and arrays.
pub(crate) fn scalar_text(value: &Value) -> Option<String> {
    match value {
        Value::Str(s) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        Value::Int(i) => Some(i.to_string()),
        Value::Float(f) => Some(php_std::number::to_string(*f)),
        Value::Bool(true) => Some("1".to_owned()),
        Value::Bool(false) => Some(String::new()),
        _ => None,
    }
}

/// `(string) $value`, as string interpolation and concatenation write it:
/// arrays are `Array`, `null` is empty.
pub(crate) fn interpolate(value: &Value) -> String {
    match value {
        Value::Null => String::new(),
        Value::Array(_) => "Array".to_owned(),
        other => scalar_text(other).unwrap_or_default(),
    }
}

/// The PHP type name a `TypeError` quotes.
fn type_name(value: &Value) -> &'static str {
    match value {
        Value::Null => "null",
        Value::Bool(_) => "bool",
        Value::Int(_) => "int",
        Value::Float(_) => "float",
        Value::Str(_) => "string",
        Value::Array(_) => "array",
        Value::Object(_) => "stdClass",
        Value::Ext(_) => "object",
    }
}

/// Where a value is passed to `Response::addResult()` from an adapter
/// without strict types: the argument, and the caller's file and line.
pub(crate) struct Site {
    /// `1` for `$recipient`, `2` for `$error`.
    pub argument: u8,
    /// The adapter's file, from `src/`.
    pub file: &'static str,
    /// The line of the call.
    pub line: u32,
}

/// The string `Response::addResult()` receives for `value` passed at
/// `site`: scalars are coerced, `null` and arrays are a `TypeError`.
pub(crate) fn result_text(value: &Value, site: Site) -> Result<String, Error> {
    scalar_text(value).ok_or_else(|| {
        let param = if site.argument == 1 { "recipient" } else { "error" };
        Error::Type(format!(
            "Utopia\\Messaging\\Response::addResult(): Argument #{} (${param}) must be of type string, {} given, called in /usr/src/code/packages/messaging/src/{} on line {}",
            site.argument,
            type_name(value),
            site.file,
            site.line
        ))
    })
}

/// A PHP string value.
pub(crate) fn text(s: &str) -> Value {
    Value::Str(Str::from(s))
}

/// A PHP array of `(key, value)` entries, keys as PHP normalises them.
pub(crate) fn map<'a>(entries: impl IntoIterator<Item = (&'a str, Value)>) -> Value {
    Value::Array(entries.into_iter().map(|(k, v)| (ArrayKey::normalize(Str::from(k)), v)).collect())
}

/// A PHP list.
pub(crate) fn list(values: impl IntoIterator<Item = Value>) -> Value {
    Value::Array(values.into_iter().collect())
}

/// `$array[$key] = $value`, creating nested arrays along `path` the way
/// PHP's auto-vivification does (a non-array in the way is replaced).
pub(crate) fn set(target: &mut Value, path: &[&str], value: Value) {
    let Some((first, rest)) = path.split_first() else {
        *target = value;
        return;
    };
    if !matches!(target, Value::Array(_)) {
        *target = Value::Array(Array::new());
    }
    let Value::Array(array) = target else { unreachable!() };
    let key = ArrayKey::normalize(Str::from(*first));
    if array.get(&key).is_none() {
        array.set(key.clone(), Value::Null);
    }
    if let Some(slot) = array.get_mut(&key) {
        set(slot, rest, value);
    }
}

/// The entries of a PHP array, `[]` for anything else.
pub(crate) fn entries(value: &Value) -> Vec<(ArrayKey, Value)> {
    match value {
        Value::Array(a) => a.iter().map(|(k, v)| (k.to_owned(), v.clone())).collect(),
        _ => Vec::new(),
    }
}
