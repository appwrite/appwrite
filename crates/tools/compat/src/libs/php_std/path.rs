//! `path.*`: `basename()` and `pathinfo($path, PATHINFO_EXTENSION)` (`php_std::path`), and `fnmatch()`
//! (`php_std::glob`).

use php_std::{glob, path};
use serde_json::Value;

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &["path.basename", "path.pathinfo_extension", "path.fnmatch"];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    if op == "path.fnmatch" {
        return Ok(match glob::fnmatch(&a.bytes("pattern")?, &a.bytes("filename")?) {
            Ok(matched) => Outcome::Ok(Value::Bool(matched)),
            Err(e) => Outcome::err(e.class, e.message),
        });
    }
    let p = a.bytes("path")?;
    Ok(match op {
        "path.basename" => Outcome::Ok(bytes_value(path::basename(&p, b""))),
        "path.pathinfo_extension" => Outcome::Ok(bytes_value(path::pathinfo_extension(&p))),
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    })
}
