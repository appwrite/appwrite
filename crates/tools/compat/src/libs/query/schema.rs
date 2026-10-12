//! The `schema` area of the query adapter (see [`super::engine`]): schemas,
//! table blueprints, columns, foreign keys, indexes and the schema enums.
//!
//! PHP's blueprints are objects that point at each other: a column holds
//! its table, and forwards table methods to it. The crate's [`Table`] owns
//! its columns and keys instead, so the adapter shares each table between
//! its program objects (`Arc<TableCell>`): a column object is (table, column
//! index), a foreign key object (table, key index). A forwarded call that
//! returns the table answers with the table object's existing number,
//! found by scanning the program's objects, as PHP returns the same object.

use std::sync::{Arc, Mutex, MutexGuard};

use php_std::ArrayKey;
use serde_json::Value as Json;
use utopia_query::Value;
use utopia_query::builder::{AnyBuilder, Statement};
use utopia_query::schema::{
    self as s, CheckConstraint, Column, ColumnDefinition, ColumnType, Engine, ForeignKey, ForeignKeyAction, Index,
    IndexAlgorithm, IndexOptions, IndexType, Order, ParameterDirection, PartitionType, RenameColumn, Schema, Table,
    TriggerEvent, TriggerTiming, ViewBody, column as col, feature as f, table as tb,
};

use super::engine::{Args, Cx, Failure, Object, Outcome, Ret, fault};
use crate::query_object_boilerplate;

type Res<T> = Result<T, Failure>;

static NULL: Json = Json::Null;

/// Runs each `Res<Option<_>>` expression in turn; returns the first that
/// handled the call (or failed).
macro_rules! chain {
    ($($e:expr),+ $(,)?) => {{
        $(
            match $e {
                Ok(None) => {}
                other => return other,
            }
        )+
        Ok(None)
    }};
}

/// `$body` with `$x` bound to the inner value of any dialect variant.
macro_rules! each {
    ($value:expr, $kind:ident, $x:ident => $body:expr) => {
        match $value {
            $kind::MySql($x) => $body,
            $kind::Pg($x) => $body,
            $kind::Sqlite($x) => $body,
            $kind::Ch($x) => $body,
            $kind::Mongo($x) => $body,
        }
    };
}

// ---------------------------------------------------------------------------
// Dialects
// ---------------------------------------------------------------------------

#[derive(Clone, Copy, PartialEq, Eq)]
enum Dialect {
    MySql,
    Pg,
    Sqlite,
    Ch,
    Mongo,
}

impl Dialect {
    fn php(self) -> &'static str {
        match self {
            Dialect::MySql => "MySQL",
            Dialect::Pg => "PostgreSQL",
            Dialect::Sqlite => "SQLite",
            Dialect::Ch => "ClickHouse",
            Dialect::Mongo => "MongoDB",
        }
    }

    fn from_php(name: &str) -> Option<Dialect> {
        Some(match name {
            "MySQL" => Dialect::MySql,
            "PostgreSQL" => Dialect::Pg,
            "SQLite" => Dialect::Sqlite,
            "ClickHouse" => Dialect::Ch,
            "MongoDB" => Dialect::Mongo,
            _ => return None,
        })
    }
}

#[derive(Clone)]
enum AnySchema {
    MySql(s::MySql),
    Pg(s::PostgreSql),
    Sqlite(s::Sqlite),
    Ch(s::ClickHouse),
    Mongo(s::MongoDb),
}

impl AnySchema {
    fn new(dialect: Dialect) -> AnySchema {
        match dialect {
            Dialect::MySql => AnySchema::MySql(s::MySql::new()),
            Dialect::Pg => AnySchema::Pg(s::PostgreSql::new()),
            Dialect::Sqlite => AnySchema::Sqlite(s::Sqlite::new()),
            Dialect::Ch => AnySchema::Ch(s::ClickHouse::new()),
            Dialect::Mongo => AnySchema::Mongo(s::MongoDb::new()),
        }
    }

    fn dialect(&self) -> Dialect {
        match self {
            AnySchema::MySql(_) => Dialect::MySql,
            AnySchema::Pg(_) => Dialect::Pg,
            AnySchema::Sqlite(_) => Dialect::Sqlite,
            AnySchema::Ch(_) => Dialect::Ch,
            AnySchema::Mongo(_) => Dialect::Mongo,
        }
    }
}

enum AnyTable {
    MySql(Table<s::MySql>),
    Pg(Table<s::PostgreSql>),
    Sqlite(Table<s::Sqlite>),
    Ch(Table<s::ClickHouse>),
    Mongo(Table<s::MongoDb>),
}

impl AnyTable {
    fn detached(dialect: Dialect, name: String) -> AnyTable {
        match dialect {
            Dialect::MySql => AnyTable::MySql(Table::new(name)),
            Dialect::Pg => AnyTable::Pg(Table::new(name)),
            Dialect::Sqlite => AnyTable::Sqlite(Table::new(name)),
            Dialect::Ch => AnyTable::Ch(Table::new(name)),
            Dialect::Mongo => AnyTable::Mongo(Table::new(name)),
        }
    }

    fn of(schema: &AnySchema, name: String) -> AnyTable {
        match schema {
            AnySchema::MySql(x) => AnyTable::MySql(x.table(name)),
            AnySchema::Pg(x) => AnyTable::Pg(x.table(name)),
            AnySchema::Sqlite(x) => AnyTable::Sqlite(x.table(name)),
            AnySchema::Ch(x) => AnyTable::Ch(x.table(name)),
            AnySchema::Mongo(x) => AnyTable::Mongo(x.table(name)),
        }
    }

    fn dialect(&self) -> Dialect {
        match self {
            AnyTable::MySql(_) => Dialect::MySql,
            AnyTable::Pg(_) => Dialect::Pg,
            AnyTable::Sqlite(_) => Dialect::Sqlite,
            AnyTable::Ch(_) => Dialect::Ch,
            AnyTable::Mongo(_) => Dialect::Mongo,
        }
    }

    fn deep_clone(&self) -> AnyTable {
        match self {
            AnyTable::MySql(t) => AnyTable::MySql(t.clone()),
            AnyTable::Pg(t) => AnyTable::Pg(t.clone()),
            AnyTable::Sqlite(t) => AnyTable::Sqlite(t.clone()),
            AnyTable::Ch(t) => AnyTable::Ch(t.clone()),
            AnyTable::Mongo(t) => AnyTable::Mongo(t.clone()),
        }
    }
}

enum AnyColumn {
    MySql(Column<s::MySql>),
    Pg(Column<s::PostgreSql>),
    Sqlite(Column<s::Sqlite>),
    Ch(Column<s::ClickHouse>),
    Mongo(Column<s::MongoDb>),
}

impl Clone for AnyColumn {
    fn clone(&self) -> Self {
        match self {
            AnyColumn::MySql(c) => AnyColumn::MySql(c.clone()),
            AnyColumn::Pg(c) => AnyColumn::Pg(c.clone()),
            AnyColumn::Sqlite(c) => AnyColumn::Sqlite(c.clone()),
            AnyColumn::Ch(c) => AnyColumn::Ch(c.clone()),
            AnyColumn::Mongo(c) => AnyColumn::Mongo(c.clone()),
        }
    }
}

