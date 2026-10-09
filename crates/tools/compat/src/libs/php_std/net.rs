//! `net.*`: `ip2long`/`long2ip`, `inet_pton`/`inet_ntop`, `idn_to_ascii`/`idn_to_utf8` (`php_std::net`).

use php_std::net::{self, idn};
use serde_json::Value;

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &[
    "net.ip2long",
    "net.long2ip",
    "net.inet_pton",
    "net.inet_ntop",
    "net.idn_to_ascii",
    "net.idn_to_utf8",
];

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
            let flags = idn::Flags::from_bits(a.i64("flags")?);
            let result =
                if op == "net.idn_to_ascii" { idn::to_ascii(&domain, flags) } else { idn::to_utf8(&domain, flags) };
            match result {
                Ok(v) => Outcome::Ok(v.map_or(Value::Bool(false), |s| bytes_value(&s))),
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            }
        }
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    })
}
