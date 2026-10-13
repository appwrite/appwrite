//! The `clickhouse@<service>` snapshot: everything in the ClickHouse
//! database named after the namespace, on a service of `compat.json` (its
//! `runner` URL, `http://<user>:<password>@<host>:<port>`). Cases that point
//! utopia-php/audit's ClickHouse adapter at database `${ns}` compare what
//! each runtime left there.
//!
//! ```json
//! {
//!   "exists": true,
//!   "tables": {
//!     "<table>": {
//!       "create": "CREATE TABLE ${ns}.<table> (...) ENGINE = MergeTree ...",
//!       "rows": [{"<column>": <value>, ...}, ...]
//!     }
//!   }
//! }
//! ```
//!
//! `create` is the server's `create_table_query` (columns, indexes, engine,
//! keys, TTL and settings). Rows are `JSONEachRow` objects (64-bit integers
//! as strings), sorted by their text without the `id` column, then by `id`:
//! audit row ids are random, while every other column is the same on both
//! runtimes once the scrubs ran. Cleanup drops the database.
//!
//! It speaks HTTP/1.0 over a plain TCP stream, so the runner needs no HTTP
//! client of its own.

use std::io::{Read, Write};
use std::net::TcpStream;
use std::time::Duration;

use serde_json::{Map, Value, json};

/// The host, port, user and password of a `http://user:password@host:port` URL.
struct Endpoint {
    address: String,
    user: String,
    password: String,
}

fn endpoint(url: &str) -> Result<Endpoint, String> {
    let rest = url.strip_prefix("http://").ok_or_else(|| format!("clickhouse: `{url}` is not an http:// URL"))?;
    let rest = rest.trim_end_matches('/');
    let (credentials, address) = match rest.rsplit_once('@') {
        Some((credentials, address)) => (credentials, address),
        None => ("default:", rest),
    };
    let (user, password) = credentials.split_once(':').unwrap_or((credentials, ""));
    Ok(Endpoint { address: address.to_owned(), user: user.to_owned(), password: password.to_owned() })
}

/// Runs `sql` and returns the response body; an error for a non-200 answer.
fn query(endpoint: &Endpoint, sql: &str) -> Result<String, String> {
    let mut stream =
        TcpStream::connect(&endpoint.address).map_err(|e| format!("clickhouse {}: {e}", endpoint.address))?;
    let _ = stream.set_read_timeout(Some(Duration::from_secs(60)));
    let request = format!(
        "POST / HTTP/1.0\r\nHost: {}\r\nX-ClickHouse-User: {}\r\nX-ClickHouse-Key: {}\r\nContent-Type: text/plain\r\nContent-Length: {}\r\n\r\n{sql}",
        endpoint.address,
        endpoint.user,
        endpoint.password,
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

/// `'text'` as a ClickHouse string literal.
fn literal(text: &str) -> String {
    format!("'{}'", text.replace('\\', "\\\\").replace('\'', "\\'"))
}

/// `name` as a ClickHouse identifier.
fn identifier(name: &str) -> String {
    format!("`{}`", name.replace('`', "``"))
}

fn rows(body: &str) -> Result<Vec<Value>, String> {
    body.lines()
        .filter(|l| !l.trim().is_empty())
        .map(|l| serde_json::from_str(l).map_err(|e| format!("clickhouse: {e}: {l}")))
        .collect()
}

pub fn snapshot(url: &str, ns: &str) -> Result<Value, String> {
    let endpoint = endpoint(url)?;
    let exists =
        query(&endpoint, &format!("SELECT count() FROM system.databases WHERE name = {} FORMAT TSV", literal(ns)))?;
    if exists.trim() == "0" {
        return Ok(json!({ "exists": false, "tables": {} }));
    }
    let listed = query(
        &endpoint,
        &format!(
            "SELECT name, create_table_query FROM system.tables WHERE database = {} ORDER BY name FORMAT JSONEachRow",
            literal(ns)
        ),
    )?;
    let mut tables = Map::new();
    for table in rows(&listed)? {
        let name = table.get("name").and_then(Value::as_str).unwrap_or_default().to_owned();
        let create = table.get("create_table_query").cloned().unwrap_or(Value::Null);
        let body =
            query(&endpoint, &format!("SELECT * FROM {}.{} FORMAT JSONEachRow", identifier(ns), identifier(&name)))?;
        let mut keyed: Vec<(String, String, Value)> = rows(&body)?
            .into_iter()
            .map(|row| {
                let id = row.get("id").map(Value::to_string).unwrap_or_default();
                let mut rest = row.clone();
                if let Value::Object(o) = &mut rest {
                    o.remove("id");
                }
                (rest.to_string(), id, row)
            })
            .collect();
        keyed.sort_by(|a, b| (&a.0, &a.1).cmp(&(&b.0, &b.1)));
        let rows: Vec<Value> = keyed.into_iter().map(|(_, _, row)| row).collect();
        tables.insert(name, json!({ "create": create, "rows": rows }));
    }
    Ok(json!({ "exists": true, "tables": tables }))
}

pub fn cleanup(url: &str, ns: &str) -> Result<(), String> {
    let endpoint = endpoint(url)?;
    query(&endpoint, &format!("DROP DATABASE IF EXISTS {} SYNC", identifier(ns))).map(|_| ())
}
