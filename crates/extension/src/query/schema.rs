//! The schema compilers: the abstract `Schema` and `Schema\SQL`, and the dialects
//! `Schema\MySQL`, `PostgreSQL`, `SQLite`, `ClickHouse` and `MongoDB`.
//!
//! The blueprints stay the package's own PHP: `Table`, `Column`, `ForeignKey`, `Index` and
//! the rest are what PHP code fills with fluent calls, and the enums are PHP enums. A schema
//! reads a blueprint through its property slots, which it finds by name once a request for
//! each class, compiles it with the crate and returns the package's `Builder\Statement`.
//!
//! Strings: a SQL dialect's are widened going in and narrowed coming out, as the AST's are,
//! so PHP gets back the bytes it gave. MongoDB's statements are JSON, which is UTF-8, so its
//! strings pass as they are and one that is not UTF-8 is the `JsonException` `json_encode`
//! throws.
//!
//! Parameters: PHP callers name arguments (`createIndex(unique: true, rawColumns: [...])`),
//! and ext-php-rs gives a parameter its Rust name, so the methods' parameters are spelled as
//! PHP's.

#![allow(non_snake_case)]

use std::cell::{Cell, RefCell};
use std::rc::Rc;

use ext_php_rs::convert::{FromZval, IntoZval};
use ext_php_rs::exception::PhpException;
use ext_php_rs::ffi::zend_property_info;
use ext_php_rs::flags::{ClassFlags, DataType};
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey, ZendClassObject, ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ce};
use utopia_query::Value;
use utopia_query::builder::{AnyBuilder, Statement as Compiled};
use utopia_query::schema::feature::{
    AnalyzeTable, ColumnComments, CreatePartition, Databases, DropPartition, ForeignKeys, MaterializedViews,
    Partitioning, Procedures, RenameIndex, ReplaceView, Sequences, TableComments, Triggers, Types, ViewBody, Views,
};
use utopia_query::schema::{
    CheckConstraint, ClickHouse, Column, ColumnType, Engine, ForeignKey, ForeignKeyAction, Index, IndexAlgorithm,
    IndexOptions, IndexType, MongoDb, MySql, ParameterDirection, PartitionType, PostgreSql, RenameColumn, Schema,
    Sqlite, Table, TriggerEvent, TriggerTiming,
};

use super::ast::{failed, float, narrow, null, slots, wide};
use super::exception;
use super::tokenizer::class;
use super::value::{Arr, Bool, Int, call, empty};
use crate::emails::{construct, string};
use crate::string::PhpString;

const NAMESPACE: &str = "Utopia\\Query\\Schema\\";
const STATEMENT: &str = "Utopia\\Query\\Builder\\Statement";
/// The properties of a `Statement`, in the order [`statement`] sets them.
const STATEMENT_PROPERTIES: [&str; 5] = ["query", "bindings", "readOnly", "executor", "namedBindings"];

/// What a schema class needs to know of its dialect beyond the crate's [`Schema`].
trait Dialect: Schema {
    /// The class `table()` returns.
    const TABLE: &'static str;

    /// Whether the dialect's statements are JSON, so its strings are UTF-8 and not bytes.
    const JSON: bool = false;

    fn fresh() -> Self;
}

impl Dialect for MySql {
    const TABLE: &'static str = "Utopia\\Query\\Schema\\Table\\MySQL";

    fn fresh() -> Self {
        MySql::new()
    }
}

impl Dialect for PostgreSql {
    const TABLE: &'static str = "Utopia\\Query\\Schema\\Table\\PostgreSQL";

    fn fresh() -> Self {
        PostgreSql::new()
    }
}

impl Dialect for Sqlite {
    const TABLE: &'static str = "Utopia\\Query\\Schema\\Table\\SQLite";

    fn fresh() -> Self {
        Sqlite::new()
    }
}

impl Dialect for ClickHouse {
    const TABLE: &'static str = "Utopia\\Query\\Schema\\Table\\ClickHouse";

    fn fresh() -> Self {
        ClickHouse::new()
    }
}

impl Dialect for MongoDb {
    const TABLE: &'static str = "Utopia\\Query\\Schema\\Table\\MongoDB";
    const JSON: bool = true;

    fn fresh() -> Self {
        MongoDb::new()
    }
}

// ---------------------------------------------------------------------------------------
// Strings and errors
// ---------------------------------------------------------------------------------------

/// PHP's bytes as the crate's text.
fn inward(json: bool, bytes: &[u8]) -> PhpResult<String> {
    if !json {
        return Ok(wide(bytes).into_owned());
    }
    String::from_utf8(bytes.to_vec()).map_err(|_| {
        let class = ClassEntry::try_find("JsonException").unwrap_or_else(ce::exception);
        PhpException::new("Malformed UTF-8 characters, possibly incorrectly encoded".to_owned(), 5, class)
    })
}

/// The crate's text as the bytes PHP gave.
fn outward(json: bool, text: &str) -> Zval {
    if json { string(text.as_bytes()) } else { string(&narrow(text)) }
}

fn thrown(json: bool, error: &utopia_query::Error) -> PhpException {
    if json { exception(error) } else { failed(error) }
}

fn type_error(message: String) -> PhpException {
    PhpException::new(message, 0, ce::type_error())
}

/// A scalar as PHP passes it to a `string` parameter: anything else is a `TypeError`.
fn scalar(json: bool, value: &Zval, what: &str) -> PhpResult<String> {
    if let Some(found) = value.zend_str() {
        inward(json, found.as_bytes())
    } else if let Some(found) = value.long() {
        Ok(found.to_string())
    } else if let Some(found) = value.double() {
        Ok(float(found))
    } else if let Some(found) = value.bool() {
        Ok(if found { "1".to_owned() } else { String::new() })
    } else {
        Err(type_error(format!("{what} must be of type string")))
    }
}

/// The values of an array, each as a string.
fn strings(json: bool, table: Option<&ZendHashTable>, what: &str) -> PhpResult<Vec<String>> {
    table.into_iter().flat_map(ZendHashTable::values).map(|value| scalar(json, value, what)).collect()
}

/// An array key as the string PHP makes of it.
fn key(json: bool, key: &ArrayKey<'_>) -> PhpResult<String> {
    match key {
        ArrayKey::Long(number) => Ok(number.to_string()),
        ArrayKey::String(text) => inward(json, text.as_bytes()),
        ArrayKey::Str(text) => inward(json, text.as_bytes()),
        ArrayKey::ZendString(text) => inward(json, text.as_bytes()),
    }
}

/// The entries of an array PHP reads with `isset($array[$key])`: one holding `null` is not set.
fn pairs(json: bool, table: Option<&ZendHashTable>, what: &str) -> PhpResult<Vec<(String, String)>> {
    let mut out = Vec::new();
    for (name, value) in table.into_iter().flat_map(ZendHashTable::iter) {
        if !value.is_null() {
            out.push((key(json, &name)?, scalar(json, value, what)?));
        }
    }
    Ok(out)
}

/// As [`pairs`], for values PHP writes as numbers.
fn lengths(json: bool, table: Option<&ZendHashTable>, what: &str) -> PhpResult<Vec<(String, i64)>> {
    let mut out = Vec::new();
    for (name, value) in table.into_iter().flat_map(ZendHashTable::iter) {
        if value.is_null() {
            continue;
        }
        let Some(Int(length)) = Int::from_zval(value) else {
            return Err(type_error(format!("{what} must be of type int")));
        };
        out.push((key(json, &name)?, length));
    }
    Ok(out)
}

