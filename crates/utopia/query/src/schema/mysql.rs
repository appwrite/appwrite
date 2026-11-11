//! [`MySql`]: the MySQL / MariaDB schema.

use super::column::{self, Column};
use super::enums::{ColumnType, ParameterDirection, TriggerEvent, TriggerTiming};
use super::feature;
use super::table::{self, ForeignKey, Table};
use super::{Schema, base, names};
use crate::builder::{AnyBuilder, Executor, Statement};
use crate::error::{Error, Result};

/// The MySQL schema: backtick quoting, `AUTO_INCREMENT`, inline indexes,
/// `PARTITION BY ... PARTITIONS n`.
///
/// ```
/// use utopia_query::schema::{MySql, Schema};
///
/// let schema = MySql::new();
/// let mut table = schema.table("events");
/// table.id("id");
/// table.json("payload").nullable();
/// table.partition_by_hash("id", Some(4))?;
/// assert_eq!(
///     table.create()?.query,
///     "CREATE TABLE `events` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `payload` JSON NULL, \
///      PRIMARY KEY (`id`)) PARTITION BY HASH(id) PARTITIONS 4"
/// );
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Schema\MySQL;
///
/// $table = (new MySQL())->table('events');
/// $table->id();
/// $table->json('payload')->nullable();
/// $table->partitionByHash('id', 4);
/// echo $table->create()->query, "\n";
/// ```
///
/// PHP: `Utopia\Query\Schema\MySQL`.
#[derive(Clone, Default)]
pub struct MySql {
    executor: Option<Executor>,
}

impl std::fmt::Debug for MySql {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("MySql").field("executor", &self.executor.is_some()).finish()
    }
}

impl MySql {
    /// A MySQL schema without an executor.
    ///
    /// PHP: `new MySQL()`.
    pub fn new() -> Self {
        MySql::default()
    }

    /// `ALTER TABLE table CHANGE COLUMN old new type`: renames and retypes a
    /// column (`kind` is emitted verbatim).
    ///
    /// PHP: `changeColumn()`.
    pub fn change_column(&self, table: &str, old_name: &str, new_name: &str, kind: &str) -> Result<Statement> {
        base::plain(
            self,
            format!(
                "ALTER TABLE {} CHANGE COLUMN {} {} {kind}",
                self.quote(table)?,
                self.quote_literal(old_name)?,
                self.quote_literal(new_name)?
            ),
        )
    }

    /// `ALTER TABLE table MODIFY name type`: retypes a column (`kind` is
    /// emitted verbatim).
    ///
    /// PHP: `MySQL::modifyColumn()`.
    pub fn modify_column(&self, table: &str, name: &str, kind: &str) -> Result<Statement> {
        base::plain(self, format!("ALTER TABLE {} MODIFY {} {kind}", self.quote(table)?, self.quote_literal(name)?))
    }
}

impl Schema for MySql {
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
        let srid = |kind: &str| match c.srid {
            Some(srid) => format!("{kind} SRID {srid}"),
            None => kind.to_owned(),
        };
        let precise = |kind: &str| match c.precision {
            Some(p) if p != 0 => format!("{kind}({p})"),
            _ => kind.to_owned(),
        };
        Ok(match c.kind {
            ColumnType::String | ColumnType::Varchar | ColumnType::Relationship => {
                format!("VARCHAR({})", c.length.unwrap_or(255))
            }
            ColumnType::Text => "TEXT".into(),
            ColumnType::MediumText => "MEDIUMTEXT".into(),
            ColumnType::LongText => "LONGTEXT".into(),
            ColumnType::TinyInteger => "TINYINT".into(),
            ColumnType::SmallInteger | ColumnType::SmallSerial => "SMALLINT".into(),
            ColumnType::Integer | ColumnType::Serial => "INT".into(),
            ColumnType::BigInteger | ColumnType::Id | ColumnType::BigSerial => "BIGINT".into(),
            ColumnType::Float | ColumnType::Double => "DOUBLE".into(),
            ColumnType::Decimal => format!("DECIMAL({}, {})", c.precision.unwrap_or(10), c.scale.unwrap_or(0)),
            ColumnType::Boolean => "TINYINT(1)".into(),
            ColumnType::Datetime => precise("DATETIME"),
            ColumnType::Timestamp => precise("TIMESTAMP"),
            ColumnType::Json | ColumnType::Object => "JSON".into(),
            ColumnType::Binary => "BLOB".into(),
            ColumnType::Enum => {
                let values: Vec<String> = c.enum_values.iter().map(|v| names::escape(v)).collect();
                format!("ENUM('{}')", values.join("','"))
            }
            ColumnType::Point => srid("POINT"),
            ColumnType::Linestring => srid("LINESTRING"),
            ColumnType::Polygon => srid("POLYGON"),
            ColumnType::Uuid => "CHAR(36)".into(),
            ColumnType::Uuid7 => "VARCHAR(36)".into(),
            ColumnType::Vector => return Err(Error::Unsupported("Vector type is not supported in MySQL.".into())),
            ColumnType::Array | ColumnType::Tuple => {
                return Err(Error::Unsupported("Array/Tuple column types are not supported in MySQL.".into()));
            }
        })
    }

    fn compile_auto_increment(&self) -> &'static str {
        "AUTO_INCREMENT"
    }

    fn compile_create_suffix(&self, table: &Table<Self>) -> String {
        feature::Partitioning::compile_create_partitioning(self, table)
    }
}

