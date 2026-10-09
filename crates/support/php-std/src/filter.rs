//! `filter_var()`: the validation filters (`FILTER_VALIDATE_INT`, `BOOL`,
//! `FLOAT`, `REGEXP`, `URL`, `EMAIL`, `IP`, `MAC`, `DOMAIN`) and
//! `FILTER_DEFAULT`/`FILTER_UNSAFE_RAW`, with their flags and options, ported
//! from `ext/filter` (`logical_filters.c`, `filter.c`) of PHP 8.5.
//!
//! | PHP | Rust |
//! |---|---|
//! | `filter_var($value, $filter, $options)` | [`filter_var`] |
//!
//! PHP values are [`Value`]s (strings are bytes). The third argument is
//! either flags or an options array ([`Options`]), read the way
//! `php_filter_call()` reads it: `"filter"`, `"flags"` and `"options"` keys,
//! with `zval_get_long()`/`zval_get_double()` conversions of `min_range` and
//! `max_range`, and the `"default"` option replacing a `false` (or, with
//! `FILTER_NULL_ON_FAILURE`, `null`) result, even a valid `false` from
//! `FILTER_VALIDATE_BOOL`.
//!
//! `FILTER_VALIDATE_EMAIL` and `FILTER_VALIDATE_REGEXP` run their regular
//! expressions on [`crate::pcre`], through PHP's compiled-pattern cache like
//! PHP does. `FILTER_VALIDATE_URL` parses with [`crate::url::parse_url`], or
//! with [`crate::url::rfc3986`] for `"uri_parser_class" =>
//! Uri\Rfc3986\Uri`.
//!
//! Not supported (an [`Error`] with class `Unsupported`): the sanitizing
//! filters other than `FILTER_UNSAFE_RAW`, `FILTER_CALLBACK`, and the
//! WHATWG URL parser (`Uri\WhatWg\Url`) for `FILTER_VALIDATE_URL`.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `filter.*`).

use std::fmt;

pub use crate::pcre::Key;
use crate::{number, pcre, url, value};

pub const FILTER_FLAG_NONE: i64 = 0x0000;
pub const FILTER_REQUIRE_ARRAY: i64 = 0x0100_0000;
pub const FILTER_REQUIRE_SCALAR: i64 = 0x0200_0000;
pub const FILTER_FORCE_ARRAY: i64 = 0x0400_0000;
pub const FILTER_NULL_ON_FAILURE: i64 = 0x0800_0000;
pub const FILTER_THROW_ON_FAILURE: i64 = 0x1000_0000;
pub const FILTER_FLAG_ALLOW_OCTAL: i64 = 0x0001;
pub const FILTER_FLAG_ALLOW_HEX: i64 = 0x0002;
pub const FILTER_FLAG_STRIP_LOW: i64 = 0x0004;
pub const FILTER_FLAG_STRIP_HIGH: i64 = 0x0008;
pub const FILTER_FLAG_ENCODE_LOW: i64 = 0x0010;
pub const FILTER_FLAG_ENCODE_HIGH: i64 = 0x0020;
pub const FILTER_FLAG_ENCODE_AMP: i64 = 0x0040;
pub const FILTER_FLAG_NO_ENCODE_QUOTES: i64 = 0x0080;
pub const FILTER_FLAG_EMPTY_STRING_NULL: i64 = 0x0100;
pub const FILTER_FLAG_STRIP_BACKTICK: i64 = 0x0200;
pub const FILTER_FLAG_ALLOW_FRACTION: i64 = 0x1000;
pub const FILTER_FLAG_ALLOW_THOUSAND: i64 = 0x2000;
pub const FILTER_FLAG_ALLOW_SCIENTIFIC: i64 = 0x4000;
pub const FILTER_FLAG_PATH_REQUIRED: i64 = 0x04_0000;
pub const FILTER_FLAG_QUERY_REQUIRED: i64 = 0x08_0000;
pub const FILTER_FLAG_IPV4: i64 = 0x0010_0000;
pub const FILTER_FLAG_IPV6: i64 = 0x0020_0000;
pub const FILTER_FLAG_NO_RES_RANGE: i64 = 0x0040_0000;
pub const FILTER_FLAG_NO_PRIV_RANGE: i64 = 0x0080_0000;
pub const FILTER_FLAG_GLOBAL_RANGE: i64 = 0x2000_0000;
pub const FILTER_FLAG_HOSTNAME: i64 = 0x0010_0000;
pub const FILTER_FLAG_EMAIL_UNICODE: i64 = 0x0010_0000;

pub const FILTER_VALIDATE_INT: i64 = 0x0101;
pub const FILTER_VALIDATE_BOOL: i64 = 0x0102;
pub const FILTER_VALIDATE_FLOAT: i64 = 0x0103;
pub const FILTER_VALIDATE_REGEXP: i64 = 0x0110;
pub const FILTER_VALIDATE_URL: i64 = 0x0111;
pub const FILTER_VALIDATE_EMAIL: i64 = 0x0112;
pub const FILTER_VALIDATE_IP: i64 = 0x0113;
pub const FILTER_VALIDATE_MAC: i64 = 0x0114;
pub const FILTER_VALIDATE_DOMAIN: i64 = 0x0115;
pub const FILTER_DEFAULT: i64 = 0x0204;
pub const FILTER_UNSAFE_RAW: i64 = 0x0204;
pub const FILTER_CALLBACK: i64 = 0x0400;

/// A PHP value given to or returned by `filter_var()`.
#[derive(Debug, Clone, PartialEq)]
pub enum Value {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Str(Vec<u8>),
    Array(Vec<(Key, Value)>),
    /// A `stdClass` instance (no `__toString()`).
    Object,
}

/// The third argument of `filter_var()`.
#[derive(Debug, Clone, PartialEq)]
pub enum Options {
    Flags(i64),
    /// `['filter' => ..., 'flags' => ..., 'options' => [...]]`
    Array(Vec<(Key, Value)>),
}

/// An exception `filter_var()` throws (`ValueError`,
/// `Filter\FilterFailedException`), or `Unsupported` for a filter this port
/// does not implement.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Error {
    class: &'static str,
    message: Vec<u8>,
}

impl Error {
    fn new(class: &'static str, message: impl Into<Vec<u8>>) -> Self {
        Error { class, message: message.into() }
    }