/// A value PHP holds as the crate's.
fn value(json: bool, zval: &Zval) -> PhpResult<Value> {
    Ok(if zval.is_null() {
        Value::Null
    } else if let Some(found) = zval.bool() {
        Value::Bool(found)
    } else if let Some(found) = zval.long() {
        Value::Int(found)
    } else if let Some(found) = zval.double() {
        Value::Float(found)
    } else if let Some(found) = zval.zend_str() {
        Value::Str(php_std::Str::from(inward(json, found.as_bytes())?.as_bytes()))
    } else {
        // `(string) $value`, with the warning or the error PHP has for it.
        let cast = call("strval", vec![zval]).map_err(|thrown| match thrown {
            Some(thrown) => match thrown.into_zval(false) {
                Ok(thrown) => PhpException::from_message(String::new()).with_object(thrown),
                Err(error) => PhpException::from(error),
            },
            None => type_error("A value must be a scalar".to_owned()),
        })?;
        let bytes = cast.zend_str().map(|found| found.as_bytes().to_vec()).unwrap_or_default();
        Value::Str(php_std::Str::from(inward(json, &bytes)?.as_bytes()))
    })
}

/// The backing value of an enum case: the second slot of the case object, after its name.
fn backing(case: &Zval) -> Option<&str> {
    slots(case.object()?).get(1)?.str()
}

// ---------------------------------------------------------------------------------------
// Blueprints
// ---------------------------------------------------------------------------------------

/// What a schema reads: a blueprint class, and its properties in the order they are read.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Kind {
    Table,
    Partitioning,
    Column,
    Index,
    ForeignKey,
    Check,
    Rename,
}

/// The class of each [`Kind`] under `Utopia\Query\Schema\` and the properties read of it.
/// `Column\ClickHouse` and `Table\ClickHouse` alone declare the last ones of theirs.
const KINDS: [(&str, &[&str]); 7] = [
    (
        "Table",
        &[
            "name",
            "columns",
            "indexes",
            "foreignKeys",
            "dropColumns",
            "renameColumns",
            "dropIndexes",
            "dropForeignKeys",
            "rawColumnDefs",
            "rawIndexDefs",
            "checks",
            "compositePrimaryKey",
            "orderBy",
            "partitionType",
            "partitionExpression",
            "partitionCount",
            "engine",
            "engineArgs",
            "ttl",
            "settings",
            "sampleBy",
            "orderByRaw",
        ],
    ),
    ("Table", &["partitionType", "partitionExpression", "partitionCount"]),
    (
        "Column",
        &[
            "type",
            "name",
            "length",
            "precision",
            "scale",
            "isNullable",
            "defaultRaw",
            "hasDefault",
            "default",
            "isUnsigned",
            "isUnique",
            "isPrimary",
            "isAutoIncrement",
            "after",
            "comment",
            "enumValues",
            "srid",
            "dimensions",
            "isModify",
            "collation",
            "checkExpression",
            "generatedExpression",
            "generatedStored",
            "ttl",
            "userTypeName",
            "isLowCardinality",
            "fixedStringLength",
            "codecs",
            "arrayElementType",
            "tupleElementTypes",
        ],
    ),
    (
        "Index",
        &[
            "type",
            "name",
            "columns",
            "lengths",
            "orders",
            "method",
            "operatorClass",
            "collations",
            "rawColumns",
            "algorithm",
            "algorithmArgs",
            "granularity",
        ],
    ),
    ("ForeignKey", &["column", "refTable", "refColumn", "onDelete", "onUpdate"]),
    ("CheckConstraint", &["name", "expression"]),
    ("RenameColumn", &["from", "to"]),
];

/// Where one class keeps the properties of a [`Kind`]: the slot of each, or none where the
/// class does not declare it.
struct Layout {
    class: *const ClassEntry,
    kind: Kind,
    slots: Rc<[Option<usize>]>,
}

thread_local! {
    /// The layouts found this request. A blueprint is a handful of classes, so this stays short.
    static LAYOUTS: RefCell<Vec<Layout>> = const { RefCell::new(Vec::new()) };
    /// The package's classes looked up this request, by the name they were asked for.
    static CLASSES: RefCell<Vec<(&'static str, &'static ClassEntry)>> = const { RefCell::new(Vec::new()) };
    /// The slots of [`STATEMENT_PROPERTIES`] in the package's `Statement`, found once a request.
    static STATEMENT_SLOTS: Cell<Option<[usize; 5]>> = const { Cell::new(None) };
}

/// Forgets the classes at the end of a request, when PHP frees them.
pub fn request_shutdown() {
    LAYOUTS.with_borrow_mut(Vec::clear);
    CLASSES.with_borrow_mut(Vec::clear);
    STATEMENT_SLOTS.set(None);
}

/// A class of the package, looked up once a request.
fn known(name: &'static str) -> PhpResult<&'static ClassEntry> {
    if let Some(found) =
        CLASSES.with_borrow(|classes| classes.iter().find(|(known, _)| std::ptr::eq(*known, name)).map(|found| found.1))
    {
        return Ok(found);
    }
    let found = class(name)?;
    CLASSES.with_borrow_mut(|classes| classes.push((name, found)));
    Ok(found)
}

/// The slot `class` keeps its property `name` in.
pub(super) fn slot_of(class: &ClassEntry, name: &str) -> Option<usize> {
    let info = class.properties_info.get(name).filter(|found| found.is_ptr())?;
    // SAFETY: a class's `properties_info` holds a pointer to a `zend_property_info` for each
    // property it declares.
    let offset = usize::try_from(unsafe { (*info.value.ptr.cast::<zend_property_info>()).offset }).ok()?;
    let first = std::mem::offset_of!(ZendObject, properties_table);
    let slot = offset.checked_sub(first)? / size_of::<Zval>();
    (slot < usize::try_from(class.default_properties_count).unwrap_or(0)).then_some(slot)
}

/// The properties of a blueprint object, read once each in the order [`KINDS`] lists them.
struct Fields<'a> {
    object: &'a ZendObject,
    slots: Rc<[Option<usize>]>,
    next: Cell<usize>,
    names: &'static [&'static str],
    json: bool,
}

impl<'a> Fields<'a> {
    /// `value` as an object of the class of `kind`.
    fn of(value: &'a Zval, kind: Kind, json: bool) -> PhpResult<Self> {
        let (name, names) = KINDS[kind as usize];
        let wrong = || type_error(format!("A blueprint's {name} must be of type {NAMESPACE}{name}"));
        let object = value.object().ok_or_else(wrong)?;
        let class = object.get_class_entry();
        let laid = |layout: &&Layout| std::ptr::eq(layout.class, class) && layout.kind == kind;
        let slots = match LAYOUTS.with_borrow(|layouts| layouts.iter().find(laid).map(|layout| layout.slots.clone())) {
            Some(slots) => slots,
            None => {
                if !object.instance_of(self::class(&format!("{NAMESPACE}{name}"))?) {
                    return Err(wrong());
                }
                let slots: Rc<[Option<usize>]> = names.iter().map(|name| slot_of(class, name)).collect();
                LAYOUTS.with_borrow_mut(|layouts| layouts.push(Layout { class, kind, slots: slots.clone() }));
                slots
            }
        };
        Ok(Fields { object, slots, next: Cell::new(0), names, json })
    }

