//! [`Column`]: a column of a table blueprint, with the modifiers its
//! dialect supports.
//!
//! Modifiers every dialect has are inherent methods. The others are gated
//! by marker traits the dialects implement ([`Unique`], [`Collation`],
//! [`Generated`], ...), so a modifier a dialect cannot express does not
//! exist on its columns, as in PHP where each dialect's column class only
//! uses the traits it supports.

use std::fmt;
use std::marker::PhantomData;

use super::enums::ColumnType;
use super::{ClickHouse, PostgreSql, Schema, names};
use crate::error::{Error, Result};
use crate::value::{IntoValue, Value};

/// What a column is: its name, type, sizes and every modifier set on it.
///
/// PHP: the public properties of `Utopia\Query\Schema\Column` (and
/// `Column\ClickHouse`).
#[derive(Debug, Clone, PartialEq)]
pub struct ColumnDefinition {
    /// The name.
    pub name: String,
    /// The portable type.
    pub kind: ColumnType,
    /// The length (strings).
    pub length: Option<i64>,
    /// The precision (decimals, date-times).
    pub precision: Option<i64>,
    /// The scale (decimals).
    pub scale: Option<i64>,
    /// Whether the column accepts `NULL`.
    pub nullable: bool,
    /// The default value, rendered as a literal (`Some(Value::Null)` is
    /// `DEFAULT NULL`; `None` is no default).
    pub default: Option<Value>,
    /// A raw default expression, emitted verbatim.
    pub default_raw: Option<String>,
    /// Whether the column is unsigned.
    pub unsigned: bool,
    /// Whether the column is unique.
    pub unique: bool,
    /// Whether the column is (part of) the primary key.
    pub primary: bool,
    /// Whether the server increments the column.
    pub auto_increment: bool,
    /// The column this one is placed after (MySQL `ALTER`).
    pub after: Option<String>,
    /// The comment.
    pub comment: Option<String>,
    /// The allowed values of an enum column.
    pub enum_values: Vec<String>,
    /// The spatial reference system of a geometry column.
    pub srid: Option<i64>,
    /// The width of a vector column.
    pub dimensions: Option<i64>,
    /// Whether an `ALTER` modifies the column instead of adding it.
    pub modify: bool,
    /// The collation.
    pub collation: Option<String>,
    /// A column-level `CHECK` expression.
    pub check: Option<String>,
    /// The expression of a generated column.
    pub generated_expression: Option<String>,
    /// Whether a generated column is stored (`Some(true)`) or virtual
    /// (`Some(false)`); `None` when not set.
    pub generated_stored: Option<bool>,
    /// A column-level TTL expression (ClickHouse).
    pub ttl: Option<String>,
    /// A user-defined type name (PostgreSQL).
    pub user_type: Option<String>,
    /// Whether the type is wrapped in `LowCardinality(...)` (ClickHouse).
    pub low_cardinality: bool,
    /// The width of a `FixedString(N)` column (ClickHouse).
    pub fixed_string_length: Option<i64>,
    /// The `CODEC(...)` clauses (ClickHouse).
    pub codecs: Vec<String>,
    /// The element type of an `Array(T)` column (ClickHouse).
    pub array_element_type: Option<ColumnType>,
    /// The element types of a `Tuple(...)` column (ClickHouse).
    pub tuple_element_types: Vec<ColumnType>,
}

/// A column of a table blueprint for the dialect `S`.
///
/// Built by the table's factories ([`Table::string`](super::Table::string),
/// [`Table::integer`](super::Table::integer), ...), which return it to
/// chain modifiers: `table.string("email", 255).unique().nullable()`.
///
/// PHP: `Utopia\Query\Schema\Column` and its dialect classes
/// (`Column\MySQL`, `Column\PostgreSQL`, `Column\SQLite`,
/// `Column\ClickHouse`, `Column\MongoDB`).
pub struct Column<S> {
    pub(crate) def: ColumnDefinition,
    dialect: PhantomData<fn() -> S>,
}

impl<S> Clone for Column<S> {
    fn clone(&self) -> Self {
        Column { def: self.def.clone(), dialect: PhantomData }
    }
}

impl<S> fmt::Debug for Column<S> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        self.def.fmt(f)
    }
}

impl<S> PartialEq for Column<S> {
    fn eq(&self, other: &Self) -> bool {
        self.def == other.def
    }
}