enum ColRef<'a> {
    MySql(&'a mut Column<s::MySql>),
    Pg(&'a mut Column<s::PostgreSql>),
    Sqlite(&'a mut Column<s::Sqlite>),
    Ch(&'a mut Column<s::ClickHouse>),
    Mongo(&'a mut Column<s::MongoDb>),
}

// ---------------------------------------------------------------------------
// Shared tables and object numbers
// ---------------------------------------------------------------------------

/// A table shared by its program objects, with the engine to find their numbers.
struct TableCell {
    table: Mutex<AnyTable>,
    cx: Cx,
}

type Shared = Arc<TableCell>;

fn share(table: AnyTable, cx: &Cx) -> Shared {
    Arc::new(TableCell { table: Mutex::new(table), cx: cx.clone() })
}

fn lock(cell: &TableCell) -> MutexGuard<'_, AnyTable> {
    cell.table.lock().unwrap_or_else(|e| e.into_inner())
}

/// The number of the first program object `pred` accepts (objects being
/// called are skipped).
fn find_id(cx: &Cx, pred: impl Fn(&dyn Object) -> bool) -> Option<i64> {
    let args = cx.decoded(&NULL);
    let mut id = 1;
    loop {
        match args.with_id(id, |o| pred(o)) {
            Ok(true) => return Some(id),
            Ok(false) => {}
            Err(Failure::Fault(message)) if message.starts_with("unknown object") => return None,
            Err(_) => {}
        }
        id += 1;
    }
}

/// The table object of `cell`: its existing number, or a new object.
fn table_ret(cell: &Shared) -> Ret {
    let found = find_id(&cell.cx, |o| o.as_any().downcast_ref::<TableObj>().is_some_and(|t| Arc::ptr_eq(&t.0, cell)));
    match found {
        Some(id) => Ret::Existing(id),
        None => Ret::obj(TableObj(cell.clone())),
    }
}

fn column_ret(cell: &Shared, index: usize) -> Ret {
    let found = find_id(&cell.cx, |o| {
        o.as_any()
            .downcast_ref::<ColumnObj>()
            .is_some_and(|c| Arc::ptr_eq(&c.table, cell) && matches!(c.at, Slot::Index(i) if i == index))
    });
    match found {
        Some(id) => Ret::Existing(id),
        None => Ret::obj(ColumnObj { table: cell.clone(), at: Slot::Index(index), base: false }),
    }
}

fn foreign_key_ret(cell: &Shared, index: usize) -> Ret {
    let found = find_id(&cell.cx, |o| {
        o.as_any().downcast_ref::<ForeignKeyObj>().is_some_and(|k| Arc::ptr_eq(&k.table, cell) && k.index == index)
    });
    match found {
        Some(id) => Ret::Existing(id),
        None => Ret::obj(ForeignKeyObj { table: cell.clone(), index }),
    }
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

fn key_string(key: &ArrayKey) -> String {
    match key {
        ArrayKey::Int(i) => i.to_string(),
        ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
    }
}

fn enum_arg<E>(a: &Args, i: usize, name: &str, from_name: fn(&str) -> Option<E>) -> Res<Option<E>> {
    match a.enum_case(i, name)? {
        None => Ok(None),
        Some((class, case)) => from_name(&case).map(Some).ok_or_else(|| fault(format!("no case {class}::{case}"))),
    }
}

fn required_enum<E>(a: &Args, i: usize, name: &str, from_name: fn(&str) -> Option<E>) -> Res<E> {
    enum_arg(a, i, name, from_name)?.ok_or_else(|| fault(format!("missing argument ${name}")))
}

fn column_type(a: &Args, i: usize, name: &str) -> Res<ColumnType> {
    required_enum(a, i, name, ColumnType::from_name)
}

fn column_types(a: &Args, i: usize, name: &str) -> Res<Vec<ColumnType>> {
    let mut out = Vec::new();
    for item in a.items(i, name)? {
        out.push(required_enum(&a.cx().decoded(item), 0, name, ColumnType::from_name)?);
    }
    Ok(out)
}

fn str_pairs(a: &Args, i: usize, name: &str) -> Res<Vec<(String, String)>> {
    if !a.has(i, name) {
        return Ok(Vec::new());
    }
    let mut out = Vec::new();
    for (key, value) in a.entries(i, name)? {
        out.push((key_string(&key), a.cx().decoded(value).str(0, name)?));
    }
    Ok(out)
}

fn int_pairs(a: &Args, i: usize, name: &str) -> Res<Vec<(String, i64)>> {
    if !a.has(i, name) {
        return Ok(Vec::new());
    }
    let mut out = Vec::new();
    for (key, value) in a.entries(i, name)? {
        out.push((key_string(&key), a.cx().decoded(value).int(0, name)?));
    }
    Ok(out)
}

fn values(a: &Args, i: usize, name: &str) -> Res<Vec<Value>> {
    if !a.has(i, name) {
        return Ok(Vec::new());
    }
    a.items(i, name)?.into_iter().map(|v| a.php(v)).collect()
}

fn is_array(raw: Option<&Json>) -> bool {
    matches!(raw, Some(Json::Array(_)))
        || matches!(raw, Some(Json::Object(m)) if !m.contains_key("$o") && !m.contains_key("$enum"))
}

/// Runs `f` on the builder object argument `i` / `name`.
fn with_builder<R>(a: &Args, i: usize, name: &str, f: impl FnOnce(&mut dyn AnyBuilder) -> R) -> Res<R> {
    super::builder::with_builder_arg(a, i, name, f)
}

fn statement(result: utopia_query::Result<Statement>) -> Res<Ret> {
    Ok(Ret::obj(result?))
}

fn opt_enum<T: Copy>(value: Option<T>, class: &str, name: fn(T) -> &'static str) -> Ret {
    match value {
        Some(v) => Ret::Enum(class.to_owned(), name(v)),
        None => Ret::null(),
    }
}

fn opt_int(value: Option<i64>) -> Ret {
    value.map(Ret::int).unwrap_or_else(Ret::null)
}

// ---------------------------------------------------------------------------
// Schemas
// ---------------------------------------------------------------------------

#[derive(Clone)]
struct SchemaObj(AnySchema);

const MYSQL_FEATURES: &[&str] = &[
    "Schema\\Feature\\ForeignKeys",
    "Schema\\Feature\\Procedures",
    "Schema\\Feature\\Triggers",
    "Schema\\Feature\\TableComments",
    "Schema\\Feature\\CreatePartition",
    "Schema\\Feature\\DropPartition",
    "Schema\\Feature\\Views",
    "Schema\\Feature\\ReplaceView",
    "Schema\\Feature\\Databases",
    "Schema\\Feature\\RenameIndex",
    "Schema\\Feature\\AnalyzeTable",
    "Schema\\Feature\\Partitioning",
];

const POSTGRESQL_FEATURES: &[&str] = &[
    "Schema\\Feature\\ForeignKeys",
    "Schema\\Feature\\Procedures",
    "Schema\\Feature\\Triggers",
    "Schema\\Feature\\Types",
    "Schema\\Feature\\Sequences",
    "Schema\\Feature\\TableComments",
    "Schema\\Feature\\ColumnComments",
    "Schema\\Feature\\CreatePartition",
    "Schema\\Feature\\DropPartition",
    "Schema\\Feature\\Views",
    "Schema\\Feature\\ReplaceView",
    "Schema\\Feature\\Databases",
    "Schema\\Feature\\RenameIndex",
    "Schema\\Feature\\AnalyzeTable",
    "Schema\\Feature\\Partitioning",
];

const CLICKHOUSE_FEATURES: &[&str] = &[
    "Schema\\Feature\\TableComments",
    "Schema\\Feature\\ColumnComments",
    "Schema\\Feature\\DropPartition",
    "Schema\\Feature\\Views",
    "Schema\\Feature\\Databases",
    "Schema\\Feature\\MaterializedViews",
];

impl Object for SchemaObj {
    fn class(&self) -> String {
        format!("Schema\\{}", self.0.dialect().php())
    }

    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        let result = match &mut self.0 {
            AnySchema::MySql(x) => mysql_call(x, method, a),
            AnySchema::Pg(x) => postgresql_call(x, method, a),
            AnySchema::Sqlite(x) => sqlite_call(x, method, a),
            AnySchema::Ch(x) => clickhouse_call(x, method, a),
            AnySchema::Mongo(x) => mongodb_call(x, method, a),
        };
        let schema = self.0.clone();
        match result {
            Ok(Some(SchemaOut::Ret(r))) => Some(Ok(r)),
            Ok(Some(SchemaOut::This)) => Some(Ok(Ret::This)),
            Ok(Some(SchemaOut::Table(name))) => {
                Some(Ok(Ret::obj(TableObj(share(AnyTable::of(&schema, name), a.cx())))))
            }
            Ok(None) => None,
            Err(e) => Some(Err(e)),
        }
    }

    fn parents(&self) -> &'static [&'static str] {
        match self.0.dialect() {
            Dialect::MySql | Dialect::Pg | Dialect::Sqlite => &["Schema\\SQL", "Schema"],
            Dialect::Ch | Dialect::Mongo => &["Schema"],
        }
    }

    fn interfaces(&self) -> &'static [&'static str] {
        match self.0.dialect() {
            Dialect::MySql => MYSQL_FEATURES,
            Dialect::Pg => POSTGRESQL_FEATURES,
            Dialect::Sqlite => &["Schema\\Feature\\Views"],
            Dialect::Ch => CLICKHOUSE_FEATURES,
            Dialect::Mongo => {
                &["Schema\\Feature\\Views", "Schema\\Feature\\Databases", "Schema\\Feature\\AnalyzeTable"]
            }
        }
    }

    query_object_boilerplate!();
}

enum SchemaOut {
    Ret(Ret),
    This,
    Table(String),
}

type SchemaRes = Res<Option<SchemaOut>>;

fn ret(result: utopia_query::Result<Statement>) -> SchemaRes {
    Ok(Some(SchemaOut::Ret(statement(result)?)))
}

fn schema_common<S: Schema>(x: &mut S, m: &str, a: &Args, typed: bool) -> SchemaRes {
    match m {
        "table" => Ok(Some(SchemaOut::Table(a.str(0, "name")?))),
        "setExecutor" => {
            x.set_executor(super::core::executor(a.closure(0, "executor")?));
            Ok(Some(SchemaOut::This))
        }
        "createIndex" => {
            let unique = a.bool_or(3, "unique", false)?;
            let kind = a.str_or(4, "type", "")?;
            let kind = if !typed || unique || kind.is_empty() { None } else { Some(IndexType::from_value(&kind)?) };
            let options = IndexOptions {
                unique,
                kind,
                method: a.str_or(5, "method", "")?,
                operator_class: a.str_or(6, "operatorClass", "")?,
                lengths: int_pairs(a, 7, "lengths")?,
                orders: str_pairs(a, 8, "orders")?,
                collations: str_pairs(a, 9, "collations")?,
                raw_columns: a.strings_or_empty(10, "rawColumns")?,
                concurrently: a.bool_or(11, "concurrently", false)?,
            };
            let columns = a.strings(2, "columns")?;
            let columns: Vec<&str> = columns.iter().map(String::as_str).collect();
            ret(x.create_index(&a.str(0, "table")?, &a.str(1, "name")?, &columns, &options))
        }
        "dropIndex" => ret(x.drop_index(&a.str(0, "table")?, &a.str(1, "name")?)),
        "compileDrop" => ret(x.compile_drop(&a.str(0, "name")?, a.bool(1, "ifExists")?)),
        "compileRename" => ret(x.compile_rename(&a.str(0, "from")?, &a.str(1, "to")?)),
        "compileTruncate" => ret(x.compile_truncate(&a.str(0, "name")?)),
        _ => Ok(None),
    }
}

fn foreign_keys<S: f::ForeignKeys>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "addForeignKey" => {
            let mut key = ForeignKey::new(a.str(2, "column")?);
            key.on(a.str(3, "refTable")?).references(a.str(4, "refColumn")?);
            key.on_delete = enum_arg(a, 5, "onDelete", ForeignKeyAction::from_name)?;
            key.on_update = enum_arg(a, 6, "onUpdate", ForeignKeyAction::from_name)?;
            ret(x.add_foreign_key(&a.str(0, "table")?, &a.str(1, "name")?, &key))
        }
        "dropForeignKey" => ret(x.drop_foreign_key(&a.str(0, "table")?, &a.str(1, "name")?)),
        _ => Ok(None),
    }
}

