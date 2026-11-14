//! [`Sqlite`]: the SQLite schema.

use super::column::{self, Column};
use super::enums::ColumnType;
use super::feature;
use super::table::{self, Table};
use super::{Schema, base};
use crate::builder::{AnyBuilder, Executor, Statement};
use crate::error::{Error, Result};

/// The SQLite schema: backtick quoting, `INTEGER PRIMARY KEY AUTOINCREMENT`
/// inline, `TEXT` for most types, `DELETE FROM` for truncation.
///
/// ```
/// use utopia_query::schema::{Schema, Sqlite};
///
/// let mut table = Sqlite::new().table("notes");
/// table.id("id");
/// table.text("body");
/// assert_eq!(
///     table.create()?.query,
///     "CREATE TABLE `notes` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `body` TEXT NOT NULL)"
/// );
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Schema\SQLite;
///
/// $table = (new SQLite())->table('notes');
/// $table->id();
/// $table->text('body');
/// echo $table->create()->query, "\n";
/// ```
///
/// PHP: `Utopia\Query\Schema\SQLite`.
#[derive(Clone, Default)]
pub struct Sqlite {
    executor: Option<Executor>,
}

impl std::fmt::Debug for Sqlite {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Sqlite").field("executor", &self.executor.is_some()).finish()
    }
}

impl Sqlite {
    /// A SQLite schema without an executor.
    ///
    /// PHP: `new SQLite()`.
    pub fn new() -> Self {
        Sqlite::default()
    }
}

/// `query` without its first `, PRIMARY KEY (`...`)` clause
/// (`preg_replace('/, PRIMARY KEY \(`[^`]+`\)/', '', $query, 1)`).
fn without_primary_clause(query: &str) -> String {
    const NEEDLE: &str = ", PRIMARY KEY (`";
    let mut from = 0;
    while let Some(found) = query[from..].find(NEEDLE) {
        let start = from + found;
        let name_start = start + NEEDLE.len();
        let rest = &query.as_bytes()[name_start..];
        let name_len = rest.iter().position(|&b| b == b'`').unwrap_or(rest.len());
        if name_len > 0 && rest[name_len..].starts_with(b"`)") {
            let end = name_start + name_len + 2;
            return format!("{}{}", &query[..start], &query[end..]);
        }
        from = start + 1;
    }
    query.to_owned()
}

impl Schema for Sqlite {
    fn executor(&self) -> Option<&Executor> {
        self.executor.as_ref()
    }

    fn set_executor(&mut self, executor: Executor) -> &mut Self {
        self.executor = Some(executor);
        self
    }

    fn quote(&self, identifier: &str) -> Result<String> {
        crate::quote(identifier, '`')
    }

    fn quote_literal(&self, identifier: &str) -> Result<String> {
        crate::quote_literal(identifier, '`')
    }

    fn compile_column_type(&self, column: &Column<Self>) -> Result<String> {
        let c = &column.def;
        Ok(match c.kind {
            ColumnType::String | ColumnType::Varchar | ColumnType::Relationship => {
                format!("VARCHAR({})", c.length.unwrap_or(255))
            }
            ColumnType::Text | ColumnType::MediumText | ColumnType::LongText => "TEXT".into(),
            ColumnType::TinyInteger
            | ColumnType::SmallInteger
            | ColumnType::Integer
            | ColumnType::BigInteger
            | ColumnType::Id
            | ColumnType::Serial
            | ColumnType::BigSerial
            | ColumnType::SmallSerial => "INTEGER".into(),
            ColumnType::Float | ColumnType::Double => "REAL".into(),
            ColumnType::Decimal => format!("NUMERIC({}, {})", c.precision.unwrap_or(10), c.scale.unwrap_or(0)),
            ColumnType::Boolean => "INTEGER".into(),
            ColumnType::Datetime | ColumnType::Timestamp => "TEXT".into(),
            ColumnType::Json | ColumnType::Object => "TEXT".into(),
            ColumnType::Binary => "BLOB".into(),
            ColumnType::Enum => "TEXT".into(),
            ColumnType::Point | ColumnType::Linestring | ColumnType::Polygon => "TEXT".into(),
            ColumnType::Uuid => "TEXT".into(),
            ColumnType::Uuid7 => "VARCHAR(36)".into(),
            ColumnType::Vector => return Err(Error::Unsupported("Vector type is not supported in SQLite.".into())),
            ColumnType::Array | ColumnType::Tuple => {
                return Err(Error::Unsupported("Array/Tuple column types are not supported in SQLite.".into()));
            }
        })
    }

