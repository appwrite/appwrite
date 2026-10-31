//! [`MongoDb`]: the MongoDB schema, whose statements are JSON commands.

use php_std::json::{self, Flags};
use php_std::{Array, ArrayKey, Str, Value};

use super::column::{self, Column};
use super::enums::ColumnType;
use super::feature;
use super::index::IndexOptions;
use super::table::{self, Table};
use super::{Schema, base};
use crate::builder::{AnyBuilder, Executor, Statement};
use crate::error::{Error, Result};

/// The MongoDB schema: collections with `$jsonSchema` validators, indexes,
/// views and databases as JSON commands (`{"command": "createCollection", ...}`).
///
/// ```
/// use utopia_query::schema::{MongoDb, Schema};
///
/// let mut users = MongoDb::new().table("users");
/// users.string("email", 255);
/// users.integer("age").nullable();
/// assert_eq!(
///     users.create()?.query,
///     r#"{"command":"createCollection","collection":"users","validator":{"$jsonSchema":{"bsonType":"object","properties":{"email":{"bsonType":"string"},"age":{"bsonType":"int"}},"required":["email"]}}}"#
/// );
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Schema\MongoDB;
///
/// $users = (new MongoDB())->table('users');
/// $users->string('email');
/// $users->integer('age')->nullable();
/// echo $users->create()->query, "\n";
/// ```
///
/// PHP: `Utopia\Query\Schema\MongoDB`.
#[derive(Clone, Default)]
pub struct MongoDb {
    executor: Option<Executor>,
}

impl std::fmt::Debug for MongoDb {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("MongoDb").field("executor", &self.executor.is_some()).finish()
    }
}

fn key(name: &str) -> ArrayKey {
    ArrayKey::normalize(Str::from(name))
}

fn string(s: &str) -> Value {
    Value::Str(Str::from(s))
}

/// An array of `(key, value)` entries, keys as PHP normalises them.
fn map<'a>(entries: impl IntoIterator<Item = (&'a str, Value)>) -> Value {
    let mut array = Array::default();
    for (k, v) in entries {
        array.set(key(k), v);
    }
    Value::Array(array)
}

fn encode(value: &Value, flags: Flags) -> Result<String> {
    json::encode(value, flags | Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH)
        .map_err(|e| Error::query(e.message().to_owned()))
}

impl MongoDb {
    /// A MongoDB schema without an executor.
    ///
    /// PHP: `new MongoDB()`.
    pub fn new() -> Self {
        MongoDb::default()
    }

    fn command(&self, value: &Value, flags: Flags) -> Result<Statement> {
        Ok(base::statement(self, encode(value, flags)?, Vec::new()))
    }

    /// The `$jsonSchema` validator of `table`'s columns (enum values listed
    /// when `enums`), or `None` without columns.
    fn validator(&self, table: &Table<Self>, enums: bool) -> Result<Option<Value>> {
        let columns = table.columns();
        if columns.is_empty() {
            return Ok(None);
        }
        let mut properties = Array::default();
        let mut required = Vec::new();
        for column in columns {
            let c = &column.def;
            let mut property = Array::default();
            property.set(key("bsonType"), string(&self.compile_column_type(column)?));
            if let Some(comment) = &c.comment {
                property.set(key("description"), string(comment));
            }
            if enums && c.kind == ColumnType::Enum && !c.enum_values.is_empty() {
                let values = c.enum_values.iter().map(|v| string(v)).collect();
                property.set(key("enum"), Value::Array(Array::from_list(values)));
            }
            properties.set(key(&c.name), Value::Array(property));
            if !c.nullable && c.default.is_none() {
                required.push(string(&c.name));
            }
        }
        let mut schema = vec![("bsonType", string("object")), ("properties", Value::Array(properties))];
        if !required.is_empty() {
            schema.push(("required", Value::Array(Array::from_list(required))));
        }
        Ok(Some(map([("$jsonSchema", map(schema))])))
    }

    fn collection_command(&self, command: &str, table: &Table<Self>, enums: bool) -> Result<Statement> {
        let mut entries = vec![("command", string(command)), ("collection", string(table.name()))];
        if let Some(validator) = self.validator(table, enums)? {
            entries.push(("validator", validator));
        }
        self.command(&map(entries), Flags::UNESCAPED_SLASHES)
    }
}

impl Schema for MongoDb {
    fn executor(&self) -> Option<&Executor> {
        self.executor.as_ref()
    }

    fn set_executor(&mut self, executor: Executor) -> &mut Self {
        self.executor = Some(executor);
        self
    }

    /// Identifiers are used as they are.
    fn quote(&self, identifier: &str) -> Result<String> {
        Ok(identifier.to_owned())
    }

    fn quote_literal(&self, identifier: &str) -> Result<String> {
        Ok(identifier.to_owned())
    }