    /// The next property, which is `name`: nothing where the class does not declare it or the
    /// object never initialized it.
    fn find(&self, name: &str) -> Option<&'a Zval> {
        let index = self.next.replace(self.next.get() + 1);
        debug_assert_eq!(self.names.get(index), Some(&name), "a blueprint is read in the order of KINDS");
        let found = slots(self.object).get((*self.slots.get(index)?)?)?;
        // SAFETY: a slot's type tag is readable in any state; 0 is a property never initialized.
        (unsafe { found.u1.type_info } != 0).then_some(found)
    }

    fn get(&self, name: &str) -> PhpResult<&'a Zval> {
        self.find(name).ok_or_else(|| {
            PhpException::new(
                format!(
                    "Typed property {}::${name} must not be accessed before initialization",
                    self.object.get_class_entry().name().unwrap_or_default()
                ),
                0,
                ce::error(),
            )
        })
    }

    fn text(&self, name: &str) -> PhpResult<String> {
        scalar(self.json, self.get(name)?, name)
    }

    fn maybe_text(&self, name: &str) -> PhpResult<Option<String>> {
        match self.find(name) {
            Some(found) if !found.is_null() => scalar(self.json, found, name).map(Some),
            _ => Ok(None),
        }
    }

    fn flag(&self, name: &str) -> bool {
        self.find(name).is_some_and(|found| found.bool() == Some(true))
    }

    fn maybe_int(&self, name: &str) -> Option<i64> {
        self.find(name).and_then(Zval::long)
    }

    fn table(&self, name: &str) -> Option<&'a ZendHashTable> {
        self.find(name).and_then(Zval::array)
    }

    fn texts(&self, name: &str) -> PhpResult<Vec<String>> {
        strings(self.json, self.table(name), name)
    }

    fn each<T>(&self, name: &str, each: impl FnMut(&'a Zval) -> PhpResult<T>) -> PhpResult<Vec<T>> {
        self.table(name).into_iter().flat_map(ZendHashTable::values).map(each).collect()
    }

    /// An enum property as the crate's case, `None` when it is null.
    fn case<T>(&self, name: &str, parse: fn(&str) -> Option<T>) -> Option<T> {
        self.find(name).and_then(backing).and_then(parse)
    }
}

/// Reads a PHP `Column` onto the end of `columns`: a definition is too large to hand around.
fn column<S: Dialect>(value: &Zval, columns: &mut Vec<Column<S>>) -> PhpResult<()> {
    let fields = Fields::of(value, Kind::Column, S::JSON)?;
    let kind = fields
        .case("type", ColumnType::try_from_value)
        .ok_or_else(|| type_error(format!("A column's type must be of type {}", ColumnType::PHP_CLASS)))?;
    columns.push(Column::new(fields.text("name")?, kind));
    let Some(found) = columns.last_mut().map(Column::definition_mut) else {
        return Ok(());
    };
    found.length = fields.maybe_int("length");
    found.precision = fields.maybe_int("precision");
    found.scale = fields.maybe_int("scale");
    found.nullable = fields.flag("isNullable");
    found.default_raw = fields.maybe_text("defaultRaw")?;
    let (has_default, default) = (fields.flag("hasDefault"), fields.find("default"));
    if let (true, Some(default)) = (has_default, default) {
        // `(string) $float` follows the `precision` setting, and the crate writes floats for
        // Appwrite's (-1). A raw default is written as it is, where the default would be.
        if let (Some(number), None) = (default.double(), &found.default_raw) {
            found.default_raw = Some(float(number));
        }
        // PHP only writes the default of a column without a raw one, and MongoDB's validator
        // only asks whether there is one.
        found.default =
            Some(if S::JSON || found.default_raw.is_some() { Value::Null } else { self::value(false, default)? });
    }
    found.unsigned = fields.flag("isUnsigned");
    found.unique = fields.flag("isUnique");
    found.primary = fields.flag("isPrimary");
    found.auto_increment = fields.flag("isAutoIncrement");
    found.after = fields.maybe_text("after")?;
    found.comment = fields.maybe_text("comment")?;
    found.enum_values = fields.texts("enumValues")?;
    found.srid = fields.maybe_int("srid");
    found.dimensions = fields.maybe_int("dimensions");
    found.modify = fields.flag("isModify");
    found.collation = fields.maybe_text("collation")?;
    found.check = fields.maybe_text("checkExpression")?;
    found.generated_expression = fields.maybe_text("generatedExpression")?;
    found.generated_stored = fields.find("generatedStored").and_then(Zval::bool);
    found.ttl = fields.maybe_text("ttl")?;
    found.user_type = fields.maybe_text("userTypeName")?;
    found.low_cardinality = fields.flag("isLowCardinality");
    found.fixed_string_length = fields.maybe_int("fixedStringLength");
    found.codecs = fields.texts("codecs")?;
    found.array_element_type = fields.case("arrayElementType", ColumnType::try_from_value);
    found.tuple_element_types = fields
        .each("tupleElementTypes", |kind| Ok(backing(kind).and_then(ColumnType::try_from_value)))?
        .into_iter()
        .flatten()
        .collect();
    Ok(())
}

fn index(value: &Zval, json: bool) -> PhpResult<Index> {
    let fields = Fields::of(value, Kind::Index, json)?;
    let kind = fields.case("type", IndexType::try_from_value).unwrap_or(IndexType::Index);
    let mut index = Index::new(fields.text("name")?, fields.texts("columns")?, kind);
    index.lengths = lengths(json, fields.table("lengths"), "lengths")?;
    index.orders = pairs(json, fields.table("orders"), "orders")?;
    index.method = fields.text("method")?;
    index.operator_class = fields.text("operatorClass")?;
    index.collations = pairs(json, fields.table("collations"), "collations")?;
    index.raw_columns = fields.texts("rawColumns")?;
    index.algorithm = fields.case("algorithm", IndexAlgorithm::try_from_value);
    // PHP takes these as `string|int|float`: a bool is its number, and nothing else passes.
    index.algorithm_args = fields.each("algorithmArgs", |argument| match argument.bool() {
        Some(flag) => Ok(Value::Int(i64::from(flag))),
        None if argument.is_long() || argument.is_double() || argument.is_string() => self::value(json, argument),
        None => Err(type_error("An index algorithm argument must be of type string|int|float".to_owned())),
    })?;
    index.granularity = fields.maybe_int("granularity");
    Ok(index)
}

fn foreign_key(value: &Zval, json: bool) -> PhpResult<ForeignKey> {
    let fields = Fields::of(value, Kind::ForeignKey, json)?;
    Ok(ForeignKey {
        column: fields.text("column")?,
        ref_table: fields.text("refTable")?,
        ref_column: fields.text("refColumn")?,
        on_delete: fields.case("onDelete", ForeignKeyAction::try_from_value),
        on_update: fields.case("onUpdate", ForeignKeyAction::try_from_value),
    })
}

/// A PHP `Table` as the crate's, to compile.
fn blueprint<S: Dialect>(value: &Zval) -> PhpResult<Table<S>> {
    let json = S::JSON;
    let fields = Fields::of(value, Kind::Table, json)?;
    let mut table = S::fresh().table(fields.text("name")?);
    let found = table.definition_mut();
    let columns = fields.table("columns");
    found.columns.reserve(columns.map_or(0, ZendHashTable::len));
    for item in columns.into_iter().flat_map(ZendHashTable::values) {
        column::<S>(item, &mut found.columns)?;
    }
    found.indexes = fields.each("indexes", |item| index(item, json))?;
    found.foreign_keys = fields.each("foreignKeys", |item| foreign_key(item, json))?;
    found.drop_columns = fields.texts("dropColumns")?;
    found.rename_columns = fields.each("renameColumns", |item| {
        let rename = Fields::of(item, Kind::Rename, json)?;
        Ok(RenameColumn { from: rename.text("from")?, to: rename.text("to")? })
    })?;
    found.drop_indexes = fields.texts("dropIndexes")?;
    found.drop_foreign_keys = fields.texts("dropForeignKeys")?;
    found.raw_column_defs = fields.texts("rawColumnDefs")?;
    found.raw_index_defs = fields.texts("rawIndexDefs")?;
    found.checks = fields.each("checks", |item| {
        let check = Fields::of(item, Kind::Check, json)?;
        Ok(CheckConstraint { name: check.text("name")?, expression: check.text("expression")? })
    })?;
    found.composite_primary_key = fields.texts("compositePrimaryKey")?;
    found.order_by = fields.texts("orderBy")?;
    found.partition_type = fields.case("partitionType", PartitionType::try_from_value);
    found.partition_expression = fields.text("partitionExpression")?;
    found.partition_count = fields.maybe_int("partitionCount");
    found.engine = fields.case("engine", Engine::try_from_value);
    found.engine_args = fields.texts("engineArgs")?;
    found.ttl = fields.maybe_text("ttl")?;
    for (name, setting) in fields.table("settings").into_iter().flat_map(ZendHashTable::iter) {
        let setting = if setting.is_null() { String::new() } else { scalar(json, setting, "settings")? };
        found.settings.push((key(json, &name)?, setting));
    }
    found.sample_by = fields.maybe_text("sampleBy")?;
    found.order_by_raw = fields.maybe_text("orderByRaw")?;
    Ok(table)
}