    fn compile_auto_increment(&self) -> &'static str {
        "AUTOINCREMENT"
    }

    /// An auto-incrementing primary key is `INTEGER PRIMARY KEY
    /// AUTOINCREMENT` inline; other columns compile as usual.
    fn compile_column_definition(&self, column: &Column<Self>) -> Result<String> {
        let c = &column.def;
        if !c.auto_increment || !c.primary {
            return base::column_definition(self, column);
        }
        let mut parts = vec![
            self.quote_literal(&c.name)?,
            self.compile_column_type(column)?,
            "PRIMARY KEY".to_owned(),
            self.compile_auto_increment().to_owned(),
        ];
        if !c.nullable {
            parts.push("NOT NULL".into());
        }
        if let Some(raw) = &c.default_raw {
            parts.push(format!("DEFAULT {raw}"));
        } else if let Some(value) = &c.default {
            parts.push(format!("DEFAULT {}", base::default_value(value)));
        }
        if let Some(check) = &c.check {
            parts.push(format!("CHECK ({check})"));
        }
        Ok(parts.join(" "))
    }

    /// Without the trailing `PRIMARY KEY (...)` clause when a column carries
    /// its key inline.
    fn compile_create(&self, table: &Table<Self>, if_not_exists: bool) -> Result<Statement> {
        let inline = table.columns().iter().any(|c| c.def.auto_increment && c.def.primary);
        let statement = base::create(self, table, if_not_exists)?;
        if !inline {
            return Ok(statement);
        }
        let query = without_primary_clause(&statement.query);
        Ok(base::statement(self, query, statement.bindings))
    }

    fn compile_unsigned(&self) -> &'static str {
        ""
    }

    fn compile_rename(&self, from: &str, to: &str) -> Result<Statement> {
        base::plain(self, format!("ALTER TABLE {} RENAME TO {}", self.quote(from)?, self.quote(to)?))
    }

    fn compile_truncate(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DELETE FROM {}", self.quote(name)?))
    }

    fn drop_index(&self, _table: &str, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP INDEX {}", self.quote(name)?))
    }
}

impl column::AutoIncrement for Sqlite {}
impl column::Collation for Sqlite {}
impl column::Comment for Sqlite {}
impl column::Generated for Sqlite {}
impl column::Unique for Sqlite {}
impl column::VirtualGenerated for Sqlite {}
impl table::Serial for Sqlite {}
impl table::Checks for Sqlite {}
impl table::ColumnAlterations for Sqlite {}
impl table::CompositePrimary for Sqlite {}
impl table::InlineForeignKey for Sqlite {}

impl feature::Views for Sqlite {
    fn create_view(&self, name: &str, query: &mut dyn AnyBuilder) -> Result<Statement> {
        base::create_view(self, "CREATE VIEW", name, query)
    }

    fn drop_view(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP VIEW {}", self.quote(name)?))
    }
}

#[cfg(test)]
mod tests {
    use super::without_primary_clause;

    #[test]
    fn removes_the_first_primary_clause() {
        assert_eq!(without_primary_clause("(`a` INT, PRIMARY KEY (`a`))"), "(`a` INT)");
        assert_eq!(
            without_primary_clause("(`a` INT, PRIMARY KEY (``), PRIMARY KEY (`b`))"),
            "(`a` INT, PRIMARY KEY (``))"
        );
        assert_eq!(without_primary_clause("(`a` INT)"), "(`a` INT)");
    }
}
