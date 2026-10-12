//! The `mysql@<service>` snapshot: everything in the MariaDB/MySQL database
//! named after the namespace, on a service of `compat.json` (its `runner`
//! URL). Cases that run a database named `${ns}` (utopia-php/database's
//! `setDatabase()`) compare what each runtime left there.
//!
//! ```json
//! {
//!   "exists": true,
//!   "tables": {
//!     "<table>": {
//!       "columns": [{"name", "type", "nullable", "default", "extra", "collation"}],
//!       "indexes": {"<name>": {"unique": true, "type": "BTREE", "columns": [["<column or expression>", <sub part> | null, "A" | "D" | null]]}},
//!       "rows": [["<column value as text>" | null, ...], ...]
//!     }
//!   }
//! }
//! ```
//!
//! Tables and indexes are sorted by name, columns kept in their table order
//! (`ORDINAL_POSITION`), index parts in theirs (`SEQ_IN_INDEX`); a functional
//! index part (MySQL 8's `EXPRESSION`) is its expression. Rows are ordered by
//! `_id` (when the table has it) and then by every column's text; every value
//! is the text the server sends, spatial and binary columns as `HEX()`. The
//! namespace in names becomes `${ns}` like everywhere else. Cleanup drops the
//! database and the `${ns}_<name>` databases next to it.
//!
//! The runner is synchronous and may itself run inside a Tokio runtime (the
//! PHP-free replay), so each call runs on its own thread with its own
//! single-threaded runtime.

use std::future::Future;
use std::pin::Pin;

use mysql_async::prelude::Queryable;
use mysql_async::{Conn, Opts, OptsBuilder, Row};
use serde_json::{Map, Value, json};

type Work<T> = Box<dyn for<'c> FnOnce(&'c mut Conn) -> Pin<Box<dyn Future<Output = Result<T, String>> + 'c>> + Send>;

/// Data types whose values are selected as `HEX()`: their bytes are not text.
const BINARY: &[&str] = &[
    "geometry",
    "point",
    "linestring",
    "polygon",
    "multipoint",
    "multilinestring",
    "multipolygon",
    "geometrycollection",
    "blob",
    "tinyblob",
    "mediumblob",
    "longblob",
    "binary",
    "varbinary",
];

/// Runs `f` with a connection to `url`, on a thread of its own.
fn with_conn<T: Send + 'static>(url: &str, f: Work<T>) -> Result<T, String> {
    let url = url.to_owned();
    std::thread::spawn(move || {
        let rt = tokio::runtime::Builder::new_current_thread().enable_all().build().map_err(|e| e.to_string())?;
        rt.block_on(async move {
            let opts = Opts::from_url(&url).map_err(|e| format!("mysql {url}: {e}"))?;
            let mut conn = Conn::new(OptsBuilder::from_opts(opts).prefer_socket(false))
                .await
                .map_err(|e| format!("mysql {url}: {e}"))?;
            let out = f(&mut conn).await;
            let _ = conn.disconnect().await;
            out
        })
    })
    .join()
    .map_err(|_| "mysql snapshot thread panicked".to_owned())?
}

fn quote(ident: &str) -> String {
    format!("`{}`", ident.replace('`', "``"))
}

fn literal(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "''"))
}

fn err(e: mysql_async::Error) -> String {
    format!("mysql: {e}")
}

/// Rows of a text protocol query: every value as text or null.
async fn texts(conn: &mut Conn, sql: &str) -> Result<Vec<Vec<Value>>, String> {
    let rows: Vec<Row> = conn.query(sql).await.map_err(err)?;
    Ok(rows
        .into_iter()
        .map(|row| {
            row.unwrap()
                .into_iter()
                .map(|v| match v {
                    mysql_async::Value::NULL => Value::Null,
                    mysql_async::Value::Bytes(b) => Value::String(String::from_utf8_lossy(&b).into_owned()),
                    other => Value::String(other.as_sql(true)),
                })
                .collect()
        })
        .collect())
}

fn text(v: &Value) -> &str {
    v.as_str().unwrap_or_default()
}