/// As [`blueprint`], with only what `compileCreatePartitioning()` reads.
fn partitioned<S: Dialect>(value: &Zval) -> PhpResult<Table<S>> {
    let fields = Fields::of(value, Kind::Partitioning, S::JSON)?;
    let mut table = S::fresh().table(String::new());
    let found = table.definition_mut();
    found.partition_type = fields.case("partitionType", PartitionType::try_from_value);
    found.partition_expression = fields.text("partitionExpression")?;
    found.partition_count = fields.maybe_int("partitionCount");
    Ok(table)
}

// ---------------------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------------------

/// An argument that must be an object of one PHP class.
macro_rules! object_argument {
    ($(#[$meta:meta])* $name:ident, $class:literal) => {
        $(#[$meta])*
        pub struct $name<'a>(&'a Zval);

        impl<'a> FromZval<'a> for $name<'a> {
            const TYPE: DataType = DataType::object($class);

            fn from_zval(zval: &'a Zval) -> Option<Self> {
                let class = known($class).ok()?;
                zval.object().filter(|object| object.instance_of(class)).map(|_| $name(zval))
            }
        }
    };
}

object_argument!(
    /// A `Schema\Table` argument.
    Blueprint,
    "Utopia\\Query\\Schema\\Table"
);
object_argument!(
    /// A `Builder` argument.
    Query,
    "Utopia\\Query\\Builder"
);
object_argument!(
    /// A `Closure` argument.
    Executor,
    "Closure"
);
object_argument!(
    /// A `ForeignKeyAction` argument.
    Action,
    "Utopia\\Query\\Schema\\ForeignKeyAction"
);
object_argument!(
    /// A `TriggerTiming` argument.
    Timing,
    "Utopia\\Query\\Schema\\TriggerTiming"
);
object_argument!(
    /// A `TriggerEvent` argument.
    Event,
    "Utopia\\Query\\Schema\\TriggerEvent"
);

/// What a PHP builder built, for the crate to wrap in a view: its bindings stay PHP's.
#[derive(Clone)]
struct Built(String);

impl AnyBuilder for Built {
    fn build(&mut self) -> utopia_query::Result<Compiled> {
        Ok(Compiled::new(self.0.clone(), Vec::new()))
    }

    fn clone_box(&self) -> Box<dyn AnyBuilder> {
        Box::new(self.clone())
    }

    fn php_class(&self) -> &'static str {
        "Utopia\\Query\\Builder"
    }

    fn as_any(&self) -> &dyn std::any::Any {
        self
    }
}

/// `$builder->build()`: its query, and its bindings as PHP holds them.
fn built(json: bool, builder: &Zval) -> PhpResult<(Built, Zval)> {
    let object = builder.object().ok_or_else(|| type_error("A view's query must be a builder".to_owned()))?;
    let statement = object.try_call_method("build", vec![]).map_err(|error| {
        match ext_php_rs::zend::ExecutorGlobals::take_exception().map(|thrown| thrown.into_zval(false)) {
            Some(Ok(thrown)) => PhpException::from_message(String::new()).with_object(thrown),
            _ => PhpException::from(error),
        }
    })?;
    let fields = statement
        .object()
        .and_then(|object| object.get_properties().ok())
        .ok_or_else(|| type_error(format!("A builder must build a {STATEMENT}")))?;
    // A statement whose constructor never ran has neither: PHP's error for reading one.
    let property = |name: &str| {
        let found = fields.get(name).map(|found| found.indirect().unwrap_or(found));
        // SAFETY: a slot's type tag is readable in any state; 0 is a property never initialized.
        found.filter(|found| unsafe { found.u1.type_info } != 0).ok_or_else(|| {
            let message = format!("Typed property {STATEMENT}::${name} must not be accessed before initialization");
            PhpException::new(message, 0, ce::error())
        })
    };
    let query = property("query")?.zend_str().map(|found| inward(json, found.as_bytes())).transpose()?;
    let bindings = property("bindings")?.shallow_clone();
    Ok((Built(query.unwrap_or_default()), bindings))
}

// ---------------------------------------------------------------------------------------
// Statements
// ---------------------------------------------------------------------------------------

/// What every schema object holds: the executor its statements carry.
#[derive(Default)]
pub struct Held {
    executor: Option<Zval>,
}

impl Clone for Held {
    fn clone(&self) -> Self {
        Held { executor: self.executor.as_ref().map(Zval::shallow_clone) }
    }
}

/// The package's `Statement` of what the crate compiled, carrying the schema's executor.
fn statement<S: Dialect>(
    held: &Held,
    compiled: utopia_query::Result<Compiled>,
    bindings: Option<Zval>,
) -> PhpResult<Zval> {
    let compiled = compiled.map_err(|error| thrown(S::JSON, &error))?;
    let class = known(STATEMENT)?;
    let slots = STATEMENT_SLOTS.get().or_else(|| {
        let slots: Vec<usize> = STATEMENT_PROPERTIES.iter().filter_map(|name| slot_of(class, name)).collect();
        let slots = slots.try_into().ok();
        STATEMENT_SLOTS.set(slots);
        slots
    });
    let mut read_only = Zval::new();
    read_only.set_bool(compiled.read_only);
    let mut values = [
        outward(S::JSON, &compiled.query),
        bindings.unwrap_or_else(empty),
        read_only,
        held.executor.as_ref().map_or_else(null, Zval::shallow_clone),
        null(),
    ];
    let object = match slots {
        // What the constructor does, without the call: it only assigns its promoted
        // properties, and these values already have the types it declares. If `Statement.php`'s
        // constructor ever does more, this has to do it too.
        Some(slots) => {
            let mut object = class.new();
            for (slot, value) in slots.into_iter().zip(values) {
                // SAFETY: `slot_of` only answers a slot the class declares, and a new object's
                // typed property without a default is undefined, so nothing is overwritten.
                unsafe { object.properties_table.as_mut_ptr().add(slot).write(value) };
            }
            object
        }
        // A `Statement` that is not the package's, lacking one of the properties.
        None => construct(class, &mut values[..4])?,
    };
    object.into_zval(false).map_err(PhpException::from)
}

/// `new Table\<Dialect>($this, $name)`.
fn table_of<S: Dialect>(this: &mut ZendObject, name: &PhpString<'_>) -> PhpResult<Zval> {
    let mut schema = Zval::new();
    schema.set_object(this);
    construct(known(S::TABLE)?, &mut [schema, string(name.bytes())])?.into_zval(false).map_err(PhpException::from)
}

/// The `createIndex()` arguments every dialect shares.
struct IndexArguments<'a> {
    unique: bool,
    kind: PhpString<'a>,
    method: PhpString<'a>,
    operator_class: PhpString<'a>,
    lengths: Arr<'a>,
    orders: Arr<'a>,
    collations: Arr<'a>,
    raw_columns: Arr<'a>,
    concurrently: bool,
}

