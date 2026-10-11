//! `path.*`: `basename()` and `pathinfo($path, PATHINFO_EXTENSION)` (`php_std::path`).

use php_std::path;
use serde_json::Value;

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &["path.basename", "path.pathinfo_extension"];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let p = a.bytes("path")?;
    Ok(match op {
        "path.basename" => Outcome::Ok(bytes_value(path::basename(&p))),
        "path.pathinfo_extension" => Outcome::Ok(bytes_value(path::pathinfo_extension(&p))),
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    })
}
