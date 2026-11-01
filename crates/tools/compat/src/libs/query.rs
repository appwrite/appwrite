//! Compat adapter for `query`: replays the programs recorded from the PHP
//! tests (tests/compat/query/Replay.php is the PHP side) on `utopia-query`.
//!
//! [`engine`] runs a program: it numbers objects, decodes arguments, calls
//! back into the program when the library invokes a test closure or a test
//! object, and exports results the way the PHP replay does. Each area maps
//! PHP classes and methods onto the crate: [`core`] (queries, statements,
//! hooks), [`builder`], [`schema`], [`ast`], [`tokenizer`], [`classifier`].

// Dispatch arms are `(|| -> Outcome { ... })()` blocks so `?` returns from the arm.
#![allow(clippy::redundant_closure_call)]

use serde_json::Value;

use utopia_query::Query;
use utopia_query::ast::{Parser, Serializer};
use utopia_query::tokenizer::Tokenizer;

use crate::adapter::{Fault, OpResult, Outcome, Session};
use crate::bench::Bench;

mod ast;
mod builder;
mod builder_clickhouse;
mod builder_mariadb;
mod builder_mongodb;
mod builder_postgresql;
mod builder_sqlite;
mod classifier;
mod core;
pub mod engine;
mod schema;
mod tokenizer;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "ast",
    "ast.parse",
    "builder",
    "builder.build",
    "builder.queries",
    "classifier",
    "classifier.classify",
    "hook",
    "query",
    "query.parse",
    "query.parse_query",
    "schema",
    "tokenizer",
    "tokenizer.tokenize",
];

const REPLAYS: &[&str] = &["ast", "builder", "classifier", "hook", "query", "schema", "tokenizer"];

fn class_of(args: &Value, namespace: &str) -> Result<String, Fault> {
    let name = match args.get("dialect").and_then(Value::as_str).unwrap_or_default() {
        "mysql" => "MySQL",
        "mariadb" => "MariaDB",
        "postgresql" => "PostgreSQL",
        "sqlite" => "SQLite",
        "clickhouse" => "ClickHouse",
        "mongodb" => "MongoDB",
        _ => return Err(Fault::new("unknown dialect")),
    };
    Ok(format!("{namespace}\\{name}"))
}

/// Runs a short program and returns its last output.
fn last(program: Vec<Value>) -> OpResult {
    let out = engine::run(&program, &Value::Null)?;
    Ok(Outcome::Ok(out.as_array().and_then(|o| o.last()).cloned().unwrap_or(Value::Null)))
}

fn lib(result: utopia_query::Result<Value>) -> OpResult {
    Ok(match result {
        Ok(v) => Outcome::Ok(v),
        Err(e) => Outcome::err_bytes(e.php_class(), &e.message_bytes()),
    })
}

fn build_dialect(args: &Value, queries: &[Query]) -> OpResult {
    use utopia_query::builder::*;
    lib(match args.get("dialect").and_then(Value::as_str).unwrap_or_default() {
        "mysql" => build_with::<MySqlDialect>(queries),
        "mariadb" => build_with::<MariaDbDialect>(queries),
        "postgresql" => build_with::<PostgreSqlDialect>(queries),
        "sqlite" => build_with::<SqliteDialect>(queries),
        "clickhouse" => build_with::<ClickHouseDialect>(queries),
        "mongodb" => build_with::<MongoDbDialect>(queries),
        _ => return Err(Fault::new("unknown dialect")),
    })
}

fn build_with<D: utopia_query::builder::Dialect>(queries: &[Query]) -> utopia_query::Result<Value> {
    let mut builder = utopia_query::builder::Builder::<D>::new();
    builder.from("t", "").queries(queries.iter().cloned());
    let statement = builder.build()?;
    let cx = engine::standalone();
    let bindings: Vec<Value> = statement.bindings.iter().map(|v| encode(&cx, v)).collect();
    Ok(serde_json::json!({"query": statement.query, "bindings": bindings}))
}