fn create_index<S: Dialect>(
    held: &Held,
    table: &PhpString<'_>,
    name: &PhpString<'_>,
    columns: &Arr<'_>,
    arguments: &IndexArguments<'_>,
) -> PhpResult<Zval> {
    let json = S::JSON;
    let kind = match arguments.kind.bytes() {
        // PHP only reads the type of an index that is not unique, and MongoDB's never.
        _ if arguments.unique || json => None,
        b"" => None,
        kind => Some(std::str::from_utf8(kind).ok().and_then(IndexType::try_from_value).ok_or_else(|| {
            let message =
                [b"\"", kind, b"\" is not a valid backing value for enum ", IndexType::PHP_CLASS.as_bytes()].concat();
            PhpException::new(String::from_utf8_lossy(&message).into_owned(), 0, ce::value_error())
        })?),
    };
    let options = IndexOptions {
        unique: arguments.unique,
        kind,
        method: inward(json, arguments.method.bytes())?,
        operator_class: inward(json, arguments.operator_class.bytes())?,
        lengths: lengths(json, arguments.lengths.table(), "lengths")?,
        orders: pairs(json, arguments.orders.table(), "orders")?,
        collations: pairs(json, arguments.collations.table(), "collations")?,
        raw_columns: strings(json, arguments.raw_columns.table(), "rawColumns")?,
        concurrently: arguments.concurrently,
    };
    let columns = strings(json, columns.table(), "columns")?;
    let columns: Vec<&str> = columns.iter().map(String::as_str).collect();
    let (table, name) = (inward(json, table.bytes())?, inward(json, name.bytes())?);
    statement::<S>(held, S::fresh().create_index(&table, &name, &columns, &options), None)
}

/// `createProcedure()`'s parameters: a list of `[ParameterDirection, name, type]`.
fn parameters(json: bool, params: &Arr<'_>) -> PhpResult<Vec<(ParameterDirection, String, String)>> {
    let invalid = || type_error("A procedure parameter must be [ParameterDirection, string, string]".to_owned());
    let mut out = Vec::new();
    for param in params.table().into_iter().flat_map(ZendHashTable::values) {
        let parts = param.array().ok_or_else(invalid)?;
        let part = |index: i64| parts.get_index(index).ok_or_else(invalid);
        let direction = backing(part(0)?).and_then(ParameterDirection::try_from_value).ok_or_else(invalid)?;
        out.push((direction, scalar(json, part(1)?, "name")?, scalar(json, part(2)?, "type")?));
    }
    Ok(out)
}

