//! `net.*`: `inet_pton`/`inet_ntop` (`php_std::net`).

use php_std::net;
use serde_json::Value;

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &["net.inet_pton", "net.inet_ntop"];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let nul = |e: net::NulByteError| Outcome::err(e.php_class(), e.to_string());
    Ok(match op {
        "net.inet_pton" => match net::inet_pton(&a.bytes("ip")?) {
            Ok(v) => Outcome::Ok(v.map_or(Value::Bool(false), |p| bytes_value(p.as_bytes()))),
            Err(e) => nul(e),
        },
        "net.inet_ntop" => Outcome::Ok(net::inet_ntop(&a.bytes("packed")?).map_or(Value::Bool(false), Value::String)),
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    })
}