    /// The PHP exception class.
    pub fn php_class(&self) -> &'static str {
        self.class
    }

    /// The message as bytes (it can quote the value).
    pub fn message(&self) -> &[u8] {
        &self.message
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&String::from_utf8_lossy(&self.message))
    }
}

impl std::error::Error for Error {}

/// What `filter_var()` returned, and the warning it emitted, if any.
#[derive(Debug, Clone, PartialEq)]
pub struct Filtered {
    pub value: Value,
    pub warning: Option<Vec<u8>>,
}

const FAILED: &str = "Filter\\FilterFailedException";

fn lookup<'a>(array: &'a [(Key, Value)], key: &str) -> Option<&'a Value> {
    array.iter().find(|(k, _)| matches!(k, Key::Str(s) if s.as_slice() == key.as_bytes())).map(|(_, v)| v)
}

/// `zval_get_long()`; a float that does not fit warns (PHP 8.5).
fn get_long(v: &Value, warning: &mut Option<Vec<u8>>) -> i64 {
    match v {
        Value::Null | Value::Bool(false) => 0,
        Value::Bool(true) => 1,
        Value::Int(i) => *i,
        Value::Float(f) => {
            if !f.is_finite() || !(-TWO_POW_63..TWO_POW_63).contains(f) {
                *warning = Some(
                    format!("The float {} is not representable as an int, cast occurred", number::gcvt(*f, -1, 'E'))
                        .into_bytes(),
                );
            }
            dval_to_lval(*f)
        }
        Value::Str(s) => match numeric_prefix(s) {
            None => 0,
            Some(value::Number::Int(i)) => i,
            Some(value::Number::Float(f)) => dval_to_lval_cap(f),
        },
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object => 1,
    }
}

/// `zval_get_double()`.
fn get_double(v: &Value) -> f64 {
    match v {
        Value::Null | Value::Bool(false) => 0.0,
        Value::Bool(true) => 1.0,
        Value::Int(i) => *i as f64,
        Value::Float(f) => *f,
        Value::Str(s) => strtod(s),
        Value::Array(a) => f64::from(u8::from(!a.is_empty())),
        Value::Object => 1.0,
    }
}

/// `zend_dval_to_lval()`: modular for out-of-range values, 0 for non-finite.
/// 2^63, the first double past `ZEND_LONG_MAX` (which rounds to it).
const TWO_POW_63: f64 = 9_223_372_036_854_775_808.0;

fn dval_to_lval(d: f64) -> i64 {
    if !d.is_finite() {
        return 0;
    }
    if (-TWO_POW_63..TWO_POW_63).contains(&d) {
        return d as i64;
    }
    let two64 = 18_446_744_073_709_551_616.0_f64;
    let mut dmod = d % two64;
    if dmod < 0.0 {
        // we're going to make this number positive; call ourselves recursively
        // if the result is -zero, i.e. the number was a multiple of 2^64
        dmod += two64;
        if dmod >= two64 {
            return 0;
        }
    }
    if dmod > TWO_POW_63 {
        dmod -= two64;
    }
    dmod as i64
}

/// `zend_dval_to_lval_cap()`: saturating.
fn dval_to_lval_cap(d: f64) -> i64 {
    if !d.is_finite() {
        return 0;
    }
    if d >= TWO_POW_63 {
        return i64::MAX;
    }
    if d < -TWO_POW_63 {
        return i64::MIN;
    }
    d as i64
}

/// `is_numeric_string(..., allow_errors = true)`: leading whitespace, then
/// the longest numeric prefix; `None` when there is none.
fn numeric_prefix(s: &[u8]) -> Option<value::Number> {
    let mut i = 0;
    while i < s.len() && matches!(s[i], b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c) {
        i += 1;
    }
    let start = i;
    if i < s.len() && (s[i] == b'+' || s[i] == b'-') {
        i += 1;
    }
    let int_start = i;
    while i < s.len() && s[i].is_ascii_digit() {
        i += 1;
    }
    let int_digits = i - int_start;
    let mut end = i;
    let mut frac_digits = 0;
    if i < s.len() && s[i] == b'.' {
        let mut j = i + 1;
        while j < s.len() && s[j].is_ascii_digit() {
            j += 1;
        }
        frac_digits = j - i - 1;
        if int_digits > 0 || frac_digits > 0 {
            end = j;
        }
    }
    if int_digits == 0 && frac_digits == 0 {
        return None;
    }
    let mut k = end;
    if k < s.len() && (s[k] == b'e' || s[k] == b'E') {
        let mut j = k + 1;
        if j < s.len() && (s[j] == b'+' || s[j] == b'-') {
            j += 1;
        }
        let d = j;
        while j < s.len() && s[j].is_ascii_digit() {
            j += 1;
        }
        if j > d {
            k = j;
        }
    }
    let text = std::str::from_utf8(&s[start..k]).ok()?;
    value::numeric_str(text)
}

/// `zend_strtod()` on the start of a string: leading whitespace, then the
/// longest decimal prefix, or 0.
fn strtod(s: &[u8]) -> f64 {
    let mut i = 0;
    while i < s.len() && matches!(s[i], b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c) {
        i += 1;
    }
    let s = &s[i..];
    let mut i = 0;
    if i < s.len() && (s[i] == b'+' || s[i] == b'-') {
        i += 1;
    }
    let ds = i;
    while i < s.len() && s[i].is_ascii_digit() {
        i += 1;
    }
    let mut digits = i - ds;
    if i < s.len() && s[i] == b'.' {
        let mut j = i + 1;
        while j < s.len() && s[j].is_ascii_digit() {
            j += 1;
        }
        digits += j - i - 1;
        if digits > 0 {
            i = j;
        }
    }
    if digits == 0 {
        return 0.0;
    }
    if i < s.len() && (s[i] == b'e' || s[i] == b'E') {
        let mut j = i + 1;
        if j < s.len() && (s[j] == b'+' || s[j] == b'-') {
            j += 1;
        }
        let d = j;
        while j < s.len() && s[j].is_ascii_digit() {
            j += 1;
        }
        if j > d {
            i = j;
        }
    }
    std::str::from_utf8(&s[..i]).ok().and_then(|t| t.parse::<f64>().ok()).unwrap_or(0.0)
}

