//! The `postgres@<service>` snapshot: everything in the PostgreSQL schema
//! named after the namespace, on a service of `compat.json` (its `runner`
//! URL). Cases that run a database in schema `${ns}` (utopia-php/database's
//! `setDatabase()`) compare what each runtime left there.
//!
//! ```json
//! {
//!   "exists": true,
//!   "tables": {
//!     "<table>": {
//!       "columns": [{"name", "type", "nullable", "default", "identity", "generated"}],
//!       "indexes": {"<name>": "CREATE INDEX ... ON \"${ns}\".\"<table>\" ..."},
//!       "constraints": {"<name>": {"type": "p", "definition": "PRIMARY KEY (_id)"}},
//!       "rows": [["<column value as text>" | null, ...], ...]
//!     }
//!   }
//! }
//! ```
//!
//! Tables, indexes and constraints are sorted by name, columns kept in their
//! table order, rows ordered by `_id` (when the table has it) and then by
//! every column's text; every value is its `::text` form. The namespace in
//! names and definitions becomes `${ns}` like everywhere else. Cleanup drops
//! the schema with everything in it.
//!
//! The runner is synchronous and may itself run inside a runtime (the
//! PHP-free replay), so each call runs on its own thread with its own
//! single-threaded runtime.

use std::future::Future;
use std::pin::Pin;

use serde_json::{Map, Value, json};
use tokio_postgres::{Client, NoTls, SimpleQueryMessage};

type Work<T> = Box<dyn for<'c> FnOnce(&'c Client) -> Pin<Box<dyn Future<Output = Result<T, String>> + 'c>> + Send>;

/// Runs `f` with a client of `url`, on a thread of its own.
fn with_client<T: Send + 'static>(url: &str, f: Work<T>) -> Result<T, String> {
    let url = url.to_owned();
    std::thread::spawn(move || {
        let rt = utopia_async::Builder::current_thread().build().map_err(|e| e.to_string())?;
        rt.block_on(async move {
            let (client, connection) =
                tokio_postgres::connect(&url, NoTls).await.map_err(|e| format!("postgres {url}: {e}"))?;
            let driver = utopia_async::spawn(connection);
            let out = f(&client).await;
            drop(client);
            let _ = driver.await;
            out
        })
    })
    .join()
    .map_err(|_| "postgres snapshot thread panicked".to_owned())?
}

fn quote(ident: &str) -> String {
    format!("\"{}\"", ident.replace('"', "\"\""))
}

fn literal(s: &str) -> String {
    format!("'{}'", s.replace('\'', "''"))
}

fn err(e: tokio_postgres::Error) -> String {
    match e.as_db_error() {
        Some(db) => format!("postgres: {}", db.message()),
        None => format!("postgres: {e}"),
    }
}

/// Rows of a simple (text protocol) query: every value as text or null.
async fn texts(client: &Client, sql: &str) -> Result<Vec<Vec<Value>>, String> {
    let mut rows = Vec::new();
    for message in client.simple_query(sql).await.map_err(err)? {
        if let SimpleQueryMessage::Row(row) = message {
            rows.push(
                (0..row.len()).map(|i| row.get(i).map_or(Value::Null, |s| Value::String(s.to_owned()))).collect(),
            );
        }
    }
    Ok(rows)
}

fn text(v: &Value) -> &str {
    v.as_str().unwrap_or_default()
}

pub fn snapshot(url: &str, ns: &str) -> Result<Value, String> {
    let ns = ns.to_owned();
    with_client(
        url,
        Box::new(move |client| {
            Box::pin(async move {
                let schema = literal(&ns);
                let exists =
                    texts(client, &format!("SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = {schema})"))
                        .await?
                        .first()
                        .and_then(|r| r.first())
                        .is_some_and(|v| v == "t");
                let mut tables = Map::new();
                if exists {
                    let mut list = texts(
                        client,
                        &format!(
                            "SELECT c.oid::text, c.relname::text FROM pg_class c \
                             JOIN pg_namespace n ON n.oid = c.relnamespace \
                             WHERE n.nspname = {schema} AND c.relkind IN ('r', 'p')"
                        ),
                    )
                    .await?;
                    list.sort_by(|a, b| text(&a[1]).cmp(text(&b[1])));
                    for t in list {
                        let name = text(&t[1]).to_owned();
                        let value = table(client, &ns, text(&t[0]), &name).await?;
                        tables.insert(name, value);
                    }
                }
                Ok(json!({ "exists": exists, "tables": tables }))
            })
        }),
    )
}

