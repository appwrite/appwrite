//! [`ClickHouse`]: the ClickHouse schema.

use super::column::{self, Column};
use super::enums::{ColumnType, Engine, IndexType};
use super::feature::{self, ViewBody};
use super::index::Index;
use super::table::{self, Table};
use super::{Schema, base, names};
use crate::builder::{AnyBuilder, Executor, Statement};
use crate::error::{Error, Result};
use crate::value::Value;

/// The ClickHouse schema: backtick quoting, `Nullable(...)` /
/// `LowCardinality(...)` wrapping, engines with `ORDER BY`, `PARTITION BY`,
/// `SAMPLE BY`, TTL and `SETTINGS`, data-skipping indexes, codecs and
/// materialized views.
///
/// ```
/// use utopia_query::schema::{ClickHouse, Engine, Schema};
///
/// let mut table = ClickHouse::new().table("events");
/// table.big_integer("id").unsigned().primary();
/// table.string("country", 255).low_cardinality();
/// table.datetime("at", 3);
/// table.engine(Engine::MergeTree, &[])?.partition_by("toYYYYMM(at)");
/// assert_eq!(
///     table.create()?.query,
///     "CREATE TABLE `events` (`id` UInt64, `country` LowCardinality(String), `at` DateTime64(3)) \
///      ENGINE = MergeTree() PARTITION BY toYYYYMM(at) ORDER BY (`id`)"
/// );
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Schema\ClickHouse;
/// use Utopia\Query\Schema\ClickHouse\Engine;
///
/// $table = (new ClickHouse())->table('events');
/// $table->bigInteger('id')->unsigned()->primary();
/// $table->string('country')->lowCardinality();
/// $table->datetime('at', 3);
/// $table->engine(Engine::MergeTree)->partitionBy('toYYYYMM(at)');
/// echo $table->create()->query, "\n";
/// ```
///
/// PHP: `Utopia\Query\Schema\ClickHouse`.
#[derive(Clone, Default)]
pub struct ClickHouse {
    executor: Option<Executor>,
}

impl std::fmt::Debug for ClickHouse {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ClickHouse").field("executor", &self.executor.is_some()).finish()
    }
}

impl ClickHouse {
    /// A ClickHouse schema without an executor.
    ///
    /// PHP: `new ClickHouse()`.
    pub fn new() -> Self {
        ClickHouse::default()
    }

    /// `INDEX name expr TYPE algorithm[(args)] GRANULARITY n`; without an
    /// algorithm, `TYPE minmax GRANULARITY 3`.
    fn skip_index(&self, index: &Index) -> Result<String> {
        let columns = index.columns.iter().map(|c| self.quote_literal(c)).collect::<Result<Vec<_>>>()?;
        let expr = if columns.len() == 1 { columns[0].clone() } else { format!("({})", columns.join(", ")) };
        let Some(algorithm) = index.algorithm else {
            return Ok(format!(
                "INDEX {} {expr} TYPE minmax GRANULARITY {}",
                self.quote(&index.name)?,
                index.granularity.unwrap_or(3)
            ));
        };
        let mut kind = algorithm.as_str().to_owned();
        if !index.algorithm_args.is_empty() {
            let args: Vec<String> = index
                .algorithm_args
                .iter()
                .map(|arg| match arg {
                    Value::Str(s) => format!("'{}'", String::from_utf8_lossy(s.as_bytes()).replace('\'', "''")),
                    Value::Float(f) => names::fixed(*f),
                    other => crate::value::to_php_string(other),
                })
                .collect();
            kind.push_str(&format!("({})", args.join(", ")));
        }
        Ok(format!(
            "INDEX {} {expr} TYPE {kind} GRANULARITY {}",
            self.quote(&index.name)?,
            index.granularity.unwrap_or(1)
        ))
    }