pub fn snapshot(url: &str, ns: &str) -> Result<Value, String> {
    let ns = ns.to_owned();
    with_conn(
        url,
        Box::new(move |conn| {
            Box::pin(async move {
                let schema = literal(&ns);
                let exists =
                    !texts(conn, &format!("SELECT 1 FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = {schema}"))
                        .await?
                        .is_empty();
                let mut tables = Map::new();
                if exists {
                    let expression = !texts(conn, "SHOW COLUMNS FROM INFORMATION_SCHEMA.STATISTICS LIKE 'EXPRESSION'")
                        .await?
                        .is_empty();
                    let mut list = texts(
                        conn,
                        &format!(
                            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES \
                             WHERE TABLE_SCHEMA = {schema} AND TABLE_TYPE = 'BASE TABLE'"
                        ),
                    )
                    .await?;
                    list.sort_by(|a, b| text(&a[0]).as_bytes().cmp(text(&b[0]).as_bytes()));
                    for t in list {
                        let name = text(&t[0]).to_owned();
                        let value = table(conn, &ns, &name, expression).await?;
                        tables.insert(name, value);
                    }
                }
                Ok(json!({ "exists": exists, "tables": tables }))
            })
        }),
    )
}

async fn table(conn: &mut Conn, ns: &str, name: &str, expression: bool) -> Result<Value, String> {
    let (schema, table) = (literal(ns), literal(name));
    let columns = texts(
        conn,
        &format!(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME, DATA_TYPE \
             FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = {schema} AND TABLE_NAME = {table} \
             ORDER BY ORDINAL_POSITION"
        ),
    )
    .await?;
    let select: Vec<String> = columns
        .iter()
        .map(|c| {
            let column = quote(text(&c[0]));
            if BINARY.contains(&text(&c[6]).to_ascii_lowercase().as_str()) { format!("HEX({column})") } else { column }
        })
        .collect();
    let names: Vec<String> = columns.iter().map(|c| text(&c[0]).to_owned()).collect();
    let columns: Vec<Value> = columns
        .into_iter()
        .map(|c| {
            json!({
                "name": c[0],
                "type": c[1],
                "nullable": c[2] == "YES",
                "default": c[3],
                "extra": c[4],
                "collation": c[5],
            })
        })
        .collect();
    let parts = texts(
        conn,
        &format!(
            "SELECT INDEX_NAME, NON_UNIQUE, INDEX_TYPE, COLUMN_NAME, SUB_PART, COLLATION, {} \
             FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = {schema} AND TABLE_NAME = {table} \
             ORDER BY INDEX_NAME, SEQ_IN_INDEX",
            if expression { "EXPRESSION" } else { "NULL" }
        ),
    )
    .await?;
    let mut indexes: std::collections::BTreeMap<String, Value> = std::collections::BTreeMap::new();
    for p in parts {
        let index = indexes
            .entry(text(&p[0]).to_owned())
            .or_insert_with(|| json!({ "unique": text(&p[1]) == "0", "type": p[2], "columns": [] }));
        let column = if p[3].is_null() { p[6].clone() } else { p[3].clone() };
        let sub = p[4].as_str().and_then(|s| s.parse::<u64>().ok()).map_or(Value::Null, Value::from);
        if let Some(list) = index["columns"].as_array_mut() {
            list.push(json!([column, sub, p[5]]));
        }
    }
    let rows = if names.is_empty() {
        Vec::new()
    } else {
        let mut rows = texts(conn, &format!("SELECT {} FROM {}.{}", select.join(", "), quote(ns), quote(name))).await?;
        let id = names.iter().position(|n| n == "_id");
        rows.sort_by(|a, b| order_rows(a, b, id));
        rows
    };
    Ok(json!({
        "columns": columns,
        "indexes": indexes.into_iter().collect::<Map<String, Value>>(),
        "rows": rows,
    }))
}

