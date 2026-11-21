//! [`PostgreSql`]: the PostgreSQL schema.

use super::column::{self, Column};
use super::enums::{ColumnType, IndexType, ParameterDirection, TriggerEvent, TriggerTiming};
use super::feature;
use super::index::IndexOptions;
use super::table::{self, ForeignKey, Table};
use super::{Schema, base, names};
use crate::builder::{AnyBuilder, Executor, Statement};
use crate::error::{Error, Result};

/// The PostgreSQL schema: double-quote quoting, identity columns, `SERIAL`
/// types, `CHECK`-emulated enums, standalone index statements, PL/pgSQL
/// procedures and triggers, types, sequences, extensions and collations.
///
/// ```
/// use utopia_query::schema::{PostgreSql, Schema};
///
/// let mut table = PostgreSql::new().table("users");
/// table.add_column("bio", utopia_query::schema::ColumnType::Text, None).nullable();
/// table.drop_column("legacy");
/// table.index(["email"])?;
/// assert_eq!(
///     table.alter()?.query,
///     "ALTER TABLE \"users\" ADD COLUMN \"bio\" TEXT NULL, DROP COLUMN \"legacy\"; \
///      CREATE INDEX \"idx_email\" ON \"users\" (\"email\")"
/// );
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Schema\ColumnType;
/// use Utopia\Query\Schema\PostgreSQL;
///
/// $table = (new PostgreSQL())->table('users');
/// $table->addColumn('bio', ColumnType::Text)->nullable();
/// $table->dropColumn('legacy');
/// $table->index(['email']);
/// echo $table->alter()->query, "\n";
/// ```
///
/// PHP: `Utopia\Query\Schema\PostgreSQL`.
#[derive(Clone, Default)]
pub struct PostgreSql {
    executor: Option<Executor>,
}

impl std::fmt::Debug for PostgreSql {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("PostgreSql").field("executor", &self.executor.is_some()).finish()
    }
}

/// Refuses bodies containing the `$$` that would end the dollar-quoted block.
fn dollar_quoted(body: &str) -> Result<()> {
    if body.contains("$$") {
        return Err(Error::Validation(
            "Procedure/trigger body must not contain the dollar-quote terminator \"$$\"".into(),
        ));
    }
    Ok(())
}

/// Refuses expressions over 1024 bytes or containing `;`, `--` or `/*`.
fn safe_expression(expression: &str, label: &str) -> Result<()> {
    if expression.len() > 1024 {
        return Err(Error::Validation(format!("{label} exceeds 1024 character limit")));
    }
    if expression.contains(';') || expression.contains("--") || expression.contains("/*") {
        return Err(Error::Validation(format!("Invalid {label}: {expression}")));
    }
    Ok(())
}

impl PostgreSql {
    /// A PostgreSQL schema without an executor.
    ///
    /// PHP: `new PostgreSQL()`.
    pub fn new() -> Self {
        PostgreSql::default()
    }

    /// `CREATE EXTENSION IF NOT EXISTS name`.
    ///
    /// PHP: `createExtension()`.
    pub fn create_extension(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("CREATE EXTENSION IF NOT EXISTS {}", self.quote(name)?))
    }

    /// `DROP EXTENSION IF EXISTS name`.
    ///
    /// PHP: `dropExtension()`.
    pub fn drop_extension(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP EXTENSION IF EXISTS {}", self.quote(name)?))
    }

    /// `CREATE COLLATION IF NOT EXISTS name (key = 'value', ..., deterministic = ...)`;
    /// option keys are identifiers (PHP's default for `deterministic` is `true`).
    ///
    /// PHP: `createCollation()`.
    pub fn create_collation(&self, name: &str, options: &[(&str, &str)], deterministic: bool) -> Result<Statement> {
        let mut parts = Vec::with_capacity(options.len() + 1);
        for (key, value) in options {
            if !names::is_identifier(key) {
                return Err(Error::Validation(format!("Invalid collation option key: {key}")));
            }
            parts.push(format!("{key} = {}", names::literal(value)));
        }
        parts.push(format!("deterministic = {deterministic}"));
        base::plain(self, format!("CREATE COLLATION IF NOT EXISTS {} ({})", self.quote(name)?, parts.join(", ")))
    }

    /// `ALTER TABLE table ALTER COLUMN column TYPE type [USING expr]`: the
    /// type is a type declaration, the cast expression has no `;`, `--` or
    /// `/*` (PHP's default for `using` is empty).
    ///
    /// PHP: `alterColumnType()`.
    pub fn alter_column_type(&self, table: &str, column: &str, kind: &str, using: &str) -> Result<Statement> {
        if !names::is_type_declaration(kind) {
            return Err(Error::Validation(format!("Invalid column type: {kind}")));
        }
        if !using.is_empty() {
            safe_expression(using, "USING expression")?;
        }
        let mut sql =
            format!("ALTER TABLE {} ALTER COLUMN {} TYPE {kind}", self.quote(table)?, self.quote_literal(column)?);
        if !using.is_empty() {
            sql.push_str(" USING ");
            sql.push_str(using);
        }
        base::plain(self, sql)
    }

    /// `ALTER TABLE table ALTER COLUMN column DROP NOT NULL` / `SET NOT NULL`.
    ///
    /// PHP: `alterColumnNullable()`.
    pub fn alter_column_nullable(&self, table: &str, column: &str, nullable: bool) -> Result<Statement> {
        base::plain(
            self,
            format!(
                "ALTER TABLE {} ALTER COLUMN {}{}",
                self.quote(table)?,
                self.quote_literal(column)?,
                if nullable { " DROP NOT NULL" } else { " SET NOT NULL" }
            ),
        )
    }

    /// `DROP INDEX CONCURRENTLY name`.
    ///
    /// PHP: `dropIndexConcurrently()`.
    pub fn drop_index_concurrently(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP INDEX CONCURRENTLY {}", self.quote(name)?))
    }
}