/// The methods of a schema class. Every class declares its own, since a parent's method
/// reads its own Rust type: the ones all schemas have, the ones of each feature the dialect
/// has (PHP's `Schema\Feature\*`), and the dialect's own.
macro_rules! schema_methods {
    ($class:ident, $schema:ty, [$($feature:ident),*] $(, { $($own:tt)* })?) => {
        schema_methods!(@add $class, $schema, {
            pub fn __construct() -> Self {
                Self(Held::default())
            }

            #[php(name = "setExecutor")]
            pub fn set_executor<'a>(
                self_: &'a mut ZendClassObject<$class>,
                executor: Executor<'_>,
            ) -> &'a mut ZendClassObject<$class> {
                self_.0.executor = Some(executor.0.shallow_clone());
                self_
            }

            pub fn table(self_: &mut ZendClassObject<$class>, name: PhpString<'_>) -> PhpResult<Zval> {
                table_of::<$schema>(&mut self_.std, &name)
            }

            #[php(name = "compileCreate", defaults(ifNotExists = "Bool(false)"))]
            pub fn compile_create(&self, table: Blueprint<'_>, ifNotExists: Bool) -> PhpResult<Zval> {
                let table = blueprint::<$schema>(table.0)?;
                statement::<$schema>(&self.0, <$schema>::fresh().compile_create(&table, ifNotExists.0), None)
            }

            #[php(name = "compileAlter")]
            pub fn compile_alter(&self, table: Blueprint<'_>) -> PhpResult<Zval> {
                let table = blueprint::<$schema>(table.0)?;
                statement::<$schema>(&self.0, <$schema>::fresh().compile_alter(&table), None)
            }

            #[php(name = "compileDrop")]
            pub fn compile_drop(&self, name: PhpString<'_>, ifExists: Bool) -> PhpResult<Zval> {
                let name = inward(<$schema>::JSON, name.bytes())?;
                statement::<$schema>(&self.0, <$schema>::fresh().compile_drop(&name, ifExists.0), None)
            }

            #[php(name = "compileRename")]
            pub fn compile_rename(&self, from: PhpString<'_>, to: PhpString<'_>) -> PhpResult<Zval> {
                let (from, to) = (inward(<$schema>::JSON, from.bytes())?, inward(<$schema>::JSON, to.bytes())?);
                statement::<$schema>(&self.0, <$schema>::fresh().compile_rename(&from, &to), None)
            }

            #[php(name = "compileTruncate")]
            pub fn compile_truncate(&self, name: PhpString<'_>) -> PhpResult<Zval> {
                let name = inward(<$schema>::JSON, name.bytes())?;
                statement::<$schema>(&self.0, <$schema>::fresh().compile_truncate(&name), None)
            }

            #[php(name = "dropIndex")]
            pub fn drop_index(&self, table: PhpString<'_>, name: PhpString<'_>) -> PhpResult<Zval> {
                let (table, name) = (inward(<$schema>::JSON, table.bytes())?, inward(<$schema>::JSON, name.bytes())?);
                statement::<$schema>(&self.0, <$schema>::fresh().drop_index(&table, &name), None)
            }
        } [$($feature),*] { $($($own)*)? });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [] { $($own:tt)* }) => {
        #[php_impl]
        impl $class {
            $($methods)*
            $($own)*
        }
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [index $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "createIndex", defaults(
                unique = "Bool(false)", r#type = "\"\"", method = "\"\"", operatorClass = "\"\"",
                lengths = "Arr::Empty", orders = "Arr::Empty", collations = "Arr::Empty", rawColumns = "Arr::Empty"
            ))]
            #[allow(clippy::too_many_arguments)]
            pub fn create_index(
                &self,
                table: PhpString<'_>,
                name: PhpString<'_>,
                columns: Arr<'_>,
                unique: Bool,
                r#type: PhpString<'_>,
                method: PhpString<'_>,
                operatorClass: PhpString<'_>,
                lengths: Arr<'_>,
                orders: Arr<'_>,
                collations: Arr<'_>,
                rawColumns: Arr<'_>,
            ) -> PhpResult<Zval> {
                let arguments = IndexArguments {
                    unique: unique.0, kind: r#type, method, operator_class: operatorClass, lengths, orders, collations,
                    raw_columns: rawColumns,
                    concurrently: false,
                };
                create_index::<$schema>(&self.0, &table, &name, &columns, &arguments)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [foreign_keys $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "addForeignKey")]
            #[allow(clippy::too_many_arguments)]
            pub fn add_foreign_key(
                &self,
                table: PhpString<'_>,
                name: PhpString<'_>,
                column: PhpString<'_>,
                refTable: PhpString<'_>,
                refColumn: PhpString<'_>,
                onDelete: Option<Action<'_>>,
                onUpdate: Option<Action<'_>>,
            ) -> PhpResult<Zval> {
                let json = <$schema>::JSON;
                let action = |action: Option<Action<'_>>| {
                    action.and_then(|action| backing(action.0)).and_then(ForeignKeyAction::try_from_value)
                };
                let key = ForeignKey {
                    column: inward(json, column.bytes())?,
                    ref_table: inward(json, refTable.bytes())?,
                    ref_column: inward(json, refColumn.bytes())?,
                    on_delete: action(onDelete),
                    on_update: action(onUpdate),
                };
                let (table, name) = (inward(json, table.bytes())?, inward(json, name.bytes())?);
                statement::<$schema>(&self.0, <$schema>::fresh().add_foreign_key(&table, &name, &key), None)
            }

            #[php(name = "dropForeignKey")]
            pub fn drop_foreign_key(&self, table: PhpString<'_>, name: PhpString<'_>) -> PhpResult<Zval> {
                let (table, name) = (inward(<$schema>::JSON, table.bytes())?, inward(<$schema>::JSON, name.bytes())?);
                statement::<$schema>(&self.0, <$schema>::fresh().drop_foreign_key(&table, &name), None)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [procedures $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "createProcedure")]
            pub fn create_procedure(&self, name: PhpString<'_>, params: Arr<'_>, body: PhpString<'_>) -> PhpResult<Zval> {
                let json = <$schema>::JSON;
                let params = parameters(json, &params)?;
                let params: Vec<_> = params.iter().map(|(way, name, kind)| (*way, name.as_str(), kind.as_str())).collect();
                let (name, body) = (inward(json, name.bytes())?, inward(json, body.bytes())?);
                statement::<$schema>(&self.0, <$schema>::fresh().create_procedure(&name, &params, &body), None)
            }

            #[php(name = "dropProcedure")]
            pub fn drop_procedure(&self, name: PhpString<'_>) -> PhpResult<Zval> {
                let name = inward(<$schema>::JSON, name.bytes())?;
                statement::<$schema>(&self.0, <$schema>::fresh().drop_procedure(&name), None)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [triggers $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "createTrigger")]
            pub fn create_trigger(
                &self,
                name: PhpString<'_>,
                table: PhpString<'_>,
                timing: Timing<'_>,
                event: Event<'_>,
                body: PhpString<'_>,
            ) -> PhpResult<Zval> {
                let json = <$schema>::JSON;
                let timing = backing(timing.0).and_then(TriggerTiming::try_from_value);
                let event = backing(event.0).and_then(TriggerEvent::try_from_value);
                let (Some(timing), Some(event)) = (timing, event) else {
                    return Err(type_error("A trigger's timing and event must be enum cases".to_owned()));
                };
                let (name, table, body) =
                    (inward(json, name.bytes())?, inward(json, table.bytes())?, inward(json, body.bytes())?);
                statement::<$schema>(&self.0, <$schema>::fresh().create_trigger(&name, &table, timing, event, &body), None)
            }

            #[php(name = "dropTrigger")]
            pub fn drop_trigger(&self, name: PhpString<'_>, table: Option<PhpString<'_>>) -> PhpResult<Zval> {
                let json = <$schema>::JSON;
                let name = inward(json, name.bytes())?;
                let table = table.map(|table| inward(json, table.bytes())).transpose()?;
                statement::<$schema>(&self.0, <$schema>::fresh().drop_trigger(&name, table.as_deref()), None)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [table_comments $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "commentOnTable")]
            pub fn comment_on_table(&self, table: PhpString<'_>, comment: PhpString<'_>) -> PhpResult<Zval> {
                let (table, comment) =
                    (inward(<$schema>::JSON, table.bytes())?, inward(<$schema>::JSON, comment.bytes())?);
                statement::<$schema>(&self.0, <$schema>::fresh().comment_on_table(&table, &comment), None)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [column_comments $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "commentOnColumn")]
            pub fn comment_on_column(
                &self,
                table: PhpString<'_>,
                column: PhpString<'_>,
                comment: PhpString<'_>,
            ) -> PhpResult<Zval> {
                let json = <$schema>::JSON;
                let (table, column, comment) =
                    (inward(json, table.bytes())?, inward(json, column.bytes())?, inward(json, comment.bytes())?);
                statement::<$schema>(&self.0, <$schema>::fresh().comment_on_column(&table, &column, &comment), None)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [create_partition $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "createPartition")]
            pub fn create_partition(
                &self,
                parent: PhpString<'_>,
                name: PhpString<'_>,
                expression: PhpString<'_>,
            ) -> PhpResult<Zval> {
                let json = <$schema>::JSON;
                let (parent, name, expression) =
                    (inward(json, parent.bytes())?, inward(json, name.bytes())?, inward(json, expression.bytes())?);
                statement::<$schema>(&self.0, <$schema>::fresh().create_partition(&parent, &name, &expression), None)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [drop_partition $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "dropPartition")]
            pub fn drop_partition(&self, table: PhpString<'_>, name: PhpString<'_>) -> PhpResult<Zval> {
                let (table, name) = (inward(<$schema>::JSON, table.bytes())?, inward(<$schema>::JSON, name.bytes())?);
                statement::<$schema>(&self.0, <$schema>::fresh().drop_partition(&table, &name), None)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [views $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "createView")]
            pub fn create_view(&self, name: PhpString<'_>, query: Query<'_>) -> PhpResult<Zval> {
                let (mut query, bindings) = built(<$schema>::JSON, query.0)?;
                let name = inward(<$schema>::JSON, name.bytes())?;
                statement::<$schema>(&self.0, <$schema>::fresh().create_view(&name, &mut query), Some(bindings))
            }

            #[php(name = "dropView")]
            pub fn drop_view(&self, name: PhpString<'_>) -> PhpResult<Zval> {
                let name = inward(<$schema>::JSON, name.bytes())?;
                statement::<$schema>(&self.0, <$schema>::fresh().drop_view(&name), None)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [replace_view $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "createOrReplaceView")]
            pub fn create_or_replace_view(&self, name: PhpString<'_>, query: Query<'_>) -> PhpResult<Zval> {
                let (mut query, bindings) = built(<$schema>::JSON, query.0)?;
                let name = inward(<$schema>::JSON, name.bytes())?;
                statement::<$schema>(&self.0, <$schema>::fresh().create_or_replace_view(&name, &mut query), Some(bindings))
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [databases $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "createDatabase")]
            pub fn create_database(&self, name: PhpString<'_>) -> PhpResult<Zval> {
                let name = inward(<$schema>::JSON, name.bytes())?;
                statement::<$schema>(&self.0, <$schema>::fresh().create_database(&name), None)
            }

            #[php(name = "dropDatabase")]
            pub fn drop_database(&self, name: PhpString<'_>) -> PhpResult<Zval> {
                let name = inward(<$schema>::JSON, name.bytes())?;
                statement::<$schema>(&self.0, <$schema>::fresh().drop_database(&name), None)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [rename_index $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "renameIndex")]
            pub fn rename_index(&self, table: PhpString<'_>, from: PhpString<'_>, to: PhpString<'_>) -> PhpResult<Zval> {
                let json = <$schema>::JSON;
                let (table, from, to) = (inward(json, table.bytes())?, inward(json, from.bytes())?, inward(json, to.bytes())?);
                statement::<$schema>(&self.0, <$schema>::fresh().rename_index(&table, &from, &to), None)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [analyze_table $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "analyzeTable")]
            pub fn analyze_table(&self, table: PhpString<'_>) -> PhpResult<Zval> {
                let table = inward(<$schema>::JSON, table.bytes())?;
                statement::<$schema>(&self.0, <$schema>::fresh().analyze_table(&table), None)
            }
        } [$($rest),*] { $($own)* });
    };

    (@add $class:ident, $schema:ty, { $($methods:tt)* } [partitioning $(, $rest:ident)*] { $($own:tt)* }) => {
        schema_methods!(@add $class, $schema, {
            $($methods)*

            #[php(name = "compileCreatePartitioning")]
            pub fn compile_create_partitioning(&self, table: Blueprint<'_>) -> PhpResult<Zval> {
                let table = partitioned::<$schema>(table.0)?;
                Ok(outward(<$schema>::JSON, &<$schema>::fresh().compile_create_partitioning(&table)))
            }
        } [$($rest),*] { $($own)* });
    };
}

/// PHP's `Schema\Feature\*`: what a dialect can do, for `instanceof`. They declare no method:
/// ext-php-rs checks a class's methods against its interface's when PHP starts, and these
/// classes return their statements untyped. The classes do have every method.
pub mod feature {
    use ext_php_rs::prelude::*;