impl column::AutoIncrement for MySql {}
impl column::Collation for MySql {}
impl column::Comment for MySql {}
impl column::Generated for MySql {}
impl column::Positioning for MySql {}
impl column::Srid for MySql {}
impl column::Unique for MySql {}
impl column::VirtualGenerated for MySql {}
impl table::Serial for MySql {}
impl table::Checks for MySql {}
impl table::ColumnAlterations for MySql {}
impl table::CompositePrimary for MySql {}
impl table::InlineForeignKey for MySql {}
impl table::ForeignKeys for MySql {}
impl table::FulltextSpatialIndex for MySql {}
impl table::StandardPartitioning for MySql {}

impl feature::ForeignKeys for MySql {
    fn add_foreign_key(&self, table: &str, name: &str, key: &ForeignKey) -> Result<Statement> {
        base::add_foreign_key(self, table, name, key)
    }

    fn drop_foreign_key(&self, table: &str, name: &str) -> Result<Statement> {
        base::plain(self, format!("ALTER TABLE {} DROP FOREIGN KEY {}", self.quote(table)?, self.quote(name)?))
    }
}

impl feature::Procedures for MySql {
    fn create_procedure(
        &self,
        name: &str,
        params: &[(ParameterDirection, &str, &str)],
        body: &str,
    ) -> Result<Statement> {
        base::create_procedure(self, name, params, body)
    }

    fn drop_procedure(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP PROCEDURE {}", self.quote(name)?))
    }
}

impl feature::Triggers for MySql {
    fn create_trigger(
        &self,
        name: &str,
        table: &str,
        timing: TriggerTiming,
        event: TriggerEvent,
        body: &str,
    ) -> Result<Statement> {
        base::create_trigger(self, name, table, timing, event, body)
    }

    fn drop_trigger(&self, name: &str, _table: Option<&str>) -> Result<Statement> {
        base::plain(self, format!("DROP TRIGGER {}", self.quote(name)?))
    }
}

impl feature::TableComments for MySql {
    fn comment_on_table(&self, table: &str, comment: &str) -> Result<Statement> {
        base::plain(self, format!("ALTER TABLE {} COMMENT = {}", self.quote(table)?, names::literal(comment)))
    }
}

impl feature::CreatePartition for MySql {
    fn create_partition(&self, parent: &str, name: &str, expression: &str) -> Result<Statement> {
        base::plain(
            self,
            format!("ALTER TABLE {} ADD PARTITION (PARTITION {} {expression})", self.quote(parent)?, self.quote(name)?),
        )
    }
}

impl feature::DropPartition for MySql {
    fn drop_partition(&self, table: &str, name: &str) -> Result<Statement> {
        base::plain(self, format!("ALTER TABLE {} DROP PARTITION {}", self.quote(table)?, self.quote(name)?))
    }
}

impl feature::Views for MySql {
    fn create_view(&self, name: &str, query: &mut dyn AnyBuilder) -> Result<Statement> {
        base::create_view(self, "CREATE VIEW", name, query)
    }

    fn drop_view(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP VIEW {}", self.quote(name)?))
    }
}

impl feature::ReplaceView for MySql {
    fn create_or_replace_view(&self, name: &str, query: &mut dyn AnyBuilder) -> Result<Statement> {
        base::create_view(self, "CREATE OR REPLACE VIEW", name, query)
    }
}

impl feature::Databases for MySql {
    fn create_database(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("CREATE DATABASE {} /*!40100 DEFAULT CHARACTER SET utf8mb4 */", self.quote(name)?))
    }

    fn drop_database(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP DATABASE {}", self.quote(name)?))
    }
}

impl feature::RenameIndex for MySql {
    fn rename_index(&self, table: &str, from: &str, to: &str) -> Result<Statement> {
        base::plain(
            self,
            format!("ALTER TABLE {} RENAME INDEX {} TO {}", self.quote(table)?, self.quote(from)?, self.quote(to)?),
        )
    }
}

impl feature::AnalyzeTable for MySql {
    fn analyze_table(&self, table: &str) -> Result<Statement> {
        base::plain(self, format!("ANALYZE TABLE {}", self.quote(table)?))
    }
}

impl feature::Partitioning for MySql {
    fn compile_create_partitioning(&self, table: &Table<Self>) -> String {
        base::partitioning(table, true)
    }
}
