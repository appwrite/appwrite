//! Driver errors as the library's.
//!
//! PHP: `processException()` and `processSelectException()` of `SQL`,
//! `Postgres`, `MariaDB`, `MySQL` and `SQLite`, `getViolatedKey()`,
//! `getViolatedColumns()`, `Postgres::isUndefinedAlias()`, `isTransient()`.

use php_std::pcre;

use super::{Engine, Sql};
use crate::adapter::Adapter;
use crate::error::{Error, Kind, UNIQUE_MESSAGE};
use crate::storage;

/// `Postgres::QUOTED_IDENTIFIER`.
const QUOTED_IDENTIFIER: &[u8] = "/[\"\u{AB}\u{BB}\u{201C}\u{201D}\u{201E}\u{300C}\u{300D}][\\s\u{A0}\u{202F}]*([^\"\u{AB}\u{BB}\u{201C}\u{201D}\u{201E}\u{300C}\u{300D}]+?)[\\s\u{A0}\u{202F}]*[\"\u{AB}\u{BB}\u{201C}\u{201D}\u{201E}\u{300C}\u{300D}]/u".as_bytes();

/// `Postgres::HASHED_IDENTIFIER`.
const HASHED_IDENTIFIER: &[u8] = b"/^[0-9a-f]{32}(?:_[A-Za-z0-9_-]+)?$/";

/// The first group `pattern` matches in `subject`, as `preg_match()`.
fn first_group(pattern: &[u8], subject: &[u8]) -> Option<Vec<u8>> {
    let result = pcre::preg_match(pattern, subject, 0, 0).ok()?;
    if !matches!(result.value.result, pcre::Value::Int(1)) {
        return None;
    }
    let matches = result.value.matches?;
    match matches.get(&pcre::Key::Int(1))? {
        pcre::Value::Str(group) => Some(group.clone()),
        _ => None,
    }
}

/// Whether `pattern` matches `subject` (`preg_match() === 1`).
fn matches(pattern: &[u8], subject: &[u8]) -> bool {
    pcre::preg_match(pattern, subject, 0, 0).is_ok_and(|r| matches!(r.value.result, pcre::Value::Int(1)))
}

/// `stripos($haystack, $needle) !== false`.
fn contains_ci(haystack: &str, needle: &str) -> bool {
    haystack.to_ascii_lowercase().contains(&needle.to_ascii_lowercase())
}

/// `MariaDB::getViolatedKey()`: the key a duplicate entry names.
pub(crate) fn violated_key(message: &str) -> Option<String> {
    first_group(br"/for key '(?:[^'.]*\.)?([^']+)'/", message.as_bytes())
        .map(|g| String::from_utf8_lossy(&g).into_owned())
}

/// The columns, trimmed and sorted as `sort()` sorts strings.
fn sorted_columns(group: &[u8], trim: &[u8], after_dot: bool) -> Vec<String> {
    let mut columns: Vec<String> = group
        .split(|b| *b == b',')
        .map(|c| {
            let column = match (after_dot, c.iter().rposition(|b| *b == b'.')) {
                (true, Some(i)) => &c[i + 1..],
                _ => c,
            };
            String::from_utf8_lossy(php_std::string::trim(column, trim)).into_owned()
        })
        .collect();
    columns.sort_by(|a, b| php_std::string::strcmp(a.as_bytes(), b.as_bytes()).cmp(&0));
    columns
}

/// `Postgres::getViolatedColumns()`: the columns a unique violation names.
pub(crate) fn postgres_violated_columns(message: &str) -> Option<Vec<String>> {
    first_group(br"/Key \(([^)]+)\)=/", message.as_bytes()).map(|g| sorted_columns(&g, b" \t\"'", false))
}

/// `SQLite::getViolatedColumns()`.
pub(crate) fn sqlite_violated_columns(message: &str) -> Option<Vec<String>> {
    first_group(br"/UNIQUE constraint failed:\s*(.+)/", message.as_bytes()).map(|g| sorted_columns(&g, b" \t`\"", true))
}

/// Whether violated columns name only the document key.
fn is_document_key(columns: &Option<Vec<String>>) -> bool {
    match columns {
        None => true,
        Some(columns) => {
            columns == &[storage::UID.to_owned()] || columns == &[storage::TENANT.to_owned(), storage::UID.to_owned()]
        }
    }
}