/// A binding as the PHP driver's `Codec::encode` writes the value the
/// adapter returns: arrays and `stdClass` as JSON (an empty `stdClass` is
/// `{}`), strings that are not UTF-8 and non-finite floats tagged.
fn encode(cx: &engine::Cx, value: &utopia_query::Value) -> Value {
    use utopia_query::Value as V;
    let entries = |a: &utopia_query::Array| {
        a.iter()
            .map(|(k, v)| {
                let key = match k {
                    php_std::types::KeyRef::Int(i) => i.to_string(),
                    php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                };
                (key, encode(cx, v))
            })
            .collect::<serde_json::Map<String, Value>>()
    };
    match value {
        V::Array(a) if a.is_list() => Value::Array(a.values().map(|v| encode(cx, v)).collect()),
        V::Array(a) | V::Object(a) => Value::Object(entries(a)),
        other => cx.export_value(other),
    }
}

/// Benchmarks: name and what each times.
pub const BENCHES: &[(&str, &str)] = &[
    ("parse", "Parse a query from its JSON (an equal filter with two values)"),
    ("mysql_select", "Build a MySQL select: three filters, an order, a limit and an offset"),
    ("postgresql_select", "Build the same select for PostgreSQL"),
    ("clickhouse_select", "Build the same select for ClickHouse"),
    ("mongodb_find", "Build the same query as a MongoDB find command"),
    ("tokenize", "Tokenize a 190-byte select with a join, grouping and ordering"),
    ("classify", "Classify a 190-byte select as a read"),
    ("ast_roundtrip", "Parse the 190-byte select into an AST and serialize it back"),
    ("schema_create", "Compile a MySQL CREATE TABLE with four columns, a unique index and a primary key"),
];

const BENCH_FILTER: &str = r#"{"method":"equal","attribute":"status","values":["active","pending"]}"#;

const BENCH_SQL: &str = "SELECT u.id, u.name, COUNT(o.id) AS orders FROM users AS u LEFT JOIN orders AS o ON o.user_id = u.id WHERE u.status = ? AND u.age > 18 GROUP BY u.id, u.name ORDER BY orders DESC LIMIT 25";

