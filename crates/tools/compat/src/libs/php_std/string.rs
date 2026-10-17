//! `string.*`: byte-string functions (`php_std::string`).
//!
//! Arguments carry PHP's parameter names (the PHP side spreads them as named
//! arguments), and omitted optional arguments take PHP's defaults. Strings
//! may be `{"$bytes": ...}`.

use std::borrow::Cow;

use php_std::string::{self, Error, Replace};
use serde_json::{Map, Value};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value};

pub const OPS: &[&str] = &[
    "string.strtolower",
    "string.strtoupper",
    "string.ucfirst",
    "string.lcfirst",
    "string.strip_tags",
    "string.ucwords",
    "string.trim",
    "string.ltrim",
    "string.rtrim",
    "string.substr",
    "string.stripos",
    "string.str_contains",
    "string.str_starts_with",
    "string.strcmp",
    "string.strcasecmp",
    "string.str_replace",
    "string.strtr",
    "string.explode",
    "string.strval",
    "string.basename",
    "string.escapeshellarg",
    "string.addcslashes",
    "string.stripcslashes",
    "string.ctype_alnum",
    "string.ctype_digit",
    "string.ctype_xdigit",
    "string.crypt",
    "string.hash",
    "string.password_verify",
    "string.password_hash",
];

/// A PHP exception as an outcome.
pub(super) fn thrown(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.message())
}

/// The outcome of a function that may throw.
pub(super) fn done(r: Result<Value, Error>) -> OpResult {
    Ok(match r {
        Ok(v) => Outcome::Ok(v),
        Err(e) => thrown(e),
    })
}

/// An `int|false` result.
pub(super) fn position(p: Option<usize>) -> Value {
    p.map(Value::from).unwrap_or(Value::Bool(false))
}

/// A `string|false` result.
pub(super) fn string_or_false(s: Option<&[u8]>) -> Value {
    s.map(bytes_value).unwrap_or(Value::Bool(false))
}

/// A list of strings.
pub(super) fn list<T: AsRef<[u8]>>(items: &[T]) -> Value {
    Value::Array(items.iter().map(|i| bytes_value(i.as_ref())).collect())
}

/// An optional byte-string argument.
pub(super) fn opt_bytes(a: &Args, key: &str) -> Result<Option<Vec<u8>>, Fault> {
    a.opt(key)
        .map(|v| bytes(v).ok_or_else(|| Fault::new(format!("argument `{key}` must be a string or $bytes"))))
        .transpose()
}

/// A PHP value converted to a string the way string functions do: `$bytes`
/// is its bytes, anything else goes through `php_std::string::to_php_string`.
pub(super) fn coerce(value: &Value) -> Result<Cow<'_, [u8]>, Error> {
    if let Some(b) = value.get("$bytes").and_then(|_| bytes(value)) {
        return Ok(Cow::Owned(b));
    }
    Ok(match string::to_php_string(value)? {
        Cow::Borrowed(s) => Cow::Borrowed(s.as_bytes()),
        Cow::Owned(s) => Cow::Owned(s.into_bytes()),
    })
}

/// Whether a value is a PHP array (a list or a non-empty object that is not `$bytes`).
pub(super) fn is_php_array(value: &Value) -> bool {
    match value {
        Value::Array(_) => true,
        Value::Object(o) => !(o.is_empty() || (o.len() == 1 && o.contains_key("$bytes"))),
        _ => false,
    }
}

/// The entries of a PHP array value, with keys as PHP prints them.
pub(super) fn entries(value: &Value) -> Vec<(String, &Value)> {
    match value {
        Value::Array(a) => a.iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect(),
        Value::Object(o) => o.iter().map(|(k, v)| (k.clone(), v)).collect(),
        _ => Vec::new(),
    }
}

/// A PHP array as `json_encode` writes it: a list when its keys are 0..n-1
/// in order, an object otherwise.
pub(super) fn php_array(items: Vec<(String, Value)>) -> Value {
    if items.iter().enumerate().all(|(i, (k, _))| *k == i.to_string()) {
        Value::Array(items.into_iter().map(|(_, v)| v).collect())
    } else {
        Value::Object(items.into_iter().collect::<Map<String, Value>>())
    }
}