async fn table(client: &Client, ns: &str, oid: &str, name: &str) -> Result<Value, String> {
    let columns = texts(
        client,
        &format!(
            "SELECT a.attname::text, format_type(a.atttypid, a.atttypmod), (NOT a.attnotnull)::text, \
             pg_get_expr(d.adbin, d.adrelid), a.attidentity::text, a.attgenerated::text \
             FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum \
             WHERE a.attrelid = {oid} AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum"
        ),
    )
    .await?;
    let names: Vec<String> = columns.iter().map(|c| text(&c[0]).to_owned()).collect();
    let columns: Vec<Value> = columns
        .into_iter()
        .map(|c| {
            json!({
                "name": c[0],
                "type": c[1],
                "nullable": c[2] == "true",
                "default": c[3],
                "identity": c[4],
                "generated": c[5],
            })
        })
        .collect();
    let mut indexes = texts(
        client,
        &format!(
            "SELECT i.relname::text, pg_get_indexdef(i.oid) FROM pg_index x JOIN pg_class i ON i.oid = x.indexrelid \
             WHERE x.indrelid = {oid}"
        ),
    )
    .await?;
    indexes.sort_by(|a, b| text(&a[0]).cmp(text(&b[0])));
    let mut constraints = texts(
        client,
        &format!(
            "SELECT conname::text, contype::text, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = {oid}"
        ),
    )
    .await?;
    constraints.sort_by(|a, b| text(&a[0]).cmp(text(&b[0])));
    let rows = if names.is_empty() {
        Vec::new()
    } else {
        let select: Vec<String> = names.iter().map(|n| format!("{}::text", quote(n))).collect();
        let mut rows =
            texts(client, &format!("SELECT {} FROM {}.{}", select.join(", "), quote(ns), quote(name))).await?;
        // Ordered here rather than with ORDER BY, which would put every
        // column in the target list twice (PostgreSQL allows 1664 entries,
        // and a table can have 1600 columns).
        let id = names.iter().position(|n| n == "_id");
        rows.sort_by(|a, b| order_rows(a, b, id));
        rows
    };
    Ok(json!({
        "columns": columns,
        "indexes": indexes.into_iter().map(|i| (text(&i[0]).to_owned(), i[1].clone())).collect::<Map<String, Value>>(),
        "constraints": constraints
            .into_iter()
            .map(|c| (text(&c[0]).to_owned(), json!({ "type": c[1], "definition": c[2] })))
            .collect::<Map<String, Value>>(),
        "rows": rows,
    }))
}

/// The order of `ORDER BY "_id", <every column>::text COLLATE "C"`: `_id`
/// numerically (it is a `bigint`), then each column's text byte by byte,
/// `NULL` last.
fn order_rows(a: &[Value], b: &[Value], id: Option<usize>) -> std::cmp::Ordering {
    let key = |row: &[Value]| id.and_then(|i| row.get(i)).and_then(Value::as_str).and_then(|s| s.parse::<i64>().ok());
    let by_text = |x: &Value, y: &Value| match (x.as_str(), y.as_str()) {
        (Some(x), Some(y)) => x.as_bytes().cmp(y.as_bytes()),
        (Some(_), None) => std::cmp::Ordering::Less,
        (None, Some(_)) => std::cmp::Ordering::Greater,
        (None, None) => std::cmp::Ordering::Equal,
    };
    let by_id = match (key(a), key(b)) {
        (Some(x), Some(y)) => x.cmp(&y),
        (Some(_), None) => std::cmp::Ordering::Less,
        (None, Some(_)) => std::cmp::Ordering::Greater,
        (None, None) => std::cmp::Ordering::Equal,
    };
    by_id.then_with(|| {
        a.iter().zip(b).map(|(x, y)| by_text(x, y)).find(|o| o.is_ne()).unwrap_or(std::cmp::Ordering::Equal)
    })
}

/// Drops schema `ns` and the schemas `${ns}_<name>` a case's other
/// databases live in (the database recorder isolates them so).
pub fn cleanup(url: &str, ns: &str) -> Result<(), String> {
    let sql = format!(
        "DROP SCHEMA IF EXISTS {} CASCADE; \
         DO $cleanup$ DECLARE s text; BEGIN \
         FOR s IN SELECT nspname FROM pg_namespace WHERE starts_with(nspname, {}) LOOP \
         EXECUTE format('DROP SCHEMA IF EXISTS %I CASCADE', s); \
         END LOOP; END $cleanup$",
        quote(ns),
        literal(&format!("{ns}_"))
    );
    with_client(url, Box::new(move |client| Box::pin(async move { client.batch_execute(&sql).await.map_err(err) })))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn quotes_identifiers_and_literals() {
        assert_eq!(quote("a\"b"), "\"a\"\"b\"");
        assert_eq!(literal("o'k"), "'o''k'");
    }

    /// Against the database cases' PostgreSQL (`tests/compat/database/services.sh up`),
    /// only with COMPAT_SERVICES=1.
    #[test]
    fn snapshots_a_schema() {
        if std::env::var("COMPAT_SERVICES").map_or(true, |v| v != "1") {
            return;
        }
        let url = "postgres://root:password@localhost:16432/root";
        let ns = format!("cxsnap{}", std::process::id());
        cleanup(url, &ns).unwrap();
        assert_eq!(snapshot(url, &ns).unwrap(), json!({ "exists": false, "tables": {} }));
        let setup = format!(
            "CREATE SCHEMA {s}; CREATE TABLE {s}.t (_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, \
             name VARCHAR(10) DEFAULT 'x', n NUMERIC(5, 2)); CREATE INDEX t_name ON {s}.t (name); \
             INSERT INTO {s}.t (name, n) VALUES ('b', 1.5), (NULL, NULL);",
            s = quote(&ns)
        );
        with_client(url, Box::new(move |c| Box::pin(async move { c.batch_execute(&setup).await.map_err(err) })))
            .unwrap();
        let snap = snapshot(url, &ns).unwrap();
        let t = &snap["tables"]["t"];
        assert_eq!(snap["exists"], true);
        assert_eq!(
            t["columns"][1],
            json!({"name": "name", "type": "character varying(10)", "nullable": true,
                   "default": "'x'::character varying", "identity": "", "generated": ""})
        );
        assert_eq!(t["columns"][0]["identity"], "a");
        assert_eq!(t["indexes"]["t_name"], format!("CREATE INDEX t_name ON {ns}.t USING btree (name)"));
        assert_eq!(t["constraints"]["t_pkey"]["type"], "p");
        assert_eq!(t["rows"], json!([["1", "b", "1.50"], ["2", null, null]]));
        cleanup(url, &ns).unwrap();
        assert_eq!(snapshot(url, &ns).unwrap()["exists"], false);
    }
}