/// Dialects whose columns can be server-incremented.
///
/// PHP: `Column\Trait\AutoIncrement`.
pub trait AutoIncrement: Schema {}
/// Dialects with column-level collations.
///
/// PHP: `Column\Trait\Collation`.
pub trait Collation: Schema {}
/// Dialects with inline column comments.
///
/// PHP: `Column\Trait\Comment`.
pub trait Comment: Schema {}
/// Dialects whose vector columns have a declared width.
///
/// PHP: `Column\Trait\Dimensions`.
pub trait Dimensions: Schema {}
/// Dialects with stored generated columns.
///
/// PHP: `Column\Trait\Generated`.
pub trait Generated: Schema {}
/// Dialects that place columns with `AFTER`.
///
/// PHP: `Column\Trait\Positioning`.
pub trait Positioning: Schema {}
/// Dialects whose geometry columns carry an SRID.
///
/// PHP: `Column\Trait\Srid`.
pub trait Srid: Schema {}
/// Dialects with column-level `UNIQUE`.
///
/// PHP: `Column\Trait\Unique`.
pub trait Unique: Schema {}
/// Dialects with virtual generated columns.
///
/// PHP: `Column\Trait\VirtualGenerated`.
pub trait VirtualGenerated: Schema {}

/// A trimmed expression that is not empty and has no `;`.
pub(crate) fn expression(value: &str, empty: &str, semicolon: &str) -> Result<String> {
    let trimmed = php_std::string::trim(value.as_bytes(), php_std::string::TRIM_CHARACTERS);
    let trimmed = String::from_utf8_lossy(trimmed).into_owned();
    if trimmed.is_empty() {
        return Err(Error::Validation(empty.into()));
    }
    if trimmed.contains(';') {
        return Err(Error::Validation(semicolon.into()));
    }
    Ok(trimmed)
}

impl<S> Column<S> {
    /// A column `name` of type `kind`, with no size and no modifier (PHP's
    /// table factories make columns; this one belongs to no table).
    ///
    /// PHP: `new Column($table, $name, $type)`.
    pub fn new(name: impl Into<String>, kind: ColumnType) -> Self {
        Column {
            def: ColumnDefinition {
                name: name.into(),
                kind,
                length: None,
                precision: None,
                scale: None,
                nullable: false,
                default: None,
                default_raw: None,
                unsigned: false,
                unique: false,
                primary: false,
                auto_increment: false,
                after: None,
                comment: None,
                enum_values: Vec::new(),
                srid: None,
                dimensions: None,
                modify: false,
                collation: None,
                check: None,
                generated_expression: None,
                generated_stored: None,
                ttl: None,
                user_type: None,
                low_cardinality: false,
                fixed_string_length: None,
                codecs: Vec::new(),
                array_element_type: None,
                tuple_element_types: Vec::new(),
            },
            dialect: PhantomData,
        }
    }

    /// The column with a length (strings).
    ///
    /// PHP: the `$length` constructor parameter.
    pub fn with_length(mut self, length: Option<i64>) -> Self {
        self.def.length = length;
        self
    }

    /// The column with a precision.
    ///
    /// PHP: the `$precision` constructor parameter.
    pub fn with_precision(mut self, precision: Option<i64>) -> Self {
        self.def.precision = precision;
        self
    }

    /// The column with a scale.
    ///
    /// PHP: the `$scale` constructor parameter.
    pub fn with_scale(mut self, scale: Option<i64>) -> Self {
        self.def.scale = scale;
        self
    }

    /// The column with a spatial reference system.
    ///
    /// PHP: the `$srid` constructor parameter.
    pub fn with_srid(mut self, srid: Option<i64>) -> Self {
        self.def.srid = srid;
        self
    }

    /// The column with a vector width.
    ///
    /// PHP: the `$dimensions` constructor parameter.
    pub fn with_dimensions(mut self, dimensions: Option<i64>) -> Self {
        self.def.dimensions = dimensions;
        self
    }

    /// The column, server-incremented or not.
    ///
    /// PHP: the `$autoIncrement` constructor parameter.
    pub fn with_auto_increment(mut self, auto_increment: bool) -> Self {
        self.def.auto_increment = auto_increment;
        self
    }

