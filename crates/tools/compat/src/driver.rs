//! The Rust driver: reads requests as JSON lines on stdin and answers on
//! stdout, the same protocol `tests/compat/php/driver.php` speaks.
//!
//! Request:  `{"id": 7, "lib": "validators", "op": "validate", "args": {...}}`
//! Replies:  `{"id": 7, "ok": <value>}`
//!           `{"id": 7, "err": {"class": "<PHP exception class>", "message": "..." | {"$bytes": ...}}}`
//!           `{"id": 7, "fault": "<harness problem>"}`
//!
//! Control operations: `$hello` (runtime, OS, kernel and the operations of every library),
//! `$config` (`{"ns": ..., "services": {...}}`, resets handles), `$reset`,
//! `$equal` (`{"a": ..., "b": ...}`, compares two values) and `$bench`
//! (`{}` lists the library's benchmarks, `{"name": ...}` times one; see
//! [`crate::bench`]).

use std::panic::AssertUnwindSafe;

use futures_util::FutureExt as _;
use serde_json::{Map, Value, json};
use utopia_async::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};

use crate::adapter::{Fault, OpResult, Outcome, Session};
use crate::libs;

/// Answers one request against `session`.
pub async fn handle(request: &Value, session: &mut Session) -> OpResult {
    let op = request.get("op").and_then(Value::as_str).unwrap_or_default();
    let lib = request.get("lib").and_then(Value::as_str).unwrap_or_default();
    let empty = Value::Object(Map::new());
    let args = request.get("args").unwrap_or(&empty);
    match op {
        "$hello" => {
            let libs: Map<String, Value> =
                libs::LIBS.iter().map(|(name, ops)| ((*name).to_owned(), json!(ops))).collect();
            let (os, kernel) = system();
            Ok(Outcome::Ok(json!({ "runtime": "rust", "os": os, "kernel": kernel, "libs": libs })))
        }
        "$config" => {
            let ns = args.get("ns").and_then(Value::as_str).unwrap_or("compat");
            let services = args.get("services").and_then(Value::as_object).cloned().unwrap_or_default();
            *session = Session::new(ns, services);
            Ok(Outcome::Ok(Value::Bool(true)))
        }
        // Compares two values, typically bound results of earlier steps.
        "$equal" => Ok(Outcome::Ok(Value::Bool(args.get("a") == args.get("b")))),
        "$reset" => {
            session.reset();
            Ok(Outcome::Ok(Value::Bool(true)))
        }
        "$bench" => match args.get("name").and_then(Value::as_str) {
            None => Ok(Outcome::Ok(Value::Object(
                libs::benches(lib).iter().map(|(n, d)| ((*n).to_owned(), json!(d))).collect(),
            ))),
            Some(name) => {
                let bench = libs::bench(lib, name, session).await?;
                Ok(Outcome::Ok(crate::bench::measure(bench).await))
            }
        },
        _ => match AssertUnwindSafe(libs::call(lib, op, args, session)).catch_unwind().await {
            Ok(result) => result,
            // A panic is a behaviour difference (PHP would not panic), not a harness fault.
            Err(panic) => {
                let message = panic
                    .downcast_ref::<&str>()
                    .map(|s| (*s).to_owned())
                    .or_else(|| panic.downcast_ref::<String>().cloned())
                    .unwrap_or_else(|| "panic".to_owned());
                Ok(Outcome::err("rust::panic", message))
            }
        },
    }
}

/// The OS the driver runs on (`/etc/os-release`'s name on Linux) and its
/// kernel release, for `compat bench`'s environment.
fn system() -> (String, String) {
    let os = std::fs::read_to_string("/etc/os-release")
        .ok()
        .and_then(|t| t.lines().find_map(|l| l.strip_prefix("PRETTY_NAME=").map(|v| v.trim_matches('"').to_owned())))
        .unwrap_or_else(|| std::env::consts::OS.to_owned());
    let kernel = std::fs::read_to_string("/proc/sys/kernel/osrelease")
        .ok()
        .or_else(|| {
            let out = std::process::Command::new("uname").arg("-r").output().ok()?;
            Some(String::from_utf8_lossy(&out.stdout).into_owned())
        })
        .map(|k| k.trim().to_owned())
        .unwrap_or_default();
    (os, kernel)
}

/// Encodes a reply line.
pub fn reply(id: &Value, result: &OpResult) -> Value {
    match result {
        Ok(Outcome::Ok(v)) => json!({ "id": id, "ok": v }),
        Ok(Outcome::Err { class, message }) => json!({ "id": id, "err": { "class": class, "message": message } }),
        Err(Fault(message)) => json!({ "id": id, "fault": message }),
    }
}

/// Creates files with the mode PHP's driver container gives them: its umask
/// is 022, a CI runner's may be 002 (0664 where PHP writes 0644). For every
/// process that runs the Rust adapters: the driver and `replay`'s test.
pub fn php_umask() {
    // SAFETY: umask only sets this process's file mode creation mask.
    #[cfg(unix)]
    unsafe {
        libc::umask(0o022);
    }
}

/// Serves requests until stdin closes.
pub async fn serve() -> std::io::Result<()> {
    let mut lines = BufReader::new(utopia_async::io::stdin()).lines();
    let mut stdout = utopia_async::io::stdout();
    let mut session = Session::default();
    while let Some(line) = lines.next_line().await? {
        if line.trim().is_empty() {
            continue;
        }
        let out = match serde_json::from_str::<Value>(&line) {
            Ok(request) => {
                let result = handle(&request, &mut session).await;
                reply(request.get("id").unwrap_or(&Value::Null), &result)
            }
            Err(e) => json!({ "id": null, "fault": format!("invalid request: {e}") }),
        };
        let mut bytes = serde_json::to_vec(&out).unwrap_or_default();
        bytes.push(b'\n');
        stdout.write_all(&bytes).await?;
        stdout.flush().await?;
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[utopia_async::test]
    async fn hello_lists_every_library() {
        let mut s = Session::default();
        let Ok(Outcome::Ok(v)) = handle(&json!({"op": "$hello"}), &mut s).await else { panic!() };
        assert_eq!(v["runtime"], "rust");
        assert!(v["libs"].get("validators").is_some());
    }

    #[utopia_async::test]
    async fn unknown_operations_are_faults() {
        let mut s = Session::default();
        let r = handle(&json!({"lib": "validators", "op": "nope"}), &mut s).await;
        assert!(matches!(r, Err(Fault(_))));
    }
}