fn replace(a: &Args) -> Result<Outcome, Fault> {
    let (search, replace, subject) = (a.value("search")?, a.value("replace")?, a.value("subject")?);
    if !is_php_array(search) && is_php_array(replace) {
        return Err(Fault::new("a string $search with an array $replace has no Rust form (deviation)"));
    }
    let run = |subject: &[u8]| -> Result<(Vec<u8>, usize), Error> {
        if is_php_array(search) {
            let searches: Vec<Cow<'_, [u8]>> =
                entries(search).into_iter().map(|(_, v)| coerce(v)).collect::<Result<_, _>>()?;
            let search_refs: Vec<&[u8]> = searches.iter().map(|s| s.as_ref()).collect();
            if is_php_array(replace) {
                let replaces: Vec<Cow<'_, [u8]>> =
                    entries(replace).into_iter().map(|(_, v)| coerce(v)).collect::<Result<_, _>>()?;
                let replace_refs: Vec<&[u8]> = replaces.iter().map(|s| s.as_ref()).collect();
                let (r, n) = string::str_replace_array(&search_refs, Replace::Each(&replace_refs), subject, false);
                Ok((r.into_owned(), n))
            } else {
                let r = coerce(replace)?;
                let (r, n) = string::str_replace_array(&search_refs, Replace::All(&r), subject, false);
                Ok((r.into_owned(), n))
            }
        } else {
            let (s, r) = (coerce(search)?, coerce(replace)?);
            let (out, n) = string::str_replace(&s, &r, subject);
            Ok((out.into_owned(), n))
        }
    };
    let result = if is_php_array(subject) {
        let mut items = Vec::new();
        let mut total = 0;
        for (k, v) in entries(subject) {
            let s = match coerce(v) {
                Ok(s) => s,
                Err(e) => return Ok(thrown(e)),
            };
            match run(&s) {
                Ok((r, n)) => {
                    items.push((k, bytes_value(&r)));
                    total += n;
                }
                Err(e) => return Ok(thrown(e)),
            }
        }
        (php_array(items), total)
    } else {
        let s = match coerce(subject) {
            Ok(s) => s,
            Err(e) => return Ok(thrown(e)),
        };
        match run(&s) {
            Ok((r, n)) => (bytes_value(&r), n),
            Err(e) => return Ok(thrown(e)),
        }
    };
    Ok(Outcome::Ok(Value::Array(vec![result.0, Value::from(result.1)])))
}