    /// What the column is.
    ///
    /// PHP: the column's public properties.
    pub fn definition(&self) -> &ColumnDefinition {
        &self.def
    }

    /// The name.
    ///
    /// PHP: `$column->name`.
    pub fn name(&self) -> &str {
        &self.def.name
    }

    /// Accepts `NULL`.
    ///
    /// PHP: `nullable()`.
    pub fn nullable(&mut self) -> &mut Self {
        self.def.nullable = true;
        self
    }

    /// Sets the default value, rendered as a literal (`None` for
    /// `DEFAULT NULL`).
    ///
    /// PHP: `default()`.
    pub fn default(&mut self, value: impl IntoValue) -> &mut Self {
        self.def.default = Some(value.into_value());
        self
    }

    /// Sets a raw default expression (`now()`, `gen_random_uuid()`),
    /// trimmed; it must not be empty nor contain `;`.
    ///
    /// PHP: `defaultRaw()`.
    pub fn default_raw(&mut self, expression: &str) -> Result<&mut Self> {
        self.def.default_raw = Some(self::expression(
            expression,
            "Raw default expression must not be empty.",
            "Raw default expression must not contain \";\".",
        )?);
        Ok(self)
    }

    /// Marks the column unsigned.
    ///
    /// PHP: `unsigned()`.
    pub fn unsigned(&mut self) -> &mut Self {
        self.def.unsigned = true;
        self
    }

    /// Marks the column as the primary key (a composite key is
    /// [`Table::primary`](super::Table::primary)).
    ///
    /// PHP: `primary()` without arguments.
    pub fn primary(&mut self) -> &mut Self {
        self.def.primary = true;
        self
    }

    /// Sets the allowed values of an enum column; there must be one at least.
    ///
    /// PHP: `enum()` with one array argument.
    pub fn enum_values<V: Into<String>>(&mut self, values: impl IntoIterator<Item = V>) -> Result<&mut Self> {
        let values: Vec<String> = values.into_iter().map(Into::into).collect();
        if values.is_empty() {
            return Err(Error::Validation("enum() requires at least one allowed value.".into()));
        }
        self.def.enum_values = values;
        Ok(self)
    }

    /// Makes an `ALTER` modify the column instead of adding it.
    ///
    /// PHP: `modify()`.
    pub fn modify(&mut self) -> &mut Self {
        self.def.modify = true;
        self
    }
}

impl<S: AutoIncrement> Column<S> {
    /// Marks the column server-incremented.
    ///
    /// PHP: `autoIncrement()`.
    pub fn auto_increment(&mut self) -> &mut Self {
        self.def.auto_increment = true;
        self
    }
}

impl<S: Collation> Column<S> {
    /// Sets the collation.
    ///
    /// PHP: `collation()`.
    pub fn collation(&mut self, collation: impl Into<String>) -> &mut Self {
        self.def.collation = Some(collation.into());
        self
    }
}

impl<S: Comment> Column<S> {
    /// Sets the comment.
    ///
    /// PHP: `comment()`.
    pub fn comment(&mut self, comment: impl Into<String>) -> &mut Self {
        self.def.comment = Some(comment.into());
        self
    }
}

impl<S: Dimensions> Column<S> {
    /// Sets the width of a vector column.
    ///
    /// PHP: `dimensions()`.
    pub fn dimensions(&mut self, dimensions: i64) -> &mut Self {
        self.def.dimensions = Some(dimensions);
        self
    }
}

impl<S: Generated> Column<S> {
    /// Makes the column generated from `expression`.
    ///
    /// PHP: `generatedAs()`.
    pub fn generated_as(&mut self, expression: impl Into<String>) -> &mut Self {
        self.def.generated_expression = Some(expression.into());
        self
    }

    /// Stores the generated column.
    ///
    /// PHP: `stored()`.
    pub fn stored(&mut self) -> &mut Self {
        self.def.generated_stored = Some(true);
        self
    }
}

impl<S: VirtualGenerated> Column<S> {
    /// Computes the generated column on read.
    ///
    /// PHP: `virtual()`.
    pub fn virtual_(&mut self) -> &mut Self {
        self.def.generated_stored = Some(false);
        self
    }
}

impl<S: Positioning> Column<S> {
    /// Places the column after `column` (in an `ALTER`).
    ///
    /// PHP: `after()`.
    pub fn after(&mut self, column: impl Into<String>) -> &mut Self {
        self.def.after = Some(column.into());
        self
    }
}