/// `convert_to_string()` of a scalar; `NAN` warns (PHP 8.5).
fn to_php_string(v: &Value, warning: &mut Option<Vec<u8>>) -> Vec<u8> {
    if let Value::Float(f) = v
        && f.is_nan()
    {
        *warning = Some(b"unexpected NAN value was coerced to string".to_vec());
    }
    match v {
        Value::Null | Value::Bool(false) => Vec::new(),
        Value::Bool(true) => b"1".to_vec(),
        Value::Int(i) => i.to_string().into_bytes(),
        Value::Float(f) => number::to_string(*f).into_bytes(),
        Value::Str(s) => s.clone(),
        Value::Array(_) => b"Array".to_vec(),
        Value::Object => Vec::new(),
    }
}

/// `zend_zval_value_name()`.
fn type_name(v: &Value) -> &'static str {
    match v {
        Value::Null => "null",
        Value::Bool(true) => "true",
        Value::Bool(false) => "false",
        Value::Int(_) => "int",
        Value::Float(_) => "float",
        Value::Str(_) => "string",
        Value::Array(_) => "array",
        Value::Object => "stdClass",
    }
}

/// The name of a filter in `filter_list[]`.
fn filter_name(id: i64) -> &'static str {
    match id {
        FILTER_VALIDATE_INT => "int",
        FILTER_VALIDATE_BOOL => "boolean",
        FILTER_VALIDATE_FLOAT => "float",
        FILTER_VALIDATE_REGEXP => "validate_regexp",
        FILTER_VALIDATE_DOMAIN => "validate_domain",
        FILTER_VALIDATE_URL => "validate_url",
        FILTER_VALIDATE_EMAIL => "validate_email",
        FILTER_VALIDATE_IP => "validate_ip",
        FILTER_VALIDATE_MAC => "validate_mac",
        0x0201 => "string",
        0x0202 => "encoded",
        0x0203 => "special_chars",
        0x020a => "full_special_chars",
        0x0205 => "email",
        0x0206 => "url",
        0x0207 => "number_int",
        0x0208 => "number_float",
        0x020b => "add_slashes",
        FILTER_CALLBACK => "callback",
        _ => "unsafe_raw",
    }
}

/// Whether `filter_list[]` has the filter (`php_find_filter()` falls back
/// to `FILTER_DEFAULT` otherwise).
fn in_list(id: i64) -> bool {
    matches!(id, 0x0101..=0x0103 | 0x0110..=0x0115 | 0x0201..=0x0208 | 0x020a | 0x020b | FILTER_CALLBACK)
}

/// `PHP_FILTER_ID_EXISTS()`.
fn id_exists(id: i64) -> bool {
    (0x0200..=0x020b).contains(&id) || (0x0100..=0x0115).contains(&id) || id == FILTER_CALLBACK
}

/// The outcome of one filter function.
enum Outcome {
    /// The filter accepted the value (possibly converting it).
    Ok(Value),
    /// `RETURN_VALIDATION_FAILED`.
    Failed,
}

/// `filter_var($value, $filter, $options)`.
pub fn filter_var(value: &Value, filter: i64, options: &Options) -> Result<Filtered, Error> {
    if !id_exists(filter) {
        return Ok(Filtered {
            value: Value::Bool(false),
            warning: Some(format!("filter_var(): Unknown filter with ID {filter}").into_bytes()),
        });
    }
    let mut warning = None;
    let mut filter = filter;
    let mut flags = FILTER_REQUIRE_SCALAR;
    let mut opts: Option<&[(Key, Value)]> = None;
    match options {
        Options::Flags(f) => {
            flags = *f;
            if flags & (FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY) == 0 {
                flags |= FILTER_REQUIRE_SCALAR;
            }
        }
        Options::Array(args) => {
            if let Some(f) = lookup(args, "filter") {
                filter = get_long(f, &mut warning);
            }
            if let Some(o) = lookup(args, "options") {
                if filter == FILTER_CALLBACK {
                    return Err(Error::new("Unsupported", "FILTER_CALLBACK"));
                }
                if let Value::Array(a) = o {
                    opts = Some(a);
                }
            }
            if let Some(f) = lookup(args, "flags") {
                flags = get_long(f, &mut warning);
                if flags & (FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY) == 0 {
                    flags |= FILTER_REQUIRE_SCALAR;
                }
            }
        }
    }
    if flags & FILTER_NULL_ON_FAILURE != 0 && flags & FILTER_THROW_ON_FAILURE != 0 {
        return Err(Error::new(
            "ValueError",
            "filter_var(): Argument #3 ($options) cannot use both FILTER_NULL_ON_FAILURE and FILTER_THROW_ON_FAILURE",
        ));
    }
    let failure = |flags: i64| if flags & FILTER_NULL_ON_FAILURE != 0 { Value::Null } else { Value::Bool(false) };
    if let Value::Array(items) = value {
        if flags & FILTER_REQUIRE_SCALAR != 0 {
            if flags & FILTER_THROW_ON_FAILURE != 0 {
                return Err(Error::new(FAILED, "filter validation failed: not a scalar value (got an array)"));
            }
            return Ok(Filtered { value: failure(flags), warning });
        }
        let out = filter_recursive(items, filter, flags, opts, &mut warning)?;
        return Ok(Filtered { value: Value::Array(out), warning });
    }
    if flags & FILTER_REQUIRE_ARRAY != 0 {
        if flags & FILTER_THROW_ON_FAILURE != 0 {
            return Err(Error::new(
                FAILED,
                format!("filter validation failed: not an array (got {})", type_name(value)),
            ));
        }
        return Ok(Filtered { value: failure(flags), warning });
    }
    let mut out = zval_filter(value, filter, flags, opts, &mut warning)?;
    if flags & FILTER_FORCE_ARRAY != 0 {
        out = Value::Array(vec![(Key::Int(0), out)]);
    }
    Ok(Filtered { value: out, warning })
}

fn filter_recursive(
    items: &[(Key, Value)],
    filter: i64,
    flags: i64,
    opts: Option<&[(Key, Value)]>,
    warning: &mut Option<Vec<u8>>,
) -> Result<Vec<(Key, Value)>, Error> {
    let mut out = Vec::with_capacity(items.len());
    for (k, v) in items {
        let r = match v {
            Value::Array(inner) => Value::Array(filter_recursive(inner, filter, flags, opts, warning)?),
            _ => zval_filter(v, filter, flags, opts, warning)?,
        };
        out.push((k.clone(), r));
    }
    Ok(out)
}

