//! The `sqlite` snapshot: every SQLite database file under
//! `/tmp/compat-fs/<namespace>/` (where utopia-php/database's SQLite cases
//! keep their database), read through SQLite itself, so two files with the
//! same schema and rows compare equal whatever their page layout.
//!
//! ```json
//! {
//!   "<file>": {
//!     "schema": [{"type": "table", "name": "...", "table": "...", "sql": "CREATE TABLE ..."}],
//!     "tables": {
//!       "<table>": {
//!         "columns": [{"name", "type", "notnull", "default", "pk"}],
//!         "rows": [[1, "text", {"$real": "1.5"}, {"$blob": "<base64>"}, null], ...]
//!       }
//!     }
//!   }
//! }
//! ```
//!
//! `schema` is `sqlite_master` (tables, indexes, triggers, views) sorted by
//! type and name, with each object's `CREATE` statement as stored. Rows keep
//! SQLite's storage class: integers as numbers, reals as `{"$real": text}`,
//! blobs as `{"$blob": base64}`, text as strings (`{"$bytes": base64}` when
//! not UTF-8); they are sorted, so the order rows were stored in does not
//! matter. Virtual tables (FTS5) are not read themselves: their shadow
//! tables are. Cleanup removes the directory, like `files`.

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use rusqlite::types::ValueRef;
use rusqlite::{Connection, OpenFlags};
use serde_json::{Map, Value, json};

/// The snapshot of the namespace's database files.
pub fn snapshot(ns: &str) -> Result<Value, String> {
    let dir = super::snapshot::files_root().join(ns);
    let mut files: Vec<std::path::PathBuf> = match std::fs::read_dir(&dir) {
        Ok(entries) => entries.flatten().map(|e| e.path()).filter(|p| is_database(p)).collect(),
        Err(_) => Vec::new(),
    };
    files.sort();
    let mut out = Map::new();
    for path in files {
        let name = path.file_name().map(|n| n.to_string_lossy().into_owned()).unwrap_or_default();
        out.insert(name, file(&path)?);
    }
    Ok(Value::Object(out))
}

/// Whether a file starts with SQLite's header.
fn is_database(path: &std::path::Path) -> bool {
    use std::io::Read as _;
    let mut header = [0u8; 16];
    std::fs::File::open(path).and_then(|mut f| f.read_exact(&mut header)).is_ok() && &header == b"SQLite format 3\0"
}

fn file(path: &std::path::Path) -> Result<Value, String> {
    let conn = Connection::open_with_flags(path, OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_NO_MUTEX)
        .map_err(|e| format!("{}: {e}", path.display()))?;
    let err = |e: rusqlite::Error| format!("{}: {e}", path.display());
    let mut schema = Vec::new();
    let mut tables = Vec::new();
    {
        let mut stmt =
            conn.prepare("SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name").map_err(err)?;
        let mut rows = stmt.query([]).map_err(err)?;
        while let Some(row) = rows.next().map_err(err)? {
            let kind: String = row.get(0).map_err(err)?;
            let name: String = row.get(1).map_err(err)?;
            let table: String = row.get(2).map_err(err)?;
            let sql: Option<String> = row.get(3).map_err(err)?;
            let is_virtual = sql.as_deref().is_some_and(|s| s.starts_with("CREATE VIRTUAL TABLE"));
            if kind == "table" && !is_virtual {
                tables.push(name.clone());
            }
            schema.push(json!({"type": kind, "name": name, "table": table, "sql": sql}));
        }
    }
    let mut out = Map::new();
    for table in tables {
        let quoted = format!("\"{}\"", table.replace('"', "\"\""));
        let mut columns = Vec::new();
        {
            let mut stmt = conn.prepare(&format!("PRAGMA table_info({quoted})")).map_err(err)?;
            let mut rows = stmt.query([]).map_err(err)?;
            while let Some(row) = rows.next().map_err(err)? {
                columns.push(json!({
                    "name": value(row.get_ref(1).map_err(err)?),
                    "type": value(row.get_ref(2).map_err(err)?),
                    "notnull": value(row.get_ref(3).map_err(err)?),
                    "default": value(row.get_ref(4).map_err(err)?),
                    "pk": value(row.get_ref(5).map_err(err)?),
                }));
            }
        }
        let mut rows_out: Vec<Value> = Vec::new();
        {
            let mut stmt = conn.prepare(&format!("SELECT * FROM {quoted}")).map_err(err)?;
            let count = stmt.column_count();
            let mut rows = stmt.query([]).map_err(err)?;
            while let Some(row) = rows.next().map_err(err)? {
                let mut values = Vec::with_capacity(count);
                for i in 0..count {
                    values.push(value(row.get_ref(i).map_err(err)?));
                }
                rows_out.push(Value::Array(values));
            }
        }
        rows_out.sort_by_key(|v| v.to_string());
        out.insert(table, json!({"columns": columns, "rows": rows_out}));
    }
    Ok(json!({"schema": schema, "tables": out}))
}

fn value(v: ValueRef<'_>) -> Value {
    match v {
        ValueRef::Null => Value::Null,
        ValueRef::Integer(i) => json!(i),
        ValueRef::Real(f) => json!({"$real": format!("{f:?}")}),
        ValueRef::Text(t) => match std::str::from_utf8(t) {
            Ok(s) => Value::String(s.to_owned()),
            Err(_) => json!({"$bytes": BASE64.encode(t)}),
        },
        ValueRef::Blob(b) => json!({"$blob": BASE64.encode(b)}),
    }
}