/// `_id` numerically (it is a `BIGINT UNSIGNED`), then each column's text
/// byte by byte, `NULL` last.
fn order_rows(a: &[Value], b: &[Value], id: Option<usize>) -> std::cmp::Ordering {
    let key = |row: &[Value]| id.and_then(|i| row.get(i)).and_then(Value::as_str).and_then(|s| s.parse::<i128>().ok());
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

/// Drops database `ns` and the databases `${ns}_<name>` a case's other
/// databases live in (the database recorder isolates them so).
pub fn cleanup(url: &str, ns: &str) -> Result<(), String> {
    let ns = ns.to_owned();
    with_conn(
        url,
        Box::new(move |conn| {
            Box::pin(async move {
                let prefix = format!("{ns}_");
                let mut drop = vec![ns.clone()];
                for s in texts(conn, "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA").await? {
                    if text(&s[0]).starts_with(&prefix) {
                        drop.push(text(&s[0]).to_owned());
                    }
                }
                for db in drop {
                    conn.query_drop(format!("DROP DATABASE IF EXISTS {}", quote(&db))).await.map_err(err)?;
                }
                Ok(())
            })
        }),
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn quotes_identifiers_and_literals() {
        assert_eq!(quote("a`b"), "`a``b`");
        assert_eq!(literal("o'k\\"), "'o''k\\\\'");
    }

    #[test]
    fn orders_rows_by_id_then_text() {
        let mut rows = vec![
            vec![json!("10"), json!("a")],
            vec![json!("9"), Value::Null],
            vec![json!("9"), json!("b")],
            vec![json!("18446744073709551615"), json!("z")],
        ];
        rows.sort_by(|a, b| order_rows(a, b, Some(0)));
        assert_eq!(
            rows,
            vec![
                vec![json!("9"), json!("b")],
                vec![json!("9"), Value::Null],
                vec![json!("10"), json!("a")],
                vec![json!("18446744073709551615"), json!("z")],
            ]
        );
    }

    /// Against the database cases' servers (`tests/compat/database/services.sh up`),
    /// only with COMPAT_SERVICES=1.
    fn snapshots_a_database(url: &str, tag: &str) {
        if std::env::var("COMPAT_SERVICES").map_or(true, |v| v != "1") {
            return;
        }
        let ns = format!("cxsnap{tag}{}", std::process::id());
        cleanup(url, &ns).unwrap();
        assert_eq!(snapshot(url, &ns).unwrap(), json!({ "exists": false, "tables": {} }));
        let s = quote(&ns);
        let other = quote(&format!("{ns}_other"));
        let mut setup = vec![
            format!("CREATE DATABASE {s}"),
            format!("CREATE DATABASE {other}"),
            format!(
                "CREATE TABLE {s}.t (_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, \
                 name VARCHAR(10) DEFAULT 'x', n DOUBLE, p POINT NULL, \
                 UNIQUE KEY t_n (n), KEY t_name (name(4) DESC))"
            ),
            format!(
                "INSERT INTO {s}.t (name, n, p) VALUES ('b', 1.5, ST_GeomFromText('POINT(1 2)')), (NULL, NULL, NULL)"
            ),
        ];
        if tag == "mysql" {
            // A functional index part has no column, only an expression.
            setup.push(format!("CREATE INDEX t_f ON {s}.t ((n * 2))"));
        }
        with_conn(
            url,
            Box::new(move |c| {
                Box::pin(async move {
                    for q in setup {
                        c.query_drop(q).await.map_err(err)?;
                    }
                    Ok(())
                })
            }),
        )
        .unwrap();
        let snap = snapshot(url, &ns).unwrap();
        println!("{}", serde_json::to_string_pretty(&snap).unwrap());
        let t = &snap["tables"]["t"];
        assert_eq!(snap["exists"], true);
        assert!(t["columns"][0]["type"].as_str().is_some_and(|t| t.starts_with("bigint") && t.ends_with("unsigned")));
        assert_eq!(t["columns"][0]["extra"], "auto_increment");
        assert_eq!(t["columns"][1]["nullable"], true);
        assert_eq!(t["columns"][1]["type"], "varchar(10)");
        assert_eq!(t["indexes"]["PRIMARY"]["unique"], true);
        assert_eq!(t["indexes"]["PRIMARY"]["columns"], json!([["_id", null, "A"]]));
        assert_eq!(t["indexes"]["t_n"]["unique"], true);
        assert_eq!(t["indexes"]["t_name"]["unique"], false);
        assert_eq!(t["indexes"]["t_name"]["columns"][0][0], "name");
        assert_eq!(t["indexes"]["t_name"]["columns"][0][1], 4);
        assert_eq!(t["rows"][0][0], "1");
        assert_eq!(t["rows"][0][1], "b");
        assert_eq!(t["rows"][0][2], "1.5");
        assert!(t["rows"][0][3].as_str().is_some_and(|h| h.chars().all(|c| c.is_ascii_hexdigit())));
        assert_eq!(t["rows"][1], json!(["2", null, null, null]));
        if tag == "mysql" {
            assert_eq!(t["indexes"]["t_f"]["columns"][0][0], "(`n` * 2)");
        }
        cleanup(url, &ns).unwrap();
        assert_eq!(snapshot(url, &ns).unwrap()["exists"], false);
        let ns2 = format!("{ns}_other");
        assert_eq!(snapshot(url, &ns2).unwrap()["exists"], false);
    }

    #[test]
    fn snapshots_a_mariadb_database() {
        snapshots_a_database("mysql://root:password@localhost:16306", "mariadb");
    }

    #[test]
    fn snapshots_a_mysql_database() {
        snapshots_a_database("mysql://root:password@localhost:16307", "mysql");
    }
}
