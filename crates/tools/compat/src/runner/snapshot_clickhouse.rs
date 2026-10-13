//! The `clickhouse@<service>` snapshot: every ClickHouse database named
//! after the namespace (`<ns>` and `<ns>_*`), on a service of `compat.json`
//! (its `runner` URL, `http://user:password@host:port`), over the HTTP
//! interface. utopia-php/audit's cases use it on `audit-clickhouse`,
//! utopia-php/usage's on `usage-clickhouse`.
//!
//! ```json
//! {
//!   "<database>": {
//!     "<table>": {
//!       "engine": "MergeTree",
//!       "create": "CREATE TABLE ...",
//!       "rows": [{"<column>": <value>, ...}, ...]
//!     }
//!   }
//! }
//! ```
//!
//! Databases and tables are sorted by name, and a namespace without a
//! database is `{}`; `create` is the server's `create_table_query` (columns,
//! indexes, engine, keys, TTL and settings). Rows are every row of a table
//! (views have none), read `FINAL` from the engines that fold rows in merges
//! (so a summed rollup reads the same whether its parts merged yet or not),
//! each as the server's `FORMAT JSON` object (64-bit integers as strings).
//! They are sorted by their columns other than `id` and `ingestId` (random
//! ids, which the scrubs or `state_mask` hide), then by the whole row, so
//! the order never depends on the server's. Cleanup drops the databases.
//!
//! It speaks HTTP/1.0 over a plain TCP stream, so the runner needs no HTTP
//! client of its own.

use std::io::{Read, Write};
use std::net::TcpStream;
use std::time::Duration;

use serde_json::{Map, Value};

/// A ClickHouse server reached over HTTP.
struct Server {
    host: String,
    user: String,
    password: String,
}

impl Server {
    fn parse(url: &str) -> Result<Server, String> {
        let rest = url.strip_prefix("http://").ok_or_else(|| format!("clickhouse: `{url}` is not an http:// URL"))?;
        let rest = rest.split('/').next().unwrap_or("");
        let (auth, host) = rest.rsplit_once('@').unwrap_or(("default:", rest));
        let (user, password) = auth.split_once(':').unwrap_or((auth, ""));
        Ok(Server { host: host.to_owned(), user: user.to_owned(), password: password.to_owned() })
    }

    /// Runs `sql`, returning the response body; an error for a non-200 answer.
    fn run(&self, sql: &str) -> Result<String, String> {
        let mut stream = TcpStream::connect(&self.host).map_err(|e| format!("clickhouse {}: {e}", self.host))?;
        let _ = stream.set_read_timeout(Some(Duration::from_secs(60)));
        let request = format!(
            "POST / HTTP/1.0\r\nHost: {}\r\nX-ClickHouse-User: {}\r\nX-ClickHouse-Key: {}\r\nContent-Type: text/plain\r\nContent-Length: {}\r\n\r\n{sql}",
            self.host,
            self.user,
            self.password,
            sql.len()
        );
        stream.write_all(request.as_bytes()).map_err(|e| format!("clickhouse: {e}"))?;
        let mut response = Vec::new();
        stream.read_to_end(&mut response).map_err(|e| format!("clickhouse: {e}"))?;
        let text = String::from_utf8_lossy(&response).into_owned();
        let (head, body) = text.split_once("\r\n\r\n").ok_or("clickhouse: malformed response")?;
        let status = head.split_whitespace().nth(1).unwrap_or("");
        if status != "200" {
            return Err(format!("clickhouse: {sql}: HTTP {status}: {body}"));
        }
        Ok(body.to_owned())
    }

    fn rows(&self, sql: &str) -> Result<Vec<Map<String, Value>>, String> {
        let body = self.run(&format!("{sql} FORMAT JSON"))?;
        let json: Value = serde_json::from_str(&body).map_err(|e| format!("clickhouse: {e}: {body}"))?;
        Ok(json
            .get("data")
            .and_then(Value::as_array)
            .map(|rows| rows.iter().filter_map(|r| r.as_object().cloned()).collect())
            .unwrap_or_default())
    }
}

/// `'text'` as a ClickHouse string literal.
fn literal(text: &str) -> String {
    format!("'{}'", text.replace('\\', "\\\\").replace('\'', "\\'"))
}

/// `name` as a ClickHouse identifier.
fn identifier(name: &str) -> String {
    format!("`{}`", name.replace('`', "``"))
}

fn databases(server: &Server, ns: &str) -> Result<Vec<String>, String> {
    let rows = server.rows(&format!(
        "SELECT name FROM system.databases WHERE name = {} OR startsWith(name, {}) ORDER BY name",
        literal(ns),
        literal(&format!("{ns}_"))
    ))?;
    Ok(rows.iter().filter_map(|r| r.get("name").and_then(Value::as_str).map(str::to_owned)).collect())
}

/// A row's sort key: its columns without the random ids, then the row.
fn key(row: &Map<String, Value>) -> (String, String) {
    let mut rest = row.clone();
    rest.remove("id");
    rest.remove("ingestId");
    (Value::Object(rest).to_string(), Value::Object(row.clone()).to_string())
}

pub fn snapshot(url: &str, ns: &str) -> Result<Value, String> {
    let server = Server::parse(url)?;
    let mut out = Map::new();
    for database in databases(&server, ns)? {
        let mut tables = Map::new();
        let listed = server.rows(&format!(
            "SELECT name, engine, create_table_query FROM system.tables WHERE database = {} ORDER BY name",
            literal(&database)
        ))?;
        for table in listed {
            let name = table.get("name").and_then(Value::as_str).unwrap_or("").to_owned();
            let engine = table.get("engine").and_then(Value::as_str).unwrap_or("").to_owned();
            let mut rows = Vec::new();
            if !engine.contains("View") {
                let fold = ["Summing", "Replacing", "Collapsing", "Aggregating"].iter().any(|e| engine.contains(e));
                let mut found = server.rows(&format!(
                    "SELECT * FROM {}.{}{}",
                    identifier(&database),
                    identifier(&name),
                    if fold { " FINAL" } else { "" }
                ))?;
                found.sort_by_cached_key(key);
                rows = found.into_iter().map(Value::Object).collect();
            }
            let mut entry = Map::new();
            entry.insert("engine".into(), Value::String(engine));
            entry.insert("create".into(), table.get("create_table_query").cloned().unwrap_or(Value::Null));
            entry.insert("rows".into(), Value::Array(rows));
            tables.insert(name, Value::Object(entry));
        }
        out.insert(database, Value::Object(tables));
    }
    Ok(Value::Object(out))
}

pub fn cleanup(url: &str, ns: &str) -> Result<(), String> {
    let server = Server::parse(url)?;
    for database in databases(&server, ns)? {
        server.run(&format!("DROP DATABASE IF EXISTS {} SYNC", identifier(&database)))?;
    }
    Ok(())
}
