//! [`Table`]: a fluent table blueprint, compiled by its schema into
//! `CREATE TABLE`, `ALTER TABLE` and the other table statements; and
//! [`ForeignKey`], a foreign key declared on it.
//!
//! Factories and alterations every dialect has are inherent methods; the
//! others are gated by marker traits the dialects implement ([`Serial`],
//! [`Checks`], [`InlineForeignKey`], ...), as PHP's dialect table classes
//! each use only the traits they support.

use std::fmt;

use super::column::{Column, expression};
use super::enums::{ColumnType, Engine, ForeignKeyAction, IndexType, PartitionType};
use super::index::{CheckConstraint, Index, RenameColumn};
use super::{ClickHouse, MongoDb, PostgreSql, Schema, names};
use crate::builder::Statement;
use crate::error::{Error, Result};
use crate::value::Value;

/// A foreign key: `FOREIGN KEY (column) REFERENCES table (column)` with
/// optional `ON DELETE` / `ON UPDATE` actions.
///
/// PHP: `Utopia\Query\Schema\ForeignKey` and its dialect classes.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ForeignKey {
    /// The local column.
    pub column: String,
    /// The referenced table.
    pub ref_table: String,
    /// The referenced column.
    pub ref_column: String,
    /// The action on delete.
    pub on_delete: Option<ForeignKeyAction>,
    /// The action on update.
    pub on_update: Option<ForeignKeyAction>,
}

impl ForeignKey {
    /// A foreign key on `column`, referencing nothing yet.
    ///
    /// PHP: `new ForeignKey($table, $column)`.
    pub fn new(column: impl Into<String>) -> Self {
        ForeignKey {
            column: column.into(),
            ref_table: String::new(),
            ref_column: String::new(),
            on_delete: None,
            on_update: None,
        }
    }

    /// Sets the referenced column.
    ///
    /// PHP: `references()`.
    pub fn references(&mut self, column: impl Into<String>) -> &mut Self {
        self.ref_column = column.into();
        self
    }

    /// Sets the referenced table.
    ///
    /// PHP: `on()`.
    pub fn on(&mut self, table: impl Into<String>) -> &mut Self {
        self.ref_table = table.into();
        self
    }

    /// Sets the action on delete.
    ///
    /// PHP: `onDelete()`.
    pub fn on_delete(&mut self, action: ForeignKeyAction) -> &mut Self {
        self.on_delete = Some(action);
        self
    }

    /// Sets the action on update.
    ///
    /// PHP: `onUpdate()`.
    pub fn on_update(&mut self, action: ForeignKeyAction) -> &mut Self {
        self.on_update = Some(action);
        self
    }
}

/// Everything a table blueprint holds: added columns, indexes, keys, and
/// the alterations, partitioning and engine options set on it.
///
/// PHP: the public properties of `Utopia\Query\Schema\Table`.
pub struct TableDefinition<S> {
    /// The table name.
    pub name: String,
    /// The columns to add (or modify).
    pub columns: Vec<Column<S>>,
    /// The indexes to add.
    pub indexes: Vec<Index>,
    /// The foreign keys to add.
    pub foreign_keys: Vec<ForeignKey>,
    /// The columns to drop.
    pub drop_columns: Vec<String>,
    /// The columns to rename.
    pub rename_columns: Vec<RenameColumn>,
    /// The indexes to drop.
    pub drop_indexes: Vec<String>,
    /// The foreign keys to drop.
    pub drop_foreign_keys: Vec<String>,
    /// Raw column definitions.
    pub raw_column_defs: Vec<String>,
    /// Raw index definitions.
    pub raw_index_defs: Vec<String>,
    /// Named `CHECK` constraints.
    pub checks: Vec<CheckConstraint>,
    /// The columns of a composite primary key.
    pub composite_primary_key: Vec<String>,
    /// The ClickHouse `ORDER BY` columns.
    pub order_by: Vec<String>,
    /// How the table is partitioned.
    pub partition_type: Option<PartitionType>,
    /// The partition expression.
    pub partition_expression: String,
    /// The number of partitions (`PARTITIONS n`).
    pub partition_count: Option<i64>,
    /// The ClickHouse engine.
    pub engine: Option<Engine>,
    /// The engine's arguments.
    pub engine_args: Vec<String>,
    /// The ClickHouse table TTL.
    pub ttl: Option<String>,
    /// The ClickHouse `SETTINGS`, rendered.
    pub settings: Vec<(String, String)>,
    /// The ClickHouse `SAMPLE BY` expression.
    pub sample_by: Option<String>,
    /// A raw ClickHouse `ORDER BY` expression.
    pub order_by_raw: Option<String>,
}