/// `php_zval_filter()`.
fn zval_filter(
    value: &Value,
    filter: i64,
    flags: i64,
    opts: Option<&[(Key, Value)]>,
    warning: &mut Option<Vec<u8>>,
) -> Result<Value, Error> {
    let filter = if in_list(filter) { filter } else { FILTER_DEFAULT };
    let result = if let Value::Object = value {
        if flags & FILTER_THROW_ON_FAILURE != 0 {
            return Err(Error::new(
                FAILED,
                "filter validation failed: object of type stdClass has no __toString() method",
            ));
        }
        if flags & FILTER_NULL_ON_FAILURE != 0 { Value::Null } else { Value::Bool(false) }
    } else {
        let s = to_php_string(value, warning);
        match run_filter(filter, &s, flags, opts, warning)? {
            Outcome::Ok(v) => v,
            Outcome::Failed => {
                if flags & FILTER_THROW_ON_FAILURE != 0 {
                    let shown = s.iter().position(|&b| b == 0).map_or(&s[..], |n| &s[..n]);
                    let mut m = format!("filter validation failed: filter {} not satisfied by '", filter_name(filter))
                        .into_bytes();
                    m.extend_from_slice(shown);
                    m.push(b'\'');
                    return Err(Error::new(FAILED, m));
                }
                if flags & FILTER_NULL_ON_FAILURE != 0 { Value::Null } else { Value::Bool(false) }
            }
        }
    };
    // handle_default
    if let Some(opts) = opts {
        let replace =
            if flags & FILTER_NULL_ON_FAILURE != 0 { result == Value::Null } else { result == Value::Bool(false) };
        if replace && let Some(d) = lookup(opts, "default") {
            return Ok(d.clone());
        }
    }
    Ok(result)
}

fn run_filter(
    filter: i64,
    s: &[u8],
    flags: i64,
    opts: Option<&[(Key, Value)]>,
    warning: &mut Option<Vec<u8>>,
) -> Result<Outcome, Error> {
    let opt = |name: &str| opts.and_then(|o| lookup(o, name));
    let str_opt = |name: &str| match opt(name) {
        Some(Value::Str(v)) => Some(v.as_slice()),
        _ => None,
    };
    match filter {
        FILTER_VALIDATE_INT => {
            let min = opt("min_range").map(|v| get_long(v, warning));
            let max = opt("max_range").map(|v| get_long(v, warning));
            Ok(validate_int(s, flags, min, max).map_or(Outcome::Failed, |v| Outcome::Ok(Value::Int(v))))
        }
        FILTER_VALIDATE_BOOL => Ok(match validate_bool(s) {
            Some(b) => Outcome::Ok(Value::Bool(b)),
            None => Outcome::Failed,
        }),
        FILTER_VALIDATE_FLOAT => {
            let trimmed = trim(s);
            if trimmed.is_empty() {
                return Ok(Outcome::Failed);
            }
            let decimal = match str_opt("decimal") {
                Some(d) if d.len() != 1 => {
                    return Err(Error::new(
                        "ValueError",
                        "filter_var(): \"decimal\" option must be one character long",
                    ));
                }
                Some(d) => d[0],
                None => b'.',
            };
            let thousand: Vec<u8> = match str_opt("thousand") {
                Some([]) => {
                    return Err(Error::new("ValueError", "filter_var(): \"thousand\" option must not be empty"));
                }
                // A C string: strchr() stops at the first NUL.
                Some(t) => t.iter().copied().take_while(|&b| b != 0).collect(),
                None => b"',.".to_vec(),
            };
            let min = opt("min_range").map(get_double);
            let max = opt("max_range").map(get_double);
            Ok(validate_float(s, flags, decimal, &thousand, min, max)
                .map_or(Outcome::Failed, |f| Outcome::Ok(Value::Float(f))))
        }
        FILTER_VALIDATE_REGEXP => {
            let Some(re) = str_opt("regexp") else {
                return Err(Error::new("ValueError", "filter_var(): \"regexp\" option is missing"));
            };
            let (matched, w) = pcre::engine_match("filter_var", re, s);
            if w.is_some() {
                *warning = w;
            }
            Ok(if matched == Some(true) { Outcome::Ok(Value::Str(s.to_vec())) } else { Outcome::Failed })
        }
        FILTER_VALIDATE_DOMAIN => Ok(if validate_domain(s, flags & FILTER_FLAG_HOSTNAME != 0) {
            Outcome::Ok(Value::Str(s.to_vec()))
        } else {
            Outcome::Failed
        }),
        FILTER_VALIDATE_URL => {
            let parser = match opts.and_then(|o| lookup(o, "uri_parser_class")) {
                Some(Value::Str(p)) => Some(p.as_slice()),
                _ => None,
            };
            validate_url(s, flags, parser)
                .map(|ok| if ok { Outcome::Ok(Value::Str(s.to_vec())) } else { Outcome::Failed })
        }
        FILTER_VALIDATE_EMAIL => {
            if s.len() > 320 {
                return Ok(Outcome::Failed);
            }
            let re = if flags & FILTER_FLAG_EMAIL_UNICODE != 0 { EMAIL_UNICODE } else { EMAIL };
            let (matched, w) = pcre::engine_match("filter_var", re, s);
            if w.is_some() {
                *warning = w;
            }
            Ok(if matched == Some(true) { Outcome::Ok(Value::Str(s.to_vec())) } else { Outcome::Failed })
        }
        FILTER_VALIDATE_IP => {
            Ok(if validate_ip(s, flags) { Outcome::Ok(Value::Str(s.to_vec())) } else { Outcome::Failed })
        }
        FILTER_VALIDATE_MAC => {
            let sep = match str_opt("separator") {
                Some(d) if d.len() != 1 => {
                    return Err(Error::new(
                        "ValueError",
                        "filter_var(): \"separator\" option must be one character long",
                    ));
                }
                Some(d) => Some(d[0]),
                None => None,
            };
            Ok(if validate_mac(s, sep) { Outcome::Ok(Value::Str(s.to_vec())) } else { Outcome::Failed })
        }
        FILTER_UNSAFE_RAW => Ok(Outcome::Ok(unsafe_raw(s, flags))),
        _ => Err(Error::new("Unsupported", format!("filter {}", filter_name(filter)))),
    }
}

/// `PHP_FILTER_TRIM_DEFAULT`: the range without leading and trailing
/// `' '`, `\t`, `\r`, `\v`, `\n`.
fn trim(s: &[u8]) -> &[u8] {
    let ws = |b: u8| matches!(b, b' ' | b'\t' | b'\r' | 0x0b | b'\n');
    let mut start = 0;
    while start < s.len() && ws(s[start]) {
        start += 1;
    }
    let mut end = s.len();
    while end > start && ws(s[end - 1]) {
        end -= 1;
    }
    &s[start..end]
}