    /// The engine declaration: `Name()` or `Name(args...)`.
    fn engine(&self, engine: Engine, args: &[String]) -> Result<String> {
        let name = engine.as_str();
        Ok(match engine {
            Engine::MergeTree | Engine::AggregatingMergeTree => format!("{name}()"),
            Engine::ReplacingMergeTree => match args.first() {
                Some(version) => format!("{name}({})", self.quote_literal(version)?),
                None => format!("{name}()"),
            },
            Engine::SummingMergeTree => {
                let columns = args.iter().map(|c| self.quote_literal(c)).collect::<Result<Vec<_>>>()?;
                format!("{name}({})", columns.join(", "))
            }
            Engine::CollapsingMergeTree => {
                format!("{name}({})", self.quote_literal(args.first().map(String::as_str).unwrap_or_default())?)
            }
            Engine::ReplicatedMergeTree => {
                let at = |i: usize| args.get(i).map(|a| a.replace('\'', "''")).unwrap_or_default();
                format!("{name}('{}', '{}')", at(0), at(1))
            }
            Engine::Memory | Engine::Log | Engine::TinyLog | Engine::StripeLog => name.to_owned(),
        })
    }

    /// An element type inside `Array(...)` / `Tuple(...)`, sized by the parent column.
    fn element_type(element: ColumnType, parent: &Column<Self>) -> Result<String> {
        let p = &parent.def;
        let int = |signed: &str, unsigned: &str| if p.unsigned { unsigned } else { signed }.to_owned();
        Ok(match element {
            ColumnType::String
            | ColumnType::Varchar
            | ColumnType::Relationship
            | ColumnType::Text
            | ColumnType::MediumText
            | ColumnType::LongText
            | ColumnType::Json
            | ColumnType::Object
            | ColumnType::Binary => "String".into(),
            ColumnType::TinyInteger => int("Int8", "UInt8"),
            ColumnType::SmallInteger => int("Int16", "UInt16"),
            ColumnType::Integer => int("Int32", "UInt32"),
            ColumnType::BigInteger | ColumnType::Id => int("Int64", "UInt64"),
            ColumnType::Float | ColumnType::Double => "Float64".into(),
            ColumnType::Decimal => format!("Decimal({}, {})", p.precision.unwrap_or(10), p.scale.unwrap_or(0)),
            ColumnType::Boolean => "UInt8".into(),
            ColumnType::Datetime | ColumnType::Timestamp => match p.precision {
                Some(precision) if precision != 0 => format!("DateTime64({precision})"),
                _ => "DateTime".into(),
            },
            ColumnType::Uuid => "UUID".into(),
            ColumnType::Uuid7 => "FixedString(36)".into(),
            ColumnType::Point => "Tuple(Float64, Float64)".into(),
            ColumnType::Linestring => "Array(Tuple(Float64, Float64))".into(),
            ColumnType::Polygon => "Array(Array(Tuple(Float64, Float64)))".into(),
            ColumnType::Vector => "Array(Float64)".into(),
            ColumnType::Enum
            | ColumnType::Serial
            | ColumnType::BigSerial
            | ColumnType::SmallSerial
            | ColumnType::Array
            | ColumnType::Tuple => {
                return Err(Error::Unsupported(format!(
                    "Nested element type {} is not supported inside Array/Tuple.",
                    element.as_str()
                )));
            }
        })
    }

    /// `Enum8('a' = 1, 'b' = 2, ...)`, with `\` and `'` backslash-escaped.
    fn enumeration(values: &[String]) -> String {
        let parts: Vec<String> = values
            .iter()
            .enumerate()
            .map(|(i, value)| {
                let mut escaped = String::with_capacity(value.len());
                for c in value.chars() {
                    match c {
                        '\\' => escaped.push_str("\\\\"),
                        '\'' => escaped.push_str("\\'"),
                        c => escaped.push(c),
                    }
                }
                format!("'{escaped}' = {}", i + 1)
            })
            .collect();
        format!("Enum8({})", parts.join(", "))
    }
}