fn procedures<S: f::Procedures>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "createProcedure" => {
            let mut params = Vec::new();
            for item in a.items(1, "params")? {
                let p = a.cx().decoded(item);
                let list = p.items(0, "params")?;
                let d = a.cx().decoded(list.first().copied().unwrap_or(&NULL));
                let direction = required_enum(&d, 0, "params", ParameterDirection::from_name)?;
                let name = a.cx().decoded(list.get(1).copied().unwrap_or(&NULL)).str(0, "params")?;
                let kind = a.cx().decoded(list.get(2).copied().unwrap_or(&NULL)).str(0, "params")?;
                params.push((direction, name, kind));
            }
            let params: Vec<(ParameterDirection, &str, &str)> =
                params.iter().map(|(d, n, k)| (*d, n.as_str(), k.as_str())).collect();
            ret(x.create_procedure(&a.str(0, "name")?, &params, &a.str(2, "body")?))
        }
        "dropProcedure" => ret(x.drop_procedure(&a.str(0, "name")?)),
        _ => Ok(None),
    }
}

fn triggers<S: f::Triggers>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "createTrigger" => ret(x.create_trigger(
            &a.str(0, "name")?,
            &a.str(1, "table")?,
            required_enum(a, 2, "timing", TriggerTiming::from_name)?,
            required_enum(a, 3, "event", TriggerEvent::from_name)?,
            &a.str(4, "body")?,
        )),
        "dropTrigger" => ret(x.drop_trigger(&a.str(0, "name")?, a.opt_str(1, "table")?.as_deref())),
        _ => Ok(None),
    }
}

fn types<S: f::Types>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "createType" => {
            let values = a.strings(1, "values")?;
            let values: Vec<&str> = values.iter().map(String::as_str).collect();
            ret(x.create_type(&a.str(0, "name")?, &values))
        }
        "dropType" => ret(x.drop_type(&a.str(0, "name")?)),
        _ => Ok(None),
    }
}

fn sequences<S: f::Sequences>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "createSequence" => {
            ret(x.create_sequence(&a.str(0, "name")?, a.int_or(1, "start", 1)?, a.int_or(2, "incrementBy", 1)?))
        }
        "dropSequence" => ret(x.drop_sequence(&a.str(0, "name")?)),
        "nextVal" => ret(x.next_val(&a.str(0, "name")?)),
        _ => Ok(None),
    }
}

fn table_comments<S: f::TableComments>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "commentOnTable" => ret(x.comment_on_table(&a.str(0, "table")?, &a.str(1, "comment")?)),
        _ => Ok(None),
    }
}

fn column_comments<S: f::ColumnComments>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "commentOnColumn" => ret(x.comment_on_column(&a.str(0, "table")?, &a.str(1, "column")?, &a.str(2, "comment")?)),
        _ => Ok(None),
    }
}

fn create_partition<S: f::CreatePartition>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "createPartition" => {
            ret(x.create_partition(&a.str(0, "parent")?, &a.str(1, "name")?, &a.str(2, "expression")?))
        }
        _ => Ok(None),
    }
}

fn drop_partition<S: f::DropPartition>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "dropPartition" => ret(x.drop_partition(&a.str(0, "table")?, &a.str(1, "name")?)),
        _ => Ok(None),
    }
}

fn views<S: f::Views>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "createView" => {
            let name = a.str(0, "name")?;
            ret(with_builder(a, 1, "query", |b| x.create_view(&name, b))?)
        }
        "dropView" => ret(x.drop_view(&a.str(0, "name")?)),
        _ => Ok(None),
    }
}

fn replace_view<S: f::ReplaceView>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "createOrReplaceView" => {
            let name = a.str(0, "name")?;
            ret(with_builder(a, 1, "query", |b| x.create_or_replace_view(&name, b))?)
        }
        _ => Ok(None),
    }
}

fn databases<S: f::Databases>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "createDatabase" => ret(x.create_database(&a.str(0, "name")?)),
        "dropDatabase" => ret(x.drop_database(&a.str(0, "name")?)),
        _ => Ok(None),
    }
}

fn rename_index<S: f::RenameIndex>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "renameIndex" => ret(x.rename_index(&a.str(0, "table")?, &a.str(1, "from")?, &a.str(2, "to")?)),
        _ => Ok(None),
    }
}

fn analyze<S: f::AnalyzeTable>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "analyzeTable" => ret(x.analyze_table(&a.str(0, "table")?)),
        _ => Ok(None),
    }
}

fn materialized_views<S: f::MaterializedViews>(x: &S, m: &str, a: &Args) -> SchemaRes {
    match m {
        "createMaterializedView" => {
            let name = a.str(0, "name")?;
            let target = a.opt_str(2, "targetTable")?;
            let if_not_exists = a.bool_or(3, "ifNotExists", true)?;
            match a.raw(1, "body") {
                Some(Json::String(sql)) => {
                    ret(x.create_materialized_view(&name, ViewBody::Sql(sql), target.as_deref(), if_not_exists))
                }
                _ => ret(with_builder(a, 1, "body", |b| {
                    x.create_materialized_view(&name, ViewBody::Builder(b), target.as_deref(), if_not_exists)
                })?),
            }
        }
        "dropMaterializedView" => ret(x.drop_materialized_view(&a.str(0, "name")?, a.bool_or(1, "ifExists", true)?)),
        _ => Ok(None),
    }
}

fn sqlite_call(x: &mut s::Sqlite, m: &str, a: &Args) -> SchemaRes {
    chain!(schema_common(x, m, a, true), views(x, m, a))
}

fn clickhouse_call(x: &mut s::ClickHouse, m: &str, a: &Args) -> SchemaRes {
    chain!(
        schema_common(x, m, a, true),
        table_comments(x, m, a),
        column_comments(x, m, a),
        drop_partition(x, m, a),
        views(x, m, a),
        databases(x, m, a),
        materialized_views(x, m, a),
    )
}

fn mongodb_call(x: &mut s::MongoDb, m: &str, a: &Args) -> SchemaRes {
    chain!(schema_common(x, m, a, false), views(x, m, a), databases(x, m, a), analyze(x, m, a))
}

fn mysql_call(x: &mut s::MySql, m: &str, a: &Args) -> SchemaRes {
    chain!(
        schema_common(x, m, a, true),
        foreign_keys(x, m, a),
        procedures(x, m, a),
        triggers(x, m, a),
        table_comments(x, m, a),
        create_partition(x, m, a),
        drop_partition(x, m, a),
        views(x, m, a),
        replace_view(x, m, a),
        databases(x, m, a),
        rename_index(x, m, a),
        analyze(x, m, a),
        match m {
            "changeColumn" => ret(x.change_column(
                &a.str(0, "table")?,
                &a.str(1, "oldName")?,
                &a.str(2, "newName")?,
                &a.str(3, "type")?
            )),
            "modifyColumn" => ret(x.modify_column(&a.str(0, "table")?, &a.str(1, "name")?, &a.str(2, "type")?)),
            _ => Ok(None),
        },
    )
}

