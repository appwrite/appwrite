//! `mb.*`: multibyte functions on UTF-8 (`php_std::mb`).
//!
//! Arguments carry PHP's parameter names; omitted optional arguments take
//! PHP's defaults (the encoding is always PHP's internal UTF-8, except
//! `mb_strlen`'s `$encoding`). Strings may be `{"$bytes": ...}`.

use php_std::mb::{self, Encoding};
use serde_json::Value;

use super::string::{done, position};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &[
    "mb.strlen",
    "mb.substr",
    "mb.strtolower",
    "mb.strimwidth",
    "mb.strpos",
    "mb.check_encoding",
    "mb.scrub",
    "mb.convert_encoding",
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
        "mb.strtolower" => ok(bytes_value(&mb::mb_strtolower(&s()?))),
        "mb.strimwidth" => {
            let width = usize::try_from(a.i64("width")?)
                .map_err(|_| Fault::new("a negative width is not ported (a deviation)"))?;
            ok(bytes_value(&mb::mb_strimwidth(&s()?, width)))
        }
        "mb.strpos" => done(mb::mb_strpos(&hay()?, &needle()?, offset()?).map(position)),
        "mb.check_encoding" => ok(Value::Bool(mb::mb_check_encoding(&a.bytes("value")?))),
        "mb.scrub" => ok(bytes_value(&mb::mb_scrub(&s()?))),
        "mb.convert_encoding" => {
            if a.str("to_encoding")? != "UTF-8" {
                return Err(Fault::new("only UTF-8 to UTF-8 is ported"));
            }
            ok(bytes_value(&mb::mb_scrub(&s()?)))
        }
        _ => Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }
}
