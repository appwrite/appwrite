//! `string.*`: byte-string functions (`php_std::string`).
//!
//! Arguments carry PHP's parameter names (the PHP side spreads them as named
//! arguments), and omitted optional arguments take PHP's defaults. Strings
//! may be `{"$bytes": ...}`.

use std::borrow::Cow;

use php_std::string::{self, Error, Pad, Replace};
use serde_json::{Map, Value};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value};

pub const OPS: &[&str] = &[
    "string.strtolower",
    "string.strtoupper",
    "string.ucfirst",
    "string.lcfirst",
    "string.ucwords",
    "string.trim",
    "string.ltrim",
    "string.rtrim",
    "string.str_pad",
    "string.substr",
    "string.strpos",
    "string.stripos",
    "string.strrpos",
    "string.strripos",
    "string.str_contains",
    "string.str_starts_with",
    "string.str_ends_with",
    "string.strstr",
    "string.stristr",
    "string.strrchr",
    "string.strpbrk",
    "string.substr_count",
    "string.strspn",
    "string.strcspn",
    "string.substr_replace",
    "string.substr_compare",
    "string.strcmp",
    "string.strcasecmp",
    "string.strncmp",
    "string.strncasecmp",
    "string.str_replace",
    "string.str_ireplace",
    "string.strtr",
    "string.explode",
    "string.implode",
    "string.implode_values",
    "string.strval",
    "string.str_split",
    "string.chunk_split",
    "string.wordwrap",
    "string.nl2br",
    "string.str_repeat",
    "string.strrev",
    "string.ord",
    "string.chr",
    "string.quotemeta",
    "string.addcslashes",
    "string.stripcslashes",
    "string.ctype_alnum",
    "string.ctype_alpha",
    "string.ctype_cntrl",
    "string.ctype_digit",
    "string.ctype_graph",
    "string.ctype_lower",
    "string.ctype_print",
    "string.ctype_punct",
    "string.ctype_space",
    "string.ctype_upper",
    "string.ctype_xdigit",
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
        Value::Object(o) => !o.is_empty() && !(o.len() == 1 && o.contains_key("$bytes")),
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

fn replace(a: &Args, case_insensitive: bool) -> Result<Outcome, Fault> {
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
                let (r, n) =
                    string::str_replace_array(&search_refs, Replace::Each(&replace_refs), subject, case_insensitive);
                Ok((r.into_owned(), n))
            } else {
                let r = coerce(replace)?;
                let (r, n) = string::str_replace_array(&search_refs, Replace::All(&r), subject, case_insensitive);
                Ok((r.into_owned(), n))
            }
        } else {
            let (s, r) = (coerce(search)?, coerce(replace)?);
            let (out, n) = if case_insensitive {
                string::str_ireplace(&s, &r, subject)
            } else {
                string::str_replace(&s, &r, subject)
            };
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

/// `$offset`/`$length` of `substr_replace`: an integer or an array of
/// values converted with `(int)`.
fn per_string(value: &Value) -> Result<(Option<i64>, Vec<i64>), Fault> {
    if is_php_array(value) {
        let list = entries(value).into_iter().map(|(_, v)| php_std::format::Arg::from(v).to_int()).collect();
        return Ok((None, list));
    }
    value.as_i64().map(|i| (Some(i), Vec::new())).ok_or_else(|| Fault::new("offset/length must be an int or array"))
}

fn substr_replace(a: &Args) -> Result<Outcome, Fault> {
    let (subject, replace) = (a.value("string")?, a.value("replace")?);
    let offset = per_string(a.value("offset")?)?;
    let length = a.opt("length").map(per_string).transpose()?;
    if !is_php_array(subject) {
        let (Some(offset), None | Some((Some(_), _))) = (offset.0, &length) else {
            return Err(Fault::new("array offset/length with a string has no Rust form (deviation)"));
        };
        // A string subject converts only the replacement it uses.
        let entries = entries(replace);
        let used = if is_php_array(replace) { entries.first().map(|(_, v)| *v) } else { Some(replace) };
        let r = match used.map(coerce).transpose() {
            Ok(r) => r,
            Err(e) => return Ok(thrown(e)),
        };
        let r = r.unwrap_or(Cow::Borrowed(b""));
        let list = [r.as_ref()];
        let replace = if is_php_array(replace) { Replace::Each(&list) } else { Replace::All(list[0]) };
        let s = a.bytes("string")?;
        let length = length.and_then(|l| l.0);
        return Ok(Outcome::Ok(bytes_value(&string::substr_replace(&s, replace.first(), offset, length))));
    }
    let replaces: Vec<Cow<'_, [u8]>> = match if is_php_array(replace) {
        entries(replace).into_iter().map(|(_, v)| coerce(v)).collect::<Result<_, _>>()
    } else {
        coerce(replace).map(|r| vec![r])
    } {
        Ok(r) => r,
        Err(e) => return Ok(thrown(e)),
    };
    let refs: Vec<&[u8]> = replaces.iter().map(|r| r.as_ref()).collect();
    let replace = if is_php_array(replace) { Replace::Each(&refs) } else { Replace::All(refs[0]) };
    let items = entries(subject);
    let strings: Vec<Cow<'_, [u8]>> = match items.iter().map(|(_, v)| coerce(v)).collect::<Result<_, _>>() {
        Ok(s) => s,
        Err(e) => return Ok(thrown(e)),
    };
    let refs: Vec<&[u8]> = strings.iter().map(|s| s.as_ref()).collect();
    fn per(p: &(Option<i64>, Vec<i64>)) -> string::PerString<'_> {
        match p.0 {
            Some(i) => string::PerString::All(i),
            None => string::PerString::Each(&p.1),
        }
    }
    let out = string::substr_replace_array(&refs, replace, per(&offset), length.as_ref().map(per));
    let result = items.into_iter().zip(out).map(|((k, _), r)| (k, bytes_value(&r))).collect();
    Ok(Outcome::Ok(php_array(result)))
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