impl Schema for PostgreSql {
    fn executor(&self) -> Option<&Executor> {
        self.executor.as_ref()
    }

    fn set_executor(&mut self, executor: Executor) -> &mut Self {
        self.executor = Some(executor);
        self
    }

    fn quote(&self, identifier: &str) -> Result<String> {
        crate::quote(identifier, '"')
    }

    fn quote_literal(&self, identifier: &str) -> Result<String> {
        crate::quote_literal(identifier, '"')
    }

    fn compile_column_type(&self, column: &Column<Self>) -> Result<String> {
        let c = &column.def;
        if let Some(user_type) = &c.user_type {
            return self.quote(user_type);
        }
        let geometry = |kind: &str| match c.srid {
            Some(srid) => format!("GEOMETRY({kind}, {srid})"),
            None => format!("GEOMETRY({kind})"),
        };
        let precision = c.precision.filter(|p| *p != 0);
        Ok(match c.kind {
            ColumnType::String | ColumnType::Varchar | ColumnType::Relationship => {
                format!("VARCHAR({})", c.length.unwrap_or(255))
            }
            ColumnType::Text | ColumnType::MediumText | ColumnType::LongText => "TEXT".into(),
            ColumnType::TinyInteger | ColumnType::SmallInteger => "SMALLINT".into(),
            ColumnType::Integer => "INTEGER".into(),
            ColumnType::BigInteger | ColumnType::Id => "BIGINT".into(),
            ColumnType::Float | ColumnType::Double => "DOUBLE PRECISION".into(),
            ColumnType::Decimal => format!("DECIMAL({}, {})", c.precision.unwrap_or(10), c.scale.unwrap_or(0)),
            ColumnType::Boolean => "BOOLEAN".into(),
            ColumnType::Datetime => match precision {
                Some(p) => format!("TIMESTAMP({p})"),
                None => "TIMESTAMP".into(),
            },
            ColumnType::Timestamp => match precision {
                Some(p) => format!("TIMESTAMP({p}) WITHOUT TIME ZONE"),
                None => "TIMESTAMP WITHOUT TIME ZONE".into(),
            },
            ColumnType::Json | ColumnType::Object => "JSONB".into(),
            ColumnType::Binary => "BYTEA".into(),
            ColumnType::Enum => "TEXT".into(),
            ColumnType::Point => geometry("POINT"),
            ColumnType::Linestring => geometry("LINESTRING"),
            ColumnType::Polygon => geometry("POLYGON"),
            ColumnType::Uuid => "UUID".into(),
            ColumnType::Uuid7 => "VARCHAR(36)".into(),
            ColumnType::Vector => format!("VECTOR({})", c.dimensions.unwrap_or(0)),
            ColumnType::Serial => "SERIAL".into(),
            ColumnType::BigSerial => "BIGSERIAL".into(),
            ColumnType::SmallSerial => "SMALLSERIAL".into(),
            ColumnType::Array | ColumnType::Tuple => {
                return Err(Error::Unsupported("Array/Tuple column types are not supported in PostgreSQL.".into()));
            }
        })
    }