impl Schema for ClickHouse {
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
        if let Some(length) = c.fixed_string_length {
            let mut kind = format!("FixedString({length})");
            if c.nullable {
                kind = format!("Nullable({kind})");
            }
            if c.low_cardinality {
                kind = format!("LowCardinality({kind})");
            }
            return Ok(kind);
        }
        if let Some(element) = c.array_element_type {
            if c.low_cardinality {
                return Err(Error::Unsupported(
                    "LowCardinality is not supported inside Array(...). Wrap the element type instead.".into(),
                ));
            }
            if c.nullable {
                return Err(Error::Unsupported(
                    "Nullable(Array(...)) is not supported in ClickHouse. Use an empty array [] to represent a missing value instead."
                        .into(),
                ));
            }
            return Ok(format!("Array({})", Self::element_type(element, column)?));
        }
        if !c.tuple_element_types.is_empty() {
            if c.low_cardinality {
                return Err(Error::Unsupported("LowCardinality is not supported on Tuple(...) columns.".into()));
            }
            let inner = c
                .tuple_element_types
                .iter()
                .map(|element| Self::element_type(*element, column))
                .collect::<Result<Vec<_>>>()?;
            if c.nullable {
                return Err(Error::Unsupported(
                    "Nullable(Tuple(...)) is an experimental ClickHouse feature and requires allow_experimental_nullable_tuple_type = 1. Use Tuple(Nullable(T1), Nullable(T2), ...) instead."
                        .into(),
                ));
            }
            return Ok(format!("Tuple({})", inner.join(", ")));
        }
        let int = |signed: &str, unsigned: &str| if c.unsigned { unsigned } else { signed }.to_owned();
        let date = || match c.precision {
            Some(p) if p != 0 => format!("DateTime64({p})"),
            _ => "DateTime".into(),
        };
        let mut kind = match c.kind {
            ColumnType::String | ColumnType::Varchar | ColumnType::Relationship => "String".into(),
            ColumnType::Text => "String".into(),
            ColumnType::MediumText | ColumnType::LongText => "String".into(),
            ColumnType::TinyInteger => int("Int8", "UInt8"),
            ColumnType::SmallInteger => int("Int16", "UInt16"),
            ColumnType::Integer => int("Int32", "UInt32"),
            ColumnType::BigInteger | ColumnType::Id => int("Int64", "UInt64"),
            ColumnType::Serial | ColumnType::BigSerial | ColumnType::SmallSerial => {
                return Err(Error::Unsupported(
                    "SERIAL types are not supported in ClickHouse. Reached via addColumn(); Table\\ClickHouse has no serial() factory."
                        .into(),
                ));
            }
            ColumnType::Float | ColumnType::Double => "Float64".into(),
            ColumnType::Decimal => format!("Decimal({}, {})", c.precision.unwrap_or(10), c.scale.unwrap_or(0)),
            ColumnType::Boolean => "UInt8".into(),
            ColumnType::Datetime | ColumnType::Timestamp => date(),
            ColumnType::Json | ColumnType::Object => "String".into(),
            ColumnType::Binary => "String".into(),
            ColumnType::Enum => Self::enumeration(&c.enum_values),
            ColumnType::Point => "Tuple(Float64, Float64)".into(),
            ColumnType::Linestring => "Array(Tuple(Float64, Float64))".into(),
            ColumnType::Polygon => "Array(Array(Tuple(Float64, Float64)))".into(),
            ColumnType::Uuid => "UUID".into(),
            ColumnType::Uuid7 => "FixedString(36)".into(),
            ColumnType::Vector => "Array(Float64)".into(),
            ColumnType::Array | ColumnType::Tuple => {
                return Err(Error::Unsupported(
                    "Array/Tuple columns must be declared via Table\\ClickHouse::array() or ::tuple().".into(),
                ));
            }
        };
        if c.nullable {
            kind = format!("Nullable({kind})");
        }
        if c.low_cardinality {
            kind = format!("LowCardinality({kind})");
        }
        Ok(kind)
    }

    fn compile_auto_increment(&self) -> &'static str {
        ""
    }

    fn compile_unsigned(&self) -> &'static str {
        ""
    }

    /// Name, type, default, codecs, TTL and comment.
    fn compile_column_definition(&self, column: &Column<Self>) -> Result<String> {
        let c = &column.def;
        let mut parts = vec![self.quote_literal(&c.name)?, self.compile_column_type(column)?];
        if let Some(raw) = &c.default_raw {
            parts.push(format!("DEFAULT {raw}"));
        } else if let Some(value) = &c.default {
            parts.push(format!("DEFAULT {}", base::default_value(value)));
        }
        if !c.codecs.is_empty() {
            parts.push(format!("CODEC({})", c.codecs.join(", ")));
        }
        if let Some(ttl) = &c.ttl {
            parts.push(format!("TTL {ttl}"));
        }
        if let Some(comment) = &c.comment {
            parts.push(format!("COMMENT {}", names::literal(comment)));
        }
        Ok(parts.join(" "))
    }

    fn drop_index(&self, table: &str, name: &str) -> Result<Statement> {
        base::plain(self, format!("ALTER TABLE {} DROP INDEX {}", self.quote(table)?, self.quote(name)?))
    }

    /// Columns, renames, drops and data-skipping indexes; settings cannot change here.
    fn compile_alter(&self, table: &Table<Self>) -> Result<Statement> {
        let t = table.definition();
        let mut alterations = Vec::new();
        for column in &t.columns {
            let keyword = if column.def.modify { "MODIFY COLUMN" } else { "ADD COLUMN" };
            alterations.push(format!("{keyword} {}", self.compile_column_definition(column)?));
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
        for name in &t.drop_indexes {
            alterations.push(format!("DROP INDEX {}", self.quote(name)?));
        }
        for index in &t.indexes {
            if index.kind != IndexType::Index {
                return Err(Error::Unsupported(
                    "Only data-skipping indexes (index()) are supported in ClickHouse ALTER TABLE.".into(),
                ));
            }
            alterations.push(format!("ADD {}", self.skip_index(index)?));
        }
        if !t.settings.is_empty() {
            return Err(Error::Unsupported(
                "Table SETTINGS can only be set on CREATE TABLE; emit `ALTER TABLE ... MODIFY SETTING` directly to change them."
                    .into(),
            ));
        }
        if alterations.is_empty() {
            return Err(Error::Validation("ALTER TABLE requires at least one alteration.".into()));
        }
        base::plain(self, format!("ALTER TABLE {} {}", self.quote(&t.name)?, alterations.join(", ")))
    }

    /// Columns and skip indexes, then `ENGINE`, `PARTITION BY`, `ORDER BY`
    /// (the primary key when unset), `SAMPLE BY`, `TTL` and `SETTINGS`.
    fn compile_create(&self, table: &Table<Self>, if_not_exists: bool) -> Result<Statement> {
        let t = table.definition();
        let mut defs = Vec::new();
        let mut primary = Vec::new();
        for column in &t.columns {
            defs.push(self.compile_column_definition(column)?);
            if column.def.primary {
                primary.push(self.quote_literal(&column.def.name)?);
            }
        }
        if !t.composite_primary_key.is_empty() && !primary.is_empty() {
            return Err(Error::Validation(
                "Cannot combine column-level primary() with Table::primary() composite key.".into(),
            ));
        }
        if primary.is_empty() && !t.composite_primary_key.is_empty() {
            primary = t.composite_primary_key.iter().map(|c| self.quote_literal(c)).collect::<Result<_>>()?;
        }
        defs.extend(t.raw_column_defs.iter().cloned());
        for index in &t.indexes {
            if index.kind != IndexType::Index {
                return Err(Error::Unsupported(
                    "Only data-skipping indexes (index()) are supported in ClickHouse CREATE TABLE.".into(),
                ));
            }
            defs.push(self.skip_index(index)?);
        }
        let engine = t.engine.unwrap_or(Engine::MergeTree);
        let mut sql = format!(
            "CREATE TABLE {}{} ({}) ENGINE = {}",
            if if_not_exists { "IF NOT EXISTS " } else { "" },
            self.quote(&t.name)?,
            defs.join(", "),
            self.engine(engine, &t.engine_args)?
        );
        if !t.partition_expression.is_empty() {
            sql.push_str(" PARTITION BY ");
            sql.push_str(&t.partition_expression);
        }
        if engine.requires_order_by() {
            if let Some(raw) = &t.order_by_raw {
                sql.push_str(" ORDER BY ");
                sql.push_str(raw);
            } else {
                let order = if t.order_by.is_empty() {
                    primary
                } else {
                    t.order_by.iter().map(|c| self.quote_literal(c)).collect::<Result<Vec<_>>>()?
                };
                if order.is_empty() {
                    sql.push_str(" ORDER BY tuple()");
                } else {
                    sql.push_str(&format!(" ORDER BY ({})", order.join(", ")));
                }
            }
        }
        if let Some(sample) = &t.sample_by {
            if !engine.requires_order_by() {
                return Err(Error::Unsupported(
                    "SAMPLE BY is only supported on engines that take an ORDER BY clause.".into(),
                ));
            }
            sql.push_str(" SAMPLE BY ");
            sql.push_str(sample);
        }
        if let Some(ttl) = &t.ttl {
            sql.push_str(" TTL ");
            sql.push_str(ttl);
        }
        if !t.settings.is_empty() {
            let pairs: Vec<String> = t.settings.iter().map(|(k, v)| format!("{k} = {v}")).collect();
            sql.push_str(" SETTINGS ");
            sql.push_str(&pairs.join(", "));
        }
        base::plain(self, sql)
    }
}

