//! State snapshots: what a case left behind in a shared service, under its
//! namespace, in a comparable form. The runner takes them itself (not the
//! drivers), so both runtimes are observed the same way.
//!
//! | Kind | Captures |
//! |---|---|
//! | `redis` | every key containing the namespace: type, value, whether it expires |
//! | `files` | every file under `/tmp/compat-fs/<namespace>/` (shared with the PHP driver's container): relative path and content |
//! | `redis@<service>[/<db>[,<db>...]]` | like `redis`, on another Redis service of `compat.json` (its database, or the databases listed, merged into one snapshot); cache envelope timestamps are masked |
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
        other if other.starts_with("redis@") => {
            // Several databases are one snapshot: where a key lands may
            // depend on the namespace (sharding), what it holds may not.
            let mut merged = std::collections::BTreeMap::new();
            for url in service_urls(cfg, other)? {
                if let Value::Object(keys) = redis_snapshot(&url, ns)? {
                    merged.extend(keys);
                }
            }
            let mut snapshot = Value::Object(merged.into_iter().collect());
            mask_envelope_times(&mut snapshot);
            Ok(snapshot)
        }
        other => Err(no_kind(other)),
    }
}

/// Hides the timestamps of cache envelopes (`{"time":N,...}` in JSON, the
/// `time` integer of an igbinary array): they record when a case ran, which
/// differs between the runtimes by up to a second.
fn mask_envelope_times(value: &mut Value) {
    match value {
        Value::Object(map) if map.len() == 1 && map.contains_key("$bytes") => {
            let Some(mut raw) = map.get("$bytes").and_then(Value::as_str).and_then(|b| BASE64.decode(b).ok()) else {
                return;
            };
            if mask_igbinary_time(&mut raw) {
                map.insert("$bytes".into(), Value::String(BASE64.encode(raw)));
            }
        }
        Value::Object(map) => map.values_mut().for_each(mask_envelope_times),
        Value::String(s) if s.starts_with("{\"time\":") => {
            let digits = s[8..].bytes().take_while(|b| b.is_ascii_digit() || *b == b'-').count();
            if digits > 0 {
                s.replace_range(8..8 + digits, "\"*\"");
            }
        }
        Value::String(s) => {
            // An igbinary payload that happens to be valid UTF-8.
            let mut raw = s.as_bytes().to_vec();
            if mask_igbinary_time(&mut raw)
                && let Ok(masked) = String::from_utf8(raw)
            {
                *s = masked;
            }
        }
        _ => {}
    }
}

/// Zeroes the integer after `time` in an igbinary array; whether it did.
fn mask_igbinary_time(raw: &mut [u8]) -> bool {
    let marker = b"\x11\x04time";
    if !raw.starts_with(&[0, 0, 0, 2]) {
        return false;
    }
    let Some(at) = raw.windows(marker.len()).position(|w| w == marker) else { return false };
    let kind = at + marker.len();
    let width = match raw.get(kind) {
        Some(0x06 | 0x07) => 1,
        Some(0x08 | 0x09) => 2,
        Some(0x0a | 0x0b) => 4,
        Some(0x20 | 0x21) => 8,
        _ => return false,
    };
    for b in raw.iter_mut().skip(kind + 1).take(width) {
        *b = 0;
    }
    true
}

/// The URLs of a `redis@<service>[/<db>[,<db>...]]` kind.
fn service_urls(cfg: &Config, kind: &str) -> Result<Vec<String>, String> {
    let spec = kind.trim_start_matches("redis@");
    let Some((service, dbs)) = spec.split_once('/') else { return Ok(vec![cfg.runner_service(spec)?]) };
    let base = cfg.runner_service(service)?;
    let rest = base.strip_prefix("redis://").unwrap_or(&base);
    let host = &base[..base.len() - rest.len() + rest.find('/').unwrap_or(rest.len())];
    Ok(dbs.split(',').map(|db| format!("{host}/{db}")).collect())
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
        other if other.starts_with("redis@") => {
            for url in service_urls(cfg, other)? {
                redis_cleanup(&url, ns)?;
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