impl Sql {
    /// `processException()`: a driver error ([`Kind::Pdo`]) mapped to the
    /// library's error it means, carrying it as its previous error; any
    /// other error as it is.
    pub(crate) fn process_exception(&self, e: Error) -> Error {
        if e.kind() != Kind::Pdo {
            return e;
        }
        let state = e.state().unwrap_or("").to_owned();
        let code = e.driver_code();
        let message = e.message().to_owned();
        let mapped = |kind: Kind, text: &str| -> Option<(Kind, String)> { Some((kind, text.to_owned())) };
        let is = |s: &str, c: i64| state == s && code == Some(c);
        let found: Option<(Kind, String)> = match self.engine {
            Engine::Postgres => {
                if is("57014", 7) {
                    mapped(Kind::Timeout, "Query timed out")
                } else if is("42P07", 7) {
                    mapped(Kind::Duplicate, "Collection already exists")
                } else if is("42701", 7) {
                    mapped(Kind::Duplicate, "Attribute already exists")
                } else if is("23505", 7) {
                    if is_document_key(&postgres_violated_columns(&message)) {
                        mapped(Kind::Duplicate, "Document already exists")
                    } else {
                        mapped(Kind::Unique, UNIQUE_MESSAGE)
                    }
                } else if is("22001", 7) {
                    mapped(Kind::Truncate, "Resize would result in data truncation")
                } else if is("22003", 7) {
                    mapped(Kind::Limit, "Numeric value out of range")
                } else if is("2201F", 7) {
                    mapped(Kind::Limit, "Invalid argument for power function")
                } else if is("22008", 7) {
                    mapped(Kind::Limit, "Datetime field overflow")
                } else if is("54000", 7) && message.contains("index row") {
                    mapped(Kind::Limit, "Index row size exceeds the maximum")
                } else if is("42P01", 7) {
                    if self.is_undefined_alias(&message) {
                        mapped(Kind::Query, "Query references an undefined table or alias")
                    } else {
                        mapped(Kind::NotFound, "Collection not found")
                    }
                } else if is("42703", 7) {
                    mapped(Kind::NotFound, "Attribute not found")
                } else if is("42P10", 7)
                    && message.contains("for SELECT DISTINCT, ORDER BY expressions must appear in select list")
                {
                    mapped(
                        Kind::Query,
                        "A distinct() query can only be ordered by a selected attribute on this database",
                    )
                } else if is("40P01", 7) {
                    mapped(Kind::Contention, "Deadlock detected")
                } else if is("40001", 7) {
                    mapped(Kind::Contention, "Could not serialize access due to a concurrent update")
                } else if is("55P03", 7) {
                    mapped(Kind::Contention, "Lock not available")
                } else if is("22021", 7) {
                    mapped(Kind::Character, "Invalid character")
                } else if is("42883", 7) {
                    mapped(Kind::Query, "Query applies a function or operator the attribute type does not support")
                } else {
                    None
                }
            }
            Engine::MariaDb | Engine::MySql => {
                let mysql = if self.engine == Engine::MySql {
                    if is("HY000", 1366) {
                        mapped(Kind::Character, "Invalid character")
                    } else if is("HY000", 3024) || is("HY000", 3699) {
                        mapped(Kind::Timeout, "Query timed out")
                    } else if is("HY000", 3837) {
                        mapped(Kind::Dependency, "Attribute cannot be deleted because it is used in an index")
                    } else if is("22004", 1138) {
                        mapped(Kind::Structure, "Attribute does not allow null values")
                    } else {
                        None
                    }
                } else {
                    None
                };
                mysql.or_else(|| self.mariadb_exception(&state, code, &message))
            }
            Engine::Sqlite => {
                let sqlite = |c: i64| state == "HY000" && code == Some(c);
                if sqlite(1) && contains_ci(&message, "already exists") {
                    mapped(Kind::Duplicate, "Collection already exists")
                } else if sqlite(1) && contains_ci(&message, "no such table") {
                    mapped(Kind::NotFound, "Collection not found")
                } else if sqlite(1) && contains_ci(&message, "no such column") {
                    mapped(Kind::NotFound, "Attribute not found")
                } else if sqlite(1) && contains_ci(&message, "duplicate column name") {
                    mapped(Kind::Duplicate, "Attribute already exists")
                } else if ((sqlite(1) || sqlite(19)) || state == "23000")
                    && (code == Some(19)
                        || state == "23000"
                        || contains_ci(&message, "unique")
                        || contains_ci(&message, "duplicate"))
                {
                    if is_document_key(&sqlite_violated_columns(&message)) {
                        mapped(Kind::Duplicate, "Document already exists")
                    } else {
                        mapped(Kind::Unique, UNIQUE_MESSAGE)
                    }
                } else if sqlite(18) {
                    mapped(Kind::Limit, "Value too large")
                } else if sqlite(5) {
                    mapped(Kind::Contention, "Database is locked")
                } else {
                    None
                }
            }
        };
        match found {
            Some((kind, text)) => Error::new(kind, text).with_string_code(state.as_bytes()).with_previous(e),
            None => e,
        }
    }