    macro_rules! feature {
        ($name:ident, $php:literal) => {
            #[php_interface]
            #[php(name = $php)]
            #[allow(dead_code)]
            pub trait $name {}
        };
    }

    feature!(AnalyzeTable, "Utopia\\Query\\Schema\\Feature\\AnalyzeTable");
    feature!(ColumnComments, "Utopia\\Query\\Schema\\Feature\\ColumnComments");
    feature!(CreatePartition, "Utopia\\Query\\Schema\\Feature\\CreatePartition");
    feature!(Databases, "Utopia\\Query\\Schema\\Feature\\Databases");
    feature!(DropPartition, "Utopia\\Query\\Schema\\Feature\\DropPartition");
    feature!(ForeignKeys, "Utopia\\Query\\Schema\\Feature\\ForeignKeys");
    feature!(MaterializedViews, "Utopia\\Query\\Schema\\Feature\\MaterializedViews");
    feature!(Partitioning, "Utopia\\Query\\Schema\\Feature\\Partitioning");
    feature!(Procedures, "Utopia\\Query\\Schema\\Feature\\Procedures");
    feature!(RenameIndex, "Utopia\\Query\\Schema\\Feature\\RenameIndex");
    feature!(ReplaceView, "Utopia\\Query\\Schema\\Feature\\ReplaceView");
    feature!(Sequences, "Utopia\\Query\\Schema\\Feature\\Sequences");
    feature!(TableComments, "Utopia\\Query\\Schema\\Feature\\TableComments");
    feature!(Triggers, "Utopia\\Query\\Schema\\Feature\\Triggers");
    feature!(Types, "Utopia\\Query\\Schema\\Feature\\Types");
    feature!(Views, "Utopia\\Query\\Schema\\Feature\\Views");
}

/// `Utopia\Query\Schema`: what every dialect extends. A dialect written in PHP has nothing
/// here to build on, so the class only gives the dialects their common type.
///
/// No class here derives `Default`: with it, ext-php-rs 0.16.5 makes `new` of a PHP subclass
/// an object of the Rust class.
#[php_class]
#[php(name = "Utopia\\Query\\Schema")]
#[php(flags = ClassFlags::Abstract)]
#[derive(Clone)]
pub struct Base;

#[php_impl]
impl Base {}

/// `Utopia\Query\Schema\SQL`: the dialects that quote identifiers.
#[php_class]
#[php(name = "Utopia\\Query\\Schema\\SQL")]
#[php(extends(Base))]
#[php(flags = ClassFlags::Abstract)]
#[derive(Clone)]
pub struct Sql;

#[php_impl]
impl Sql {}

/// `Utopia\Query\Schema\MySQL`.
#[php_class]
#[php(name = "Utopia\\Query\\Schema\\MySQL")]
#[php(extends(Sql))]
#[php(implements(feature::PhpInterfaceForeignKeys))]
#[php(implements(feature::PhpInterfaceProcedures))]
#[php(implements(feature::PhpInterfaceTriggers))]
#[php(implements(feature::PhpInterfaceTableComments))]
#[php(implements(feature::PhpInterfaceCreatePartition))]
#[php(implements(feature::PhpInterfaceDropPartition))]
#[php(implements(feature::PhpInterfaceViews))]
#[php(implements(feature::PhpInterfaceReplaceView))]
#[php(implements(feature::PhpInterfaceDatabases))]
#[php(implements(feature::PhpInterfaceRenameIndex))]
#[php(implements(feature::PhpInterfaceAnalyzeTable))]
#[php(implements(feature::PhpInterfacePartitioning))]
#[derive(Clone)]
pub struct MySqlSchema(Held);

