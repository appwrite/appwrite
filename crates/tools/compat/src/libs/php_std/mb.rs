//! `mb.*`: multibyte functions on UTF-8 (`php_std::mb`).
//!
//! Arguments carry PHP's parameter names; omitted optional arguments take
//! PHP's defaults (the encoding is always PHP's internal UTF-8, except
//! `mb_strlen`'s `$encoding`). Strings may be `{"$bytes": ...}`.

use php_std::mb::{self, Case, Encoding};
use php_std::string::Pad;
use serde_json::Value;

use super::string::{done, list, opt_bytes, position, string_or_false};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &[
    "mb.strlen",
    "mb.substr",
    "mb.strcut",
    "mb.str_split",
    "mb.strtolower",
    "mb.strtoupper",
    "mb.convert_case",
    "mb.ucfirst",
    "mb.lcfirst",
    "mb.strpos",
    "mb.strrpos",
    "mb.stripos",
    "mb.strripos",
    "mb.strstr",
    "mb.strrchr",
    "mb.stristr",
    "mb.strrichr",
    "mb.substr_count",
    "mb.str_pad",
    "mb.trim",
    "mb.ltrim",
    "mb.rtrim",
    "mb.check_encoding",
    "mb.scrub",
    "mb.convert_encoding",
    "mb.ord",
    "mb.chr",
];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let s = || a.bytes("string");
    let hay = || a.bytes("haystack");
    let needle = || a.bytes("needle");
    let offset = || a.opt_i64("offset").map(|o| o.unwrap_or(0));
    let ok = |v: Value| Ok(Outcome::Ok(v));
    match op {
        "mb.strlen" => {
            let encoding = match a.opt_str("encoding")? {
                None => Encoding::Utf8,
                Some(name) => {
                    Encoding::from_name(name).ok_or_else(|| Fault::new("encoding not ported (a deviation)"))?
                }
            };
            ok(Value::from(mb::mb_strlen_in(&s()?, encoding)))
        }
        "mb.substr" => done(mb::mb_substr(&s()?, a.i64("start")?, a.opt_i64("length")?).map(|r| bytes_value(&r))),
        "mb.strcut" => ok(bytes_value(mb::mb_strcut(&s()?, a.i64("start")?, a.opt_i64("length")?))),
        "mb.str_split" => done(mb::mb_str_split(&s()?, a.opt_i64("length")?.unwrap_or(1)).map(|p| list(&p))),
        "mb.strtolower" => ok(bytes_value(&mb::mb_strtolower(&s()?))),
        "mb.strtoupper" => ok(bytes_value(&mb::mb_strtoupper(&s()?))),
        "mb.convert_case" => {
            let mode =
                Case::from_php(a.i64("mode")?).ok_or_else(|| Fault::new("mode outside MB_CASE_* (a deviation)"))?;
            ok(bytes_value(&mb::mb_convert_case(&s()?, mode)))
        }
        "mb.ucfirst" => ok(bytes_value(&mb::mb_ucfirst(&s()?))),
        "mb.lcfirst" => ok(bytes_value(&mb::mb_lcfirst(&s()?))),
        "mb.strpos" => done(mb::mb_strpos(&hay()?, &needle()?, offset()?).map(position)),
        "mb.strrpos" => done(mb::mb_strrpos(&hay()?, &needle()?, offset()?).map(position)),
        "mb.stripos" => done(mb::mb_stripos(&hay()?, &needle()?, offset()?).map(position)),
        "mb.strripos" => done(mb::mb_strripos(&hay()?, &needle()?, offset()?).map(position)),
        "mb.strstr" | "mb.strrchr" | "mb.stristr" | "mb.strrichr" => {
            let before = a.opt_bool("before_needle")?.unwrap_or(false);
            let (h, n) = (hay()?, needle()?);
            let r = match op {
                "mb.strstr" => mb::mb_strstr(&h, &n, before),
                "mb.strrchr" => mb::mb_strrchr(&h, &n, before),
                "mb.stristr" => mb::mb_stristr(&h, &n, before),
                _ => mb::mb_strrichr(&h, &n, before),
            };
            ok(string_or_false(r.as_deref()))
        }
        "mb.substr_count" => done(mb::mb_substr_count(&hay()?, &needle()?).map(Value::from)),
        "mb.str_pad" => {
            let pad = opt_bytes(&a, "pad_string")?.unwrap_or_else(|| b" ".to_vec());
            let pad_type = Pad::from_php(a.opt_i64("pad_type")?.unwrap_or(1))
                .ok_or_else(|| Fault::new("pad_type outside STR_PAD_* (a deviation)"))?;
            done(mb::mb_str_pad(&s()?, a.i64("length")?, &pad, pad_type).map(|r| bytes_value(&r)))
        }
        "mb.trim" | "mb.ltrim" | "mb.rtrim" => {
            let chars = opt_bytes(&a, "characters")?;
            let s = s()?;
            let r = match op {
                "mb.trim" => mb::mb_trim(&s, chars.as_deref()),
                "mb.ltrim" => mb::mb_ltrim(&s, chars.as_deref()),
                _ => mb::mb_rtrim(&s, chars.as_deref()),
            };
            ok(bytes_value(&r))
        }
        "mb.check_encoding" => ok(Value::Bool(mb::mb_check_encoding(&a.bytes("value")?))),
        "mb.scrub" => ok(bytes_value(&mb::mb_scrub(&s()?))),
        "mb.convert_encoding" => {
            if a.str("to_encoding")? != "UTF-8" {
                return Err(Fault::new("only UTF-8 to UTF-8 is ported"));
            }
            ok(bytes_value(&mb::mb_scrub(&s()?)))
        }
        "mb.ord" => done(mb::mb_ord(&s()?).map(|c| c.map(Value::from).unwrap_or(Value::Bool(false)))),
        "mb.chr" => ok(string_or_false(mb::mb_chr(a.i64("codepoint")?).as_deref())),
        _ => Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }
}
