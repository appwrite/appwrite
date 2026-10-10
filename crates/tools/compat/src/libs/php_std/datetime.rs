//! `datetime.*`: Date and time: date()/DateTime::format, DateTime parsing and timezones, strtotime, microtime.
//!
//! Conventions shared with `tests/compat/php-std/ops/datetime.php`: strings
//! whose date `date_parse()` leaves unset are reported as `"depends on now"`
//! instead of constructed (PHP reads its clock), and the operations whose
//! exception messages quote the input return exceptions as values.

use php_std::datetime::{self, DateTime, ErrorKind, Interval, TimeZone, Timestamp, TzInfo};
use serde_json::{Value, json};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &[
    "datetime.parse",
    "datetime.parse_from_format",
    "datetime.strtotime",
    "datetime.date",
    "datetime.gmdate",
    "datetime.create",
    "datetime.info",
    "datetime.format",
    "datetime.modify",
    "datetime.modify_mutable",
    "datetime.add",
    "datetime.create_from_format",
    "datetime.compare",
    "datetime.timezone",
    "datetime.timezone_name_from_abbr",
    "datetime.microtime",
];

/// "Now" for the Rust side of operations whose inputs do not depend on it.
const NOW: Timestamp = Timestamp { sec: 1_700_000_000, usec: 0 };

const DEPENDS_ON_NOW: &str = "depends on now";

/// Whether `date_parse($s)` leaves the year, month or day unset (and has no
/// error, which would make the constructor throw instead).
fn depends_on_now(s: &[u8]) -> bool {
    let p = datetime::date_parse(s).to_php();
    p["error_count"] == 0 && (p["year"] == false || p["month"] == false || p["day"] == false)
}

/// An exception as a value (`['exception' => class, 'message' => message]`);
/// a `ValueError` is still thrown.
fn caught(result: Result<Value, datetime::Error>) -> Outcome {
    match result {
        Ok(v) => Outcome::Ok(v),
        Err(e) if e.kind() == ErrorKind::Value => Outcome::err(e.php_class(), e.message()),
        Err(e) => Outcome::Ok(json!({"exception": e.php_class(), "message": bytes_value(e.message_bytes())})),
    }
}

fn thrown(result: Result<Value, datetime::Error>) -> Outcome {
    match result {
        Ok(v) => Outcome::Ok(v),
        Err(e) => Outcome::err(e.php_class(), e.message()),
    }
}

fn zone(a: &Args, key: &str) -> Result<Result<Option<TimeZone>, datetime::Error>, Fault> {
    Ok(match a.opt(key) {
        Some(_) => TimeZone::new(a.bytes(key)?).map(Some),
        None => Ok(None),
    })
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let utc = TzInfo::utc();
    let parse = |key: &str| -> Result<Result<DateTime, datetime::Error>, Fault> {
        Ok(DateTime::parse(&a.bytes(key)?, None, &utc, NOW))
    };
    Ok(match op {
        "datetime.parse" => Outcome::Ok(datetime::date_parse(&a.bytes("s")?).to_php()),
        "datetime.parse_from_format" => {
            thrown(datetime::date_parse_from_format(&a.bytes("format")?, &a.bytes("s")?).map(|p| p.to_php()))
        }
        "datetime.strtotime" => Outcome::Ok(match datetime::strtotime(&a.bytes("s")?, a.i64("base")?, &utc) {
            Some(ts) => Value::from(ts),
            None => Value::Bool(false),
        }),
        "datetime.date" => Outcome::Ok(bytes_value(&datetime::date(&a.bytes("format")?, a.i64("ts")?, &utc))),
        "datetime.gmdate" => Outcome::Ok(bytes_value(&datetime::gmdate(&a.bytes("format")?, a.i64("ts")?))),
        "datetime.create" | "datetime.info" if depends_on_now(&a.bytes("s")?) => Outcome::ok(DEPENDS_ON_NOW),
        "datetime.create" => {
            let s = a.bytes("s")?;
            let format = a.bytes("format")?;
            caught(
                zone(&a, "tz")?
                    .and_then(|tz| DateTime::parse(&s, tz.as_ref(), &utc, NOW))
                    .map(|d| bytes_value(&d.format(&format))),
            )
        }
        "datetime.info" => {
            let s = a.bytes("s")?;
            caught(zone(&a, "tz")?.and_then(|tz| DateTime::parse(&s, tz.as_ref(), &utc, NOW)).map(|d| {
                json!([
                    d.timestamp(),
                    d.offset(),
                    d.microsecond(),
                    d.timezone().map(|z| Value::String(z.name())).unwrap_or(Value::Bool(false))
                ])
            }))
        }
        "datetime.format" => {
            let format = a.bytes("format")?;
            let tz = a.bytes("tz")?;
            caught(parse("at")?.and_then(|mut d| {
                d.set_timezone(&TimeZone::new(&tz)?);
                Ok(bytes_value(&d.format(&format)))
            }))
        }
        "datetime.modify" | "datetime.modify_mutable" | "datetime.add" if depends_on_now(&a.bytes("base")?) => {
            Outcome::ok(DEPENDS_ON_NOW)
        }
        "datetime.modify" => {
            let (s, format) = (a.bytes("s")?, a.bytes("format")?);
            caught(parse("base")?.and_then(|d| d.modified(&s)).map(|d| bytes_value(&d.format(&format))))
        }
        "datetime.modify_mutable" => {
            let (s, format) = (a.bytes("s")?, a.bytes("format")?);
            caught(parse("base")?.and_then(|mut d| {
                d.modify(&s)?;
                Ok(bytes_value(&d.format(&format)))
            }))
        }
        "datetime.add" => {
            let (interval, format) = (a.bytes("interval")?, a.bytes("format")?);
            caught(parse("base")?.and_then(|mut d| {
                d.add(&Interval::from_date_string(&interval)?);
                Ok(bytes_value(&d.format(&format)))
            }))
        }
        "datetime.create_from_format" => {
            let (format, s, out) = (a.bytes("format")?, a.bytes("s")?, a.bytes("out")?);
            thrown(zone(&a, "tz")?.and_then(|tz| DateTime::from_format(&format, &s, tz.as_ref(), &utc, NOW)).map(|r| {
                match r {
                    Ok(d) => bytes_value(&d.format(&out)),
                    Err(messages) => messages.to_php(),
                }
            }))
        }
        "datetime.compare" if depends_on_now(&a.bytes("a")?) || depends_on_now(&a.bytes("b")?) => {
            Outcome::ok(DEPENDS_ON_NOW)
        }
        "datetime.compare" => {
            let (x, y) = (parse("a")?, parse("b")?);
            caught(x.and_then(|x| Ok(Value::from(x.cmp_time(&y?) as i32))))
        }
        "datetime.timezone" => {
            let ts = a.i64("ts")?;
            caught(TimeZone::new(a.bytes("tz")?).map(|z| json!([z.name(), z.offset_at(ts)])))
        }
        "datetime.timezone_name_from_abbr" => Outcome::Ok(
            match datetime::timezone_name_from_abbr(
                a.str("abbr")?,
                a.opt_i64("offset")?.unwrap_or(-1),
                a.opt_i64("isdst")?.unwrap_or(-1),
            ) {
                Some(name) => Value::String(name.to_owned()),
                None => Value::Bool(false),
            },
        ),
        "datetime.microtime" => Outcome::Ok(json!([
            datetime::microtime().len(),
            datetime::microtime_float().is_finite(),
            datetime::microtime_float() > 1e9,
            datetime::time() > 0
        ])),
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    })
}