/// `php_filter_parse_int()`.
fn parse_int(s: &[u8]) -> Option<i64> {
    let mut i = 0;
    let at = |i: usize| s.get(i).copied().unwrap_or(0);
    let mut negative = false;
    match at(0) {
        b'-' => {
            negative = true;
            i += 1;
        }
        b'+' => i += 1,
        _ => {}
    }
    if at(i) == b'0' && i + 1 == s.len() {
        return Some(0);
    }
    if !(i < s.len() && (b'1'..=b'9').contains(&s[i])) {
        return None;
    }
    let mut value: i64 = i64::from(s[i] - b'0') * if negative { -1 } else { 1 };
    i += 1;
    if s.len() - i > 19 {
        return None;
    }
    while i < s.len() {
        let c = s[i];
        if !c.is_ascii_digit() {
            return None;
        }
        let digit = i64::from(c - b'0');
        i += 1;
        if !negative && value <= (i64::MAX - digit) / 10 {
            value = value * 10 + digit;
        } else if negative && value >= (i64::MIN + digit) / 10 {
            value = value * 10 - digit;
        } else {
            return None;
        }
    }
    Some(value)
}

/// `php_filter_parse_octal()`.
fn parse_octal(s: &[u8]) -> Option<i64> {
    let mut v: u64 = 0;
    for &c in s {
        if !(b'0'..=b'7').contains(&c) {
            return None;
        }
        let n = u64::from(c - b'0');
        if v > u64::MAX / 8 {
            return None;
        }
        v *= 8;
        if v > u64::MAX - n {
            return None;
        }
        v += n;
    }
    Some(v as i64)
}

/// `php_filter_parse_hex()`.
fn parse_hex(s: &[u8]) -> Option<i64> {
    let mut v: u64 = 0;
    for &c in s {
        let n = match c {
            b'0'..=b'9' => c - b'0',
            b'a'..=b'f' => c - b'a' + 10,
            b'A'..=b'F' => c - b'A' + 10,
            _ => return None,
        };
        if v > u64::MAX / 16 {
            return None;
        }
        v *= 16;
        if v > u64::MAX - u64::from(n) {
            return None;
        }
        v += u64::from(n);
    }
    Some(v as i64)
}

/// `php_filter_int()`.
fn validate_int(s: &[u8], flags: i64, min: Option<i64>, max: Option<i64>) -> Option<i64> {
    if s.is_empty() {
        return None;
    }
    let p = trim(s);
    if p.is_empty() {
        return None;
    }
    let value = if p[0] == b'0' {
        let rest = &p[1..];
        if flags & FILTER_FLAG_ALLOW_HEX != 0 && matches!(rest.first(), Some(b'x' | b'X')) {
            if rest.len() == 1 {
                return None;
            }
            parse_hex(&rest[1..])
        } else if flags & FILTER_FLAG_ALLOW_OCTAL != 0 {
            let digits = if matches!(rest.first(), Some(b'o' | b'O')) {
                if rest.len() == 1 {
                    return None;
                }
                &rest[1..]
            } else {
                rest
            };
            parse_octal(digits)
        } else if !rest.is_empty() {
            None
        } else {
            Some(0)
        }
    } else {
        parse_int(p)
    }?;
    if min.is_some_and(|m| value < m) || max.is_some_and(|m| value > m) {
        return None;
    }
    Some(value)
}

/// `php_filter_boolean()`.
fn validate_bool(s: &[u8]) -> Option<bool> {
    let t = trim(s);
    let eq = |w: &[u8]| t.eq_ignore_ascii_case(w);
    match t.len() {
        0 => Some(false),
        1 => match t[0] {
            b'1' => Some(true),
            b'0' => Some(false),
            _ => None,
        },
        2 if eq(b"on") => Some(true),
        2 if eq(b"no") => Some(false),
        3 if eq(b"yes") => Some(true),
        3 if eq(b"off") => Some(false),
        4 if eq(b"true") => Some(true),
        5 if eq(b"false") => Some(false),
        _ => None,
    }
}

/// `php_filter_float()`. Reads past the trimmed range the way the C code
/// does (the byte after it, or the terminating NUL).
fn validate_float(s: &[u8], flags: i64, dec_sep: u8, tsd: &[u8], min: Option<f64>, max: Option<f64>) -> Option<f64> {
    let ws = |b: u8| matches!(b, b' ' | b'\t' | b'\r' | 0x0b | b'\n');
    let mut start = 0;
    while start < s.len() && ws(s[start]) {
        start += 1;
    }
    let mut end = s.len();
    while end > start && ws(s[end - 1]) {
        end -= 1;
    }
    let at = |i: usize| s.get(i).copied().unwrap_or(0);
    let mut str = start;
    let mut num: Vec<u8> = Vec::with_capacity(end - start);
    if str < end && (s[str] == b'+' || s[str] == b'-') {
        num.push(s[str]);
        str += 1;
    }
    let mut first = true;
    loop {
        let mut n = 0;
        while str < end && s[str].is_ascii_digit() {
            n += 1;
            num.push(s[str]);
            str += 1;
        }
        if str == end || at(str) == dec_sep || at(str) == b'e' || at(str) == b'E' {
            if !first && n != 3 {
                return None;
            }
            if at(str) == dec_sep {
                num.push(b'.');
                str += 1;
                while str < end && s[str].is_ascii_digit() {
                    num.push(s[str]);
                    str += 1;
                }
            }
            if at(str) == b'e' || at(str) == b'E' {
                num.push(at(str));
                str += 1;
                if str < end && (s[str] == b'+' || s[str] == b'-') {
                    num.push(s[str]);
                    str += 1;
                }
                while str < end && s[str].is_ascii_digit() {
                    num.push(s[str]);
                    str += 1;
                }
            }
            break;
        }
        // strchr() also finds the terminating NUL.
        let c = at(str);
        if flags & FILTER_FLAG_ALLOW_THOUSAND != 0 && (c == 0 || tsd.contains(&c)) {
            if if first { !(1..=3).contains(&n) } else { n != 3 } {
                return None;
            }
            first = false;
            str += 1;
        } else {
            return None;
        }
    }
    if str != end {
        return None;
    }
    let text = std::str::from_utf8(&num).ok()?;
    match value::numeric_str(text)? {
        value::Number::Int(l) => {
            let d = l as f64;
            if min.is_some_and(|m| d < m) || max.is_some_and(|m| d > m) {
                return None;
            }
            Some(d)
        }
        value::Number::Float(d) => {
            if (d == 0.0 && num.len() > 1 && num.iter().any(|b| (b'1'..=b'9').contains(b))) || !d.is_finite() {
                return None;
            }
            if min.is_some_and(|m| d < m) || max.is_some_and(|m| d > m) {
                return None;
            }
            Some(d)
        }
    }
}