fn postgresql_call(x: &mut s::PostgreSql, m: &str, a: &Args) -> SchemaRes {
    chain!(
        schema_common(x, m, a, true),
        foreign_keys(x, m, a),
        procedures(x, m, a),
        triggers(x, m, a),
        types(x, m, a),
        sequences(x, m, a),
        table_comments(x, m, a),
        column_comments(x, m, a),
        create_partition(x, m, a),
        drop_partition(x, m, a),
        views(x, m, a),
        replace_view(x, m, a),
        databases(x, m, a),
        rename_index(x, m, a),
        analyze(x, m, a),
        match m {
            "createExtension" => ret(x.create_extension(&a.str(0, "name")?)),
            "dropExtension" => ret(x.drop_extension(&a.str(0, "name")?)),
            "createCollation" => {
                let options = str_pairs(a, 1, "options")?;
                let options: Vec<(&str, &str)> = options.iter().map(|(k, v)| (k.as_str(), v.as_str())).collect();
                ret(x.create_collation(&a.str(0, "name")?, &options, a.bool_or(2, "deterministic", true)?))
            }
            "alterColumnType" => ret(x.alter_column_type(
                &a.str(0, "table")?,
                &a.str(1, "column")?,
                &a.str(2, "type")?,
                &a.str_or(3, "using", "")?
            )),
            "alterColumnNullable" => {
                ret(x.alter_column_nullable(&a.str(0, "table")?, &a.str(1, "column")?, a.bool(2, "nullable")?))
            }
            "dropIndexConcurrently" => ret(x.drop_index_concurrently(&a.str(0, "name")?)),
            _ => Ok(None),
        },
    )
}

// ---------------------------------------------------------------------------
// Tables
// ---------------------------------------------------------------------------

/// What a table method returned.
enum TableOut {
    /// The table (`return $this`).
    Table,
    /// The column at this index.
    Column(usize),
    /// The foreign key at this index.
    ForeignKey(usize),
    /// Anything else.
    Ret(Ret),
}

type TableRes = Res<Option<TableOut>>;

fn added<S>(x: &Table<S>) -> TableRes {
    Ok(Some(TableOut::Column(x.columns().len() - 1)))
}

fn added_key<S>(x: &Table<S>) -> TableRes {
    Ok(Some(TableOut::ForeignKey(x.foreign_keys().len() - 1)))
}

fn this() -> TableRes {
    Ok(Some(TableOut::Table))
}

fn table_statement(result: utopia_query::Result<Statement>) -> TableRes {
    Ok(Some(TableOut::Ret(statement(result)?)))
}

fn named_index(a: &Args, prefix: &str, kind: IndexType) -> Res<Index> {
    let columns = a.strings(0, "columns")?;
    let mut name = a.str_or(1, "name", "")?;
    if name.is_empty() {
        name = Index::auto_name(prefix, &columns);
    }
    Ok(Index::new(name, columns, kind))
}

fn table_common<S: Schema>(x: &mut Table<S>, m: &str, a: &Args) -> TableRes {
    match m {
        "id" => {
            x.id(a.str_or(0, "name", "id")?);
            added(x)
        }
        "string" => {
            x.string(a.str(0, "name")?, a.int_or(1, "length", 255)?);
            added(x)
        }
        "text" => {
            x.text(a.str(0, "name")?);
            added(x)
        }
        "mediumText" => {
            x.medium_text(a.str(0, "name")?);
            added(x)
        }
        "longText" => {
            x.long_text(a.str(0, "name")?);
            added(x)
        }
        "tinyInteger" => {
            x.tiny_integer(a.str(0, "name")?);
            added(x)
        }
        "smallInteger" => {
            x.small_integer(a.str(0, "name")?);
            added(x)
        }
        "integer" => {
            x.integer(a.str(0, "name")?);
            added(x)
        }
        "bigInteger" => {
            x.big_integer(a.str(0, "name")?);
            added(x)
        }
        "decimal" => {
            x.decimal(a.str(0, "name")?, a.int_or(1, "precision", 10)?, a.int_or(2, "scale", 0)?)?;
            added(x)
        }
        "uuid" => {
            x.uuid(a.str(0, "name")?);
            added(x)
        }
        "float" => {
            x.float(a.str(0, "name")?);
            added(x)
        }
        "boolean" => {
            x.boolean(a.str(0, "name")?);
            added(x)
        }
        "datetime" => {
            x.datetime(a.str(0, "name")?, a.int_or(1, "precision", 0)?);
            added(x)
        }
        "timestamp" => {
            x.timestamp(a.str(0, "name")?, a.int_or(1, "precision", 0)?);
            added(x)
        }
        "json" => {
            x.json(a.str(0, "name")?);
            added(x)
        }
        "binary" => {
            x.binary(a.str(0, "name")?);
            added(x)
        }
        "enum" => {
            x.enumeration(a.str(0, "name")?, a.strings_or_empty(1, "values")?)?;
            added(x)
        }
        "point" => {
            x.point(a.str(0, "name")?, a.int_or(1, "srid", 4326)?);
            added(x)
        }
        "linestring" => {
            x.linestring(a.str(0, "name")?, a.int_or(1, "srid", 4326)?);
            added(x)
        }
        "polygon" => {
            x.polygon(a.str(0, "name")?, a.int_or(1, "srid", 4326)?);
            added(x)
        }
        "timestamps" => {
            x.timestamps(a.int_or(0, "precision", 3)?);
            this()
        }
        "addColumn" | "modifyColumn" => {
            let name = a.str(0, "name")?;
            let kind = column_type(a, 1, "type")?;
            let size = a.opt_int(2, "lengthOrPrecision")?;
            if m == "addColumn" {
                x.add_column(name, kind, size);
            } else {
                x.modify_column(name, kind, size);
            }
            added(x)
        }
        "index" => {
            let mut index = named_index(a, "idx_", IndexType::Index)?;
            index.method = a.str_or(2, "method", "")?;
            index.operator_class = a.str_or(3, "operatorClass", "")?;
            index.lengths = int_pairs(a, 4, "lengths")?;
            index.orders = str_pairs(a, 5, "orders")?;
            index.collations = str_pairs(a, 6, "collations")?;
            index.algorithm = enum_arg(a, 7, "algorithm", IndexAlgorithm::from_name)?;
            index.algorithm_args = values(a, 8, "algorithmArgs")?;
            index.granularity = a.opt_int(9, "granularity")?;
            x.add_index(index)?;
            this()
        }
        "uniqueIndex" => {
            let mut index = named_index(a, "uniq_", IndexType::Unique)?;
            index.lengths = int_pairs(a, 2, "lengths")?;
            index.orders = str_pairs(a, 3, "orders")?;
            index.collations = str_pairs(a, 4, "collations")?;
            x.add_index(index)?;
            this()
        }
        "addIndex" => {
            let kind = enum_arg(a, 2, "type", IndexType::from_name)?.unwrap_or(IndexType::Index);
            let mut index = Index::new(a.str(0, "name")?, a.strings(1, "columns")?, kind);
            index.lengths = int_pairs(a, 3, "lengths")?;
            index.orders = str_pairs(a, 4, "orders")?;
            index.method = a.str_or(5, "method", "")?;
            index.operator_class = a.str_or(6, "operatorClass", "")?;
            index.collations = str_pairs(a, 7, "collations")?;
            index.raw_columns = a.strings_or_empty(8, "rawColumns")?;
            x.add_index(index)?;
            this()
        }
        "dropIndex" => {
            x.drop_index(a.str(0, "name")?);
            this()
        }
        "rawColumn" => {
            x.raw_column(a.str(0, "definition")?);
            this()
        }
        "rawIndex" => {
            x.raw_index(a.str(0, "definition")?);
            this()
        }
        "create" => {
            if a.bool_or(0, "ifNotExists", false)? {
                table_statement(x.create_if_not_exists())
            } else {
                table_statement(x.create())
            }
        }
        "createIfNotExists" => table_statement(x.create_if_not_exists()),
        "alter" => table_statement(x.alter()),
        "drop" => table_statement(x.drop()),
        "dropIfExists" => table_statement(x.drop_if_exists()),
        "truncate" => table_statement(x.truncate()),
        "rename" => table_statement(x.rename(&a.str(0, "to")?)),
        _ => Ok(None),
    }
}

fn table_serial<S: tb::Serial>(x: &mut Table<S>, m: &str, a: &Args) -> TableRes {
    match m {
        "serial" => {
            x.serial(a.str(0, "name")?);
            added(x)
        }
        "bigSerial" => {
            x.big_serial(a.str(0, "name")?);
            added(x)
        }
        "smallSerial" => {
            x.small_serial(a.str(0, "name")?);
            added(x)
        }
        _ => Ok(None),
    }
}

fn table_checks<S: tb::Checks>(x: &mut Table<S>, m: &str, a: &Args) -> TableRes {
    match m {
        "check" => {
            x.check(a.str(0, "name")?, a.str(1, "expression")?)?;
            this()
        }
        _ => Ok(None),
    }
}

fn table_alterations<S: tb::ColumnAlterations>(x: &mut Table<S>, m: &str, a: &Args) -> TableRes {
    match m {
        "renameColumn" => {
            x.rename_column(a.str(0, "from")?, a.str(1, "to")?);
            this()
        }
        "dropColumn" => {
            x.drop_column(a.str(0, "name")?);
            this()
        }
        _ => Ok(None),
    }
}