schema_methods!(
    MySqlSchema,
    MySql,
    [
        index,
        foreign_keys,
        procedures,
        triggers,
        table_comments,
        create_partition,
        drop_partition,
        views,
        replace_view,
        databases,
        rename_index,
        analyze_table,
        partitioning
    ],
    {
        #[php(name = "changeColumn")]
        pub fn change_column(
            &self,
            table: PhpString<'_>,
            oldName: PhpString<'_>,
            newName: PhpString<'_>,
            r#type: PhpString<'_>,
        ) -> PhpResult<Zval> {
            let (table, old, new, kind) = (
                inward(false, table.bytes())?,
                inward(false, oldName.bytes())?,
                inward(false, newName.bytes())?,
                inward(false, r#type.bytes())?,
            );
            statement::<MySql>(&self.0, MySql::new().change_column(&table, &old, &new, &kind), None)
        }

        #[php(name = "modifyColumn")]
        pub fn modify_column(
            &self,
            table: PhpString<'_>,
            name: PhpString<'_>,
            r#type: PhpString<'_>,
        ) -> PhpResult<Zval> {
            let (table, name, kind) =
                (inward(false, table.bytes())?, inward(false, name.bytes())?, inward(false, r#type.bytes())?);
            statement::<MySql>(&self.0, MySql::new().modify_column(&table, &name, &kind), None)
        }
    }
);

/// `Utopia\Query\Schema\PostgreSQL`.
#[php_class]
#[php(name = "Utopia\\Query\\Schema\\PostgreSQL")]
#[php(extends(Sql))]
#[php(implements(feature::PhpInterfaceForeignKeys))]
#[php(implements(feature::PhpInterfaceProcedures))]
#[php(implements(feature::PhpInterfaceTriggers))]
#[php(implements(feature::PhpInterfaceTypes))]
#[php(implements(feature::PhpInterfaceSequences))]
#[php(implements(feature::PhpInterfaceTableComments))]
#[php(implements(feature::PhpInterfaceColumnComments))]
#[php(implements(feature::PhpInterfaceCreatePartition))]
#[php(implements(feature::PhpInterfaceDropPartition))]
#[php(implements(feature::PhpInterfaceViews))]
#[php(implements(feature::PhpInterfaceReplaceView))]
#[php(implements(feature::PhpInterfaceDatabases))]
#[php(implements(feature::PhpInterfaceRenameIndex))]
#[php(implements(feature::PhpInterfaceAnalyzeTable))]
#[php(implements(feature::PhpInterfacePartitioning))]
#[derive(Clone)]
pub struct PostgreSqlSchema(Held);

schema_methods!(
    PostgreSqlSchema,
    PostgreSql,
    [
        foreign_keys,
        procedures,
        triggers,
        table_comments,
        column_comments,
        create_partition,
        drop_partition,
        views,
        replace_view,
        databases,
        rename_index,
        analyze_table,
        partitioning
    ],
    {
        #[php(
            name = "createIndex",
            defaults(
                unique = "Bool(false)",
                r#type = "\"\"",
                method = "\"\"",
                operatorClass = "\"\"",
                lengths = "Arr::Empty",
                orders = "Arr::Empty",
                collations = "Arr::Empty",
                rawColumns = "Arr::Empty",
                concurrently = "Bool(false)"
            )
        )]
        #[allow(clippy::too_many_arguments)]
        pub fn create_index(
            &self,
            table: PhpString<'_>,
            name: PhpString<'_>,
            columns: Arr<'_>,
            unique: Bool,
            r#type: PhpString<'_>,
            method: PhpString<'_>,
            operatorClass: PhpString<'_>,
            lengths: Arr<'_>,
            orders: Arr<'_>,
            collations: Arr<'_>,
            rawColumns: Arr<'_>,
            concurrently: Bool,
        ) -> PhpResult<Zval> {
            let arguments = IndexArguments {
                unique: unique.0,
                kind: r#type,
                method,
                operator_class: operatorClass,
                lengths,
                orders,
                collations,
                raw_columns: rawColumns,
                concurrently: concurrently.0,
            };
            create_index::<PostgreSql>(&self.0, &table, &name, &columns, &arguments)
        }

        #[php(name = "createExtension")]
        pub fn create_extension(&self, name: PhpString<'_>) -> PhpResult<Zval> {
            statement::<PostgreSql>(&self.0, PostgreSql::new().create_extension(&inward(false, name.bytes())?), None)
        }

        #[php(name = "dropExtension")]
        pub fn drop_extension(&self, name: PhpString<'_>) -> PhpResult<Zval> {
            statement::<PostgreSql>(&self.0, PostgreSql::new().drop_extension(&inward(false, name.bytes())?), None)
        }

        #[php(name = "createCollation", defaults(deterministic = "Bool(true)"))]
        pub fn create_collation(&self, name: PhpString<'_>, options: Arr<'_>, deterministic: Bool) -> PhpResult<Zval> {
            let mut pairs = Vec::new();
            for (name, option) in options.table().into_iter().flat_map(ZendHashTable::iter) {
                // PHP writes these into a string: nothing for `null`, `Array` for an array.
                let option = match option {
                    option if option.is_null() => String::new(),
                    option if option.is_array() => "Array".to_owned(),
                    option => scalar(false, option, "options")?,
                };
                pairs.push((key(false, &name)?, option));
            }
            let pairs: Vec<_> = pairs.iter().map(|(name, option)| (name.as_str(), option.as_str())).collect();
            let name = inward(false, name.bytes())?;
            statement::<PostgreSql>(&self.0, PostgreSql::new().create_collation(&name, &pairs, deterministic.0), None)
        }

        #[php(name = "alterColumnType", defaults(using = "\"\""))]
        pub fn alter_column_type(
            &self,
            table: PhpString<'_>,
            column: PhpString<'_>,
            r#type: PhpString<'_>,
            using: PhpString<'_>,
        ) -> PhpResult<Zval> {
            let (table, column, kind, using) = (
                inward(false, table.bytes())?,
                inward(false, column.bytes())?,
                inward(false, r#type.bytes())?,
                inward(false, using.bytes())?,
            );
            statement::<PostgreSql>(&self.0, PostgreSql::new().alter_column_type(&table, &column, &kind, &using), None)
        }

        #[php(name = "alterColumnNullable")]
        pub fn alter_column_nullable(
            &self,
            table: PhpString<'_>,
            column: PhpString<'_>,
            nullable: Bool,
        ) -> PhpResult<Zval> {
            let (table, column) = (inward(false, table.bytes())?, inward(false, column.bytes())?);
            statement::<PostgreSql>(&self.0, PostgreSql::new().alter_column_nullable(&table, &column, nullable.0), None)
        }

        #[php(name = "dropIndexConcurrently")]
        pub fn drop_index_concurrently(&self, name: PhpString<'_>) -> PhpResult<Zval> {
            let name = inward(false, name.bytes())?;
            statement::<PostgreSql>(&self.0, PostgreSql::new().drop_index_concurrently(&name), None)
        }

        #[php(name = "createType")]
        pub fn create_type(&self, name: PhpString<'_>, values: Arr<'_>) -> PhpResult<Zval> {
            let values = strings(false, values.table(), "values")?;
            let values: Vec<&str> = values.iter().map(String::as_str).collect();
            statement::<PostgreSql>(
                &self.0,
                PostgreSql::new().create_type(&inward(false, name.bytes())?, &values),
                None,
            )
        }

        #[php(name = "dropType")]
        pub fn drop_type(&self, name: PhpString<'_>) -> PhpResult<Zval> {
            statement::<PostgreSql>(&self.0, PostgreSql::new().drop_type(&inward(false, name.bytes())?), None)
        }

        #[php(name = "createSequence", defaults(start = "Int(1)", incrementBy = "Int(1)"))]
        pub fn create_sequence(&self, name: PhpString<'_>, start: Int, incrementBy: Int) -> PhpResult<Zval> {
            let name = inward(false, name.bytes())?;
            statement::<PostgreSql>(&self.0, PostgreSql::new().create_sequence(&name, start.0, incrementBy.0), None)
        }

        #[php(name = "dropSequence")]
        pub fn drop_sequence(&self, name: PhpString<'_>) -> PhpResult<Zval> {
            statement::<PostgreSql>(&self.0, PostgreSql::new().drop_sequence(&inward(false, name.bytes())?), None)
        }

        #[php(name = "nextVal")]
        pub fn next_val(&self, name: PhpString<'_>) -> PhpResult<Zval> {
            statement::<PostgreSql>(&self.0, PostgreSql::new().next_val(&inward(false, name.bytes())?), None)
        }
    }
);

/// `Utopia\Query\Schema\SQLite`.
#[php_class]
#[php(name = "Utopia\\Query\\Schema\\SQLite")]
#[php(extends(Sql))]
#[php(implements(feature::PhpInterfaceViews))]
#[derive(Clone)]
pub struct SqliteSchema(Held);

schema_methods!(SqliteSchema, Sqlite, [index, views]);

/// `Utopia\Query\Schema\ClickHouse`.
#[php_class]
#[php(name = "Utopia\\Query\\Schema\\ClickHouse")]
#[php(extends(Base))]
#[php(implements(feature::PhpInterfaceTableComments))]
#[php(implements(feature::PhpInterfaceColumnComments))]
#[php(implements(feature::PhpInterfaceDropPartition))]
#[php(implements(feature::PhpInterfaceViews))]
#[php(implements(feature::PhpInterfaceDatabases))]
#[php(implements(feature::PhpInterfaceMaterializedViews))]
#[derive(Clone)]
pub struct ClickHouseSchema(Held);

schema_methods!(
    ClickHouseSchema,
    ClickHouse,
    [index, table_comments, column_comments, drop_partition, views, databases],
    {
        #[php(name = "createMaterializedView", defaults(ifNotExists = "Bool(true)"))]
        pub fn create_materialized_view(
            &self,
            name: PhpString<'_>,
            body: &Zval,
            targetTable: Option<PhpString<'_>>,
            ifNotExists: Bool,
        ) -> PhpResult<Zval> {
            let name = inward(false, name.bytes())?;
            let target = targetTable.map(|target| inward(false, target.bytes())).transpose()?;
            let schema = ClickHouse::new();
            if let Some(sql) = PhpString::from_zval(body).filter(|_| !body.is_object()) {
                let sql = inward(false, sql.bytes())?;
                let view =
                    schema.create_materialized_view(&name, ViewBody::Sql(&sql), target.as_deref(), ifNotExists.0);
                return statement::<ClickHouse>(&self.0, view, None);
            }
            if Query::from_zval(body).is_none() {
                return Err(type_error(
                    "Utopia\\Query\\Schema\\ClickHouse::createMaterializedView(): Argument #2 ($body) must be of type \
                 Utopia\\Query\\Builder|string"
                        .to_owned(),
                ));
            }
            let (mut query, bindings) = built(false, body)?;
            let view =
                schema.create_materialized_view(&name, ViewBody::Builder(&mut query), target.as_deref(), ifNotExists.0);
            statement::<ClickHouse>(&self.0, view, Some(bindings))
        }

        #[php(name = "dropMaterializedView", defaults(ifExists = "Bool(true)"))]
        pub fn drop_materialized_view(&self, name: PhpString<'_>, ifExists: Bool) -> PhpResult<Zval> {
            let name = inward(false, name.bytes())?;
            statement::<ClickHouse>(&self.0, ClickHouse::new().drop_materialized_view(&name, ifExists.0), None)
        }
    }
);

/// `Utopia\Query\Schema\MongoDB`: its statements are JSON commands.
#[php_class]
#[php(name = "Utopia\\Query\\Schema\\MongoDB")]
#[php(extends(Base))]
#[php(implements(feature::PhpInterfaceViews))]
#[php(implements(feature::PhpInterfaceDatabases))]
#[php(implements(feature::PhpInterfaceAnalyzeTable))]
#[derive(Clone)]
pub struct MongoDbSchema(Held);

schema_methods!(MongoDbSchema, MongoDb, [index, views, databases, analyze_table]);