impl<S> Clone for TableDefinition<S> {
    fn clone(&self) -> Self {
        TableDefinition {
            name: self.name.clone(),
            columns: self.columns.clone(),
            indexes: self.indexes.clone(),
            foreign_keys: self.foreign_keys.clone(),
            drop_columns: self.drop_columns.clone(),
            rename_columns: self.rename_columns.clone(),
            drop_indexes: self.drop_indexes.clone(),
            drop_foreign_keys: self.drop_foreign_keys.clone(),
            raw_column_defs: self.raw_column_defs.clone(),
            raw_index_defs: self.raw_index_defs.clone(),
            checks: self.checks.clone(),
            composite_primary_key: self.composite_primary_key.clone(),
            order_by: self.order_by.clone(),
            partition_type: self.partition_type,
            partition_expression: self.partition_expression.clone(),
            partition_count: self.partition_count,
            engine: self.engine,
            engine_args: self.engine_args.clone(),
            ttl: self.ttl.clone(),
            settings: self.settings.clone(),
            sample_by: self.sample_by.clone(),
            order_by_raw: self.order_by_raw.clone(),
        }
    }
}

impl<S> fmt::Debug for TableDefinition<S> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("TableDefinition")
            .field("name", &self.name)
            .field("columns", &self.columns)
            .field("indexes", &self.indexes)
            .field("foreign_keys", &self.foreign_keys)
            .finish_non_exhaustive()
    }
}

/// A table blueprint for the dialect `S`: columns, indexes, keys and
/// alterations, compiled by the schema it came from.
///
/// ```
/// use utopia_query::schema::{MySql, Schema};
///
/// let mut users = MySql::new().table("users");
/// users.id("id");
/// users.string("email", 255).unique();
/// users.foreign_key("team_id").references("id").on("teams");
/// assert_eq!(
///     users.create()?.query,
///     "CREATE TABLE `users` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `email` VARCHAR(255) NOT NULL, \
///      PRIMARY KEY (`id`), UNIQUE (`email`), FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`))"
/// );
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Schema\MySQL;
///
/// $users = (new MySQL())->table('users');
/// $users->id();
/// $users->string('email')->unique();
/// $users->foreignKey('team_id')->references('id')->on('teams');
/// echo $users->create()->query, "\n";
/// ```
///
/// PHP: `Utopia\Query\Schema\Table` and its dialect classes (`Table\MySQL`,
/// `Table\PostgreSQL`, `Table\SQLite`, `Table\ClickHouse`, `Table\MongoDB`).
pub struct Table<S> {
    schema: Option<S>,
    def: TableDefinition<S>,
}

impl<S: Clone> Clone for Table<S> {
    fn clone(&self) -> Self {
        Table { schema: self.schema.clone(), def: self.def.clone() }
    }
}

impl<S> fmt::Debug for Table<S> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        self.def.fmt(f)
    }
}

/// Dialects with `SERIAL` column factories.
///
/// PHP: `Table\Trait\Serial`.
pub trait Serial: Schema {}
/// Dialects with named table-level `CHECK` constraints (and column-level ones).
///
/// PHP: `Table\Trait\Checks`.
pub trait Checks: Schema {}
/// Dialects that drop and rename columns in `ALTER TABLE`.
///
/// PHP: `Table\Trait\ColumnAlterations`.
pub trait ColumnAlterations: Schema {}
/// Dialects with composite primary keys.
///
/// PHP: `Table\Trait\CompositePrimary`.
pub trait CompositePrimary: Schema {}
/// Dialects with inline foreign keys in `CREATE TABLE`.
///
/// PHP: `Table\Trait\InlineForeignKey`.
pub trait InlineForeignKey: Schema {}
/// Dialects that also add and drop foreign keys in `ALTER TABLE`.
///
/// PHP: `Table\Trait\ForeignKeys`.
pub trait ForeignKeys: InlineForeignKey {}
/// Dialects with full-text and spatial indexes.
///
/// PHP: `Table\Trait\FulltextSpatialIndex`.
pub trait FulltextSpatialIndex: Schema {}
/// Dialects with `PARTITION BY RANGE / LIST / HASH`.
///
/// PHP: `Table\Trait\StandardPartitioning`.
pub trait StandardPartitioning: Schema {}