fn ctype(a: &Args, f: fn(&[u8]) -> bool) -> Result<Outcome, Fault> {
    Ok(Outcome::Ok(Value::Bool(f(&a.bytes("text")?))))
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
        "string.str_pad" => {
            let pad = opt_bytes(&a, "pad_string")?.unwrap_or_else(|| b" ".to_vec());
            let pad_type = a.opt_i64("pad_type")?.unwrap_or(1);
            let pad_type =
                Pad::from_php(pad_type).ok_or_else(|| Fault::new("pad_type outside STR_PAD_* (a deviation)"))?;
            done(string::str_pad(&s()?, a.i64("length")?, &pad, pad_type).map(|r| bytes_value(&r)))
        }
        "string.substr" => ok(bytes_value(string::substr(&s()?, a.i64("offset")?, a.opt_i64("length")?))),
        "string.strpos" => done(string::strpos(&hay()?, &needle()?, offset()?).map(position)),
        "string.stripos" => done(string::stripos(&hay()?, &needle()?, offset()?).map(position)),
        "string.strrpos" => done(string::strrpos(&hay()?, &needle()?, offset()?).map(position)),
        "string.strripos" => done(string::strripos(&hay()?, &needle()?, offset()?).map(position)),
        "string.str_contains" => ok(Value::Bool(string::str_contains(&hay()?, &needle()?))),
        "string.str_starts_with" => ok(Value::Bool(string::str_starts_with(&hay()?, &needle()?))),
        "string.str_ends_with" => ok(Value::Bool(string::str_ends_with(&hay()?, &needle()?))),
        "string.strstr" | "string.stristr" | "string.strrchr" => {
            let before = a.opt_bool("before_needle")?.unwrap_or(false);
            let (h, n) = (hay()?, needle()?);
            ok(string_or_false(match op {
                "string.strstr" => string::strstr(&h, &n, before),
                "string.stristr" => string::stristr(&h, &n, before),
                _ => string::strrchr(&h, &n, before),
            }))
        }
        "string.strpbrk" => done(string::strpbrk(&s()?, &a.bytes("characters")?).map(string_or_false)),
        "string.substr_count" => {
            done(string::substr_count(&hay()?, &needle()?, offset()?, a.opt_i64("length")?).map(Value::from))
        }
        "string.strspn" | "string.strcspn" => {
            let (s, chars, len) = (s()?, a.bytes("characters")?, a.opt_i64("length")?);
            let f = if op == "string.strspn" { string::strspn } else { string::strcspn };
            ok(Value::from(f(&s, &chars, offset()?, len)))
        }
        "string.substr_replace" => substr_replace(&a),
        "string.substr_compare" => done(
            string::substr_compare(
                &hay()?,
                &needle()?,
                a.i64("offset")?,
                a.opt_i64("length")?,
                a.opt_bool("case_insensitive")?.unwrap_or(false),
            )
            .map(Value::from),
        ),
        "string.strcmp" => ok(Value::from(string::strcmp(&a.bytes("string1")?, &a.bytes("string2")?))),
        "string.strcasecmp" => ok(Value::from(string::strcasecmp(&a.bytes("string1")?, &a.bytes("string2")?))),
        "string.strncmp" => {
            done(string::strncmp(&a.bytes("string1")?, &a.bytes("string2")?, a.i64("length")?).map(Value::from))
        }
        "string.strncasecmp" => {
            done(string::strncasecmp(&a.bytes("string1")?, &a.bytes("string2")?, a.i64("length")?).map(Value::from))
        }
        "string.str_replace" => replace(&a, false),
        "string.str_ireplace" => replace(&a, true),
        "string.strtr" => strtr(&a),
        "string.explode" => {
            let limit = a.opt_i64("limit")?.unwrap_or(string::NO_LIMIT);
            done(string::explode(&a.bytes("separator")?, &s()?, limit).map(|p| list(&p)))
        }
        "string.implode" => {
            let separator = a.bytes("separator")?;
            let pieces: Result<Vec<Cow<'_, [u8]>>, Error> =
                entries(a.value("array")?).into_iter().map(|(_, v)| coerce(v)).collect();
            done(pieces.map(|p| bytes_value(&string::implode(&separator, &p))))
        }
        "string.implode_values" => {
            let values: Vec<Value> = entries(a.value("array")?).into_iter().map(|(_, v)| v.clone()).collect();
            done(string::implode_values(&a.bytes("separator")?, &values).map(|r| bytes_value(&r)))
        }
        "string.strval" => done(string::to_php_string(a.value("value")?).map(|s| Value::String(s.into_owned()))),
        "string.str_split" => done(string::str_split(&s()?, a.opt_i64("length")?.unwrap_or(1)).map(|p| list(&p))),
        "string.chunk_split" => {
            let sep = opt_bytes(&a, "separator")?.unwrap_or_else(|| b"\r\n".to_vec());
            done(string::chunk_split(&s()?, a.opt_i64("length")?.unwrap_or(76), &sep).map(|r| bytes_value(&r)))
        }
        "string.wordwrap" => {
            let brk = opt_bytes(&a, "break")?.unwrap_or_else(|| b"\n".to_vec());
            let width = a.opt_i64("width")?.unwrap_or(75);
            let cut = a.opt_bool("cut_long_words")?.unwrap_or(false);
            done(string::wordwrap(&s()?, width, &brk, cut).map(|r| bytes_value(&r)))
        }
        "string.nl2br" => ok(bytes_value(&string::nl2br(&s()?, a.opt_bool("use_xhtml")?.unwrap_or(true)))),
        "string.str_repeat" => done(string::str_repeat(&s()?, a.i64("times")?).map(|r| bytes_value(&r))),
        "string.strrev" => ok(bytes_value(&string::strrev(&s()?))),
        "string.ord" => ok(Value::from(string::ord(&a.bytes("character")?))),
        "string.chr" => ok(bytes_value(&[string::chr(a.i64("codepoint")?)])),
        "string.quotemeta" => ok(bytes_value(&string::quotemeta(&s()?))),
        "string.addcslashes" => ok(bytes_value(&string::addcslashes(&s()?, &a.bytes("characters")?))),
        "string.stripcslashes" => ok(bytes_value(&string::stripcslashes(&s()?))),
        "string.ctype_alnum" => ctype(&a, string::ctype_alnum),
        "string.ctype_alpha" => ctype(&a, string::ctype_alpha),
        "string.ctype_cntrl" => ctype(&a, string::ctype_cntrl),
        "string.ctype_digit" => ctype(&a, string::ctype_digit),
        "string.ctype_graph" => ctype(&a, string::ctype_graph),
        "string.ctype_lower" => ctype(&a, string::ctype_lower),
        "string.ctype_print" => ctype(&a, string::ctype_print),
        "string.ctype_punct" => ctype(&a, string::ctype_punct),
        "string.ctype_space" => ctype(&a, string::ctype_space),
        "string.ctype_upper" => ctype(&a, string::ctype_upper),
        "string.ctype_xdigit" => ctype(&a, string::ctype_xdigit),
        _ => Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }
}