impl<S: Srid> Column<S> {
    /// Sets the spatial reference system.
    ///
    /// PHP: `srid()`.
    pub fn srid(&mut self, srid: i64) -> &mut Self {
        self.def.srid = Some(srid);
        self
    }
}

impl<S: Unique> Column<S> {
    /// Marks the column unique.
    ///
    /// PHP: `unique()`.
    pub fn unique(&mut self) -> &mut Self {
        self.def.unique = true;
        self
    }
}

impl<S: super::table::Checks> Column<S> {
    /// Sets a column-level `CHECK` expression (a named table-level check is
    /// [`Table::check`](super::Table::check)).
    ///
    /// PHP: `check()` with one argument.
    pub fn check(&mut self, expression: impl Into<String>) -> &mut Self {
        self.def.check = Some(expression.into());
        self
    }
}

impl Column<PostgreSql> {
    /// Uses the user-defined type `name` (a `CREATE TYPE` enum), which must
    /// be an identifier.
    ///
    /// PHP: `Column\PostgreSQL::userType()`.
    pub fn user_type(&mut self, name: &str) -> Result<&mut Self> {
        if !names::is_identifier(name) {
            return Err(Error::Validation(format!("Invalid user-defined type name: {name}")));
        }
        self.def.user_type = Some(name.to_owned());
        Ok(self)
    }
}

impl Column<ClickHouse> {
    /// Makes the column `FixedString(length)`; the length is at least 1.
    ///
    /// PHP: `Column\ClickHouse::asFixedString()`.
    pub fn as_fixed_string(&mut self, length: i64) -> Result<&mut Self> {
        if length < 1 {
            return Err(Error::Validation("FixedString length must be at least 1.".into()));
        }
        self.def.fixed_string_length = Some(length);
        Ok(self)
    }

    /// Whether the column is `FixedString(N)`.
    ///
    /// PHP: `isFixedString()`.
    pub fn is_fixed_string(&self) -> bool {
        self.def.fixed_string_length.is_some()
    }

    /// Makes the column `Array(element)`.
    ///
    /// PHP: `asArray()`.
    pub fn as_array(&mut self, element: ColumnType) -> &mut Self {
        self.def.array_element_type = Some(element);
        self
    }

    /// Whether the column is `Array(T)`.
    ///
    /// PHP: `isArray()`.
    pub fn is_array(&self) -> bool {
        self.def.array_element_type.is_some()
    }

    /// Makes the column `Tuple(elements...)`; there must be one at least.
    ///
    /// PHP: `asTuple()`.
    pub fn as_tuple(&mut self, elements: Vec<ColumnType>) -> Result<&mut Self> {
        if elements.is_empty() {
            return Err(Error::Validation("Tuple() requires at least one element type.".into()));
        }
        self.def.tuple_element_types = elements;
        Ok(self)
    }

    /// Whether the column is `Tuple(...)`.
    ///
    /// PHP: `isTuple()`.
    pub fn is_tuple(&self) -> bool {
        !self.def.tuple_element_types.is_empty()
    }

    /// Wraps the type in `LowCardinality(...)`.
    ///
    /// PHP: `lowCardinality()`.
    pub fn low_cardinality(&mut self) -> &mut Self {
        self.def.low_cardinality = true;
        self
    }

    /// Adds a `CODEC(...)` clause (`LZ4`, `Delta(4)`), trimmed; it must not
    /// be empty nor contain `;`.
    ///
    /// PHP: `codec()`.
    pub fn codec(&mut self, codec: &str) -> Result<&mut Self> {
        let codec =
            expression(codec, "CODEC expression must not be empty.", "CODEC expression must not contain \";\".")?;
        self.def.codecs.push(codec);
        Ok(self)
    }

    /// Sets a column-level TTL expression, trimmed; it must not be empty nor
    /// contain `;`.
    ///
    /// PHP: `Column\ClickHouse::ttl()`.
    pub fn ttl(&mut self, expression: &str) -> Result<&mut Self> {
        self.def.ttl = Some(self::expression(
            expression,
            "TTL expression must not be empty.",
            "TTL expression must not contain \";\".",
        )?);
        Ok(self)
    }
}