impl<S> Table<S> {
    /// A blueprint of table `name` without a schema: it collects columns and
    /// alterations but cannot compile (its terminal methods fail).
    ///
    /// PHP: `new Table(null, $name)`.
    pub fn new(name: impl Into<String>) -> Self {
        Table {
            schema: None,
            def: TableDefinition {
                name: name.into(),
                columns: Vec::new(),
                indexes: Vec::new(),
                foreign_keys: Vec::new(),
                drop_columns: Vec::new(),
                rename_columns: Vec::new(),
                drop_indexes: Vec::new(),
                drop_foreign_keys: Vec::new(),
                raw_column_defs: Vec::new(),
                raw_index_defs: Vec::new(),
                checks: Vec::new(),
                composite_primary_key: Vec::new(),
                order_by: Vec::new(),
                partition_type: None,
                partition_expression: String::new(),
                partition_count: None,
                engine: None,
                engine_args: Vec::new(),
                ttl: None,
                settings: Vec::new(),
                sample_by: None,
                order_by_raw: None,
            },
        }
    }

    /// A blueprint of table `name` compiled by `schema` (what
    /// [`Schema::table`] returns).
    ///
    /// PHP: `new Table($schema, $name)`.
    pub fn with_schema(schema: S, name: impl Into<String>) -> Self {
        let mut table = Table::new(name);
        table.schema = Some(schema);
        table
    }

    /// Everything the blueprint holds.
    ///
    /// PHP: the table's public properties.
    pub fn definition(&self) -> &TableDefinition<S> {
        &self.def
    }

    /// The table name.
    ///
    /// PHP: `$table->name`.
    pub fn name(&self) -> &str {
        &self.def.name
    }

    /// The columns added so far.
    ///
    /// PHP: `$table->columns`.
    pub fn columns(&self) -> &[Column<S>] {
        &self.def.columns
    }

    /// The column at `index` (in the order they were added), to modify it later.
    pub fn column_mut(&mut self, index: usize) -> Option<&mut Column<S>> {
        self.def.columns.get_mut(index)
    }

    /// The foreign keys declared so far.
    ///
    /// PHP: `$table->foreignKeys`.
    pub fn foreign_keys(&self) -> &[ForeignKey] {
        &self.def.foreign_keys
    }

    /// The foreign key at `index` (in the order they were declared), to modify it later.
    pub fn foreign_key_mut(&mut self, index: usize) -> Option<&mut ForeignKey> {
        self.def.foreign_keys.get_mut(index)
    }

    fn push(&mut self, column: Column<S>) -> &mut Column<S> {
        self.def.columns.push(column);
        self.def.columns.last_mut().expect("just pushed")
    }

    fn add(&mut self, name: impl Into<String>, kind: ColumnType) -> &mut Column<S> {
        self.push(Column::new(name, kind))
    }

    /// Adds an unsigned auto-incrementing `BIGINT` primary key `name`
    /// (PHP's default name is `id`).
    ///
    /// PHP: `id()`.
    pub fn id(&mut self, name: impl Into<String>) -> &mut Column<S> {
        let column = Column::new(name, ColumnType::BigInteger).with_auto_increment(true);
        self.push(column).unsigned().primary()
    }

    /// Adds a string column of `length` characters (PHP's default is 255).
    ///
    /// PHP: `string()`.
    pub fn string(&mut self, name: impl Into<String>, length: i64) -> &mut Column<S> {
        self.push(Column::new(name, ColumnType::String).with_length(Some(length)))
    }

