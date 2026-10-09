//! `pcre.*`: `preg_match`, `preg_match_all`, `preg_replace`,
//! `preg_replace_callback`, `preg_split`, `preg_quote`, `preg_grep`
//! (`php_std::pcre`). Each reports what `ops/pcre.php` reports: the return
//! value, the by-reference outputs, `preg_last_error()` and the warning.
//! `pcre.preg_match` with `"matches": false` leaves `$matches` out of the
//! call: with no flags or offset that is [`pcre::preg_match_bare`].

use php_std::pcre::{self, Array, Key, Preg, StrOrArray};
use serde_json::{Map, Value};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value};

pub const OPS: &[&str] = &[
    "pcre.preg_match",
    "pcre.preg_match_all",
    "pcre.preg_replace",
    "pcre.preg_replace_callback",
    "pcre.preg_split",
    "pcre.preg_quote",
    "pcre.preg_grep",
];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let flags = a.opt_i64("flags")?.unwrap_or(0);
    Ok(match op {
        "pcre.preg_match" | "pcre.preg_match_all" => {
            let pattern = a.bytes("pattern")?;
            let subject = a.bytes("subject")?;
            let offset = a.opt_i64("offset")?.unwrap_or(0);
            if op == "pcre.preg_match" && a.opt_bool("matches")? == Some(false) {
                let named = a.opt_i64("flags")?.is_some() || a.opt_i64("offset")?.is_some();
                let r = if named {
                    pcre::preg_match(&pattern, &subject, flags, offset).map(|p| Preg {
                        value: p.value.result,
                        error: p.error,
                        warning: p.warning,
                    })
                } else {
                    pcre::preg_match_bare(&pattern, &subject)
                };
                return Ok(match r {
                    Ok(p) => {
                        let mut o = Map::new();
                        o.insert("result".into(), php_value(&p.value));
                        report(o, &p)
                    }
                    Err(e) => Outcome::err(e.php_class(), e.to_string()),
                });
            }
            let r = if op == "pcre.preg_match" {
                pcre::preg_match(&pattern, &subject, flags, offset)
            } else {
                pcre::preg_match_all(&pattern, &subject, flags, offset)
            };
            match r {
                Ok(p) => {
                    let mut o = Map::new();
                    o.insert("result".into(), php_value(&p.value.result));
                    o.insert("matches".into(), p.value.matches.as_ref().map_or(Value::Null, array_value));
                    report(o, &p)
                }
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            }
        }
        "pcre.preg_replace" => {
            let f = "preg_replace";
            let pattern = match str_or_array(a.value("pattern")?, f, "1 ($pattern)")? {
                Ok(v) => v,
                Err(e) => return Ok(e),
            };
            let replacement = match str_or_array(a.value("replacement")?, f, "2 ($replacement)")? {
                Ok(v) => v,
                Err(e) => return Ok(e),
            };
            let subject = match str_or_array(a.value("subject")?, f, "3 ($subject)")? {
                Ok(v) => v,
                Err(e) => return Ok(e),
            };
            let limit = a.opt_i64("limit")?.unwrap_or(-1);
            match pcre::preg_replace(&pattern.borrow(), &replacement.borrow(), &subject.borrow(), limit) {
                Ok(p) => {
                    let mut o = Map::new();
                    o.insert("result".into(), php_value(&p.value.result));
                    o.insert("count".into(), Value::from(p.value.count));
                    report(o, &p)
                }
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            }
        }
        "pcre.preg_replace_callback" => {
            let f = "preg_replace_callback";
            let pattern = match str_or_array(a.value("pattern")?, f, "1 ($pattern)")? {
                Ok(v) => v,
                Err(e) => return Ok(e),
            };
            let subject = match str_or_array(a.value("subject")?, f, "3 ($subject)")? {
                Ok(v) => v,
                Err(e) => return Ok(e),
            };
            let limit = a.opt_i64("limit")?.unwrap_or(-1);
            let mut calls: Vec<Value> = Vec::new();
            let mut callback = |m: &Array| {
                calls.push(array_value(m));
                format!("<{}>", calls.len()).into_bytes()
            };
            let p = pcre::preg_replace_callback(&pattern.borrow(), &mut callback, &subject.borrow(), limit, flags);
            let mut o = Map::new();
            o.insert("result".into(), php_value(&p.value.result));
            o.insert("count".into(), Value::from(p.value.count));
            o.insert("calls".into(), Value::Array(calls));
            report(o, &p)
        }
        "pcre.preg_split" => {
            let limit = a.opt_i64("limit")?.unwrap_or(-1);
            let p = pcre::preg_split(&a.bytes("pattern")?, &a.bytes("subject")?, limit, flags);
            let mut o = Map::new();
            o.insert("result".into(), php_value(&p.value));
            report(o, &p)
        }
        "pcre.preg_quote" => {
            let delimiter = match a.opt("delimiter") {
                None | Some(Value::Null) => None,
                Some(v) => Some(bytes(v).ok_or_else(|| Fault::new("argument `delimiter` must be a string"))?),
            };
            Outcome::Ok(bytes_value(&pcre::preg_quote(&a.bytes("str")?, delimiter.as_deref())))
        }
        "pcre.preg_grep" => {
            let array = a.value("array")?;
            if is_std_class(array) {
                return Ok(Outcome::err(
                    "TypeError",
                    "preg_grep(): Argument #2 ($array) must be of type array, stdClass given",
                ));
            }
            let input = entries(array)?;
            let p = pcre::preg_grep(&a.bytes("pattern")?, &input, flags);
            let mut o = Map::new();
            o.insert("result".into(), php_value(&p.value));
            report(o, &p)
        }
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    })
}