fn strtr(a: &Args) -> Result<Outcome, Fault> {
    let s = a.bytes("string")?;
    let from = a.value("from")?;
    if is_php_array(from) {
        let pairs: Vec<(String, Cow<'_, [u8]>)> =
            match entries(from).into_iter().map(|(k, v)| coerce(v).map(|v| (k, v))).collect::<Result<_, _>>() {
                Ok(p) => p,
                Err(e) => return Ok(thrown(e)),
            };
        let refs: Vec<(&[u8], &[u8])> = pairs.iter().map(|(k, v)| (k.as_bytes(), v.as_ref())).collect();
        return Ok(Outcome::Ok(bytes_value(&string::strtr_array(&s, &refs))));
    }
    let from = a.bytes("from")?;
    let to = a.bytes("to")?;
    Ok(Outcome::Ok(bytes_value(&string::strtr(&s, &from, &to))))
}

/// `ctype_*($text)`: strings (and `$bytes`) as strings, integers by PHP's
/// integer rules, anything else is false.
fn ctype(a: &Args, kind: string::Ctype) -> Result<Outcome, Fault> {
    let text = a.value("text")?;
    let result = match text {
        Value::Number(n) if n.as_i64().is_some() => kind.on_int(n.as_i64().unwrap_or_default()),
        Value::String(_) => kind.on_bytes(&a.bytes("text")?),
        Value::Object(o) if o.len() == 1 && o.contains_key("$bytes") => kind.on_bytes(&a.bytes("text")?),
        _ => false,
    };
    Ok(Outcome::Ok(Value::Bool(result)))
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let s = || a.bytes("string");
    let hay = || a.bytes("haystack");
    let needle = || a.bytes("needle");
    let offset = || a.opt_i64("offset").map(|o| o.unwrap_or(0));
    let ok = |v: Value| Ok(Outcome::Ok(v));
    match op {
        "string.strtolower" => ok(bytes_value(&string::strtolower(&s()?))),
        "string.strtoupper" => ok(bytes_value(&string::strtoupper(&s()?))),
        "string.ucfirst" => ok(bytes_value(&string::ucfirst(&s()?))),
        "string.lcfirst" => ok(bytes_value(&string::lcfirst(&s()?))),
        "string.strip_tags" => ok(bytes_value(&string::strip_tags(&s()?))),
        "string.ucwords" => {
            let sep = opt_bytes(&a, "separators")?;
            ok(bytes_value(&string::ucwords(&s()?, sep.as_deref().unwrap_or(string::UCWORDS_SEPARATORS))))
        }
        "string.trim" | "string.ltrim" | "string.rtrim" => {
            let chars = opt_bytes(&a, "characters")?;
            let chars = chars.as_deref().unwrap_or(string::TRIM_CHARACTERS);
            let s = s()?;
            ok(bytes_value(match op {
                "string.trim" => string::trim(&s, chars),
                "string.ltrim" => string::ltrim(&s, chars),
                _ => string::rtrim(&s, chars),
            }))
        }
        "string.substr" => ok(bytes_value(string::substr(&s()?, a.i64("offset")?, a.opt_i64("length")?))),
        "string.stripos" => done(string::stripos(&hay()?, &needle()?, offset()?).map(position)),
        "string.str_contains" => ok(Value::Bool(string::str_contains(&hay()?, &needle()?))),
        "string.str_starts_with" => ok(Value::Bool(string::str_starts_with(&hay()?, &needle()?))),
        "string.strcmp" => ok(Value::from(string::strcmp(&a.bytes("string1")?, &a.bytes("string2")?))),
        "string.strcasecmp" => ok(Value::from(string::strcasecmp(&a.bytes("string1")?, &a.bytes("string2")?))),
        "string.str_replace" => replace(&a),
        "string.strtr" => strtr(&a),
        "string.explode" => {
            let limit = a.opt_i64("limit")?.unwrap_or(string::NO_LIMIT);
            done(string::explode(&a.bytes("separator")?, &s()?, limit).map(|p| list(&p)))
        }
        "string.strval" => done(string::to_php_string(a.value("value")?).map(|s| Value::String(s.into_owned()))),
        "string.basename" => {
            let suffix = opt_bytes(&a, "suffix")?.unwrap_or_default();
            done(Ok::<_, Error>(bytes_value(php_std::path::basename(&a.bytes("path")?, &suffix))))
        }
        "string.escapeshellarg" => done(string::escapeshellarg(&a.bytes("arg")?).map(|r| bytes_value(&r))),
        "string.addcslashes" => ok(bytes_value(&string::addcslashes(&s()?, &a.bytes("characters")?))),
        "string.stripcslashes" => ok(bytes_value(&string::stripcslashes(&s()?))),
        "string.ctype_alnum" => ctype(&a, string::Ctype::Alnum),
        "string.ctype_digit" => ctype(&a, string::Ctype::Digit),
        "string.ctype_xdigit" => ctype(&a, string::Ctype::Xdigit),
        "string.crypt" => ok(bytes_value(&string::crypt(&a.bytes("string")?, &a.bytes("salt")?))),
        "string.hash" => match string::hash(&a.bytes("algo")?, &a.bytes("data")?) {
            Some(digest) => ok(Value::String(digest)),
            None => Ok(Outcome::err("ValueError", "hash(): Argument #1 ($algo) must be a valid hashing algorithm")),
        },
        "string.password_verify" => ok(Value::Bool(string::password_verify(&a.bytes("password")?, &a.bytes("hash")?))),
        "string.password_hash" => {
            let option = |key: &str, default: i64| {
                a.opt("options")
                    .and_then(|o| o.get(key))
                    .map(|v| php_std::format::Arg::from(v).to_int())
                    .unwrap_or(default)
            };
            let mut salt = [0u8; 16];
            utopia_auth::random_bytes(&mut salt);
            let password = a.bytes("password")?;
            let hashed = if a.str("algo")? == "bcrypt" {
                string::password_hash_bcrypt(&password, option("cost", 12), &salt)
            } else {
                string::password_hash_argon2id(
                    &password,
                    option("memory_cost", 65536),
                    option("time_cost", 4),
                    option("threads", 1),
                    &salt,
                )
            };
            match hashed {
                Ok(h) => ok(bytes_value(&h)),
                Err(e) => Ok(Outcome::err(e.php_class(), e.message())),
            }
        }
        _ => Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }
}