    /// Adds a text column.
    ///
    /// PHP: `text()`.
    pub fn text(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::Text)
    }

    /// Adds a medium text column.
    ///
    /// PHP: `mediumText()`.
    pub fn medium_text(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::MediumText)
    }

    /// Adds a long text column.
    ///
    /// PHP: `longText()`.
    pub fn long_text(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::LongText)
    }

    /// Adds an 8-bit integer column.
    ///
    /// PHP: `tinyInteger()`.
    pub fn tiny_integer(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::TinyInteger)
    }

    /// Adds a 16-bit integer column.
    ///
    /// PHP: `smallInteger()`.
    pub fn small_integer(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::SmallInteger)
    }

    /// Adds a 32-bit integer column.
    ///
    /// PHP: `integer()`.
    pub fn integer(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::Integer)
    }

    /// Adds a 64-bit integer column.
    ///
    /// PHP: `bigInteger()`.
    pub fn big_integer(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::BigInteger)
    }

    /// Adds a `DECIMAL(precision, scale)` column (PHP's defaults are 10 and
    /// 0); the precision is at least 1 and the scale between 0 and the
    /// precision.
    ///
    /// PHP: `decimal()`.
    pub fn decimal(&mut self, name: impl Into<String>, precision: i64, scale: i64) -> Result<&mut Column<S>> {
        if precision < 1 {
            return Err(Error::Validation("DECIMAL precision must be at least 1.".into()));
        }
        if scale < 0 {
            return Err(Error::Validation("DECIMAL scale must not be negative.".into()));
        }
        if scale > precision {
            return Err(Error::Validation("DECIMAL scale must not exceed precision.".into()));
        }
        Ok(self.push(Column::new(name, ColumnType::Decimal).with_precision(Some(precision)).with_scale(Some(scale))))
    }

    /// Adds a UUID column (native where the dialect has one).
    ///
    /// PHP: `uuid()`.
    pub fn uuid(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::Uuid)
    }

    /// Adds a double-precision float column.
    ///
    /// PHP: `float()`.
    pub fn float(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::Float)
    }

    /// Adds a boolean column.
    ///
    /// PHP: `boolean()`.
    pub fn boolean(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::Boolean)
    }

    /// Adds a date-time column with fractional-second `precision` (PHP's
    /// default is 0).
    ///
    /// PHP: `datetime()`.
    pub fn datetime(&mut self, name: impl Into<String>, precision: i64) -> &mut Column<S> {
        self.push(Column::new(name, ColumnType::Datetime).with_precision(Some(precision)))
    }

    /// Adds a timestamp column with fractional-second `precision` (PHP's
    /// default is 0).
    ///
    /// PHP: `timestamp()`.
    pub fn timestamp(&mut self, name: impl Into<String>, precision: i64) -> &mut Column<S> {
        self.push(Column::new(name, ColumnType::Timestamp).with_precision(Some(precision)))
    }

    /// Adds a JSON column.
    ///
    /// PHP: `json()`.
    pub fn json(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::Json)
    }

    /// Adds a binary column.
    ///
    /// PHP: `binary()`.
    pub fn binary(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.add(name, ColumnType::Binary)
    }

    /// Adds an enum column of `values` (one at least).
    ///
    /// PHP: `enum()`.
    pub fn enumeration<V: Into<String>>(
        &mut self,
        name: impl Into<String>,
        values: impl IntoIterator<Item = V>,
    ) -> Result<&mut Column<S>> {
        let values: Vec<String> = values.into_iter().map(Into::into).collect();
        if values.is_empty() {
            return Err(Error::Validation("enum() requires at least one allowed value.".into()));
        }
        let mut column = Column::new(name, ColumnType::Enum);
        column.def.enum_values = values;
        Ok(self.push(column))
    }

    /// Adds a point column with `srid` (PHP's default is 4326).
    ///
    /// PHP: `point()`.
    pub fn point(&mut self, name: impl Into<String>, srid: i64) -> &mut Column<S> {
        self.push(Column::new(name, ColumnType::Point).with_srid(Some(srid)))
    }

    /// Adds a line string column with `srid` (PHP's default is 4326).
    ///
    /// PHP: `linestring()`.
    pub fn linestring(&mut self, name: impl Into<String>, srid: i64) -> &mut Column<S> {
        self.push(Column::new(name, ColumnType::Linestring).with_srid(Some(srid)))
    }

    /// Adds a polygon column with `srid` (PHP's default is 4326).
    ///
    /// PHP: `polygon()`.
    pub fn polygon(&mut self, name: impl Into<String>, srid: i64) -> &mut Column<S> {
        self.push(Column::new(name, ColumnType::Polygon).with_srid(Some(srid)))
    }

    /// Adds `created_at` and `updated_at` date-times with `precision` (PHP's
    /// default is 3).
    ///
    /// PHP: `timestamps()`.
    pub fn timestamps(&mut self, precision: i64) -> &mut Self {
        self.datetime("created_at", precision);
        self.datetime("updated_at", precision);
        self
    }

    fn sized(name: impl Into<String>, kind: ColumnType, length_or_precision: Option<i64>) -> Column<S> {
        let string = kind == ColumnType::String;
        Column::new(name, kind).with_length(if string { length_or_precision } else { None }).with_precision(if string {
            None
        } else {
            length_or_precision
        })
    }

    /// Adds a column of any type; `length_or_precision` is the length of a
    /// string and the precision of anything else.
    ///
    /// PHP: `addColumn()`.
    pub fn add_column(
        &mut self,
        name: impl Into<String>,
        kind: ColumnType,
        length_or_precision: Option<i64>,
    ) -> &mut Column<S> {
        self.push(Self::sized(name, kind, length_or_precision))
    }

    /// Modifies a column (an `ALTER` emits `MODIFY` / `ALTER COLUMN`).
    ///
    /// PHP: `modifyColumn()`.
    pub fn modify_column(
        &mut self,
        name: impl Into<String>,
        kind: ColumnType,
        length_or_precision: Option<i64>,
    ) -> &mut Column<S> {
        self.push(Self::sized(name, kind, length_or_precision)).modify()
    }

    /// Adds an index over `columns`, named `idx_<columns>`.
    ///
    /// PHP: `index()` (the other options: [`Table::add_index`]).
    pub fn index<C: Into<String>>(&mut self, columns: impl IntoIterator<Item = C>) -> Result<&mut Self> {
        let columns: Vec<String> = columns.into_iter().map(Into::into).collect();
        let name = Index::auto_name("idx_", &columns);
        self.add_index(Index::new(name, columns, IndexType::Index))
    }

    /// Adds a unique index over `columns`, named `uniq_<columns>`.
    ///
    /// PHP: `uniqueIndex()`.
    pub fn unique_index<C: Into<String>>(&mut self, columns: impl IntoIterator<Item = C>) -> Result<&mut Self> {
        let columns: Vec<String> = columns.into_iter().map(Into::into).collect();
        let name = Index::auto_name("uniq_", &columns);
        self.add_index(Index::new(name, columns, IndexType::Unique))
    }

    /// Adds `index`, after [`Index::validated`].
    ///
    /// PHP: `addIndex()` (and the options of `index()` / `uniqueIndex()`).
    pub fn add_index(&mut self, index: Index) -> Result<&mut Self> {
        self.def.indexes.push(index.validated()?);
        Ok(self)
    }

    /// Drops the index `name` (in an `ALTER`).
    ///
    /// PHP: `dropIndex()`.
    pub fn drop_index(&mut self, name: impl Into<String>) -> &mut Self {
        self.def.drop_indexes.push(name.into());
        self
    }

    /// Adds a raw column definition, emitted verbatim.
    ///
    /// PHP: `rawColumn()`.
    pub fn raw_column(&mut self, definition: impl Into<String>) -> &mut Self {
        self.def.raw_column_defs.push(definition.into());
        self
    }

    /// Adds a raw index definition, emitted verbatim.
    ///
    /// PHP: `rawIndex()`.
    pub fn raw_index(&mut self, definition: impl Into<String>) -> &mut Self {
        self.def.raw_index_defs.push(definition.into());
        self
    }
}