/// `php_filter_validate_domain_ex()`.
fn validate_domain(d: &[u8], hostname: bool) -> bool {
    let at = |i: usize| d.get(i).copied().unwrap_or(0);
    let alnum = |b: u8| b.is_ascii_alphanumeric();
    let mut l = d.len();
    let mut e = d.len();
    if l > 0 && d[l - 1] == b'.' {
        e -= 1;
        l -= 1;
    }
    if l > 253 {
        return false;
    }
    if at(0) == b'.' || (hostname && !alnum(at(0))) {
        return false;
    }
    let mut i: u32 = 1;
    let mut s = 0;
    while s < e {
        if d[s] == b'.' {
            if at(s + 1) == b'.' || (hostname && (!alnum(d[s - 1]) || !alnum(at(s + 1)))) {
                return false;
            }
            i = 1;
        } else {
            if i > 63 || (hostname && (d[s] != b'-' || at(s + 1) == 0) && !alnum(d[s])) {
                return false;
            }
            i += 1;
        }
        s += 1;
    }
    true
}

/// `is_userinfo_valid()`.
fn is_userinfo_valid(s: &[u8]) -> bool {
    let mut p = 0;
    while p < s.len() {
        let c = s[p];
        if c.is_ascii_alphabetic() || c.is_ascii_digit() || b"-._~!$&'()*+,;=:".contains(&c) {
            p += 1;
        } else if c == b'%' && p + 3 <= s.len() && s[p + 1].is_ascii_digit() && s[p + 2].is_ascii_hexdigit() {
            p += 3;
        } else {
            return false;
        }
    }
    true
}

/// `php_filter_url()` (`FILTER_SANITIZE_URL`): the bytes allowed in a URL.
fn sanitize_url(s: &[u8]) -> Vec<u8> {
    const EXTRA: &[u8] = b"$-_.+!*'(),{}|\\^~[]`<>#%\";/?:@&=";
    s.iter().copied().filter(|c| c.is_ascii_alphanumeric() || EXTRA.contains(c)).collect()
}

/// `php_filter_validate_url()`: `Err` for an invalid `uri_parser_class`.
fn validate_url(s: &[u8], flags: i64, parser: Option<&[u8]>) -> Result<bool, Error> {
    if sanitize_url(s).len() != s.len() {
        return Ok(false);
    }
    struct Parts {
        scheme: Option<Vec<u8>>,
        user: Option<Vec<u8>>,
        pass: Option<Vec<u8>>,
        host: Option<Vec<u8>>,
        path: Option<Vec<u8>>,
        query: Option<Vec<u8>>,
    }
    let php_parse_url = match parser {
        None | Some(b"parse_url") => true,
        Some(b"Uri\\Rfc3986\\Uri") => false,
        Some(b"Uri\\WhatWg\\Url") => return Err(Error::new("Unsupported", "the WHATWG URL parser")),
        Some(_) => return Err(Error::new("ValueError", "filter_var(): \"uri_parser_class\" option has invalid value")),
    };
    let own = |c: Option<std::borrow::Cow<'_, [u8]>>| c.map(|c| c.into_owned());
    let parts = if php_parse_url {
        let Some(u) = url::parse_url(s) else { return Ok(false) };
        Parts {
            scheme: own(u.scheme()),
            user: own(u.user()),
            pass: own(u.pass()),
            host: own(u.host()),
            path: own(u.path()),
            query: own(u.query()),
        }
    } else {
        let Some(u) = url::rfc3986::Uri::parse(s) else { return Ok(false) };
        Parts {
            scheme: own(u.raw_scheme()),
            user: own(u.raw_username()),
            pass: own(u.raw_password()),
            host: own(u.raw_host()),
            path: Some(u.raw_path().into_owned()),
            query: own(u.raw_query()),
        }
    };
    if let Some(scheme) = &parts.scheme
        && (scheme.eq_ignore_ascii_case(b"http") || scheme.eq_ignore_ascii_case(b"https"))
    {
        let Some(host) = &parts.host else { return Ok(false) };
        if php_parse_url && !is_valid_ipv6_hostname(host) && !validate_domain(host, true) {
            return Ok(false);
        }
    }
    let Some(scheme) = &parts.scheme else { return Ok(false) };
    if parts.host.is_none() && !matches!(scheme.as_slice(), b"mailto" | b"news" | b"file") {
        return Ok(false);
    }
    if (flags & FILTER_FLAG_PATH_REQUIRED != 0 && parts.path.is_none())
        || (flags & FILTER_FLAG_QUERY_REQUIRED != 0 && parts.query.is_none())
    {
        return Ok(false);
    }
    if php_parse_url
        && (parts.user.as_ref().is_some_and(|u| !is_userinfo_valid(u))
            || parts.pass.as_ref().is_some_and(|p| !is_userinfo_valid(p)))
    {
        return Ok(false);
    }
    Ok(true)
}

/// `php_filter_is_valid_ipv6_hostname()`.
fn is_valid_ipv6_hostname(s: &[u8]) -> bool {
    !s.is_empty() && s[0] == b'[' && s[s.len() - 1] == b']' && s.len() >= 2 && validate_ipv6(&s[1..s.len() - 1], None)
}

/// `_php_filter_validate_ipv4()`.
fn validate_ipv4(s: &[u8], ip: &mut [i32; 4]) -> bool {
    let mut i = 0;
    let mut n = 0;
    while i < s.len() {
        if !s[i].is_ascii_digit() {
            return false;
        }
        let leading_zero = s[i] == b'0';
        let mut m = 1;
        let mut num = i32::from(s[i] - b'0');
        i += 1;
        while i < s.len() && s[i].is_ascii_digit() {
            num = num * 10 + i32::from(s[i] - b'0');
            i += 1;
            m += 1;
            if num > 255 || m > 3 {
                return false;
            }
        }
        if leading_zero && (num != 0 || m > 1) {
            return false;
        }
        ip[n] = num;
        n += 1;
        if n == 4 {
            return i == s.len();
        } else if i >= s.len() || s[i] != b'.' {
            return false;
        }
        i += 1;
    }
    false
}