    fn compile_auto_increment(&self) -> &'static str {
        "GENERATED BY DEFAULT AS IDENTITY"
    }

    fn quote_collation(&self, collation: &str) -> Result<String> {
        self.quote(collation)
    }

    fn compile_unsigned(&self) -> &'static str {
        ""
    }

    /// Identity instead of `AUTO_INCREMENT` (none for `SERIAL` types), enums
    /// as a `CHECK (... IN (...))`, no inline comment.
    fn compile_column_definition(&self, column: &Column<Self>) -> Result<String> {
        let c = &column.def;
        let mut parts = vec![self.quote_literal(&c.name)?, self.compile_column_type(column)?];
        if let Some(collation) = &c.collation {
            parts.push(format!("COLLATE {}", self.quote_collation(collation)?));
        }
        if c.unsigned {
            let unsigned = self.compile_unsigned();
            if !unsigned.is_empty() {
                parts.push(unsigned.into());
            }
        }
        if c.generated_expression.is_some() {
            parts.push(self.compile_generated_clause(column));
            parts.push(if c.nullable { "NULL" } else { "NOT NULL" }.into());
            if let Some(check) = &c.check {
                parts.push(format!("CHECK ({check})"));
            }
            return Ok(parts.join(" "));
        }
        let serial = matches!(c.kind, ColumnType::Serial | ColumnType::BigSerial | ColumnType::SmallSerial);
        if c.auto_increment && !serial {
            parts.push(self.compile_auto_increment().into());
        }
        parts.push(if c.nullable { "NULL" } else { "NOT NULL" }.into());
        if let Some(raw) = &c.default_raw {
            parts.push(format!("DEFAULT {raw}"));
        } else if let Some(value) = &c.default {
            parts.push(format!("DEFAULT {}", base::default_value(value)));
        }
        if c.kind == ColumnType::Enum && !c.enum_values.is_empty() {
            let values: Vec<String> = c.enum_values.iter().map(|v| names::literal(v)).collect();
            parts.push(format!("CHECK ({} IN ({}))", self.quote_literal(&c.name)?, values.join(", ")));
        }
        if let Some(check) = &c.check {
            parts.push(format!("CHECK ({check})"));
        }
        Ok(parts.join(" "))
    }

    /// Always `STORED`: PostgreSQL has no virtual generated columns.
    fn compile_generated_clause(&self, column: &Column<Self>) -> String {
        format!("GENERATED ALWAYS AS ({}) STORED", column.def.generated_expression.as_deref().unwrap_or_default())
    }

    fn compile_create_suffix(&self, table: &Table<Self>) -> String {
        feature::Partitioning::compile_create_partitioning(self, table)
    }

    /// Column changes in one `ALTER TABLE`, indexes as separate `CREATE` /
    /// `DROP INDEX` statements, joined by `; `.
    fn compile_alter(&self, table: &Table<Self>) -> Result<Statement> {
        let t = table.definition();
        let mut alterations = Vec::new();
        for column in &t.columns {
            if column.def.modify {
                alterations.push(format!(
                    "ALTER COLUMN {} TYPE {}",
                    self.quote_literal(&column.def.name)?,
                    self.compile_column_type(column)?
                ));
            } else {
                alterations.push(format!("ADD COLUMN {}", self.compile_column_definition(column)?));
            }
        }
        for rename in &t.rename_columns {
            alterations.push(format!(
                "RENAME COLUMN {} TO {}",
                self.quote_literal(&rename.from)?,
                self.quote_literal(&rename.to)?
            ));
        }
        for column in &t.drop_columns {
            alterations.push(format!("DROP COLUMN {}", self.quote_literal(column)?));
        }
        for key in &t.foreign_keys {
            alterations.push(format!("ADD {}", base::foreign_key_clause(self, key)?));
        }
        for name in &t.drop_foreign_keys {
            alterations.push(format!("DROP CONSTRAINT {}", self.quote(name)?));
        }
        let mut statements = Vec::new();
        if !alterations.is_empty() {
            statements.push(format!("ALTER TABLE {} {}", self.quote(&t.name)?, alterations.join(", ")));
        }
        for index in &t.indexes {
            let keyword = if index.kind == IndexType::Unique { "CREATE UNIQUE INDEX" } else { "CREATE INDEX" };
            let mut sql = format!("{keyword} {} ON {}", self.quote(&index.name)?, self.quote(&t.name)?);
            if !index.method.is_empty() {
                sql.push_str(" USING ");
                sql.push_str(&index.method.to_ascii_uppercase());
            }
            sql.push_str(&format!(" ({})", self.compile_index_columns(index)?));
            statements.push(sql);
        }
        for name in &t.drop_indexes {
            statements.push(format!("DROP INDEX {}", self.quote(name)?));
        }
        base::plain(self, statements.join("; "))
    }

    fn compile_rename(&self, from: &str, to: &str) -> Result<Statement> {
        base::plain(self, format!("ALTER TABLE {} RENAME TO {}", self.quote(from)?, self.quote(to)?))
    }

    /// `CREATE [UNIQUE] INDEX [CONCURRENTLY] name ON table [USING method] (...)`.
    fn create_index(&self, table: &str, name: &str, columns: &[&str], options: &IndexOptions) -> Result<Statement> {
        if !options.method.is_empty() && !names::is_word(&options.method) {
            return Err(Error::Validation(format!("Invalid index method: {}", options.method)));
        }
        if !options.operator_class.is_empty() && !names::is_dotted_word(&options.operator_class) {
            return Err(Error::Validation(format!("Invalid operator class: {}", options.operator_class)));
        }
        let mut sql = String::from(if options.unique { "CREATE UNIQUE INDEX" } else { "CREATE INDEX" });
        if options.concurrently {
            sql.push_str(" CONCURRENTLY");
        }
        sql.push_str(&format!(" {} ON {}", self.quote(name)?, self.quote(table)?));
        if !options.method.is_empty() {
            sql.push_str(" USING ");
            sql.push_str(&options.method.to_ascii_uppercase());
        }
        let index = options.index(name, columns)?;
        sql.push_str(&format!(" ({})", self.compile_index_columns(&index)?));
        base::plain(self, sql)
    }

    fn drop_index(&self, _table: &str, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP INDEX {}", self.quote(name)?))
    }
}