fn table_composite<S: tb::CompositePrimary>(x: &mut Table<S>, m: &str, a: &Args) -> TableRes {
    match m {
        "primary" => {
            x.primary(a.strings(0, "columns")?)?;
            this()
        }
        _ => Ok(None),
    }
}

fn table_inline_keys<S: tb::InlineForeignKey>(x: &mut Table<S>, m: &str, a: &Args) -> TableRes {
    match m {
        "foreignKey" => {
            x.foreign_key(a.str(0, "column")?);
            added_key(x)
        }
        _ => Ok(None),
    }
}

fn table_keys<S: tb::ForeignKeys>(x: &mut Table<S>, m: &str, a: &Args) -> TableRes {
    match m {
        "addForeignKey" => {
            x.add_foreign_key(a.str(0, "column")?);
            added_key(x)
        }
        "dropForeignKey" => {
            x.drop_foreign_key(a.str(0, "name")?);
            this()
        }
        _ => Ok(None),
    }
}

fn table_fulltext<S: tb::FulltextSpatialIndex>(x: &mut Table<S>, m: &str, a: &Args) -> TableRes {
    match m {
        "fulltextIndex" => {
            x.add_index(named_index(a, "ft_", IndexType::Fulltext)?)?;
            this()
        }
        "spatialIndex" => {
            x.add_index(named_index(a, "sp_", IndexType::Spatial)?)?;
            this()
        }
        _ => Ok(None),
    }
}

fn table_partitions<S: tb::StandardPartitioning>(x: &mut Table<S>, m: &str, a: &Args) -> TableRes {
    match m {
        "partitionByRange" => {
            x.partition_by_range(a.str(0, "expression")?);
            this()
        }
        "partitionByList" => {
            x.partition_by_list(a.str(0, "expression")?);
            this()
        }
        "partitionByHash" => {
            x.partition_by_hash(a.str(0, "expression")?, a.opt_int(1, "partitions")?)?;
            this()
        }
        _ => Ok(None),
    }
}

fn table_clickhouse(x: &mut Table<s::ClickHouse>, m: &str, a: &Args) -> TableRes {
    match m {
        "vector" => {
            x.vector(a.str(0, "name")?);
            added(x)
        }
        "array" => {
            x.array(a.str(0, "name")?, column_type(a, 1, "element")?);
            added(x)
        }
        "tuple" => {
            x.tuple(a.str(0, "name")?, column_types(a, 1, "elements")?)?;
            added(x)
        }
        "fixedString" => {
            x.fixed_string(a.str(0, "name")?, a.int(1, "length")?)?;
            added(x)
        }
        "engine" => {
            let engine = required_enum(a, 0, "engine", Engine::from_name)?;
            let mut args = Vec::new();
            for raw in a.rest(1) {
                args.push(a.cx().decoded(raw).str(0, "args")?);
            }
            let args: Vec<&str> = args.iter().map(String::as_str).collect();
            x.engine(engine, &args)?;
            this()
        }
        "orderBy" => {
            x.order_by(a.strings(0, "columns")?)?;
            this()
        }
        "orderByRaw" => {
            x.order_by_raw(&a.str(0, "expression")?)?;
            this()
        }
        "ttl" => {
            x.ttl(&a.str(0, "expression")?)?;
            this()
        }
        "settings" => {
            let mut settings = Vec::new();
            for (key, value) in a.entries(0, "settings")? {
                settings.push((key_string(&key), a.php(value)?));
            }
            x.settings(settings)?;
            this()
        }
        "partitionBy" => {
            x.partition_by(a.str(0, "expression")?);
            this()
        }
        "sampleBy" => {
            x.sample_by(&a.str(0, "expression")?)?;
            this()
        }
        _ => Ok(None),
    }
}

fn table_call(t: &mut AnyTable, m: &str, a: &Args) -> TableRes {
    match t {
        AnyTable::MySql(x) => chain!(
            table_common(x, m, a),
            table_serial(x, m, a),
            table_checks(x, m, a),
            table_alterations(x, m, a),
            table_composite(x, m, a),
            table_inline_keys(x, m, a),
            table_keys(x, m, a),
            table_fulltext(x, m, a),
            table_partitions(x, m, a),
        ),
        AnyTable::Pg(x) => chain!(
            table_common(x, m, a),
            table_serial(x, m, a),
            table_checks(x, m, a),
            table_alterations(x, m, a),
            table_composite(x, m, a),
            table_inline_keys(x, m, a),
            table_keys(x, m, a),
            table_fulltext(x, m, a),
            table_partitions(x, m, a),
            match m {
                "vector" => {
                    x.vector(a.str(0, "name")?, a.int(1, "dimensions")?);
                    added(x)
                }
                _ => Ok(None),
            },
        ),
        AnyTable::Sqlite(x) => chain!(
            table_common(x, m, a),
            table_serial(x, m, a),
            table_checks(x, m, a),
            table_alterations(x, m, a),
            table_composite(x, m, a),
            table_inline_keys(x, m, a),
        ),
        AnyTable::Ch(x) => chain!(
            table_common(x, m, a),
            table_alterations(x, m, a),
            table_composite(x, m, a),
            table_clickhouse(x, m, a),
        ),
        AnyTable::Mongo(x) => chain!(
            table_common(x, m, a),
            table_serial(x, m, a),
            match m {
                "vector" => {
                    x.vector(a.str(0, "name")?);
                    added(x)
                }
                _ => Ok(None),
            },
        ),
    }
}

/// Calls table method `m` on `cell`; `receiver` when the call is on the
/// table object itself (a forwarded call answers with the table's number).
fn call_table(cell: &Shared, m: &str, a: &Args, receiver: bool) -> Option<Outcome> {
    let out = {
        let mut table = lock(cell);
        table_call(&mut table, m, a)
    };
    match out {
        Ok(None) => None,
        Err(e) => Some(Err(e)),
        Ok(Some(out)) => Some(Ok(match out {
            TableOut::Table if receiver => Ret::This,
            TableOut::Table => table_ret(cell),
            TableOut::Column(i) => Ret::obj(ColumnObj { table: cell.clone(), at: Slot::Index(i), base: false }),
            TableOut::ForeignKey(i) => Ret::obj(ForeignKeyObj { table: cell.clone(), index: i }),
            TableOut::Ret(r) => r,
        })),
    }
}

struct TableObj(Shared);

impl Clone for TableObj {
    fn clone(&self) -> Self {
        TableObj(share(lock(&self.0).deep_clone(), &self.0.cx))
    }
}

fn table_props<S>(x: &Table<S>, property: &str, cell: &Shared) -> Option<Ret> {
    let t = x.definition();
    Some(match property {
        "name" => Ret::str(&t.name),
        "columns" => Ret::List((0..t.columns.len()).map(|i| column_ret(cell, i)).collect()),
        "indexes" => Ret::List(t.indexes.iter().map(|i| Ret::obj(IndexObj(i.clone()))).collect()),
        "foreignKeys" => Ret::List((0..t.foreign_keys.len()).map(|i| foreign_key_ret(cell, i)).collect()),
        "dropColumns" => Ret::strings(&t.drop_columns),
        "renameColumns" => Ret::List(t.rename_columns.iter().map(|r| Ret::obj(RenameObj(r.clone()))).collect()),
        "dropIndexes" => Ret::strings(&t.drop_indexes),
        "dropForeignKeys" => Ret::strings(&t.drop_foreign_keys),
        "rawColumnDefs" => Ret::strings(&t.raw_column_defs),
        "rawIndexDefs" => Ret::strings(&t.raw_index_defs),
        "checks" => Ret::List(t.checks.iter().map(|c| Ret::obj(CheckObj(c.clone()))).collect()),
        "compositePrimaryKey" => Ret::strings(&t.composite_primary_key),
        "orderBy" => Ret::strings(&t.order_by),
        "partitionType" => opt_enum(t.partition_type, "Schema\\PartitionType", PartitionType::name),
        "partitionExpression" => Ret::str(&t.partition_expression),
        "partitionCount" => opt_int(t.partition_count),
        "engine" => opt_enum(t.engine, "Schema\\ClickHouse\\Engine", Engine::name),
        "engineArgs" => Ret::strings(&t.engine_args),
        "ttl" => Ret::opt_str(t.ttl.as_deref()),
        "settings" => {
            Ret::Map(t.settings.iter().map(|(k, v)| (ArrayKey::normalize(k.as_str().into()), Ret::str(v))).collect())
        }
        "sampleBy" => Ret::opt_str(t.sample_by.as_deref()),
        "orderByRaw" => Ret::opt_str(t.order_by_raw.as_deref()),
        _ => return None,
    })
}

impl Object for TableObj {
    fn class(&self) -> String {
        format!("Schema\\Table\\{}", lock(&self.0).dialect().php())
    }

    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        call_table(&self.0, method, a, true)
    }

    fn get(&self, property: &str) -> Option<Ret> {
        let table = lock(&self.0);
        each!(&*table, AnyTable, x => table_props(x, property, &self.0))
    }

    fn parents(&self) -> &'static [&'static str] {
        &["Schema\\Table"]
    }

    query_object_boilerplate!();
}