/// `_php_filter_validate_ipv6()`, filling `ip` like the C code when given.
fn validate_ipv6(s: &[u8], mut ip: Option<&mut [i32; 8]>) -> bool {
    if !s.contains(&b':') {
        return false;
    }
    let mut compressed_pos: i32 = -1;
    let mut blocks: i32 = 0;
    let mut ip4elm = [0i32; 4];
    let mut len = s.len();
    let mut ipv4_at = None;
    if let Some(dot) = s.iter().position(|&b| b == b'.') {
        let mut v = dot;
        while v > 0 && s[v - 1] != b':' {
            v -= 1;
        }
        if !validate_ipv4(&s[v..], &mut ip4elm) {
            return false;
        }
        len = v;
        if len < 2 {
            return false;
        }
        if s[v - 2] != b':' {
            len -= 1;
        }
        blocks = 2;
        ipv4_at = Some(v);
    }
    let end = len;
    let mut i = 0;
    let mut done = false;
    while i < end {
        if s[i] == b':' {
            i += 1;
            if i >= end {
                return false;
            }
            if s[i] == b':' {
                if compressed_pos >= 0 {
                    return false;
                }
                if let Some(ip) = ip.as_deref_mut()
                    && blocks < 8
                {
                    ip[blocks as usize] = -1;
                }
                compressed_pos = blocks;
                blocks += 1;
                i += 1;
                if i == end {
                    if blocks > 8 {
                        return false;
                    }
                    done = true;
                    break;
                }
            } else if i - 1 == 0 {
                return false;
            }
        }
        let mut num: u32 = 0;
        let mut n = 0;
        while i < end {
            let d = match s[i] {
                b'0'..=b'9' => u32::from(s[i] - b'0'),
                b'a'..=b'f' => u32::from(s[i] - b'a') + 10,
                b'A'..=b'F' => u32::from(s[i] - b'A') + 10,
                _ => break,
            };
            num = num.wrapping_mul(16).wrapping_add(d);
            n += 1;
            i += 1;
        }
        if let Some(ip) = ip.as_deref_mut()
            && blocks < 8
        {
            ip[blocks as usize] = num as i32;
        }
        if !(1..=4).contains(&n) {
            return false;
        }
        blocks += 1;
        if blocks > 8 {
            return false;
        }
    }
    let _ = done;
    if let Some(ip) = ip {
        if ipv4_at.is_some() {
            for x in ip.iter_mut().take(5) {
                *x = 0;
            }
            ip[5] = 0xffff;
            ip[6] = 256 * ip4elm[0] + ip4elm[1];
            ip[7] = 256 * ip4elm[2] + ip4elm[3];
        } else if compressed_pos >= 0 && blocks <= 8 {
            let offset = 8 - blocks;
            let mut k = 7;
            while k > compressed_pos + offset {
                ip[k as usize] = ip[(k - offset) as usize];
                k -= 1;
            }
            let mut k = compressed_pos + offset;
            while k >= compressed_pos {
                ip[k as usize] = 0;
                k -= 1;
            }
        }
    }
    (compressed_pos >= 0 && blocks <= 8) || blocks == 8
}

/// `ipv4_get_status_flags()`: `(global, reserved, private)`, or `None`
/// for an address in no special block.
// One branch per block of PHP's table, in its order.
#[allow(clippy::if_same_then_else)]
fn ipv4_flags(ip: &[i32; 4]) -> Option<(bool, bool, bool)> {
    let r = |g, r, p| Some((g, r, p));
    if ip[0] == 0 {
        r(false, true, false)
    } else if ip[0] == 10 {
        r(false, false, true)
    } else if ip[0] == 100 && ip[1] >= 64 && ip[1] <= 127 {
        r(false, false, false)
    } else if ip[0] == 127 {
        r(false, true, false)
    } else if ip[0] == 169 && ip[1] == 254 {
        r(false, true, false)
    } else if ip[0] == 172 && ip[1] >= 16 && ip[1] <= 31 {
        r(false, false, true)
    } else if ip[0] == 192 && ip[1] == 0 && ip[2] == 0 {
        r(false, false, false)
    } else if ip[0] == 192 && ip[1] == 0 && ip[2] == 2 {
        r(false, false, false)
    } else if ip[0] == 192 && ip[1] == 88 && ip[2] == 99 {
        r(true, false, false)
    } else if ip[0] == 192 && ip[1] == 168 {
        r(false, false, true)
    } else if ip[0] == 198 && ip[1] >= 18 && ip[1] <= 19 {
        r(false, false, false)
    } else if ip[0] == 198 && ip[1] == 51 && ip[2] == 100 {
        r(false, false, false)
    } else if ip[0] == 203 && ip[1] == 0 && ip[2] == 113 {
        r(false, false, false)
    } else if ip[0] >= 240 && ip[1] <= 255 {
        r(false, true, false)
    } else if ip[0] == 255 && ip[1] == 255 && ip[2] == 255 && ip[3] == 255 {
        r(false, true, false)
    } else {
        None
    }
}

/// `ipv6_get_status_flags()`.
// One branch per block of PHP's table, in its order.
#[allow(clippy::if_same_then_else)]
fn ipv6_flags(ip: &[i32; 8]) -> Option<(bool, bool, bool)> {
    let r = |g, r, p| Some((g, r, p));
    let zeros = |n: usize| ip[..n].iter().all(|&x| x == 0);
    if zeros(8) {
        r(false, true, false)
    } else if zeros(7) && ip[7] == 1 {
        r(false, true, false)
    } else if ip[0] == 0x0064 && ip[1] == 0xff9b {
        r(true, false, false)
    } else if zeros(5) && ip[5] == 0xffff {
        r(false, true, false)
    } else if ip[0] == 0x0100 && ip[1] == 0 && ip[2] == 0 && ip[3] == 0 {
        r(false, false, false)
    } else if ip[0] == 0x2001 && ip[1] == 0 {
        r(false, false, false)
    } else if ip[0] == 0x2001 && ip[1] <= 0x01ff {
        r(false, false, false)
    } else if ip[0] == 0x2001 && ip[1] == 0x0002 && ip[2] == 0 {
        r(false, false, false)
    } else if ip[0] == 0x2001 && ip[1] == 0x0db8 {
        r(false, false, false)
    } else if ip[0] == 0x2001 && ip[1] >= 0x0010 && ip[1] <= 0x001f {
        r(false, false, false)
    } else if ip[0] == 0x2002 {
        r(false, false, false)
    } else if ip[0] >= 0xfc00 && ip[0] <= 0xfdff {
        r(false, false, true)
    } else if ip[0] >= 0xfe80 && ip[0] <= 0xfebf {
        r(false, true, false)
    } else {
        None
    }
}