impl<S: Schema> Table<S> {
    fn schema(&self) -> Result<&S> {
        self.schema.as_ref().ok_or_else(|| {
            Error::Validation(
                "Cannot compile a Table without a Schema. Use Schema::table($name) to obtain a builder.".into(),
            )
        })
    }

    /// `CREATE TABLE`.
    ///
    /// PHP: `create()`.
    pub fn create(&self) -> Result<Statement> {
        self.schema()?.compile_create(self, false)
    }

    /// `CREATE TABLE IF NOT EXISTS`.
    ///
    /// PHP: `createIfNotExists()` / `create(true)`.
    pub fn create_if_not_exists(&self) -> Result<Statement> {
        self.schema()?.compile_create(self, true)
    }

    /// `ALTER TABLE` with the columns, renames, drops, indexes and keys
    /// collected.
    ///
    /// PHP: `alter()`.
    pub fn alter(&self) -> Result<Statement> {
        self.schema()?.compile_alter(self)
    }

    /// `DROP TABLE`.
    ///
    /// PHP: `drop()`.
    pub fn drop(&self) -> Result<Statement> {
        self.schema()?.compile_drop(&self.def.name, false)
    }

    /// `DROP TABLE IF EXISTS`.
    ///
    /// PHP: `dropIfExists()`.
    pub fn drop_if_exists(&self) -> Result<Statement> {
        self.schema()?.compile_drop(&self.def.name, true)
    }

