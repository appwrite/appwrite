//! What a schema can do beyond tables and indexes: each trait is one
//! capability a dialect may implement (foreign keys, procedures, triggers,
//! views, partitions, ...), so callers can require it in a bound.
//!
//! PHP: the `Utopia\Query\Schema\Feature\*` interfaces.

use super::Schema;
use super::enums::{ParameterDirection, TriggerEvent, TriggerTiming};
use super::table::{ForeignKey, Table};
use crate::builder::{AnyBuilder, Statement};
use crate::error::Result;

/// Adding and dropping foreign-key constraints on existing tables.
///
/// PHP: `Schema\Feature\ForeignKeys`.
pub trait ForeignKeys: Schema {
    /// `ALTER TABLE table ADD CONSTRAINT name FOREIGN KEY ...` for `key`.
    ///
    /// PHP: `addForeignKey()`.
    fn add_foreign_key(&self, table: &str, name: &str, key: &ForeignKey) -> Result<Statement>;

    /// Drops the foreign key `name` of `table`.
    ///
    /// PHP: `dropForeignKey()`.
    fn drop_foreign_key(&self, table: &str, name: &str) -> Result<Statement>;
}

/// Stored procedures.
///
/// PHP: `Schema\Feature\Procedures`.
pub trait Procedures: Schema {
    /// Creates procedure `name` with `params` (direction, name, SQL type) and
    /// `body`, which is emitted verbatim and must be trusted.
    ///
    /// PHP: `createProcedure()`.
    fn create_procedure(
        &self,
        name: &str,
        params: &[(ParameterDirection, &str, &str)],
        body: &str,
    ) -> Result<Statement>;

    /// Drops procedure `name`.
    ///
    /// PHP: `dropProcedure()`.
    fn drop_procedure(&self, name: &str) -> Result<Statement>;
}

/// Row triggers.
///
/// PHP: `Schema\Feature\Triggers`.
pub trait Triggers: Schema {
    /// Creates trigger `name` on `table`, running `body` (verbatim, trusted)
    /// for each row at `timing` of `event`.
    ///
    /// PHP: `createTrigger()`.
    fn create_trigger(
        &self,
        name: &str,
        table: &str,
        timing: TriggerTiming,
        event: TriggerEvent,
        body: &str,
    ) -> Result<Statement>;

    /// Drops trigger `name` (PostgreSQL needs its `table`).
    ///
    /// PHP: `dropTrigger()`.
    fn drop_trigger(&self, name: &str, table: Option<&str>) -> Result<Statement>;
}

/// User-defined enum types.
///
/// PHP: `Schema\Feature\Types`.
pub trait Types: Schema {
    /// `CREATE TYPE name AS ENUM (values...)`.
    ///
    /// PHP: `createType()`.
    fn create_type(&self, name: &str, values: &[&str]) -> Result<Statement>;

    /// Drops type `name`.
    ///
    /// PHP: `dropType()`.
    fn drop_type(&self, name: &str) -> Result<Statement>;
}

/// Sequences.
///
/// PHP: `Schema\Feature\Sequences`.
pub trait Sequences: Schema {
    /// Creates sequence `name` starting at `start`, stepping by `increment_by`
    /// (PHP's defaults are 1 and 1).
    ///
    /// PHP: `createSequence()`.
    fn create_sequence(&self, name: &str, start: i64, increment_by: i64) -> Result<Statement>;

    /// Drops sequence `name`.
    ///
    /// PHP: `dropSequence()`.
    fn drop_sequence(&self, name: &str) -> Result<Statement>;

    /// Reads the next value of sequence `name`.
    ///
    /// PHP: `nextVal()`.
    fn next_val(&self, name: &str) -> Result<Statement>;
}

/// Table comments.
///
/// PHP: `Schema\Feature\TableComments`.
pub trait TableComments: Schema {
    /// Sets the comment of `table`.
    ///
    /// PHP: `commentOnTable()`.
    fn comment_on_table(&self, table: &str, comment: &str) -> Result<Statement>;
}

/// Column comments as separate statements.
///
/// PHP: `Schema\Feature\ColumnComments`.
pub trait ColumnComments: Schema {
    /// Sets the comment of `column` of `table`.
    ///
    /// PHP: `commentOnColumn()`.
    fn comment_on_column(&self, table: &str, column: &str, comment: &str) -> Result<Statement>;
}