// ---------------------------------------------------------------------------
// Columns
// ---------------------------------------------------------------------------

/// Where a column object's column lives.
enum Slot {
    /// In its table, at this index.
    Index(usize),
    /// Nowhere: made with `new Column(...)`.
    Detached(AnyColumn),
}

struct ColumnObj {
    table: Shared,
    at: Slot,
    /// The base `Schema\Column` class.
    base: bool,
}

impl Clone for ColumnObj {
    fn clone(&self) -> Self {
        let column = self.with_ref(|c| match c {
            ColRef::MySql(c) => AnyColumn::MySql(c.clone()),
            ColRef::Pg(c) => AnyColumn::Pg(c.clone()),
            ColRef::Sqlite(c) => AnyColumn::Sqlite(c.clone()),
            ColRef::Ch(c) => AnyColumn::Ch(c.clone()),
            ColRef::Mongo(c) => AnyColumn::Mongo(c.clone()),
        });
        ColumnObj { table: self.table.clone(), at: Slot::Detached(column), base: self.base }
    }
}

impl ColumnObj {
    fn with<R>(&mut self, f: impl FnOnce(ColRef<'_>) -> R) -> R {
        match &mut self.at {
            Slot::Detached(c) => f(match c {
                AnyColumn::MySql(c) => ColRef::MySql(c),
                AnyColumn::Pg(c) => ColRef::Pg(c),
                AnyColumn::Sqlite(c) => ColRef::Sqlite(c),
                AnyColumn::Ch(c) => ColRef::Ch(c),
                AnyColumn::Mongo(c) => ColRef::Mongo(c),
            }),
            Slot::Index(i) => {
                let i = *i;
                let mut table = lock(&self.table);
                let missing = "column index out of range";
                f(match &mut *table {
                    AnyTable::MySql(t) => ColRef::MySql(t.column_mut(i).expect(missing)),
                    AnyTable::Pg(t) => ColRef::Pg(t.column_mut(i).expect(missing)),
                    AnyTable::Sqlite(t) => ColRef::Sqlite(t.column_mut(i).expect(missing)),
                    AnyTable::Ch(t) => ColRef::Ch(t.column_mut(i).expect(missing)),
                    AnyTable::Mongo(t) => ColRef::Mongo(t.column_mut(i).expect(missing)),
                })
            }
        }
    }

    /// [`ColumnObj::with`] for reading: works on a copy of a detached column.
    fn with_ref<R>(&self, f: impl FnOnce(ColRef<'_>) -> R) -> R {
        match &self.at {
            Slot::Detached(c) => {
                let mut copy = c.clone();
                f(match &mut copy {
                    AnyColumn::MySql(c) => ColRef::MySql(c),
                    AnyColumn::Pg(c) => ColRef::Pg(c),
                    AnyColumn::Sqlite(c) => ColRef::Sqlite(c),
                    AnyColumn::Ch(c) => ColRef::Ch(c),
                    AnyColumn::Mongo(c) => ColRef::Mongo(c),
                })
            }
            Slot::Index(i) => {
                let i = *i;
                let mut table = lock(&self.table);
                let missing = "column index out of range";
                f(match &mut *table {
                    AnyTable::MySql(t) => ColRef::MySql(t.column_mut(i).expect(missing)),
                    AnyTable::Pg(t) => ColRef::Pg(t.column_mut(i).expect(missing)),
                    AnyTable::Sqlite(t) => ColRef::Sqlite(t.column_mut(i).expect(missing)),
                    AnyTable::Ch(t) => ColRef::Ch(t.column_mut(i).expect(missing)),
                    AnyTable::Mongo(t) => ColRef::Mongo(t.column_mut(i).expect(missing)),
                })
            }
        }
    }

    fn dialect(&self) -> Dialect {
        match &self.at {
            Slot::Detached(AnyColumn::MySql(_)) => Dialect::MySql,
            Slot::Detached(AnyColumn::Pg(_)) => Dialect::Pg,
            Slot::Detached(AnyColumn::Sqlite(_)) => Dialect::Sqlite,
            Slot::Detached(AnyColumn::Ch(_)) => Dialect::Ch,
            Slot::Detached(AnyColumn::Mongo(_)) => Dialect::Mongo,
            Slot::Index(_) => lock(&self.table).dialect(),
        }
    }
}

/// What a column method returned.
enum ColumnOut {
    This,
    Ret(Ret),
}

type ColumnRes = Res<Option<ColumnOut>>;

fn same() -> ColumnRes {
    Ok(Some(ColumnOut::This))
}

/// Base column methods; `dual` when `primary([...])` declares the table's
/// composite key (the dialect column classes).
fn column_common<S>(c: &mut Column<S>, m: &str, a: &Args, dual: bool) -> ColumnRes {
    match m {
        "nullable" => {
            c.nullable();
            same()
        }
        "default" => {
            c.default(a.value(0, "value")?);
            same()
        }
        "defaultRaw" => {
            c.default_raw(&a.str(0, "expression")?)?;
            same()
        }
        "unsigned" => {
            c.unsigned();
            same()
        }
        "primary" => {
            if dual && a.has(0, "columns") && !a.strings(0, "columns")?.is_empty() {
                return Ok(None);
            }
            c.primary();
            same()
        }
        "enum" => {
            if !is_array(a.raw(0, "nameOrValues")) {
                return Ok(None);
            }
            c.enum_values(a.strings(0, "nameOrValues")?)?;
            same()
        }
        "modify" => {
            c.modify();
            same()
        }
        _ => Ok(None),
    }
}

fn column_auto<S: col::AutoIncrement>(c: &mut Column<S>, m: &str) -> ColumnRes {
    match m {
        "autoIncrement" => {
            c.auto_increment();
            same()
        }
        _ => Ok(None),
    }
}

fn column_collation<S: col::Collation>(c: &mut Column<S>, m: &str, a: &Args) -> ColumnRes {
    match m {
        "collation" => {
            c.collation(a.str(0, "collation")?);
            same()
        }
        _ => Ok(None),
    }
}

fn column_comment<S: col::Comment>(c: &mut Column<S>, m: &str, a: &Args) -> ColumnRes {
    match m {
        "comment" => {
            c.comment(a.str(0, "comment")?);
            same()
        }
        _ => Ok(None),
    }
}

fn column_dimensions<S: col::Dimensions>(c: &mut Column<S>, m: &str, a: &Args) -> ColumnRes {
    match m {
        "dimensions" => {
            c.dimensions(a.int(0, "dimensions")?);
            same()
        }
        _ => Ok(None),
    }
}

fn column_generated<S: col::Generated>(c: &mut Column<S>, m: &str, a: &Args) -> ColumnRes {
    match m {
        "generatedAs" => {
            c.generated_as(a.str(0, "expression")?);
            same()
        }
        "stored" => {
            c.stored();
            same()
        }
        _ => Ok(None),
    }
}

fn column_virtual<S: col::VirtualGenerated>(c: &mut Column<S>, m: &str) -> ColumnRes {
    match m {
        "virtual" => {
            c.virtual_();
            same()
        }
        _ => Ok(None),
    }
}

fn column_positioning<S: col::Positioning>(c: &mut Column<S>, m: &str, a: &Args) -> ColumnRes {
    match m {
        "after" => {
            c.after(a.str(0, "column")?);
            same()
        }
        _ => Ok(None),
    }
}

fn column_srid<S: col::Srid>(c: &mut Column<S>, m: &str, a: &Args) -> ColumnRes {
    match m {
        "srid" => {
            c.srid(a.int(0, "srid")?);
            same()
        }
        _ => Ok(None),
    }
}

fn column_unique<S: col::Unique>(c: &mut Column<S>, m: &str) -> ColumnRes {
    match m {
        "unique" => {
            c.unique();
            same()
        }
        _ => Ok(None),
    }
}

fn column_check<S: tb::Checks>(c: &mut Column<S>, m: &str, a: &Args) -> ColumnRes {
    match m {
        "check" => {
            if a.opt_str(1, "expression")?.is_some() {
                return Ok(None);
            }
            c.check(a.str(0, "expressionOrName")?);
            same()
        }
        _ => Ok(None),
    }
}

fn column_clickhouse(c: &mut Column<s::ClickHouse>, m: &str, a: &Args) -> ColumnRes {
    match m {
        "asFixedString" => {
            c.as_fixed_string(a.int(0, "length")?)?;
            same()
        }
        "isFixedString" => Ok(Some(ColumnOut::Ret(Ret::bool(c.is_fixed_string())))),
        "asArray" => {
            c.as_array(column_type(a, 0, "element")?);
            same()
        }
        "isArray" => Ok(Some(ColumnOut::Ret(Ret::bool(c.is_array())))),
        "asTuple" => {
            c.as_tuple(column_types(a, 0, "elements")?)?;
            same()
        }
        "isTuple" => Ok(Some(ColumnOut::Ret(Ret::bool(c.is_tuple())))),
        "lowCardinality" => {
            c.low_cardinality();
            same()
        }
        "codec" => {
            c.codec(&a.str(0, "codec")?)?;
            same()
        }
        "ttl" => {
            c.ttl(&a.str(0, "expression")?)?;
            same()
        }
        _ => Ok(None),
    }
}

fn column_method(c: ColRef<'_>, base: bool, m: &str, a: &Args) -> ColumnRes {
    if base {
        return each!(c, ColRef, c => column_common(c, m, a, false));
    }
    match c {
        ColRef::MySql(c) => chain!(
            column_common(c, m, a, true),
            column_srid(c, m, a),
            column_auto(c, m),
            column_collation(c, m, a),
            column_positioning(c, m, a),
            column_comment(c, m, a),
            column_unique(c, m),
            column_generated(c, m, a),
            column_virtual(c, m),
            column_check(c, m, a),
        ),
        ColRef::Pg(c) => chain!(
            column_common(c, m, a, true),
            column_srid(c, m, a),
            column_dimensions(c, m, a),
            column_auto(c, m),
            column_collation(c, m, a),
            column_unique(c, m),
            column_generated(c, m, a),
            column_check(c, m, a),
            match m {
                "userType" => {
                    c.user_type(&a.str(0, "name")?)?;
                    same()
                }
                _ => Ok(None),
            },
        ),
        ColRef::Sqlite(c) => chain!(
            column_common(c, m, a, true),
            column_auto(c, m),
            column_collation(c, m, a),
            column_comment(c, m, a),
            column_unique(c, m),
            column_generated(c, m, a),
            column_virtual(c, m),
            column_check(c, m, a),
        ),
        ColRef::Ch(c) => chain!(column_common(c, m, a, true), column_comment(c, m, a), column_clickhouse(c, m, a)),
        ColRef::Mongo(c) => chain!(column_common(c, m, a, false), column_comment(c, m, a)),
    }
}

fn column_props(c: &ColumnDefinition, property: &str, cell: &Shared) -> Option<Ret> {
    let opt_str = |s: &Option<String>| Ret::opt_str(s.as_deref());
    Some(match property {
        "name" => Ret::str(&c.name),
        "type" => Ret::Enum("Schema\\ColumnType".into(), c.kind.name()),
        "length" => opt_int(c.length),
        "precision" => opt_int(c.precision),
        "scale" => opt_int(c.scale),
        "isNullable" => Ret::bool(c.nullable),
        "default" => Ret::Value(c.default.clone().unwrap_or(Value::Null)),
        "hasDefault" => Ret::bool(c.default.is_some()),
        "defaultRaw" => opt_str(&c.default_raw),
        "isUnsigned" => Ret::bool(c.unsigned),
        "isUnique" => Ret::bool(c.unique),
        "isPrimary" => Ret::bool(c.primary),
        "isAutoIncrement" => Ret::bool(c.auto_increment),
        "after" => opt_str(&c.after),
        "comment" => opt_str(&c.comment),
        "enumValues" => Ret::strings(&c.enum_values),
        "srid" => opt_int(c.srid),
        "dimensions" => opt_int(c.dimensions),
        "isModify" => Ret::bool(c.modify),
        "collation" => opt_str(&c.collation),
        "checkExpression" => opt_str(&c.check),
        "generatedExpression" => opt_str(&c.generated_expression),
        "generatedStored" => c.generated_stored.map(Ret::bool).unwrap_or_else(Ret::null),
        "ttl" => opt_str(&c.ttl),
        "userTypeName" => opt_str(&c.user_type),
        "isLowCardinality" => Ret::bool(c.low_cardinality),
        "fixedStringLength" => opt_int(c.fixed_string_length),
        "codecs" => Ret::strings(&c.codecs),
        "arrayElementType" => opt_enum(c.array_element_type, "Schema\\ColumnType", ColumnType::name),
        "tupleElementTypes" => {
            Ret::List(c.tuple_element_types.iter().map(|t| Ret::Enum("Schema\\ColumnType".into(), t.name())).collect())
        }
        "table" => table_ret(cell),
        _ => return None,
    })
}

impl Object for ColumnObj {
    fn class(&self) -> String {
        if self.base { "Schema\\Column".into() } else { format!("Schema\\Column\\{}", self.dialect().php()) }
    }

    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        let base = self.base;
        let own = self.with(|c| column_method(c, base, method, a));
        match own {
            Ok(Some(ColumnOut::This)) => Some(Ok(Ret::This)),
            Ok(Some(ColumnOut::Ret(r))) => Some(Ok(r)),
            Err(e) => Some(Err(e)),
            Ok(None) => call_table(&self.table, method, a, false),
        }
    }

    fn get(&self, property: &str) -> Option<Ret> {
        let def = self.with_ref(|c| each!(c, ColRef, c => c.definition().clone()));
        column_props(&def, property, &self.table)
    }

    fn parents(&self) -> &'static [&'static str] {
        if self.base { &[] } else { &["Schema\\Column"] }
    }

