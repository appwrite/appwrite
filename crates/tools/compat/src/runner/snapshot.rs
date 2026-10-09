//! State snapshots: what a case left behind in a shared service, under its
//! namespace, in a comparable form. The runner takes them itself (not the
//! drivers), so both runtimes are observed the same way.
//!
//! | Kind | Captures |
//! |---|---|
//! | `redis` | every key containing the namespace: type, value, whether it expires |
//! | `files` | every file under `/tmp/compat-fs/<namespace>/` (shared with the PHP driver's container): relative path and content |
//!
//! Add a kind by adding a branch to [`snapshot`] and [`cleanup`]. A kind must
//! only touch resources whose names contain the namespace.

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use serde_json::{Map, Value, json};

use super::config::Config;

pub fn snapshot(cfg: &Config, kind: &str, ns: &str) -> Result<Value, String> {
    match kind {
        "redis" => redis_snapshot(&cfg.runner_service("redis")?, ns),
        "files" => files_snapshot(ns),
        other => Err(no_kind(other)),
    }
}

pub fn cleanup(cfg: &Config, kind: &str, ns: &str) -> Result<(), String> {
    match kind {
        "redis" => redis_cleanup(&cfg.runner_service("redis")?, ns),
        "files" => {
            let dir = files_root().join(ns);
            if dir.exists() {
                std::fs::remove_dir_all(&dir).map_err(|e| format!("{}: {e}", dir.display()))?;
            }
            Ok(())
        }
        other => Err(no_kind(other)),
    }
}

fn no_kind(kind: &str) -> String {
    format!("no `{kind}` snapshot; add one in crates/tools/compat/src/runner/snapshot.rs")
}

fn redis_conn(url: &str) -> Result<redis::Connection, String> {
    redis::Client::open(url).and_then(|c| c.get_connection()).map_err(|e| format!("redis {url}: {e}"))
}

fn redis_keys(conn: &mut redis::Connection, ns: &str) -> Result<Vec<Vec<u8>>, String> {
    let mut keys: Vec<Vec<u8>> = redis::cmd("KEYS").arg(format!("*{ns}*")).query(conn).map_err(|e| e.to_string())?;
    keys.sort();
    Ok(keys)
}

fn bytes(b: &[u8]) -> Value {
    match std::str::from_utf8(b) {
        Ok(s) => Value::String(s.to_owned()),
        Err(_) => json!({ "$bytes": BASE64.encode(b) }),
    }
}

fn redis_snapshot(url: &str, ns: &str) -> Result<Value, String> {
    let mut conn = redis_conn(url)?;
    let mut out = Map::new();
    for key in redis_keys(&mut conn, ns)? {
        let q = |conn: &mut redis::Connection, cmd: &str, extra: &[&str]| -> Result<redis::Value, String> {
            let mut c = redis::cmd(cmd);
            c.arg(&key);
            for e in extra {
                c.arg(*e);
            }
            c.query(conn).map_err(|e| e.to_string())
        };
        let kind: String = redis::cmd("TYPE").arg(&key).query(&mut conn).map_err(|e| e.to_string())?;
        let value = match kind.as_str() {
            "string" => match q(&mut conn, "GET", &[])? {
                redis::Value::BulkString(b) => bytes(&b),
                other => Value::String(format!("{other:?}")),
            },
            "hash" => {
                let mut pairs = flat(q(&mut conn, "HGETALL", &[])?);
                let mut fields: Vec<(Vec<u8>, Vec<u8>)> = Vec::new();
                while pairs.len() >= 2 {
                    let v = pairs.pop().unwrap_or_default();
                    let k = pairs.pop().unwrap_or_default();
                    fields.push((k, v));
                }
                fields.sort();
                let mut m = Map::new();
                for (k, v) in fields {
                    m.insert(String::from_utf8_lossy(&k).into_owned(), bytes(&v));
                }
                Value::Object(m)
            }
            "list" => Value::Array(flat(q(&mut conn, "LRANGE", &["0", "-1"])?).iter().map(|b| bytes(b)).collect()),
            "set" => {
                let mut items = flat(q(&mut conn, "SMEMBERS", &[])?);
                items.sort();
                Value::Array(items.iter().map(|b| bytes(b)).collect())
            }
            "zset" => Value::Array(
                flat(q(&mut conn, "ZRANGE", &["0", "-1", "WITHSCORES"])?).iter().map(|b| bytes(b)).collect(),
            ),
            other => Value::String(format!("<{other}>")),
        };
        let pttl: i64 = redis::cmd("PTTL").arg(&key).query(&mut conn).map_err(|e| e.to_string())?;
        out.insert(
            String::from_utf8_lossy(&key).into_owned(),
            json!({ "type": kind, "expires": pttl >= 0, "value": value }),
        );
    }
    Ok(Value::Object(out))
}

fn flat(v: redis::Value) -> Vec<Vec<u8>> {
    match v {
        redis::Value::Array(items) | redis::Value::Set(items) => items.into_iter().flat_map(flat).collect(),
        redis::Value::Map(pairs) => pairs.into_iter().flat_map(|(k, v)| flat(k).into_iter().chain(flat(v))).collect(),
        redis::Value::BulkString(b) => vec![b],
        redis::Value::SimpleString(s) => vec![s.into_bytes()],
        redis::Value::Int(i) => vec![i.to_string().into_bytes()],
        redis::Value::Double(d) => vec![d.to_string().into_bytes()],
        _ => Vec::new(),
    }
}

fn redis_cleanup(url: &str, ns: &str) -> Result<(), String> {
    let mut conn = redis_conn(url)?;
    let keys = redis_keys(&mut conn, ns)?;
    if !keys.is_empty() {
        let _: i64 = redis::cmd("DEL").arg(keys).query(&mut conn).map_err(|e| e.to_string())?;
    }
    Ok(())
}

/// Where file-writing cases put their files: `/tmp/compat-fs/${ns}/...`.
pub fn files_root() -> std::path::PathBuf {
    std::path::PathBuf::from("/tmp/compat-fs")
}

fn files_snapshot(ns: &str) -> Result<Value, String> {
    fn walk(dir: &std::path::Path, base: &std::path::Path, out: &mut Vec<(String, Value)>) -> Result<(), String> {
        let Ok(entries) = std::fs::read_dir(dir) else { return Ok(()) };
        for e in entries.flatten() {
            let p = e.path();
            let rel = p.strip_prefix(base).map_err(|e| e.to_string())?.display().to_string();
            if p.is_dir() {
                out.push((format!("{rel}/"), Value::Null));
                walk(&p, base, out)?;
            } else {
                let content = std::fs::read(&p).map_err(|e| format!("{}: {e}", p.display()))?;
                out.push((rel, bytes(&content)));
            }
        }
        Ok(())
    }
    let base = files_root().join(ns);
    let mut entries = Vec::new();
    walk(&base, &base, &mut entries)?;
    entries.sort_by(|a, b| a.0.cmp(&b.0));
    Ok(Value::Object(entries.into_iter().collect()))
}