    /// Empties the table.
    ///
    /// PHP: `truncate()`.
    pub fn truncate(&self) -> Result<Statement> {
        self.schema()?.compile_truncate(&self.def.name)
    }

    /// Renames the table to `to`.
    ///
    /// PHP: `rename()`.
    pub fn rename(&self, to: &str) -> Result<Statement> {
        self.schema()?.compile_rename(&self.def.name, to)
    }
}

impl<S: Serial> Table<S> {
    /// Adds an auto-incrementing integer column.
    ///
    /// PHP: `serial()`.
    pub fn serial(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.push(Column::new(name, ColumnType::Serial).with_auto_increment(true))
    }

    /// Adds an auto-incrementing big integer column.
    ///
    /// PHP: `bigSerial()`.
    pub fn big_serial(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.push(Column::new(name, ColumnType::BigSerial).with_auto_increment(true))
    }

    /// Adds an auto-incrementing small integer column.
    ///
    /// PHP: `smallSerial()`.
    pub fn small_serial(&mut self, name: impl Into<String>) -> &mut Column<S> {
        self.push(Column::new(name, ColumnType::SmallSerial).with_auto_increment(true))
    }
}

impl<S: Checks> Table<S> {
    /// Adds a named `CHECK` constraint (`name` is an identifier).
    ///
    /// PHP: `check()` with two arguments.
    pub fn check(&mut self, name: impl Into<String>, expression: impl Into<String>) -> Result<&mut Self> {
        self.def.checks.push(CheckConstraint::new(name, expression)?);
        Ok(self)
    }
}

impl<S: ColumnAlterations> Table<S> {
    /// Renames the column `from` to `to` (in an `ALTER`).
    ///
    /// PHP: `renameColumn()`.
    pub fn rename_column(&mut self, from: impl Into<String>, to: impl Into<String>) -> &mut Self {
        self.def.rename_columns.push(RenameColumn::new(from, to));
        self
    }

    /// Drops the column `name` (in an `ALTER`).
    ///
    /// PHP: `dropColumn()`.
    pub fn drop_column(&mut self, name: impl Into<String>) -> &mut Self {
        self.def.drop_columns.push(name.into());
        self
    }
}

impl<S: CompositePrimary> Table<S> {
    /// Declares a primary key over two `columns` or more, each an identifier
    /// (a single-column key is [`Column::primary`]).
    ///
    /// PHP: `primary()` with an array.
    pub fn primary<C: Into<String>>(&mut self, columns: impl IntoIterator<Item = C>) -> Result<&mut Self> {
        let columns: Vec<String> = columns.into_iter().map(Into::into).collect();
        if columns.len() < 2 {
            return Err(Error::Validation(
                "Table::primary(array) requires at least two columns; use Column::primary() for single-column keys."
                    .into(),
            ));
        }
        if let Some(bad) = columns.iter().find(|c| !names::is_identifier(c)) {
            return Err(Error::Validation(format!("Invalid column name in composite primary key: {bad}")));
        }
        self.def.composite_primary_key = columns;
        Ok(self)
    }
}

impl<S: InlineForeignKey> Table<S> {
    /// Declares a foreign key on `column`.
    ///
    /// PHP: `foreignKey()`.
    pub fn foreign_key(&mut self, column: impl Into<String>) -> &mut ForeignKey {
        self.def.foreign_keys.push(ForeignKey::new(column));
        self.def.foreign_keys.last_mut().expect("just pushed")
    }
}

impl<S: ForeignKeys> Table<S> {
    /// Declares a foreign key to add in an `ALTER` (the same as
    /// [`Table::foreign_key`]).
    ///
    /// PHP: `addForeignKey()`.
    pub fn add_foreign_key(&mut self, column: impl Into<String>) -> &mut ForeignKey {
        self.foreign_key(column)
    }

    /// Drops the foreign key `name` (in an `ALTER`).
    ///
    /// PHP: `dropForeignKey()`.
    pub fn drop_foreign_key(&mut self, name: impl Into<String>) -> &mut Self {
        self.def.drop_foreign_keys.push(name.into());
        self
    }
}