    query_object_boilerplate!();
}

// ---------------------------------------------------------------------------
// Foreign keys
// ---------------------------------------------------------------------------

#[derive(Clone)]
struct ForeignKeyObj {
    table: Shared,
    index: usize,
}

impl ForeignKeyObj {
    fn with<R>(&self, f: impl FnOnce(&mut ForeignKey) -> R) -> R {
        let mut table = lock(&self.table);
        let key =
            each!(&mut *table, AnyTable, t => t.foreign_key_mut(self.index).expect("foreign key index out of range"));
        f(key)
    }
}

fn foreign_key_method(key: &mut ForeignKey, m: &str, a: &Args) -> Res<Option<()>> {
    match m {
        "references" => {
            key.references(a.str(0, "column")?);
        }
        "on" => {
            key.on(a.str(0, "table")?);
        }
        "onDelete" => {
            key.on_delete(required_enum(a, 0, "action", ForeignKeyAction::from_name)?);
        }
        "onUpdate" => {
            key.on_update(required_enum(a, 0, "action", ForeignKeyAction::from_name)?);
        }
        _ => return Ok(None),
    }
    Ok(Some(()))
}

impl Object for ForeignKeyObj {
    fn class(&self) -> String {
        format!("Schema\\ForeignKey\\{}", lock(&self.table).dialect().php())
    }

    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        match self.with(|key| foreign_key_method(key, method, a)) {
            Ok(Some(())) => Some(Ok(Ret::This)),
            Err(e) => Some(Err(e)),
            Ok(None) => call_table(&self.table, method, a, false),
        }
    }

    fn get(&self, property: &str) -> Option<Ret> {
        let key = self.with(|key| key.clone());
        Some(match property {
            "refTable" => Ret::str(&key.ref_table),
            "refColumn" => Ret::str(&key.ref_column),
            "onDelete" => opt_enum(key.on_delete, "Schema\\ForeignKeyAction", ForeignKeyAction::name),
            "onUpdate" => opt_enum(key.on_update, "Schema\\ForeignKeyAction", ForeignKeyAction::name),
            "column" => Ret::str(&key.column),
            "table" => table_ret(&self.table),
            _ => return None,
        })
    }

    fn parents(&self) -> &'static [&'static str] {
        &["Schema\\ForeignKey"]
    }

    query_object_boilerplate!();
}

// ---------------------------------------------------------------------------
// Value objects
// ---------------------------------------------------------------------------

#[derive(Clone)]
struct IndexObj(Index);

fn str_map(pairs: &[(String, String)]) -> Ret {
    Ret::Map(pairs.iter().map(|(k, v)| (ArrayKey::normalize(k.as_str().into()), Ret::str(v))).collect())
}

impl Object for IndexObj {
    fn class(&self) -> String {
        "Schema\\Index".into()
    }

    fn is_value(&self) -> bool {
        true
    }

    fn props(&self) -> Vec<(&'static str, Ret)> {
        let i = &self.0;
        vec![
            ("name", Ret::str(&i.name)),
            ("columns", Ret::strings(&i.columns)),
            ("type", Ret::Enum("Schema\\IndexType".into(), i.kind.name())),
            (
                "lengths",
                Ret::Map(
                    i.lengths.iter().map(|(k, v)| (ArrayKey::normalize(k.as_str().into()), Ret::int(*v))).collect(),
                ),
            ),
            ("orders", str_map(&i.orders)),
            ("method", Ret::str(&i.method)),
            ("operatorClass", Ret::str(&i.operator_class)),
            ("collations", str_map(&i.collations)),
            ("rawColumns", Ret::strings(&i.raw_columns)),
            ("algorithm", opt_enum(i.algorithm, "Schema\\ClickHouse\\IndexAlgorithm", IndexAlgorithm::name)),
            ("algorithmArgs", Ret::values(&i.algorithm_args)),
            ("granularity", opt_int(i.granularity)),
        ]
    }

    fn call(&mut self, _method: &str, _a: &mut Args) -> Option<Outcome> {
        None
    }

    query_object_boilerplate!();
}

#[derive(Clone)]
struct CheckObj(CheckConstraint);