/// Adding partitions.
///
/// PHP: `Schema\Feature\CreatePartition`.
pub trait CreatePartition: Schema {
    /// Adds partition `name` of `parent` for `expression`.
    ///
    /// PHP: `createPartition()`.
    fn create_partition(&self, parent: &str, name: &str, expression: &str) -> Result<Statement>;
}

/// Dropping partitions.
///
/// PHP: `Schema\Feature\DropPartition`.
pub trait DropPartition: Schema {
    /// Drops partition `name` of `table`.
    ///
    /// PHP: `dropPartition()`.
    fn drop_partition(&self, table: &str, name: &str) -> Result<Statement>;
}

/// Views.
///
/// PHP: `Schema\Feature\Views`.
pub trait Views: Schema {
    /// Creates view `name` of the query `query` builds (its bindings ride
    /// along).
    ///
    /// PHP: `createView()`.
    fn create_view(&self, name: &str, query: &mut dyn AnyBuilder) -> Result<Statement>;

    /// Drops view `name`.
    ///
    /// PHP: `dropView()`.
    fn drop_view(&self, name: &str) -> Result<Statement>;
}

/// `CREATE OR REPLACE VIEW`.
///
/// PHP: `Schema\Feature\ReplaceView`.
pub trait ReplaceView: Schema {
    /// Creates or replaces view `name` of the query `query` builds.
    ///
    /// PHP: `createOrReplaceView()`.
    fn create_or_replace_view(&self, name: &str, query: &mut dyn AnyBuilder) -> Result<Statement>;
}

/// Databases (schemas on PostgreSQL).
///
/// PHP: `Schema\Feature\Databases`.
pub trait Databases: Schema {
    /// Creates database `name`.
    ///
    /// PHP: `createDatabase()`.
    fn create_database(&self, name: &str) -> Result<Statement>;

    /// Drops database `name`.
    ///
    /// PHP: `dropDatabase()`.
    fn drop_database(&self, name: &str) -> Result<Statement>;
}

/// Renaming indexes.
///
/// PHP: `Schema\Feature\RenameIndex`.
pub trait RenameIndex: Schema {
    /// Renames index `from` of `table` to `to`.
    ///
    /// PHP: `renameIndex()`.
    fn rename_index(&self, table: &str, from: &str, to: &str) -> Result<Statement>;
}

/// Refreshing table statistics.
///
/// PHP: `Schema\Feature\AnalyzeTable`.
pub trait AnalyzeTable: Schema {
    /// Analyzes `table`.
    ///
    /// PHP: `analyzeTable()`.
    fn analyze_table(&self, table: &str) -> Result<Statement>;
}

/// The body of a materialized view: a builder (its SQL inlined, its
/// bindings carried) or raw SQL, emitted verbatim (trusted input only).
///
/// PHP: `Builder|string $body`.
pub enum ViewBody<'a> {
    /// A query builder.
    Builder(&'a mut dyn AnyBuilder),
    /// Raw SQL.
    Sql(&'a str),
}

/// Materialized views.
///
/// PHP: `Schema\Feature\MaterializedViews`.
pub trait MaterializedViews: Schema {
    /// `CREATE MATERIALIZED VIEW [IF NOT EXISTS] name [TO target] AS body`
    /// (PHP's default for `if_not_exists` is `true`).
    ///
    /// PHP: `createMaterializedView()`.
    fn create_materialized_view(
        &self,
        name: &str,
        body: ViewBody<'_>,
        target_table: Option<&str>,
        if_not_exists: bool,
    ) -> Result<Statement>;

    /// Drops materialized view `name` (PHP's default for `if_exists` is `true`).
    ///
    /// PHP: `dropMaterializedView()`.
    fn drop_materialized_view(&self, name: &str, if_exists: bool) -> Result<Statement>;
}

/// `PARTITION BY` in `CREATE TABLE`.
///
/// PHP: `Schema\Feature\Partitioning`.
pub trait Partitioning: Schema {
    /// The `PARTITION BY ...` clause of `table`, or empty.
    ///
    /// PHP: `compileCreatePartitioning()`.
    fn compile_create_partitioning(&self, table: &Table<Self>) -> String;
}