impl<S: FulltextSpatialIndex> Table<S> {
    /// Adds a full-text index over `columns`, named `ft_<columns>`.
    ///
    /// PHP: `fulltextIndex()`.
    pub fn fulltext_index<C: Into<String>>(&mut self, columns: impl IntoIterator<Item = C>) -> Result<&mut Self> {
        let columns: Vec<String> = columns.into_iter().map(Into::into).collect();
        let name = Index::auto_name("ft_", &columns);
        self.add_index(Index::new(name, columns, IndexType::Fulltext))
    }

    /// Adds a spatial index over `columns`, named `sp_<columns>`.
    ///
    /// PHP: `spatialIndex()`.
    pub fn spatial_index<C: Into<String>>(&mut self, columns: impl IntoIterator<Item = C>) -> Result<&mut Self> {
        let columns: Vec<String> = columns.into_iter().map(Into::into).collect();
        let name = Index::auto_name("sp_", &columns);
        self.add_index(Index::new(name, columns, IndexType::Spatial))
    }
}

impl<S: StandardPartitioning> Table<S> {
    /// Partitions by range of `expression`.
    ///
    /// PHP: `partitionByRange()`.
    pub fn partition_by_range(&mut self, expression: impl Into<String>) -> &mut Self {
        self.partition(PartitionType::Range, expression.into(), None)
    }

    /// Partitions by list of `expression`.
    ///
    /// PHP: `partitionByList()`.
    pub fn partition_by_list(&mut self, expression: impl Into<String>) -> &mut Self {
        self.partition(PartitionType::List, expression.into(), None)
    }

    /// Partitions by hash of `expression`, into `partitions` (at least 1) when given.
    ///
    /// PHP: `partitionByHash()`.
    pub fn partition_by_hash(&mut self, expression: impl Into<String>, partitions: Option<i64>) -> Result<&mut Self> {
        if let Some(count) = partitions
            && count < 1
        {
            return Err(Error::Validation("Partition count must be at least 1.".into()));
        }
        Ok(self.partition(PartitionType::Hash, expression.into(), partitions))
    }

    fn partition(&mut self, kind: PartitionType, expression: String, count: Option<i64>) -> &mut Self {
        self.def.partition_type = Some(kind);
        self.def.partition_expression = expression;
        self.def.partition_count = count;
        self
    }
}

impl Table<PostgreSql> {
    /// Adds a `VECTOR(dimensions)` column (pgvector).
    ///
    /// PHP: `Table\PostgreSQL::vector()`.
    pub fn vector(&mut self, name: impl Into<String>, dimensions: i64) -> &mut Column<PostgreSql> {
        self.push(Column::new(name, ColumnType::Vector).with_dimensions(Some(dimensions)))
    }
}

impl Table<MongoDb> {
    /// Adds a vector (array) field.
    ///
    /// PHP: `Table\MongoDB::vector()`.
    pub fn vector(&mut self, name: impl Into<String>) -> &mut Column<MongoDb> {
        self.add(name, ColumnType::Vector)
    }
}

impl Table<ClickHouse> {
    /// Adds an `Array(Float64)` vector column.
    ///
    /// PHP: `Table\ClickHouse::vector()`.
    pub fn vector(&mut self, name: impl Into<String>) -> &mut Column<ClickHouse> {
        self.add(name, ColumnType::Vector)
    }

    /// Adds an `Array(element)` column.
    ///
    /// PHP: `array()`.
    pub fn array(&mut self, name: impl Into<String>, element: ColumnType) -> &mut Column<ClickHouse> {
        self.add(name, ColumnType::Array).as_array(element)
    }

    /// Adds a `Tuple(elements...)` column (one element at least).
    ///
    /// PHP: `tuple()`.
    pub fn tuple(&mut self, name: impl Into<String>, elements: Vec<ColumnType>) -> Result<&mut Column<ClickHouse>> {
        let mut column = Column::new(name, ColumnType::Tuple);
        column.as_tuple(elements)?;
        Ok(self.push(column))
    }

    /// Adds a `FixedString(length)` column (length at least 1).
    ///
    /// PHP: `fixedString()`.
    pub fn fixed_string(&mut self, name: impl Into<String>, length: i64) -> Result<&mut Column<ClickHouse>> {
        let mut column = Column::new(name, ColumnType::String).with_length(Some(length));
        column.as_fixed_string(length)?;
        Ok(self.push(column))
    }