    /// `MariaDB::processException()`'s mappings.
    fn mariadb_exception(&self, state: &str, code: Option<i64>, message: &str) -> Option<(Kind, String)> {
        let is = |s: &str, c: i64| state == s && code == Some(c);
        let found = |kind: Kind, text: &str| Some((kind, text.to_owned()));
        if is("22007", 1366) {
            found(Kind::Character, "Invalid character")
        } else if is("70100", 1969) {
            found(Kind::Timeout, "Query timed out")
        } else if is("42S01", 1050) {
            found(Kind::Duplicate, "Collection already exists")
        } else if is("42S21", 1060) {
            found(Kind::Duplicate, "Attribute already exists")
        } else if is("42000", 1061) {
            found(Kind::Duplicate, "Index already exists")
        } else if is("42000", 1071) {
            found(Kind::Index, "Index key length exceeds the maximum")
        } else if is("23000", 1062) {
            let key = violated_key(message);
            match key.as_deref() {
                Some(k) if k == storage::INDEX_1 => found(Kind::Duplicate, "Duplicate permissions for document"),
                Some(k) if k != storage::UID && k != "PRIMARY" => found(Kind::Unique, UNIQUE_MESSAGE),
                _ => found(Kind::Duplicate, "Document already exists"),
            }
        } else if is("22001", 1406) || is("01000", 1265) {
            found(Kind::Truncate, "Resize would result in data truncation")
        } else if is("22003", 1264) || is("22003", 1690) {
            found(Kind::Limit, "Value out of range")
        } else if is("HY000", 1690) {
            found(Kind::Limit, "Value is out of range")
        } else if is("42000", 1049) {
            found(Kind::NotFound, "Database not found")
        } else if is("42S02", 1051) || is("42S02", 1146) {
            found(Kind::NotFound, "Collection not found")
        } else if is("42000", 1091) || is("42S22", 1054) || is("42000", 1072) {
            found(Kind::NotFound, "Attribute not found")
        } else if is("HY000", 1116) {
            found(Kind::Query, "Too many tables in a join")
        } else if is("HY000", 1191) {
            found(Kind::Query, "Searching requires a fulltext index on the searched attributes")
        } else if is("HY000", 3065) {
            found(Kind::Query, "A distinct() query can only be ordered by a selected attribute on this database")
        } else if is("40001", 1213) {
            found(Kind::Contention, "Deadlock detected")
        } else if is("HY000", 1205) {
            found(Kind::Contention, "Lock wait timeout exceeded")
        } else {
            None
        }
    }

    /// `processSelectException()`: Postgres also maps an unordered distinct
    /// select by its statement.
    pub(crate) fn process_select_exception(&self, e: Error, query: &str) -> Error {
        if self.engine == Engine::Postgres
            && e.kind() == Kind::Pdo
            && e.state() == Some("42P10")
            && e.driver_code() == Some(7)
            && query.starts_with("SELECT DISTINCT ")
        {
            return Error::query("A distinct() query can only be ordered by a selected attribute on this database")
                .with_string_code(b"42P10")
                .with_previous(e);
        }
        self.process_exception(e)
    }

    /// `Postgres::isUndefinedAlias()`: whether a 42P01 names something
    /// other than a table of this namespace.
    fn is_undefined_alias(&self, message: &str) -> bool {
        let message = message.trim_end_matches([' ', '\t', '\n', '\r', '\0', '\x0b']);
        let first_line = message.split('\n').next().unwrap_or("");
        let Some(name) = first_group(QUOTED_IDENTIFIER, first_line.as_bytes()) else {
            return false;
        };
        let name = String::from_utf8_lossy(&name).into_owned();
        let separator = name.rfind('.');
        let relation = match separator {
            Some(i) => &name[i + 1..],
            None => name.as_str(),
        };
        if !relation.starts_with(&format!("{}_", self.namespace())) && !matches(HASHED_IDENTIFIER, relation.as_bytes())
        {
            return true;
        }
        separator.is_none() && message.contains('\n')
    }

    /// `isTransient()`: a lost connection, or a driver error mapped to a
    /// lock conflict.
    pub(crate) fn is_transient(&self, error: &Error) -> bool {
        crate::connection::is_lost(error)
            || (error.kind() == Kind::Pdo && self.process_exception(error.clone()).kind() == Kind::Contention)
    }
}