impl Object for CheckObj {
    fn class(&self) -> String {
        "Schema\\CheckConstraint".into()
    }

    fn is_value(&self) -> bool {
        true
    }

    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![("name", Ret::str(&self.0.name)), ("expression", Ret::str(&self.0.expression))]
    }

    fn call(&mut self, _method: &str, _a: &mut Args) -> Option<Outcome> {
        None
    }

    query_object_boilerplate!();
}

#[derive(Clone)]
struct RenameObj(RenameColumn);

impl Object for RenameObj {
    fn class(&self) -> String {
        "Schema\\RenameColumn".into()
    }

    fn is_value(&self) -> bool {
        true
    }

    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![("from", Ret::str(&self.0.from)), ("to", Ret::str(&self.0.to))]
    }

    fn call(&mut self, _method: &str, _a: &mut Args) -> Option<Outcome> {
        None
    }

    query_object_boilerplate!();
}

// ---------------------------------------------------------------------------
// Entry points
// ---------------------------------------------------------------------------

/// `new Class(...)` for the area's classes.
pub(crate) fn construct(class: &str, a: &mut Args) -> Option<Outcome> {
    if let Some(dialect) = class.strip_prefix("Schema\\").and_then(Dialect::from_php) {
        return Some(Ok(Ret::obj(SchemaObj(AnySchema::new(dialect)))));
    }
    if let Some(dialect) = class.strip_prefix("Schema\\Table\\").and_then(Dialect::from_php) {
        return Some((|| -> Outcome {
            let name = a.str_or(1, "name", "")?;
            let schema = match a.raw(0, "schema") {
                None | Some(Json::Null) => None,
                Some(_) => Some(a.object::<SchemaObj>(0, "schema")?),
            };
            let table = match schema {
                Some(schema) if schema.0.dialect() == dialect => AnyTable::of(&schema.0, name),
                _ => AnyTable::detached(dialect, name),
            };
            Ok(Ret::obj(TableObj(share(table, a.cx()))))
        })());
    }
    let column = match class {
        "Schema\\Column" => Some(None),
        _ => class.strip_prefix("Schema\\Column\\").and_then(Dialect::from_php).map(Some),
    };
    if let Some(dialect) = column {
        return Some((|| -> Outcome {
            let id = a.object_id(0, "table")?;
            let cell = a
                .with_id(id, |o| o.as_any().downcast_ref::<TableObj>().map(|t| t.0.clone()))?
                .ok_or_else(|| fault("expected a table"))?;
            let table_dialect = lock(&cell).dialect();
            if dialect.is_some_and(|d| d != table_dialect) {
                return Err(fault("a column of another dialect than its table"));
            }
            let name = a.str(1, "name")?;
            let kind = column_type(a, 2, "type")?;
            let length = a.opt_int(3, "length")?;
            let precision = a.opt_int(4, "precision")?;
            let scale = a.opt_int(5, "scale")?;
            let srid = a.opt_int(6, "srid")?;
            let dimensions = a.opt_int(7, "dimensions")?;
            let auto_increment = a.bool_or(8, "autoIncrement", false)?;
            macro_rules! make {
                ($variant:ident) => {
                    AnyColumn::$variant(
                        Column::new(name, kind)
                            .with_length(length)
                            .with_precision(precision)
                            .with_scale(scale)
                            .with_srid(srid)
                            .with_dimensions(dimensions)
                            .with_auto_increment(auto_increment),
                    )
                };
            }
            let column = match table_dialect {
                Dialect::MySql => make!(MySql),
                Dialect::Pg => make!(Pg),
                Dialect::Sqlite => make!(Sqlite),
                Dialect::Ch => make!(Ch),
                Dialect::Mongo => make!(Mongo),
            };
            Ok(Ret::obj(ColumnObj { table: cell, at: Slot::Detached(column), base: dialect.is_none() }))
        })());
    }
    match class {
        "Schema\\Index" => Some((|| -> Outcome {
            let kind = enum_arg(a, 2, "type", IndexType::from_name)?.unwrap_or(IndexType::Index);
            let mut index = Index::new(a.str(0, "name")?, a.strings(1, "columns")?, kind);
            index.lengths = int_pairs(a, 3, "lengths")?;
            index.orders = str_pairs(a, 4, "orders")?;
            index.method = a.str_or(5, "method", "")?;
            index.operator_class = a.str_or(6, "operatorClass", "")?;
            index.collations = str_pairs(a, 7, "collations")?;
            index.raw_columns = a.strings_or_empty(8, "rawColumns")?;
            index.algorithm = enum_arg(a, 9, "algorithm", IndexAlgorithm::from_name)?;
            index.algorithm_args = values(a, 10, "algorithmArgs")?;
            index.granularity = a.opt_int(11, "granularity")?;
            Ok(Ret::obj(IndexObj(index.validated()?)))
        })()),
        "Schema\\CheckConstraint" => Some((|| -> Outcome {
            Ok(Ret::obj(CheckObj(CheckConstraint::new(a.str(0, "name")?, a.str(1, "expression")?)?)))
        })()),
        "Schema\\RenameColumn" => {
            Some((|| -> Outcome { Ok(Ret::obj(RenameObj(RenameColumn::new(a.str(0, "from")?, a.str(1, "to")?)))) })())
        }
        _ => None,
    }
}

/// The relative PHP class of each schema enum.
macro_rules! enum_classes {
    ($callback:ident, $class:expr, $($args:tt)*) => {
        match $class {
            "Schema\\ColumnType" => $callback!(ColumnType, "Schema\\ColumnType", $($args)*),
            "Schema\\IndexType" => $callback!(IndexType, "Schema\\IndexType", $($args)*),
            "Schema\\ForeignKeyAction" => $callback!(ForeignKeyAction, "Schema\\ForeignKeyAction", $($args)*),
            "Schema\\Order" => $callback!(Order, "Schema\\Order", $($args)*),
            "Schema\\ParameterDirection" => $callback!(ParameterDirection, "Schema\\ParameterDirection", $($args)*),
            "Schema\\PartitionType" => $callback!(PartitionType, "Schema\\PartitionType", $($args)*),
            "Schema\\TriggerEvent" => $callback!(TriggerEvent, "Schema\\TriggerEvent", $($args)*),
            "Schema\\TriggerTiming" => $callback!(TriggerTiming, "Schema\\TriggerTiming", $($args)*),
            "Schema\\ClickHouse\\Engine" => $callback!(Engine, "Schema\\ClickHouse\\Engine", $($args)*),
            "Schema\\ClickHouse\\IndexAlgorithm" => $callback!(IndexAlgorithm, "Schema\\ClickHouse\\IndexAlgorithm", $($args)*),
            _ => None,
        }
    };
}

macro_rules! enum_static {
    ($t:ty, $class:expr, $method:expr, $a:expr) => {
        match $method {
            "cases" => Some(Ok(Ret::List(<$t>::CASES.iter().map(|c| Ret::Enum($class.into(), c.name())).collect()))),
            "from" => {
                Some((|| -> Outcome { Ok(Ret::Enum($class.into(), <$t>::from_value(&$a.str(0, "value")?)?.name())) })())
            }
            "tryFrom" => Some((|| -> Outcome {
                Ok(match <$t>::try_from_value(&$a.str(0, "value")?) {
                    Some(c) => Ret::Enum($class.into(), c.name()),
                    None => Ret::null(),
                })
            })()),
            _ => None,
        }
    };
}

/// `Class::method(...)` for the area's classes.
pub(crate) fn call_static(class: &str, method: &str, a: &mut Args) -> Option<Outcome> {
    enum_classes!(enum_static, class, method, a)
}

/// `Class::CONSTANT` for the area's classes.
pub(crate) fn constant(_class: &str, _name: &str) -> Option<Ret> {
    None
}

macro_rules! enum_method {
    ($t:ty, $class:expr, $case:expr, $method:expr) => {{
        let value = <$t>::from_name($case)?;
        let _ = $class;
        enum_case_method(value.as_str(), $method, || extra_enum_method(&value as &dyn std::any::Any, $method))
    }};
}

fn enum_case_method(value: &'static str, method: &str, extra: impl FnOnce() -> Option<Ret>) -> Option<Outcome> {
    match method {
        "->value" => Some(Ok(Ret::str(value))),
        _ => extra().map(Ok),
    }
}

fn extra_enum_method(value: &dyn std::any::Any, method: &str) -> Option<Ret> {
    if let Some(action) = value.downcast_ref::<ForeignKeyAction>() {
        return (method == "toSql").then(|| Ret::str(action.to_sql()));
    }
    if let Some(engine) = value.downcast_ref::<Engine>() {
        return (method == "requiresOrderBy").then(|| Ret::bool(engine.requires_order_by()));
    }
    None
}

/// `Class::Case->method(...)` (and `->value` as method `->value`) for the area's enums.
pub(crate) fn enum_call(class: &str, case: &str, method: &str, _args: &mut Args) -> Option<Outcome> {
    enum_classes!(enum_method, class, case, method)
}