impl column::AutoIncrement for PostgreSql {}
impl column::Collation for PostgreSql {}
impl column::Dimensions for PostgreSql {}
impl column::Generated for PostgreSql {}
impl column::Srid for PostgreSql {}
impl column::Unique for PostgreSql {}
impl table::Serial for PostgreSql {}
impl table::Checks for PostgreSql {}
impl table::ColumnAlterations for PostgreSql {}
impl table::CompositePrimary for PostgreSql {}
impl table::InlineForeignKey for PostgreSql {}
impl table::ForeignKeys for PostgreSql {}
impl table::FulltextSpatialIndex for PostgreSql {}
impl table::StandardPartitioning for PostgreSql {}

impl feature::ForeignKeys for PostgreSql {
    fn add_foreign_key(&self, table: &str, name: &str, key: &ForeignKey) -> Result<Statement> {
        base::add_foreign_key(self, table, name, key)
    }

    fn drop_foreign_key(&self, table: &str, name: &str) -> Result<Statement> {
        base::plain(self, format!("ALTER TABLE {} DROP CONSTRAINT {}", self.quote(table)?, self.quote(name)?))
    }
}

impl feature::Procedures for PostgreSql {
    /// A PL/pgSQL function returning `VOID`; the body must not contain `$$`.
    fn create_procedure(
        &self,
        name: &str,
        params: &[(ParameterDirection, &str, &str)],
        body: &str,
    ) -> Result<Statement> {
        dollar_quoted(body)?;
        let params = base::procedure_params(self, params)?;
        base::plain(
            self,
            format!(
                "CREATE FUNCTION {}({}) RETURNS VOID LANGUAGE plpgsql AS $$ BEGIN {body} END; $$",
                self.quote(name)?,
                params.join(", ")
            ),
        )
    }

    fn drop_procedure(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP FUNCTION {}()", self.quote(name)?))
    }
}

impl feature::Triggers for PostgreSql {
    /// A trigger function `<name>_func` and the trigger executing it; the
    /// body must not contain `$$`.
    fn create_trigger(
        &self,
        name: &str,
        table: &str,
        timing: TriggerTiming,
        event: TriggerEvent,
        body: &str,
    ) -> Result<Statement> {
        dollar_quoted(body)?;
        let function = self.quote(&format!("{name}_func"))?;
        base::plain(
            self,
            format!(
                "CREATE FUNCTION {function}() RETURNS TRIGGER LANGUAGE plpgsql AS $$ BEGIN {body} RETURN NEW; END; $$; \
                 CREATE TRIGGER {} {} {} ON {} FOR EACH ROW EXECUTE FUNCTION {function}()",
                self.quote(name)?,
                timing.as_str(),
                event.as_str(),
                self.quote(table)?
            ),
        )
    }