    /// Selects the engine; `CollapsingMergeTree` needs a sign column and
    /// `ReplicatedMergeTree` a ZooKeeper path and a replica name.
    ///
    /// PHP: `engine()`.
    pub fn engine(&mut self, engine: Engine, args: &[&str]) -> Result<&mut Self> {
        let args: Vec<String> = args.iter().map(|a| (*a).to_owned()).collect();
        if engine == Engine::CollapsingMergeTree && args.is_empty() {
            return Err(Error::Validation("CollapsingMergeTree requires a sign column.".into()));
        }
        if engine == Engine::ReplicatedMergeTree && args.len() < 2 {
            return Err(Error::Validation("ReplicatedMergeTree requires zookeeper_path and replica_name.".into()));
        }
        self.def.engine = Some(engine);
        self.def.engine_args = args;
        Ok(self)
    }

    /// Sets the `ORDER BY` columns (identifiers); without one, the primary
    /// key orders the table.
    ///
    /// PHP: `orderBy()`.
    pub fn order_by<C: Into<String>>(&mut self, columns: impl IntoIterator<Item = C>) -> Result<&mut Self> {
        let columns: Vec<String> = columns.into_iter().map(Into::into).collect();
        if let Some(bad) = columns.iter().find(|c| !names::is_identifier(c)) {
            return Err(Error::Validation(format!("Invalid column name in ORDER BY: {bad}")));
        }
        self.def.order_by = columns;
        Ok(self)
    }

    /// Sets a raw `ORDER BY` expression (trimmed, not empty, no `;`), which
    /// takes precedence over [`Table::order_by`].
    ///
    /// PHP: `orderByRaw()`.
    pub fn order_by_raw(&mut self, expression: &str) -> Result<&mut Self> {
        self.def.order_by_raw = Some(self::expression(
            expression,
            "Raw ORDER BY expression must not be empty.",
            "Raw ORDER BY expression must not contain \";\".",
        )?);
        Ok(self)
    }

    /// Sets the table TTL (trimmed, not empty, no `;`).
    ///
    /// PHP: `Table\ClickHouse::ttl()`.
    pub fn ttl(&mut self, expression: &str) -> Result<&mut Self> {
        self.def.ttl = Some(self::expression(
            expression,
            "TTL expression must not be empty.",
            "TTL expression must not contain \";\".",
        )?);
        Ok(self)
    }

    /// Replaces the engine `SETTINGS`: names are identifiers; booleans
    /// become `1` / `0`, numbers are printed, strings must match
    /// `[A-Za-z0-9_.\-+/]+`.
    ///
    /// PHP: `settings()`.
    pub fn settings<K: Into<String>>(&mut self, settings: impl IntoIterator<Item = (K, Value)>) -> Result<&mut Self> {
        let mut sanitized: Vec<(String, String)> = Vec::new();
        for (key, value) in settings {
            let key = key.into();
            if !names::is_identifier(&key) {
                return Err(Error::Validation(format!("Invalid setting name: {key}")));
            }
            let rendered = match &value {
                Value::Bool(b) => if *b { "1" } else { "0" }.to_owned(),
                Value::Int(i) => i.to_string(),
                Value::Float(f) => names::fixed(*f),
                Value::Str(s) => {
                    let s = String::from_utf8_lossy(s.as_bytes()).into_owned();
                    if !names::is_setting_value(&s) {
                        return Err(Error::Validation(format!(
                            "Invalid setting value for {key}: must match [A-Za-z0-9_.\\-+/]+"
                        )));
                    }
                    s
                }
                _ => {
                    return Err(Error::Validation(format!(
                        "Setting value for {key} must be string, int, float, or bool."
                    )));
                }
            };
            match sanitized.iter_mut().find(|(k, _)| *k == key) {
                Some(entry) => entry.1 = rendered,
                None => sanitized.push((key, rendered)),
            }
        }
        self.def.settings = sanitized;
        Ok(self)
    }

    /// Sets the `PARTITION BY` expression.
    ///
    /// PHP: `partitionBy()`.
    pub fn partition_by(&mut self, expression: impl Into<String>) -> &mut Self {
        self.def.partition_expression = expression.into();
        self
    }

    /// Sets the `SAMPLE BY` expression (trimmed, not empty, no `;`).
    ///
    /// PHP: `sampleBy()`.
    pub fn sample_by(&mut self, expression: &str) -> Result<&mut Self> {
        self.def.sample_by = Some(self::expression(
            expression,
            "SAMPLE BY expression must not be empty.",
            "SAMPLE BY expression must not contain \";\".",
        )?);
        Ok(self)
    }
}