/// `php_filter_validate_ip()`.
fn validate_ip(s: &[u8], flags: i64) -> bool {
    let v6 = if s.contains(&b':') {
        true
    } else if s.contains(&b'.') {
        false
    } else {
        return false;
    };
    let want4 = flags & FILTER_FLAG_IPV4 != 0;
    let want6 = flags & FILTER_FLAG_IPV6 != 0;
    if !(want4 && want6) && ((want4 && v6) || (want6 && !v6)) {
        return false;
    }
    let status = if v6 {
        let mut ip = [0i32; 8];
        if !validate_ipv6(s, Some(&mut ip)) {
            return false;
        }
        ipv6_flags(&ip)
    } else {
        let mut ip = [0i32; 4];
        if !validate_ipv4(s, &mut ip) {
            return false;
        }
        ipv4_flags(&ip)
    };
    let Some((global, reserved, private)) = status else { return true };
    if flags & FILTER_FLAG_GLOBAL_RANGE != 0 && !global {
        return false;
    }
    if flags & FILTER_FLAG_NO_PRIV_RANGE != 0 && private {
        return false;
    }
    if flags & FILTER_FLAG_NO_RES_RANGE != 0 && reserved {
        return false;
    }
    true
}

/// `php_filter_validate_mac()`.
fn validate_mac(s: &[u8], expected: Option<u8>) -> bool {
    let (tokens, length, separator) = if s.len() == 14 {
        (3, 4, b'.')
    } else if s.len() == 17 && s[2] == b'-' {
        (6, 2, b'-')
    } else if s.len() == 17 && s[2] == b':' {
        (6, 2, b':')
    } else {
        return false;
    };
    if expected.is_some_and(|e| e != separator) {
        return false;
    }
    for i in 0..tokens {
        let offset = i * (length + 1);
        if i < tokens - 1 && s[offset + length] != separator {
            return false;
        }
        if parse_hex(&s[offset..offset + length]).is_none() {
            return false;
        }
    }
    true
}

/// `php_filter_unsafe_raw()`.
fn unsafe_raw(s: &[u8], flags: i64) -> Value {
    if flags != 0 && !s.is_empty() {
        let stripped: Vec<u8> =
            if flags & (FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_BACKTICK) != 0 {
                s.iter()
                    .copied()
                    .filter(|&c| {
                        !((c >= 127 && flags & FILTER_FLAG_STRIP_HIGH != 0)
                            || (c < 32 && flags & FILTER_FLAG_STRIP_LOW != 0)
                            || (c == b'`' && flags & FILTER_FLAG_STRIP_BACKTICK != 0))
                    })
                    .collect()
            } else {
                s.to_vec()
            };
        let encode = |c: u8| {
            (c == b'&' && flags & FILTER_FLAG_ENCODE_AMP != 0)
                || (c < 32 && flags & FILTER_FLAG_ENCODE_LOW != 0)
                || (c >= 127 && flags & FILTER_FLAG_ENCODE_HIGH != 0)
        };
        let mut out = Vec::with_capacity(stripped.len());
        for c in stripped {
            if encode(c) {
                out.extend_from_slice(format!("&#{c};").as_bytes());
            } else {
                out.push(c);
            }
        }
        Value::Str(out)
    } else if flags & FILTER_FLAG_EMPTY_STRING_NULL != 0 && s.is_empty() {
        Value::Null
    } else {
        Value::Str(s.to_vec())
    }
}

/// The `FILTER_VALIDATE_EMAIL` regular expressions of `logical_filters.c`
/// (`regexp0` with `FILTER_FLAG_EMAIL_UNICODE`, `regexp1` without).
const EMAIL_UNICODE: &[u8] = br#"/^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E\pL\pN]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F\pL\pN]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E\pL\pN]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F\pL\pN]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))$/iDu"#;
const EMAIL: &[u8] = br#"/^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))$/iD"#;

#[cfg(test)]
mod tests {
    use super::*;

    fn fv(v: &str, filter: i64, flags: i64) -> Value {
        filter_var(&Value::Str(v.as_bytes().to_vec()), filter, &Options::Flags(flags)).unwrap().value
    }

    #[test]
    fn validators() {
        assert_eq!(fv(" 42 ", FILTER_VALIDATE_INT, 0), Value::Int(42));
        assert_eq!(fv("0x1A", FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX), Value::Int(26));
        assert_eq!(fv("012", FILTER_VALIDATE_INT, 0), Value::Bool(false));
        assert_eq!(fv("yes", FILTER_VALIDATE_BOOL, 0), Value::Bool(true));
        assert_eq!(fv("maybe", FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE), Value::Null);
        assert_eq!(fv("1,000.5", FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_THOUSAND), Value::Float(1000.5));
        assert_eq!(fv("192.168.0.1", FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE), Value::Bool(false));
        assert_eq!(fv("::1", FILTER_VALIDATE_IP, 0), Value::Str(b"::1".to_vec()));
        assert_eq!(fv("http://example.com/x", FILTER_VALIDATE_URL, 0), Value::Str(b"http://example.com/x".to_vec()));
        assert_eq!(fv("http://exa_mple.com", FILTER_VALIDATE_URL, 0), Value::Bool(false));
        assert_eq!(fv("a@b.co", FILTER_VALIDATE_EMAIL, 0), Value::Str(b"a@b.co".to_vec()));
        assert_eq!(fv("a@b", FILTER_VALIDATE_EMAIL, 0), Value::Bool(false));
        assert_eq!(
            fv("ex-ample.com", FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME),
            Value::Str(b"ex-ample.com".to_vec())
        );
        assert_eq!(fv("01:23:45:67:89:ab", FILTER_VALIDATE_MAC, 0), Value::Str(b"01:23:45:67:89:ab".to_vec()));
    }
}