    /// Drops the trigger and its function; the table is required.
    fn drop_trigger(&self, name: &str, table: Option<&str>) -> Result<Statement> {
        let Some(table) = table else {
            return Err(Error::Validation("PostgreSQL dropTrigger() requires the table name.".into()));
        };
        base::plain(
            self,
            format!(
                "DROP TRIGGER {} ON {}; DROP FUNCTION {}()",
                self.quote(name)?,
                self.quote(table)?,
                self.quote(&format!("{name}_func"))?
            ),
        )
    }
}

impl feature::Types for PostgreSql {
    fn create_type(&self, name: &str, values: &[&str]) -> Result<Statement> {
        let values: Vec<String> = values.iter().map(|v| names::literal(v)).collect();
        base::plain(self, format!("CREATE TYPE {} AS ENUM ({})", self.quote(name)?, values.join(", ")))
    }

    fn drop_type(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP TYPE {}", self.quote(name)?))
    }
}

impl feature::Sequences for PostgreSql {
    fn create_sequence(&self, name: &str, start: i64, increment_by: i64) -> Result<Statement> {
        base::plain(self, format!("CREATE SEQUENCE {} START {start} INCREMENT BY {increment_by}", self.quote(name)?))
    }

    fn drop_sequence(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP SEQUENCE {}", self.quote(name)?))
    }

    fn next_val(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("SELECT nextval({})", names::literal(name)))
    }
}

impl feature::TableComments for PostgreSql {
    fn comment_on_table(&self, table: &str, comment: &str) -> Result<Statement> {
        base::plain(self, format!("COMMENT ON TABLE {} IS {}", self.quote(table)?, names::literal(comment)))
    }
}

impl feature::ColumnComments for PostgreSql {
    fn comment_on_column(&self, table: &str, column: &str, comment: &str) -> Result<Statement> {
        base::plain(
            self,
            format!(
                "COMMENT ON COLUMN {}.{} IS {}",
                self.quote(table)?,
                self.quote_literal(column)?,
                names::literal(comment)
            ),
        )
    }
}

impl feature::CreatePartition for PostgreSql {
    /// `CREATE TABLE name PARTITION OF parent FOR VALUES expression`; the
    /// expression has no `;`, `--` or `/*`.
    fn create_partition(&self, parent: &str, name: &str, expression: &str) -> Result<Statement> {
        safe_expression(expression, "partition expression")?;
        base::plain(
            self,
            format!("CREATE TABLE {} PARTITION OF {} FOR VALUES {expression}", self.quote(name)?, self.quote(parent)?),
        )
    }
}

impl feature::DropPartition for PostgreSql {
    fn drop_partition(&self, _table: &str, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP TABLE {}", self.quote(name)?))
    }
}

impl feature::Views for PostgreSql {
    fn create_view(&self, name: &str, query: &mut dyn AnyBuilder) -> Result<Statement> {
        base::create_view(self, "CREATE VIEW", name, query)
    }

    fn drop_view(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP VIEW {}", self.quote(name)?))
    }
}

impl feature::ReplaceView for PostgreSql {
    fn create_or_replace_view(&self, name: &str, query: &mut dyn AnyBuilder) -> Result<Statement> {
        base::create_view(self, "CREATE OR REPLACE VIEW", name, query)
    }
}

impl feature::Databases for PostgreSql {
    /// `CREATE SCHEMA name`: PostgreSQL namespaces with schemas.
    fn create_database(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("CREATE SCHEMA {}", self.quote(name)?))
    }

    fn drop_database(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP SCHEMA IF EXISTS {} CASCADE", self.quote(name)?))
    }
}

impl feature::RenameIndex for PostgreSql {
    fn rename_index(&self, _table: &str, from: &str, to: &str) -> Result<Statement> {
        base::plain(self, format!("ALTER INDEX {} RENAME TO {}", self.quote(from)?, self.quote(to)?))
    }
}

impl feature::AnalyzeTable for PostgreSql {
    fn analyze_table(&self, table: &str) -> Result<Statement> {
        base::plain(self, format!("ANALYZE {}", self.quote(table)?))
    }
}

impl feature::Partitioning for PostgreSql {
    /// `PARTITION BY kind(expression)`, without a partition count.
    fn compile_create_partitioning(&self, table: &Table<Self>) -> String {
        base::partitioning(table, false)
    }
}
