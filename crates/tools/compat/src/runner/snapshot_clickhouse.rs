//! The `clickhouse@<service>` snapshot: every ClickHouse database named
//! after the namespace (`<ns>` and `<ns>_*`), on a service of `compat.json`
//! (its `runner` URL, `http://user:password@host:port`), over the HTTP
//! interface.
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
//! Databases and tables are sorted by name; `create` is the server's
//! `create_table_query`. Rows are every row of a table (views have none),
//! read `FINAL` from the engines that fold rows in merges (so a summed
//! rollup reads the same whether its parts merged yet or not), each as the
//! server's `FORMAT JSON` object, and sorted by their columns other than
//! `id` and `ingestId` (random ids, which `state_mask` hides). Cleanup drops
//! the databases.

use serde_json::{Map, Value};

/// A ClickHouse server reached over HTTP.
struct Server {
    host: String,
    user: String,
    password: String,
}

impl Server {
    fn parse(url: &str) -> Result<Server, String> {
        let rest = url.strip_prefix("http://").ok_or_else(|| format!("not an http:// URL: {url}"))?;
        let rest = rest.split('/').next().unwrap_or("");
        let (auth, host) = rest.rsplit_once('@').unwrap_or(("", rest));
        let (user, password) = auth.split_once(':').unwrap_or((auth, ""));
        Ok(Server { host: host.to_owned(), user: user.to_owned(), password: password.to_owned() })
    }

    /// Runs `sql`, returning the response body.
    fn run(&self, sql: &str) -> Result<String, String> {
        use std::io::{Read, Write};
        let mut stream =
            std::net::TcpStream::connect(&self.host).map_err(|e| format!("clickhouse {}: {e}", self.host))?;
        let request = format!(
            "POST / HTTP/1.0\r\nHost: {}\r\nX-ClickHouse-User: {}\r\nX-ClickHouse-Key: {}\r\nContent-Type: text/plain\r\nContent-Length: {}\r\n\r\n{sql}",
            self.host,
            self.user,
            self.password,
            sql.len()
        );
        stream.write_all(request.as_bytes()).map_err(|e| e.to_string())?;
        let mut response = Vec::new();
        stream.read_to_end(&mut response).map_err(|e| e.to_string())?;
        let text = String::from_utf8_lossy(&response).into_owned();
        let (head, body) = text.split_once("\r\n\r\n").unwrap_or((&text, ""));
        let status = head.split_whitespace().nth(1).unwrap_or("");
        if status != "200" {
            return Err(format!("clickhouse: {status}: {body}"));
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

fn quote(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "\\'"))
}

fn databases(server: &Server, ns: &str) -> Result<Vec<String>, String> {
    let rows = server.rows(&format!(
        "SELECT name FROM system.databases WHERE name = {} OR startsWith(name, {}) ORDER BY name",
        quote(ns),
        quote(&format!("{ns}_"))
    ))?;
    Ok(rows.iter().filter_map(|r| r.get("name").and_then(Value::as_str).map(str::to_owned)).collect())
}

pub fn snapshot(url: &str, ns: &str) -> Result<Value, String> {
    let server = Server::parse(url)?;
    let mut out = Map::new();
    for database in databases(&server, ns)? {
        let mut tables = Map::new();
        let listed = server.rows(&format!(
            "SELECT name, engine, create_table_query FROM system.tables WHERE database = {} ORDER BY name",
            quote(&database)
        ))?;
        for table in listed {
            let name = table.get("name").and_then(Value::as_str).unwrap_or("").to_owned();
            let engine = table.get("engine").and_then(Value::as_str).unwrap_or("").to_owned();
            let mut rows = Vec::new();
            if !engine.contains("View") {
                let fold = ["Summing", "Replacing", "Collapsing", "Aggregating"].iter().any(|e| engine.contains(e));
                let mut found = server.rows(&format!(
                    "SELECT * FROM `{}`.`{}`{}",
                    database.replace('`', "``"),
                    name.replace('`', "``"),
                    if fold { " FINAL" } else { "" }
                ))?;
                found.sort_by_cached_key(|row| {
                    let mut key = row.clone();
                    key.remove("id");
                    key.remove("ingestId");
                    Value::Object(key).to_string()
                });
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
        server.run(&format!("DROP DATABASE IF EXISTS `{}` SYNC", database.replace('`', "``")))?;
    }
    Ok(())
}