    /// The BSON type.
    fn compile_column_type(&self, column: &Column<Self>) -> Result<String> {
        Ok(match column.def.kind {
            ColumnType::String | ColumnType::Varchar | ColumnType::Relationship => "string",
            ColumnType::Text | ColumnType::MediumText | ColumnType::LongText => "string",
            ColumnType::TinyInteger
            | ColumnType::SmallInteger
            | ColumnType::Integer
            | ColumnType::BigInteger
            | ColumnType::Id
            | ColumnType::Serial
            | ColumnType::BigSerial
            | ColumnType::SmallSerial => "int",
            ColumnType::Float | ColumnType::Double => "double",
            ColumnType::Decimal => "decimal",
            ColumnType::Boolean => "bool",
            ColumnType::Datetime | ColumnType::Timestamp => "date",
            ColumnType::Json | ColumnType::Object => "object",
            ColumnType::Binary => "binData",
            ColumnType::Enum => "string",
            ColumnType::Point | ColumnType::Linestring | ColumnType::Polygon => "object",
            ColumnType::Uuid | ColumnType::Uuid7 => "string",
            ColumnType::Vector | ColumnType::Array | ColumnType::Tuple => "array",
        }
        .to_owned())
    }

    fn compile_auto_increment(&self) -> &'static str {
        ""
    }

    /// `createCollection` with a `$jsonSchema` validator.
    fn compile_create(&self, table: &Table<Self>, _if_not_exists: bool) -> Result<Statement> {
        self.collection_command("createCollection", table, true)
    }

    /// `collMod` with the new validator.
    fn compile_alter(&self, table: &Table<Self>) -> Result<Statement> {
        self.collection_command("collMod", table, false)
    }

    fn compile_drop(&self, name: &str, _if_exists: bool) -> Result<Statement> {
        self.command(&map([("command", string("drop")), ("collection", string(name))]), Flags::NONE)
    }

    fn compile_rename(&self, from: &str, to: &str) -> Result<Statement> {
        self.command(
            &map([("command", string("renameCollection")), ("from", string(from)), ("to", string(to))]),
            Flags::NONE,
        )
    }

    /// `deleteMany` with an empty filter.
    fn compile_truncate(&self, name: &str) -> Result<Statement> {
        self.command(
            &map([
                ("command", string("deleteMany")),
                ("collection", string(name)),
                ("filter", Value::Object(Array::default())),
            ]),
            Flags::NONE,
        )
    }

    /// `createIndex` with `1` / `-1` keys (from the orders) and `unique`.
    fn create_index(&self, table: &str, name: &str, columns: &[&str], options: &IndexOptions) -> Result<Statement> {
        let mut keys = Array::default();
        for column in columns {
            let order = options.orders.iter().rev().find(|(c, _)| c == column).map(|(_, o)| o);
            let direction = match order {
                Some(order) if order.eq_ignore_ascii_case("desc") => -1,
                _ => 1,
            };
            keys.set(key(column), Value::Int(direction));
        }
        let mut index = vec![("key", Value::Array(keys)), ("name", string(name))];
        if options.unique {
            index.push(("unique", Value::Bool(true)));
        }
        self.command(
            &map([("command", string("createIndex")), ("collection", string(table)), ("index", map(index))]),
            Flags::UNESCAPED_SLASHES,
        )
    }

    fn drop_index(&self, table: &str, name: &str) -> Result<Statement> {
        self.command(
            &map([("command", string("dropIndex")), ("collection", string(table)), ("index", string(name))]),
            Flags::NONE,
        )
    }
}

impl column::Comment for MongoDb {}
impl table::Serial for MongoDb {}

impl feature::Views for MongoDb {
    /// `createView` over the source collection and pipeline of the
    /// builder's command.
    fn create_view(&self, name: &str, query: &mut dyn AnyBuilder) -> Result<Statement> {
        let built = query.build()?;
        let op =
            json::decode(built.query.as_bytes(), Some(true), json::DEFAULT_DEPTH, Flags::NONE).unwrap_or(Value::Null);
        if matches!(op, Value::Null) {
            return Err(Error::Validation("Cannot parse query for MongoDB view creation.".into()));
        }
        let field = |name: &str| match &op {
            Value::Array(a) => a.get_str(name).filter(|v| !matches!(v, Value::Null)).cloned(),
            _ => None,
        };
        let command = map([
            ("command", string("createView")),
            ("view", string(name)),
            ("source", field("collection").unwrap_or_else(|| string(""))),
            ("pipeline", field("pipeline").unwrap_or(Value::Array(Array::default()))),
        ]);
        Ok(base::statement(self, encode(&command, Flags::UNESCAPED_SLASHES)?, built.bindings))
    }

    fn drop_view(&self, name: &str) -> Result<Statement> {
        self.command(&map([("command", string("drop")), ("collection", string(name))]), Flags::NONE)
    }
}

impl feature::Databases for MongoDb {
    fn create_database(&self, name: &str) -> Result<Statement> {
        self.command(&map([("command", string("createDatabase")), ("database", string(name))]), Flags::NONE)
    }

    fn drop_database(&self, name: &str) -> Result<Statement> {
        self.command(&map([("command", string("dropDatabase")), ("database", string(name))]), Flags::NONE)
    }
}

impl feature::AnalyzeTable for MongoDb {
    fn analyze_table(&self, table: &str) -> Result<Statement> {
        self.command(&map([("command", string("collStats")), ("collection", string(table))]), Flags::NONE)
    }
}
