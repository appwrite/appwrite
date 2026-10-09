//! `net.*`: `ip2long`/`long2ip`, `inet_pton`/`inet_ntop`, `idn_to_ascii`/`idn_to_utf8` (`php_std::net`).
//! The IDN operations report the result and what `$idna_info` receives, as
//! `ops/net.php` does.

use php_std::net::{self, idn};
use serde_json::{Map, Value};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] =
    &["net.ip2long", "net.long2ip", "net.inet_pton", "net.inet_ntop", "net.idn_to_ascii", "net.idn_to_utf8"];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let nul = |e: net::NulByteError| Outcome::err(e.php_class(), e.to_string());
    Ok(match op {
        "net.ip2long" => match net::ip2long(&a.bytes("ip")?) {
            Ok(v) => Outcome::Ok(v.map_or(Value::Bool(false), Value::from)),
            Err(e) => nul(e),
        },
        "net.long2ip" => Outcome::Ok(Value::String(net::long2ip(a.i64("ip")?))),
        "net.inet_pton" => match net::inet_pton(&a.bytes("ip")?) {
            Ok(v) => Outcome::Ok(v.map_or(Value::Bool(false), |p| bytes_value(p.as_bytes()))),
            Err(e) => nul(e),
        },
        "net.inet_ntop" => Outcome::Ok(net::inet_ntop(&a.bytes("packed")?).map_or(Value::Bool(false), Value::String)),
        "net.idn_to_ascii" | "net.idn_to_utf8" => {
            let domain = a.bytes("domain")?;
            let flags = idn::Flags::from_bits(a.opt_i64("flags")?.unwrap_or(idn::IDNA_DEFAULT));
            let result = if op == "net.idn_to_ascii" {
                idn::to_ascii_info(&domain, flags)
            } else {
                idn::to_utf8_info(&domain, flags)
            };
            match result {
                Ok(c) => {
                    let mut o = Map::new();
                    o.insert("result".into(), c.value.map_or(Value::Bool(false), |s| bytes_value(&s)));
                    o.insert(
                        "info".into(),
                        c.info.map_or(Value::Array(Vec::new()), |i| {
                            let mut info = Map::new();
                            info.insert("result".into(), bytes_value(&i.result));
                            info.insert("isTransitionalDifferent".into(), Value::Bool(i.is_transitional_different));
                            info.insert("errors".into(), Value::from(i.errors));
                            Value::Object(info)
                        }),
                    );
                    Outcome::Ok(Value::Object(o))
                }
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            }
        }
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    })
}
