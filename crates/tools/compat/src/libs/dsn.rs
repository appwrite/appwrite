//! Compat adapter for `dsn`: maps `tests/compat/dsn/spec.json` operations onto `utopia-dsn`.

use serde_json::{Value, json};
use utopia_dsn::{Dsn, Error};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &["dsn.new", "dsn.parse", "dsn.get", "dsn.param", "dsn.refusal_printed"];

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

/// Every getter, as `DSN::get*()` returns it (the port as PHP's string).
fn parts(d: &Dsn) -> Value {
    json!({
        "scheme": d.scheme(),
        "user": d.user().map(bytes_value),
        "password": d.password().map(bytes_value),
        "host": d.host(),
        "port": d.port().map(|p| p.to_string()),
        "path": d.path(),
        "query": d.query(),
    })
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let dsn = |s: &Session| -> Result<Dsn, Fault> { s.get::<Dsn>(a.value("dsn")?).cloned() };
    Ok(match op {
        "dsn.new" => match Dsn::parse(a.str("dsn")?) {
            Ok(d) => Outcome::Ok(session.handle(d)),
            Err(e) => err(e),
        },
        "dsn.parse" => match Dsn::parse(a.str("dsn")?) {
            Ok(d) => {
                let mut out = parts(&d);
                if let Some(keys) = a.opt("params").and_then(Value::as_array) {
                    let mut params = Vec::with_capacity(keys.len());
                    for key in keys {
                        let key = key.as_str().ok_or_else(|| Fault::new("params must be strings"))?;
                        params.push(match d.param_or(key, b"") {
                            Ok(v) => bytes_value(v),
                            Err(e) => err(e).to_json(),
                        });
                    }
                    out["params"] = Value::Array(params);
                }
                Outcome::Ok(out)
            }
            Err(e) => err(e),
        },
        "dsn.get" => Outcome::Ok(parts(&dsn(session)?)),
        "dsn.param" => {
            let d = dsn(session)?;
            let default = match a.opt("default") {
                Some(v) => crate::adapter::bytes(v).ok_or_else(|| Fault::new("default must be a string"))?,
                None => Vec::new(),
            };
            match d.param_or(a.str("key")?, &default) {
                Ok(v) => Outcome::Ok(bytes_value(v)),
                Err(e) => err(e),
            }
        }
        "dsn.refusal_printed" => match Dsn::parse(a.str("dsn")?) {
            Ok(_) => Outcome::Ok(Value::Bool(false)),
            Err(e) => {
                let printed = format!("{e}\n{e:?}");
                Outcome::ok(a.array("secrets")?.iter().filter_map(Value::as_str).all(|s| !printed.contains(s)))
            }
        },
        _ => return Err(Fault::new(format!("dsn: unknown operation `{op}`"))),
    })
}