fn report<T>(mut o: Map<String, Value>, p: &Preg<T>) -> Outcome {
    o.insert("error".into(), Value::from(p.error.code()));
    o.insert("warning".into(), p.warning.as_deref().map_or(Value::Null, bytes_value));
    Outcome::Ok(Value::Object(o))
}

/// A `string|array` argument, owned.
enum Owned {
    Str(Vec<u8>),
    Array(Vec<(Key, Vec<u8>)>),
}

impl Owned {
    fn borrow(&self) -> StrOrArray<'_> {
        match self {
            Owned::Str(s) => StrOrArray::Str(s),
            Owned::Array(a) => StrOrArray::Array(a.clone()),
        }
    }
}

/// An empty JSON object, which the PHP driver decodes as `stdClass`.
fn is_std_class(v: &Value) -> bool {
    matches!(v, Value::Object(o) if o.is_empty())
}

/// A `array|string` parameter; a `stdClass` is PHP's parameter TypeError.
fn str_or_array(v: &Value, func: &str, arg: &str) -> Result<Result<Owned, Outcome>, Fault> {
    if is_std_class(v) {
        return Ok(Err(Outcome::err(
            "TypeError",
            format!("{func}(): Argument #{arg} must be of type array|string, stdClass given"),
        )));
    }
    Ok(Ok(match v {
        Value::Array(_) => Owned::Array(entries(v)?),
        Value::Object(o) if !o.contains_key("$bytes") => Owned::Array(entries(v)?),
        _ => bytes(v).map(Owned::Str).ok_or_else(|| Fault::new("expected a string or an array of strings"))?,
    }))
}

/// The entries of a decoded PHP array of strings.
fn entries(v: &Value) -> Result<Vec<(Key, Vec<u8>)>, Fault> {
    let item = |v: &Value| bytes(v).ok_or_else(|| Fault::new("array entries must be strings"));
    match v {
        Value::Array(list) => list.iter().enumerate().map(|(i, v)| Ok((Key::Int(i as i64), item(v)?))).collect(),
        Value::Object(o) => o.iter().map(|(k, v)| Ok((php_key(k), item(v)?))).collect(),
        _ => Err(Fault::new("expected an array")),
    }
}

/// A PHP array key from a JSON object key: decimal integer strings become
/// integer keys (`ZEND_HANDLE_NUMERIC_STR`).
pub(super) fn php_key(k: &str) -> Key {
    let b = k.as_bytes();
    let digits = b.strip_prefix(b"-").unwrap_or(b);
    let canonical = !digits.is_empty()
        && digits.iter().all(u8::is_ascii_digit)
        && (digits[0] != b'0' || digits.len() == 1)
        && !(b[0] == b'-' && digits == b"0");
    match (canonical, k.parse::<i64>()) {
        (true, Ok(i)) => Key::Int(i),
        _ => Key::Str(b.to_vec()),
    }
}

fn php_value(v: &pcre::Value) -> Value {
    match v {
        pcre::Value::Null => Value::Null,
        pcre::Value::Bool(b) => Value::Bool(*b),
        pcre::Value::Int(i) => Value::from(*i),
        pcre::Value::Str(s) => bytes_value(s),
        pcre::Value::Array(a) => array_value(a),
    }
}

/// A PHP array as `json_encode` writes it: a list as a JSON array, anything
/// else as an object.
fn array_value(a: &Array) -> Value {
    if a.is_list() {
        return Value::Array(a.entries().iter().map(|(_, v)| php_value(v)).collect());
    }
    let mut o = Map::new();
    for (k, v) in a.entries() {
        let key = match k {
            Key::Int(i) => i.to_string(),
            Key::Str(s) => String::from_utf8_lossy(s).into_owned(),
        };
        o.insert(key, php_value(v));
    }
    Value::Object(o)
}