impl column::Comment for ClickHouse {}
impl table::ColumnAlterations for ClickHouse {}
impl table::CompositePrimary for ClickHouse {}

impl feature::TableComments for ClickHouse {
    fn comment_on_table(&self, table: &str, comment: &str) -> Result<Statement> {
        base::plain(self, format!("ALTER TABLE {} MODIFY COMMENT {}", self.quote(table)?, names::literal(comment)))
    }
}

impl feature::ColumnComments for ClickHouse {
    fn comment_on_column(&self, table: &str, column: &str, comment: &str) -> Result<Statement> {
        base::plain(
            self,
            format!(
                "ALTER TABLE {} COMMENT COLUMN {} {}",
                self.quote(table)?,
                self.quote_literal(column)?,
                names::literal(comment)
            ),
        )
    }
}

impl feature::DropPartition for ClickHouse {
    fn drop_partition(&self, table: &str, name: &str) -> Result<Statement> {
        base::plain(self, format!("ALTER TABLE {} DROP PARTITION {}", self.quote(table)?, names::literal(name)))
    }
}

impl feature::Views for ClickHouse {
    fn create_view(&self, name: &str, query: &mut dyn AnyBuilder) -> Result<Statement> {
        base::create_view(self, "CREATE VIEW", name, query)
    }

    fn drop_view(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP VIEW {}", self.quote(name)?))
    }
}

impl feature::Databases for ClickHouse {
    fn create_database(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("CREATE DATABASE {}", self.quote(name)?))
    }

    fn drop_database(&self, name: &str) -> Result<Statement> {
        base::plain(self, format!("DROP DATABASE {}", self.quote(name)?))
    }
}

impl feature::MaterializedViews for ClickHouse {
    fn create_materialized_view(
        &self,
        name: &str,
        body: ViewBody<'_>,
        target_table: Option<&str>,
        if_not_exists: bool,
    ) -> Result<Statement> {
        base::create_materialized_view(self, name, body, target_table, if_not_exists)
    }

    fn drop_materialized_view(&self, name: &str, if_exists: bool) -> Result<Statement> {
        base::drop_materialized_view(self, name, if_exists)
    }
}