fn bench_select<D: utopia_query::builder::Dialect>() -> utopia_query::Result<utopia_query::builder::Statement> {
    utopia_query::builder::Builder::<D>::new()
        .from("users", "")
        .select(["id", "name", "email"])
        .filter([Query::equal("status", ["active"]), Query::greater_than("age", 18), Query::starts_with("name", "a")])
        .sort_desc("createdAt", None)
        .limit(25)
        .offset(50)
        .build()
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    use serde_json::json;
    match op {
        "query.parse" => {
            return last(vec![
                json!({"static": "parse", "class": "Query", "args": [args.get("query"), args.get("allowRaw").cloned().unwrap_or(json!(false))]}),
            ]);
        }
        "query.parse_query" => {
            return last(vec![
                json!({"static": "parseQuery", "class": "Query", "args": [args.get("query"), args.get("allowRaw").cloned().unwrap_or(json!(false))]}),
            ]);
        }
        "tokenizer.tokenize" => {
            return last(vec![
                json!({"new": class_of(args, "Tokenizer")?, "args": []}),
                json!({"call": "tokenize", "on": {"$o": 1}, "args": [args.get("sql")]}),
            ]);
        }
        "classifier.classify" => {
            let method =
                if args.get("sql").and_then(Value::as_bool).unwrap_or(false) { "classifySQL" } else { "classify" };
            return last(vec![
                json!({"new": class_of(args, "Classifier")?, "args": []}),
                json!({"call": method, "on": {"$o": 1}, "args": [args.get("data")]}),
            ]);
        }
        "ast.parse" => {
            let sql = args.get("sql").and_then(Value::as_str).unwrap_or_default();
            return lib((|| {
                let tokens = Tokenizer::filter(Tokenizer::new().tokenize(sql)?);
                let select = Parser::new().parse(&tokens)?;
                Ok(Value::String(Serializer::new().serialize(&select)?))
            })());
        }
        "builder.queries" => {
            let raw = args.get("queries").and_then(Value::as_array).cloned().unwrap_or_default();
            let cx = engine::standalone();
            let mut queries = Vec::with_capacity(raw.len());
            for item in &raw {
                let decoded = cx.decoded(item);
                let array = match decoded.value(0, "query") {
                    Ok(utopia_query::Value::Array(a)) => a,
                    _ => return Err(Fault::new("a query must be an object")),
                };
                match Query::parse_query(&array, false) {
                    Ok(q) => queries.push(q),
                    Err(e) => return Ok(Outcome::err(e.php_class(), e.to_string())),
                }
            }
            return build_dialect(args, &queries);
        }
        "builder.build" => {
            let items: Vec<String> = args
                .get("queries")
                .and_then(Value::as_array)
                .map(|a| a.iter().map(|q| q.as_str().unwrap_or_default().to_owned()).collect())
                .unwrap_or_default();
            let queries = match Query::parse_queries(&items, false) {
                Ok(q) => q,
                Err(e) => return Ok(Outcome::err(e.php_class(), e.to_string())),
            };
            return build_dialect(args, &queries);
        }
        _ => {}
    }
    if !REPLAYS.contains(&op) {
        return Err(Fault::new(format!("query: unknown operation `{op}`")));
    }
    let program = args.get("program").and_then(Value::as_array).ok_or_else(|| Fault::new("missing `program`"))?;
    let impls = args.get("impls").cloned().unwrap_or(Value::Null);
    engine::run(program, &impls).map(Outcome::Ok)
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    use std::hint::black_box;

    use utopia_query::builder::{ClickHouseDialect, MongoDbDialect, MySqlDialect, PostgreSqlDialect};
    use utopia_query::classifier::{MySql as MySqlClassifier, Sql};
    use utopia_query::schema::{MySql as MySqlSchema, Schema};

    Ok(match name {
        "parse" => Bench::sync(|| {
            black_box(Query::parse(black_box(BENCH_FILTER), false).ok());
        }),
        "mysql_select" => Bench::sync(|| {
            black_box(bench_select::<MySqlDialect>().ok());
        }),
        "postgresql_select" => Bench::sync(|| {
            black_box(bench_select::<PostgreSqlDialect>().ok());
        }),
        "clickhouse_select" => Bench::sync(|| {
            black_box(bench_select::<ClickHouseDialect>().ok());
        }),
        "mongodb_find" => Bench::sync(|| {
            black_box(bench_select::<MongoDbDialect>().ok());
        }),
        "tokenize" => {
            let tokenizer = Tokenizer::new();
            Bench::sync(move || {
                black_box(tokenizer.tokenize(black_box(BENCH_SQL)).ok());
            })
        }
        "classify" => Bench::sync(|| {
            black_box(MySqlClassifier.classify_sql(black_box(BENCH_SQL.as_bytes())));
        }),
        "ast_roundtrip" => {
            let tokenizer = Tokenizer::new();
            let parser = Parser::new();
            let serializer = Serializer::new();
            Bench::sync(move || {
                let tokens = Tokenizer::filter(tokenizer.tokenize(black_box(BENCH_SQL)).unwrap_or_default());
                black_box(parser.parse(&tokens).and_then(|select| serializer.serialize(&select)).ok());
            })
        }
        "schema_create" => {
            let schema = MySqlSchema::new();
            Bench::sync(move || {
                let mut table = schema.table("users");
                table.id("id");
                table.string("email", 255).unique();
                table.integer("age");
                table.boolean("active");
                black_box(table.create().ok());
            })
        }
        _ => return Err(Fault::new(format!("query: no benchmark `{name}`"))),
    })
}

/// Constructs a library object of `class` (relative to `Utopia\Query\`).
pub(crate) fn construct(class: &str, args: &mut engine::Args) -> Option<engine::Outcome> {
    core::construct(class, args)
        .or_else(|| builder::construct(class, args))
        .or_else(|| schema::construct(class, args))
        .or_else(|| ast::construct(class, args))
        .or_else(|| tokenizer::construct(class, args))
        .or_else(|| classifier::construct(class, args))
}

/// Calls a static method of `class`.
pub(crate) fn call_static(class: &str, method: &str, args: &mut engine::Args) -> Option<engine::Outcome> {
    core::call_static(class, method, args)
        .or_else(|| builder::call_static(class, method, args))
        .or_else(|| schema::call_static(class, method, args))
        .or_else(|| ast::call_static(class, method, args))
        .or_else(|| tokenizer::call_static(class, method, args))
        .or_else(|| classifier::call_static(class, method, args))
}

/// A class constant (not an enum case) of `class`.
pub(crate) fn constant(class: &str, name: &str) -> Option<engine::Ret> {
    core::constant(class, name)
        .or_else(|| builder::constant(class, name))
        .or_else(|| schema::constant(class, name))
        .or_else(|| ast::constant(class, name))
        .or_else(|| tokenizer::constant(class, name))
        .or_else(|| classifier::constant(class, name))
}
