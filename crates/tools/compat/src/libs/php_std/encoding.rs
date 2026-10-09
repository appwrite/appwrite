//! `encoding.*`: URL, query, base64, hex, HTML entity, slash and
//! quoted-printable encodings (`php_std::encoding`).
//!
//! Arguments carry PHP's parameter names; omitted optional arguments take
//! PHP's defaults. Strings may be `{"$bytes": ...}`.

use php_std::encoding::{self, HtmlFlags, QueryEncoding};
use serde_json::Value;

use super::string::{opt_bytes, string_or_false};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &[
    "encoding.urlencode",
    "encoding.rawurlencode",
    "encoding.urldecode",
    "encoding.rawurldecode",
    "encoding.http_build_query",
    "encoding.base64_encode",
    "encoding.base64_decode",
    "encoding.bin2hex",
    "encoding.hex2bin",
    "encoding.htmlspecialchars",
    "encoding.htmlentities",
    "encoding.htmlspecialchars_decode",
    "encoding.html_entity_decode",
    "encoding.addslashes",
    "encoding.stripslashes",
    "encoding.quoted_printable_encode",
    "encoding.quoted_printable_decode",
    "encoding.parse_str",
];

/// The HTML functions' `$flags` (default `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401`).
fn flags(a: &Args) -> Result<HtmlFlags, Fault> {
    Ok(a.opt_i64("flags")?.map(HtmlFlags::from_php).unwrap_or(HtmlFlags::DEFAULT))
}

/// The HTML functions' `$encoding`: only UTF-8 is ported.
fn utf8_only(a: &Args) -> Result<(), Fault> {
    match a.opt_str("encoding")? {
        None => Ok(()),
        Some(e) if e.eq_ignore_ascii_case("UTF-8") => Ok(()),
        Some(e) => Err(Fault::new(format!("charset `{e}` is not ported (a deviation)"))),
    }
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let s = || a.bytes("string");
    let ok = |v: Value| Ok(Outcome::Ok(v));
    match op {
        "encoding.urlencode" => ok(Value::String(encoding::urlencode(&s()?))),
        "encoding.rawurlencode" => ok(Value::String(encoding::rawurlencode(&s()?))),
        "encoding.urldecode" => ok(bytes_value(&encoding::urldecode(&s()?))),
        "encoding.rawurldecode" => ok(bytes_value(&encoding::rawurldecode(&s()?))),
        "encoding.http_build_query" => {
            let prefix = opt_bytes(&a, "numeric_prefix")?.unwrap_or_default();
            let separator = opt_bytes(&a, "arg_separator")?;
            let enc = QueryEncoding::from_php(a.opt_i64("encoding_type")?.unwrap_or(1));
            ok(bytes_value(&encoding::http_build_query(a.value("data")?, &prefix, separator.as_deref(), enc)))
        }
        "encoding.base64_encode" => ok(Value::String(encoding::base64_encode(&s()?))),
        "encoding.base64_decode" => {
            let strict = a.opt_bool("strict")?.unwrap_or(false);
            ok(string_or_false(encoding::base64_decode(&s()?, strict).as_deref()))
        }
        "encoding.bin2hex" => ok(Value::String(encoding::bin2hex(&s()?))),
        "encoding.hex2bin" => ok(string_or_false(encoding::hex2bin(&s()?).as_deref())),
        "encoding.htmlspecialchars" | "encoding.htmlentities" => {
            utf8_only(&a)?;
            let double_encode = a.opt_bool("double_encode")?.unwrap_or(true);
            let f = if op == "encoding.htmlspecialchars" { encoding::htmlspecialchars } else { encoding::htmlentities };
            ok(bytes_value(&f(&s()?, flags(&a)?, double_encode)))
        }
        "encoding.htmlspecialchars_decode" => ok(bytes_value(&encoding::htmlspecialchars_decode(&s()?, flags(&a)?))),
        "encoding.html_entity_decode" => {
            utf8_only(&a)?;
            ok(bytes_value(&encoding::html_entity_decode(&s()?, flags(&a)?)))
        }
        "encoding.addslashes" => ok(bytes_value(&encoding::addslashes(&s()?))),
        "encoding.stripslashes" => ok(bytes_value(&encoding::stripslashes(&s()?))),
        "encoding.quoted_printable_encode" => ok(bytes_value(&encoding::quoted_printable_encode(&s()?))),
        "encoding.quoted_printable_decode" => ok(bytes_value(&encoding::quoted_printable_decode(&s()?))),
        "encoding.parse_str" => {
            let mut out = Vec::new();
            leaves(&encoding::parse_str(&s()?), &mut Vec::new(), &mut out)?;
            ok(Value::Array(out))
        }
        _ => Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }
}

/// A PHP array's leaves in order, as `[[key, ...], value]` (an empty array
/// is a leaf with value `[]`): keys keep their int/string type and may be
/// binary (`{"$bytes"}`), and deep nesting stays shallow on the wire.
fn leaves(array: &php_std::zval::Array, path: &mut Vec<Value>, out: &mut Vec<Value>) -> Result<(), Fault> {
    use php_std::zval::{Key, Zval};
    for (k, v) in array.iter() {
        path.push(match k {
            Key::Int(i) => Value::from(*i),
            Key::Str(s) => bytes_value(s),
        });
        match v {
            Zval::Array(a) if !a.is_empty() => leaves(a, path, out)?,
            Zval::Array(_) => out.push(Value::Array(vec![Value::Array(path.clone()), Value::Array(Vec::new())])),
            Zval::String(s) => out.push(Value::Array(vec![Value::Array(path.clone()), bytes_value(s)])),
            other => return Err(Fault::new(format!("parse_str produced {other:?}"))),
        }
        path.pop();
    }
    Ok(())
}
