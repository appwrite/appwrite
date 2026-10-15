//! The SQL adapters' document writes, reads by id, upserts and operators.
//!
//! `parity` runs one scenario per engine and table-sharing mode and checks
//! every statement it runs (SQL, bindings, event) and every value it
//! returns against what PHP's utopia-php/database 8.1.3 recorded for the
//! same scenario (`tests/fixtures/sql_documents/oracle.php`). The other
//! modules port the adapter-level PHP unit tests of the area.

#![cfg(any(feature = "sqlite", feature = "postgres", feature = "mariadb", feature = "mysql"))]

use std::sync::Arc;

use utopia_database::adapter::sql::{Engine, Sql};
use utopia_database::adapter::{Adapter, RawQuery, Upserts};
use utopia_database::attribute::Attribute;
use utopia_database::change::Change;
use utopia_database::collection::Collection;
use utopia_database::operator::Operator;
use utopia_database::profiler::Profiler;
use utopia_database::value::{Array, ArrayKey, Ext, Str, Value};
use utopia_database::{Document, Query};

/// A string value.
fn s(text: &str) -> Value {
    Value::Str(Str::from(text))
}

/// A list value.
fn list(items: Vec<Value>) -> Value {
    Value::Array(Array::from_list(items))
}

/// A list of strings.
fn strings(items: &[&str]) -> Value {
    list(items.iter().map(|i| s(i)).collect())
}

/// An operator value.
fn op(operator: Operator) -> Value {
    Value::from(operator)
}

/// A document of `pairs`, later pairs replacing earlier ones in place.
fn doc(pairs: Vec<(&str, Value)>) -> Document {
    let mut array = Array::new();
    for (k, v) in pairs {
        array.set(ArrayKey::normalize(Str::from(k)), v);
    }
    Document::new(array).unwrap()
}

/// `array_merge($document->getArrayCopy(), $pairs)`.
fn merged(document: &Document, pairs: Vec<(&str, Value)>) -> Document {
    let mut array = document.array_copy();
    for (k, v) in pairs {
        array.set(ArrayKey::normalize(Str::from(k)), v);
    }
    Document::new(array).unwrap()
}

/// A value as the oracle prints it: documents as their arrays, operators as
/// `{}`, strings that are not UTF-8 as `hex:` and their bytes.
fn json(value: &Value) -> serde_json::Value {
    use serde_json::Value as J;
    match value {
        Value::Null => J::Null,
        Value::Bool(b) => J::Bool(*b),
        Value::Int(i) => J::from(*i),
        Value::Float(f) => serde_json::Number::from_f64(*f).map_or(J::Null, J::Number),
        Value::Str(text) => match std::str::from_utf8(text.as_bytes()) {
            Ok(t) => J::String(t.to_owned()),
            Err(_) => J::String(format!("hex:{}", hex::encode(text.as_bytes()))),
        },
        Value::Array(a) => array_json(a),
        Value::Object(a) => array_json(a),
        Value::Ext(Ext::Document(d)) => array_json(d.as_array()),
        Value::Ext(_) => J::Object(serde_json::Map::new()),
    }
}

fn array_json(array: &Array) -> serde_json::Value {
    if array.is_list() {
        return serde_json::Value::Array(array.values().map(json).collect());
    }
    let mut map = serde_json::Map::new();
    for (k, v) in array.iter() {
        let key = match k {
            utopia_database::value::KeyRef::Int(i) => i.to_string(),
            utopia_database::value::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
        };
        map.insert(key, json(v));
    }
    serde_json::Value::Object(map)
}

fn documents_json(documents: &[Document]) -> serde_json::Value {
    serde_json::Value::Array(documents.iter().map(|d| array_json(d.as_array())).collect())
}

/// An adapter of `engine` on the compat server (`None`, with a note, when
/// it is unreachable).
async fn connect(engine: Engine) -> Option<Sql> {
    let (dsn, user, pass) = match engine {
        Engine::Sqlite => ("sqlite::memory:", None, None),
        Engine::Postgres => ("pgsql:host=localhost;port=16432", Some("root"), Some("password")),
        Engine::MariaDb => ("mysql:host=localhost;port=16306;charset=utf8mb4", Some("root"), Some("password")),
        Engine::MySql => ("mysql:host=localhost;port=16307;charset=utf8mb4", Some("root"), Some("password")),
    };
    match Sql::connect(engine, dsn, user, pass).await {
        Ok(adapter) => Some(adapter),
        Err(e) => {
            eprintln!("skipping {engine:?}: {}", e.message());
            None
        }
    }
}

mod parity {
    use super::*;

    fn fixture(engine: Engine, shared: bool) -> serde_json::Value {
        let text = match (engine, shared) {
            (Engine::Sqlite, false) => include_str!("fixtures/sql_documents/sqlite_0.json"),
            (Engine::Sqlite, true) => include_str!("fixtures/sql_documents/sqlite_1.json"),
            (Engine::MariaDb, false) => include_str!("fixtures/sql_documents/mariadb_0.json"),
            (Engine::MariaDb, true) => include_str!("fixtures/sql_documents/mariadb_1.json"),
            (Engine::MySql, false) => include_str!("fixtures/sql_documents/mysql_0.json"),
            (Engine::MySql, true) => include_str!("fixtures/sql_documents/mysql_1.json"),
            (Engine::Postgres, false) => include_str!("fixtures/sql_documents/postgres_0.json"),
            (Engine::Postgres, true) => include_str!("fixtures/sql_documents/postgres_1.json"),
        };
        serde_json::from_str(text).unwrap()
    }

    struct Run {
        profiler: Arc<Profiler>,
        seen: usize,
        steps: Vec<serde_json::Value>,
    }

    impl Run {
        fn skip(&mut self) {
            self.seen = self.profiler.logs().len();
        }

        fn record(&mut self, name: &str, outcome: utopia_database::Result<serde_json::Value>) {
            let logs = self.profiler.logs();
            let statements: Vec<serde_json::Value> = logs[self.seen..]
                .iter()
                .map(|log| {
                    serde_json::json!({ "op": log.operation, "q": log.query, "b": array_json(&log.bindings) })
                })
                .collect();
            self.seen = logs.len();
            let (result, error) = match outcome {
                Ok(result) => (result, serde_json::Value::Null),
                Err(e) => (serde_json::Value::Null, serde_json::Value::String(format!("{}: {}", e.php_class(), e.message()))),
            };
            self.steps.push(serde_json::json!({ "step": name, "statements": statements, "result": result, "error": error }));
        }
    }

    fn collection(spatial: bool) -> Collection {
        let mut attributes = vec![
            Attribute::string("title", 128),
            Attribute::integer("count"),
            Attribute::double("price"),
            Attribute::boolean("active"),
            Attribute::string("tags", 64).array(true),
            Attribute::string("note", 256),
            Attribute::datetime("date"),
        ];
        if spatial {
            attributes.push(Attribute::point("loc"));
        }
        Collection::create("docs", "", attributes, vec![], None, true, Array::new()).unwrap()
    }

    /// The tables PHP's `createCollection()` made, made with its statements.
    async fn create_tables(adapter: &Sql, expected: &serde_json::Value) {
        for statement in expected[0]["statements"].as_array().unwrap() {
            let query = statement["q"].as_str().unwrap();
            let trimmed = query.trim_start();
            if trimmed.starts_with("SELECT") {
                continue;
            }
            adapter.pdo().lock().await.exec(query).await.unwrap();
        }
    }

    fn base(id: &str, extra: Vec<(&str, Value)>) -> Document {
        let mut pairs = vec![
            ("$id", s(id)),
            ("$permissions", strings(&["read(\"any\")"])),
            ("$createdAt", s("2024-01-02 03:04:05.678")),
            ("$updatedAt", s("2024-01-02 03:04:05.678")),
            ("title", s(id)),
            ("count", Value::Int(1)),
            ("price", Value::Float(0.25)),
            ("active", Value::Bool(false)),
            ("tags", list(vec![])),
            ("note", Value::Null),
        ];
        pairs.extend(extra);
        doc(pairs)
    }

    fn upserted(id: &str, extra: Vec<(&str, Value)>) -> Document {
        let mut pairs = vec![
            ("$id", s(id)),
            ("$permissions", strings(&["read(\"any\")"])),
            ("$createdAt", s("2024-05-05 05:05:05.000")),
            ("$updatedAt", s("2024-05-05 05:05:05.000")),
            ("title", s(id)),
            ("count", Value::Int(4)),
            ("price", Value::Float(2.5)),
            ("active", Value::Bool(true)),
            ("tags", strings(&["u"])),
            ("note", s("n")),
        ];
        pairs.extend(extra);
        doc(pairs)
    }

    fn sequence(document: &Document) -> Value {
        document.get("$sequence").cloned().unwrap_or(Value::Null)
    }

    async fn scenario(engine: Engine, shared: bool) {
        let Some(adapter) = connect(engine).await else { return };
        let label = match engine {
            Engine::Sqlite => "sqlite",
            Engine::MariaDb => "mariadb",
            Engine::MySql => "mysql",
            Engine::Postgres => "postgres",
        };
        let db = format!("bdocs_{label}{}", if shared { "_shared" } else { "" });
        let expected = fixture(engine, shared);
        adapter.set_database(&db);
        adapter.set_namespace("ns");
        adapter.set_shared_tables(shared);
        if shared {
            adapter.set_tenant(Value::Int(7));
        }
        if engine != Engine::Sqlite {
            let _ = adapter.delete(&db).await;
            adapter.create(&db).await.unwrap();
        }
        create_tables(&adapter, &expected).await;
        let spatial = engine != Engine::Sqlite;
        let collection = collection(spatial);
        let profiler = Arc::new(Profiler::new());
        profiler.enable();
        adapter.set_profiler(Some(profiler.clone()));
        let mut run = Run { profiler, seen: 0, steps: vec![expected[0].clone()] };
        let one = |d: Document| array_json(d.as_array());

        let mut doc1 = doc(vec![
            ("$id", s("doc1")),
            ("$permissions", strings(&["read(\"any\")", "update(\"user:a/b\")"])),
            ("$createdAt", s("2024-01-02 03:04:05.678")),
            ("$updatedAt", s("2024-01-02 03:04:05.678")),
            ("title", s("Hello \"world\"")),
            ("count", Value::Int(5)),
            ("price", Value::Float(1.5)),
            ("active", Value::Bool(true)),
            ("tags", strings(&["a", "b/c"])),
            ("note", s("x")),
            ("date", s("2024-01-01 00:00:00.000")),
        ]);
        if spatial {
            doc1.set("loc", list(vec![Value::Float(1.5), Value::Int(2)])).unwrap();
        }
        let r = adapter.create_document(&collection, doc1).await.map(one);
        run.record("createDocument", r);
        let r = adapter
            .create_documents(&collection, vec![base("doc2", vec![]), base("doc3", vec![("count", Value::Int(2))])])
            .await
            .map(|d| documents_json(&d));
        run.record("createDocuments", r);
        let batch = vec![
            base("doc2", vec![("count", Value::Int(99))]),
            base("doc4", vec![]),
            base("doc4", vec![("count", Value::Int(98))]),
        ];
        let r = adapter
            .state()
            .ignoring_duplicates()
            .with(true, adapter.create_documents(&collection, batch))
            .await
            .map(|d| documents_json(&d));
        run.record("createDocumentsIgnore", r);
        let r = adapter.get_document(&collection, "doc1", vec![], false).await.map(one);
        run.record("getDocument", r);
        let r = adapter.get_document(&collection, "nope", vec![], false).await.map(one);
        run.record("getDocumentMissing", r);
        let select = || vec![Query::select(["title", "count", "$id"])];
        let r = adapter.get_document(&collection, "doc1", select(), false).await.map(one);
        run.record("getDocumentSelect", r);
        let r = adapter.get_document(&collection, "doc2", vec![], true).await.map(one);
        run.record("getDocumentForUpdate", r);
        let r = adapter.get_document(&collection, "doc2", vec![Query::select(["title"])], true).await.map(one);
        run.record("getDocumentSelectForUpdate", r);
        let r = adapter
            .get_sequences(
                &collection,
                vec![doc(vec![("$id", s("doc1"))]), doc(vec![("$id", s("doc3")), ("$sequence", s("3"))]), doc(vec![("$id", s("doc4"))])],
            )
            .await
            .map(|d| documents_json(&d));
        run.record("getSequences", r);

        let current = adapter.get_document(&collection, "doc1", vec![], false).await.unwrap();
        run.skip();
        let mut update = merged(
            &current,
            vec![
                ("title", s("Changed")),
                ("active", Value::Bool(false)),
                ("tags", strings(&["z"])),
                ("$updatedAt", s("2024-02-02 00:00:00.000")),
            ],
        );
        if spatial {
            update.set("loc", list(vec![Value::Int(3), Value::Float(4.25)])).unwrap();
        }
        let r = adapter.update_document(&collection, "doc1", update, false).await.map(one);
        run.record("updateDocument", r);
        let mut renamed = merged(&current, vec![("$id", s("doc1b")), ("title", s("Renamed"))]);
        if spatial {
            renamed.set("loc", list(vec![Value::Int(3), Value::Float(4.25)])).unwrap();
        }
        let r = adapter.update_document(&collection, "doc1", renamed, true).await.map(one);
        run.record("updateDocumentRename", r);

        let ops: Vec<(&str, &str, Operator)> = vec![
            ("increment", "count", Operator::increment(2, None)),
            ("incrementMax", "count", Operator::increment(2, Some(100.into()))),
            ("decrement", "count", Operator::decrement(1, None)),
            ("decrementMin", "count", Operator::decrement(1, Some(0.into()))),
            ("multiply", "price", Operator::multiply(2, None)),
            ("multiplyMax", "price", Operator::multiply(2, Some(1000.5.into()))),
            ("divide", "price", Operator::divide(2, None).unwrap()),
            ("divideMin", "price", Operator::divide(2, Some(0.1.into())).unwrap()),
            ("modulo", "count", Operator::modulo(3).unwrap()),
            ("power", "count", Operator::power(2, None)),
            ("powerMax", "count", Operator::power(2, Some(1000.into()))),
            ("powerOddMax", "count", Operator::power(3, Some(1000.into()))),
            ("stringConcat", "title", Operator::string_concat("!")),
            ("stringReplace", "title", Operator::string_replace("R", "r")),
            ("toggle", "active", Operator::toggle()),
            ("arrayAppend", "tags", Operator::array_append(["x", "y"])),
            ("arrayPrepend", "tags", Operator::array_prepend(["w"])),
            ("arrayInsert", "tags", Operator::array_insert(1, "i")),
            ("arrayRemove", "tags", Operator::array_remove("x")),
            ("arrayUnique", "tags", Operator::array_unique()),
            ("arrayIntersect", "tags", Operator::array_intersect(["w", "i", "y"])),
            ("arrayDiff", "tags", Operator::array_diff(["w"])),
            ("arrayFilter", "tags", Operator::array_filter("notEqual", "q")),
            ("arrayFilterNull", "tags", Operator::array_filter("isNotNull", Value::Null)),
            ("dateAddDays", "date", Operator::date_add_days(3)),
            ("dateSubDays", "date", Operator::date_sub_days(1)),
        ];
        for (label, attribute, operator) in ops {
            let current = adapter.get_document(&collection, "doc1b", vec![], false).await.unwrap();
            run.skip();
            let mut d = merged(&current, vec![(attribute, op(operator))]);
            if spatial {
                d.set("loc", list(vec![Value::Int(3), Value::Float(4.25)])).unwrap();
            }
            let r = adapter.update_document(&collection, "doc1b", d, true).await.map(one);
            run.record(&format!("op_{label}"), r);
        }
        let r = adapter.get_document(&collection, "doc1b", vec![], false).await.map(one);
        run.record("afterOps", r);

        let seqs: Vec<Value> = adapter
            .get_sequences(&collection, vec![doc(vec![("$id", s("doc2"))]), doc(vec![("$id", s("doc3"))])])
            .await
            .unwrap()
            .iter()
            .map(sequence)
            .collect();
        run.skip();
        let targets = || {
            vec![
                doc(vec![("$id", s("doc2")), ("$sequence", seqs[0].clone())]),
                doc(vec![("$id", s("doc3")), ("$sequence", seqs[1].clone())]),
            ]
        };
        let updates = doc(vec![
            ("note", s("bulk")),
            ("active", Value::Bool(true)),
            ("tags", strings(&["m"])),
            ("$updatedAt", s("2024-03-03 00:00:00.000")),
            ("$permissions", strings(&["read(\"any\")", "delete(\"any\")"])),
        ]);
        let r = adapter.update_documents(&collection, &updates, targets(), &[]).await.map(serde_json::Value::from);
        run.record("updateDocuments", r);
        let updates = doc(vec![
            ("count", op(Operator::increment(10, Some(50.into())))),
            ("$updatedAt", s("2024-03-04 00:00:00.000")),
        ]);
        let r = adapter.update_documents(&collection, &updates, targets(), &[]).await.map(serde_json::Value::from);
        run.record("updateDocumentsOperator", r);
        let r = adapter
            .increase_document_attribute(&collection, "doc2", "count", Value::Int(3), "2024-04-04 00:00:00.000", None, None)
            .await
            .map(serde_json::Value::from);
        run.record("increase", r);
        let r = adapter
            .increase_document_attribute(
                &collection,
                "doc2",
                "count",
                Value::Int(-1),
                "2024-04-05 00:00:00.000",
                Some(Value::Int(0)),
                Some(Value::Int(100)),
            )
            .await
            .map(serde_json::Value::from);
        run.record("increaseBounded", r);
        let r = adapter
            .increase_document_attribute(
                &collection,
                "doc2",
                "price",
                Value::Float(1.5),
                "2024-04-05 00:00:00.000",
                Some(Value::Int(1)),
                Some(Value::Int(-5)),
            )
            .await
            .map(serde_json::Value::from);
        run.record("increaseNegBounds", r);

        let r = Upserts::upsert_documents(
            &adapter,
            &collection,
            vec![
                Change::new(Document::empty(), upserted("doc5", vec![])),
                Change::new(doc(vec![("$id", s("doc2"))]), upserted("doc2", vec![("title", s("up2"))])),
            ],
            None,
        )
        .await
        .map(|d| documents_json(&d));
        run.record("upsert", r);
        let r = Upserts::upsert_documents(
            &adapter,
            &collection,
            vec![Change::new(doc(vec![("$id", s("doc5"))]), upserted("doc5", vec![("count", Value::Int(6))]))],
            Some("count"),
        )
        .await
        .map(|d| documents_json(&d));
        run.record("upsertIncrease", r);
        let with_ops = |id: &str| {
            upserted(
                id,
                vec![("count", op(Operator::increment(5, None))), ("tags", op(Operator::array_append(["v"])))],
            )
        };
        let r = Upserts::upsert_documents(
            &adapter,
            &collection,
            vec![
                Change::new(doc(vec![("$id", s("doc5"))]), with_ops("doc5")),
                Change::new(Document::empty(), with_ops("doc6")),
                Change::new(doc(vec![("$id", s("doc2"))]), upserted("doc2", vec![("price", op(Operator::multiply(3, None)))])),
                Change::new(Document::empty(), upserted("doc7", vec![])),
            ],
            None,
        )
        .await
        .map(|d| documents_json(&d));
        run.record("upsertOperators", r);
        let r = Upserts::upsert_document(
            &adapter,
            &collection,
            Change::new(Document::empty(), upserted("doc8", vec![("note", op(Operator::string_concat("!")))])),
        )
        .await
        .map(one);
        run.record("upsertOne", r);
        let mut after = Vec::new();
        for id in ["doc5", "doc6", "doc8"] {
            after.push(adapter.get_document(&collection, id, vec![], false).await.unwrap());
        }
        run.record("afterUpserts", Ok(documents_json(&after)));
        let r = adapter.delete_document(&collection, "doc4").await.map(serde_json::Value::from);
        run.record("deleteDocument", r);
        let r = adapter.delete_document(&collection, "doc4").await.map(serde_json::Value::from);
        run.record("deleteDocumentMissing", r);
        let seqs: Vec<Value> = adapter
            .get_sequences(&collection, vec![doc(vec![("$id", s("doc5"))]), doc(vec![("$id", s("doc6"))])])
            .await
            .unwrap()
            .iter()
            .map(sequence)
            .collect();
        run.skip();
        let r = adapter
            .delete_documents(&collection, seqs, vec!["doc5".to_owned(), "doc6".to_owned()])
            .await
            .map(serde_json::Value::from);
        run.record("deleteDocuments", r);
        let mut bindings = utopia_query::Array::new();
        let _ = bindings.push(utopia_query::Value::Str(Str::from("x")));
        let r = RawQuery::raw_query(&adapter, "SELECT 1 AS one, ? AS two", bindings).await.map(|d| documents_json(&d));
        run.record("rawQuery", r);
        let table = match engine {
            Engine::Postgres => format!("\"{db}\".\"ns_docs\""),
            Engine::Sqlite => "`ns_docs`".to_owned(),
            _ => format!("`{db}`.`ns_docs`"),
        };
        let mut bindings = utopia_query::Array::new();
        let _ = bindings.push(utopia_query::Value::Str(Str::from("doc7")));
        let r = RawQuery::raw_mutation(&adapter, &format!("DELETE FROM {table} WHERE _uid = ?"), bindings)
            .await
            .map(serde_json::Value::from);
        run.record("rawMutation", r);

        let expected = expected.as_array().unwrap();
        assert_eq!(run.steps.len(), expected.len());
        let statements: usize = run.steps.iter().map(|s| s["statements"].as_array().map_or(0, Vec::len)).sum();
        eprintln!("{label} shared={shared}: {} steps, {statements} statements compared", run.steps.len());
        assert!(statements > 50);
        let mut failures = Vec::new();
        for (actual, expected) in run.steps.iter().zip(expected) {
            for field in ["statements", "result", "error"] {
                if actual[field] != expected[field] {
                    failures.push(format!(
                        "{label} shared={shared} step {} {field}:\n  rust: {}\n  php:  {}",
                        expected["step"],
                        actual[field],
                        expected[field]
                    ));
                }
            }
        }
        assert!(failures.is_empty(), "{} mismatches:\n{}", failures.len(), failures.join("\n"));
    }

    #[cfg(feature = "sqlite")]
    #[tokio::test]
    async fn sqlite() {
        scenario(Engine::Sqlite, false).await;
    }

    #[cfg(feature = "sqlite")]
    #[tokio::test]
    async fn sqlite_shared() {
        scenario(Engine::Sqlite, true).await;
    }

    #[cfg(feature = "mariadb")]
    #[tokio::test]
    async fn mariadb() {
        scenario(Engine::MariaDb, false).await;
    }

    #[cfg(feature = "mariadb")]
    #[tokio::test]
    async fn mariadb_shared() {
        scenario(Engine::MariaDb, true).await;
    }

    #[cfg(feature = "mysql")]
    #[tokio::test]
    async fn mysql() {
        scenario(Engine::MySql, false).await;
    }

    #[cfg(feature = "mysql")]
    #[tokio::test]
    async fn mysql_shared() {
        scenario(Engine::MySql, true).await;
    }

    #[cfg(feature = "postgres")]
    #[tokio::test]
    async fn postgres() {
        scenario(Engine::Postgres, false).await;
    }

    #[cfg(feature = "postgres")]
    #[tokio::test]
    async fn postgres_shared() {
        scenario(Engine::Postgres, true).await;
    }
}

/// Fixtures of the ported unit tests: an adapter on a fresh database with
/// a profiler, tables made by hand in each engine's dialect.
mod support {
    use super::*;

    /// A column type, spelled in each engine's dialect.
    #[allow(dead_code)]
    #[derive(Clone, Copy)]
    pub enum Col {
        Str,
        Int,
        BigInt,
        UnsignedBigInt,
        Double,
        Bool,
        Json,
        Datetime,
        Point,
        Line,
        Polygon,
    }

    impl Col {
        fn sql(self, engine: Engine) -> &'static str {
            match (engine, self) {
                (Engine::Sqlite, Col::Str) => "VARCHAR(255)",
                (Engine::Sqlite, Col::Int | Col::BigInt | Col::UnsignedBigInt | Col::Bool) => "INTEGER",
                (Engine::Sqlite, Col::Double) => "REAL",
                (Engine::Sqlite, Col::Json | Col::Point | Col::Line | Col::Polygon) => "TEXT",
                (Engine::Sqlite, Col::Datetime) => "DATETIME(3)",
                (Engine::Postgres, Col::Str) => "VARCHAR(255)",
                (Engine::Postgres, Col::Int) => "INTEGER",
                (Engine::Postgres, Col::BigInt | Col::UnsignedBigInt) => "BIGINT",
                (Engine::Postgres, Col::Double) => "DOUBLE PRECISION",
                (Engine::Postgres, Col::Bool) => "BOOLEAN",
                (Engine::Postgres, Col::Json) => "JSONB",
                (Engine::Postgres, Col::Datetime) => "TIMESTAMP(3)",
                (Engine::Postgres, Col::Point) => "GEOMETRY(POINT,4326)",
                (Engine::Postgres, Col::Line) => "GEOMETRY(LINESTRING,4326)",
                (Engine::Postgres, Col::Polygon) => "GEOMETRY(POLYGON,4326)",
                (_, Col::Str) => "VARCHAR(255)",
                (_, Col::Int) => "INT",
                (_, Col::BigInt) => "BIGINT",
                (_, Col::UnsignedBigInt) => "BIGINT UNSIGNED",
                (_, Col::Double) => "DOUBLE",
                (_, Col::Bool) => "TINYINT(1)",
                (_, Col::Json) => "JSON",
                (_, Col::Datetime) => "DATETIME(3)",
                (Engine::MySql, Col::Point) => "POINT SRID 4326",
                (Engine::MySql, Col::Line) => "LINESTRING SRID 4326",
                (Engine::MySql, Col::Polygon) => "POLYGON SRID 4326",
                (_, Col::Point) => "POINT",
                (_, Col::Line) => "LINESTRING",
                (_, Col::Polygon) => "POLYGON",
            }
        }
    }

    pub struct Fx {
        pub adapter: Sql,
        pub profiler: Arc<Profiler>,
        pub engine: Engine,
        pub db: String,
    }

    /// An adapter of `engine` on a fresh database `db`, namespace `ns`.
    pub async fn fx(engine: Engine, db: &str, shared: bool) -> Option<Fx> {
        let adapter = connect(engine).await?;
        adapter.set_database(db);
        adapter.set_namespace("ns");
        adapter.set_shared_tables(shared);
        if shared {
            adapter.set_tenant(Value::Int(1));
        }
        if engine != Engine::Sqlite {
            let _ = adapter.delete(db).await;
            adapter.create(db).await.unwrap();
        }
        let profiler = Arc::new(Profiler::new());
        profiler.enable();
        adapter.set_profiler(Some(profiler.clone()));
        Some(Fx { adapter, profiler, engine, db: db.to_owned() })
    }

    impl Fx {
        /// The quoted table of collection `name`.
        pub fn table(&self, name: &str) -> String {
            match self.engine {
                Engine::Sqlite => format!("`ns_{name}`"),
                Engine::Postgres => format!("\"{}\".\"ns_{name}\"", self.db),
                _ => format!("`{}`.`ns_{name}`", self.db),
            }
        }

        fn q(&self, column: &str) -> String {
            match self.engine {
                Engine::Postgres => format!("\"{column}\""),
                _ => format!("`{column}`"),
            }
        }

        /// The tables of collection `name` with `columns`: the document key
        /// unique, the permissions table beside it.
        pub async fn create_table(&self, name: &str, columns: &[(&str, Col)]) {
            let shared = self.adapter.shared_tables();
            let (id, tenant) = match self.engine {
                Engine::Sqlite => ("INTEGER PRIMARY KEY AUTOINCREMENT", "INTEGER"),
                Engine::Postgres => ("BIGSERIAL PRIMARY KEY", "INTEGER"),
                _ => ("BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY", "INT"),
            };
            let datetime = Col::Datetime.sql(self.engine);
            let text = if self.engine == Engine::Postgres { "TEXT" } else { "MEDIUMTEXT" };
            let mut definitions = vec![
                format!("{} {id}", self.q("_id")),
                format!("{} VARCHAR(255) NOT NULL", self.q("_uid")),
                format!("{} {tenant} NULL", self.q("_tenant")),
                format!("{} {datetime} NULL", self.q("_createdAt")),
                format!("{} {datetime} NULL", self.q("_updatedAt")),
                format!("{} {text} NULL", self.q("_permissions")),
            ];
            for (column, kind) in columns {
                definitions.push(format!("{} {}", self.q(column), kind.sql(self.engine)));
            }
            let key = if shared {
                if self.engine == Engine::Postgres {
                    format!("{}, {}", self.q("_uid"), self.q("_tenant"))
                } else {
                    format!("{}, {}", self.q("_tenant"), self.q("_uid"))
                }
            } else {
                self.q("_uid")
            };
            let perms = self.table(&format!("{name}_perms"));
            let mut statements = vec![format!("CREATE TABLE {} ({})", self.table(name), definitions.join(", "))];
            statements.push(format!(
                "CREATE TABLE {perms} ({} {id}, {} {tenant} NULL, {} VARCHAR(12) NOT NULL, {} VARCHAR(255) NOT NULL, {} VARCHAR(255) NOT NULL)",
                self.q("_id"),
                self.q("_tenant"),
                self.q("_type"),
                self.q("_permission"),
                self.q("_document"),
            ));
            match self.engine {
                Engine::Sqlite => {
                    statements.push(format!("CREATE UNIQUE INDEX `ns_{name}_uid` ON `ns_{name}` ({key})"));
                    statements.push(format!(
                        "CREATE UNIQUE INDEX `ns_{name}_index1` ON `ns_{name}_perms` (`_document`, `_type`, `_permission`)"
                    ));
                }
                Engine::Postgres => {
                    statements.push(format!("CREATE UNIQUE INDEX \"ns_{name}_uid\" ON {} ({key})", self.table(name)));
                    statements.push(format!(
                        "CREATE UNIQUE INDEX \"ns_{name}_index1\" ON {perms} (\"_document\", \"_type\", \"_permission\")"
                    ));
                }
                _ => {
                    statements.push(format!("CREATE UNIQUE INDEX `_uid` ON {} ({key})", self.table(name)));
                    statements.push(format!("CREATE UNIQUE INDEX `_index1` ON {perms} (`_document`, `_type`, `_permission`)"));
                }
            }
            let mut pdo = self.adapter.pdo().lock().await;
            for statement in statements {
                pdo.exec(&statement).await.unwrap();
            }
        }

        /// The statements recorded since the last call, with their bindings.
        pub fn statements(&self) -> Vec<(String, Vec<Value>)> {
            let logs = self.profiler.logs();
            self.profiler.reset();
            logs.into_iter().map(|log| (log.query, log.bindings.values().cloned().collect())).collect()
        }

        /// Every bound value recorded since the last call.
        pub fn bound(&self) -> Vec<Value> {
            self.statements().into_iter().flat_map(|(_, b)| b).collect()
        }

        /// A column of every row of the table of `name`, by `_id`.
        pub async fn stored(&self, name: &str, column: &str) -> Vec<Value> {
            let sql = format!("SELECT {} FROM {} ORDER BY {}", self.q(column), self.table(name), self.q("_id"));
            let mut pdo = self.adapter.pdo().lock().await;
            let mut statement = pdo.query(&sql).await.unwrap();
            statement.fetch_all_column(0).unwrap()
        }

        /// A stored attribute of a document, as the adapter reads it.
        pub async fn attribute(&self, collection: &Collection, id: &str, key: &str) -> Value {
            let document = self.adapter.get_document(collection, id, vec![], false).await.unwrap();
            self.profiler.reset();
            document.as_array().get_str(key).cloned().unwrap_or(Value::Null)
        }
    }

    /// A collection definition of `attributes`.
    pub fn collection(name: &str, attributes: Vec<Attribute>) -> Collection {
        Collection::create(name, "", attributes, vec![], None, true, Array::new()).unwrap()
    }

    /// A document to write: no permissions, fixed dates.
    pub fn document(id: &str, pairs: Vec<(&str, Value)>) -> Document {
        let mut all = vec![
            ("$id", s(id)),
            ("$permissions", list(vec![])),
            ("$createdAt", s("2026-09-30 00:00:00.000")),
            ("$updatedAt", s("2026-09-30 00:00:00.000")),
        ];
        all.extend(pairs);
        doc(all)
    }

    /// A driver error as the engine reports it.
    pub fn engine_error(state: &str, code: i64, message: &str) -> utopia_database::Error {
        utopia_database::adapter::sql::pdo::PdoError::driver(state, code, message).into_error()
    }

    /// A JSON text value as its decoded value (`json_decode($v, true)`).
    pub fn decoded(value: &Value) -> serde_json::Value {
        match value {
            Value::Str(text) => serde_json::from_slice(text.as_bytes()).unwrap(),
            other => json(other),
        }
    }
}

#[allow(unused_imports)]
use support::{Col, collection, decoded, document, engine_error, fx};

/// PHP: `Tests\Unit\SQLiteInsertStatementTest` (without the permissions
/// hook, which the database layer provides).
mod sqlite_insert_statement {
    use super::*;

    #[tokio::test]
    async fn create_document_runs_one_insert_per_table_and_no_select() {
        for shared in [false, true] {
            let fx = fx(Engine::Sqlite, "insert_statement", shared).await.unwrap();
            fx.create_table("notes", &[("body", Col::Str)]).await;
            let notes = collection("notes", vec![Attribute::string("body", 64)]);
            fx.adapter.create_document(&notes, document("warmup", vec![("body", s("warmup"))])).await.unwrap();
            fx.profiler.reset();
            let created = fx.adapter.create_document(&notes, document("measured", vec![("body", s("measured"))])).await.unwrap();
            let statements: Vec<String> = fx.statements().into_iter().map(|(q, _)| q.trim_start().to_uppercase()).collect();
            assert!(statements.iter().all(|q| !q.starts_with("SELECT")));
            assert_eq!(statements.iter().filter(|q| q.starts_with("INSERT")).count(), 1);
            assert_eq!(created.get("$sequence"), Some(&s("2")));
            assert_eq!(fx.adapter.get_document(&notes, "measured", vec![], false).await.unwrap().id(), "measured");
        }
    }
}

/// PHP: `Tests\Unit\SQLOperatorBindingTest`.
mod sql_operator_binding {
    use super::*;

    #[tokio::test]
    async fn upsert_binds_operators_to_database_statement() {
        let fx = fx(Engine::Sqlite, "operators", false).await.unwrap();
        fx.create_table("scores", &[("value", Col::Int)]).await;
        let scores = collection("scores", vec![Attribute::integer("value")]);
        fx.adapter.create_document(&scores, document("first", vec![("value", Value::Int(1))])).await.unwrap();
        let old = fx.adapter.get_document(&scores, "first", vec![], false).await.unwrap();
        Upserts::upsert_documents(
            &fx.adapter,
            &scores,
            vec![Change::new(old, document("first", vec![("value", op(Operator::increment(2, None)))]))],
            None,
        )
        .await
        .unwrap();
        assert_eq!(fx.attribute(&scores, "first", "value").await, s("3"));
    }
}

/// PHP: `Tests\Unit\SQLiteForUpdateDocumentTest`.
mod sqlite_for_update_document {
    use super::*;

    #[tokio::test]
    async fn for_update_read_on_raw_sqlite_pdo_does_not_treat_column_indexes_as_attributes() {
        let fx = fx(Engine::Sqlite, "for_update", false).await.unwrap();
        fx.create_table("migrations", &[("status", Col::Str), ("stage", Col::Str)]).await;
        let migrations = collection("migrations", vec![Attribute::string("status", 255), Attribute::string("stage", 255)]);
        let active = fx
            .adapter
            .create_document(&migrations, document("migration", vec![("status", s("processing")), ("stage", s("processing"))]))
            .await
            .unwrap();
        let locked = fx.adapter.get_document(&migrations, "migration", vec![], true).await.unwrap();
        assert!(locked.as_array().get(&ArrayKey::Int(0)).is_none());
        assert!(locked.get("0").is_none());
        let newer = merged(&locked, vec![("stage", s("migrating"))]);
        let newer = fx.adapter.update_document(&migrations, "migration", newer, false).await.unwrap();
        assert_eq!(newer.get("stage"), Some(&s("migrating")));
        assert_eq!(newer.get("status"), Some(&s("processing")));
        assert_eq!(newer.updated_at(), active.updated_at());
        assert_eq!(fx.attribute(&migrations, "migration", "stage").await, s("migrating"));
    }
}

/// PHP: `Tests\Unit\SQLitePrepareTest` (the prepare refusal is a mock of
/// `\PDO`, not ported).
mod sqlite_prepare {
    use super::*;

    #[tokio::test]
    async fn wrapper_backed_collection_and_document_statements_execute() {
        let fx = fx(Engine::Sqlite, "prepare", false).await.unwrap();
        fx.create_table("movies", &[]).await;
        let movies = collection("movies", vec![]);
        let created =
            fx.adapter.create_document(&movies, doc(vec![("$id", s("movie")), ("$permissions", list(vec![]))])).await.unwrap();
        assert_eq!(created.id(), "movie");
        assert_eq!(fx.adapter.get_document(&movies, "movie", vec![], false).await.unwrap().id(), "movie");
    }
}

/// PHP: `Tests\Unit\SQLiteOperatorBehaviorTest`.
mod sqlite_operator_behavior {
    use super::*;

    async fn applied(id: &str, start: f64, operator: Operator) -> f64 {
        let fx = fx(Engine::Sqlite, "operators", false).await.unwrap();
        fx.create_table("operators", &[("value", Col::Double)]).await;
        let operators = collection("operators", vec![Attribute::double("value")]);
        fx.adapter.create_document(&operators, document(id, vec![("value", Value::Float(start))])).await.unwrap();
        fx.adapter.update_document(&operators, id, doc(vec![("$id", s(id)), ("value", op(operator))]), true).await.unwrap();
        fx.attribute(&operators, id, "value").await.to_double()
    }

    #[tokio::test]
    async fn inclusive_increment_bound_applies_exact_maximum() {
        assert_eq!(applied("increment", 5.0, Operator::increment(5, Some(10.into()))).await, 10.0);
    }

    #[tokio::test]
    async fn power_above_maximum_leaves_value_unchanged() {
        assert_eq!(applied("power", 5.0, Operator::power(3, Some(100.into()))).await, 5.0);
    }

    #[tokio::test]
    async fn fractional_power_below_maximum_is_applied() {
        assert_eq!(applied("root", 100.0, Operator::power(0.5, Some(50.into()))).await, 10.0);
    }
}

/// PHP: `Tests\Unit\SQLGetDocumentTest` (the joined reads are the find
/// port's; PostgreSQL's session statements are not observable here).
mod sql_get_document {
    use super::*;

    struct Recorder(std::sync::Mutex<Vec<(utopia_database::Event, String)>>);

    impl utopia_database::hook::Transform for Recorder {
        fn transform(&self, event: utopia_database::Event, query: String) -> utopia_database::Result<String> {
            self.0.lock().unwrap().push((event, query.clone()));
            Ok(format!("{query} /* transformed */"))
        }
    }

    #[tokio::test]
    async fn applies_typed_read_transform_on_fast_and_builder_paths() {
        let fx = fx(Engine::Sqlite, "transform", false).await.unwrap();
        fx.create_table("collection", &[("title", Col::Str)]).await;
        let recorder = Arc::new(Recorder(std::sync::Mutex::new(Vec::new())));
        fx.adapter.add_transform("test", recorder.clone());
        let c = collection("collection", vec![Attribute::string("title", 64)]);
        fx.adapter.get_document(&c, "fast", vec![], false).await.unwrap();
        fx.adapter.get_document(&c, "builder", vec![Query::select(["title"])], false).await.unwrap();
        let recorded = recorder.0.lock().unwrap().clone();
        assert_eq!(recorded.iter().map(|r| r.0).collect::<Vec<_>>(), [utopia_database::Event::DocumentRead; 2]);
        let executed: Vec<String> = fx.statements().into_iter().map(|(q, _)| q).collect();
        assert!(executed.iter().all(|q| q.ends_with("/* transformed */")));
        assert_eq!(executed.len(), 2);
    }

    async fn assert_timeout(db: &str, queries: Vec<Query>) {
        let Some(fx) = fx(Engine::MySql, db, false).await else { return };
        fx.create_table("collection", &[("title", Col::Str)]).await;
        let c = collection("collection", vec![Attribute::string("title", 64)]);
        fx.adapter.pdo().lock().await.inject(utopia_database::adapter::sql::pdo::Fault::Next(engine_error(
            "HY000",
            3024,
            "Query execution was interrupted",
        )));
        let error = fx.adapter.get_document(&c, "document", queries, false).await.unwrap_err();
        assert_eq!(error.kind(), utopia_database::Kind::Timeout);
        assert_eq!(error.previous().map(|p| p.kind()), Some(utopia_database::Kind::Pdo));
    }

    #[tokio::test]
    async fn translates_execute_timeout() {
        assert_timeout("bdocs_get_timeout_fast", vec![]).await;
    }

    #[tokio::test]
    async fn translates_builder_timeout() {
        assert_timeout("bdocs_get_timeout_builder", vec![Query::select(["title"])]).await;
    }
}

/// PHP: `Tests\Unit\Adapter\LockedDocumentReadTest`, against the servers.
mod locked_document_read {
    use super::*;

    async fn books(engine: Engine, db: &str, shared: bool, tenant: Value) -> Option<support::Fx> {
        let fx = fx(engine, db, shared).await?;
        fx.create_table("books", &[("title", Col::Str)]).await;
        fx.create_table("_metadata", &[("title", Col::Str)]).await;
        fx.adapter.set_tenant(tenant);
        let books = collection("books", vec![Attribute::string("title", 64)]);
        fx.adapter.create_document(&books, document("dune", vec![("title", s("Dune"))])).await.unwrap();
        fx.profiler.reset();
        Some(fx)
    }

    #[tokio::test]
    async fn a_locked_read_selects_the_document_for_update() {
        for (engine, db, select) in [
            (Engine::MariaDb, "bdocs_locked_m", "SELECT * FROM `bdocs_locked_m`.`ns_books` AS `table_main` WHERE `_uid` = :_uid"),
            (
                Engine::Postgres,
                "bdocs_locked_p",
                "SELECT * FROM \"bdocs_locked_p\".\"ns_books\" AS \"table_main\" WHERE \"_uid\" = :_uid",
            ),
        ] {
            let Some(fx) = books(engine, db, false, Value::Null).await else { continue };
            let c = collection("books", vec![]);
            let document = fx.adapter.get_document(&c, "dune", vec![], true).await.unwrap();
            let statements = fx.statements();
            assert_eq!(statements.len(), 1);
            assert_eq!(statements[0].0, format!("{select} FOR UPDATE"));
            assert_eq!(statements[0].1, vec![s("dune")]);
            assert_eq!(document.id(), "dune");
            assert_eq!(document.get("$sequence"), Some(&s("1")));
            assert_eq!(document.get("title"), Some(&s("Dune")));
            fx.adapter.get_document(&c, "dune", vec![], false).await.unwrap();
            assert_eq!(fx.statements()[0].0, select);
        }
    }

    #[tokio::test]
    async fn a_shared_table_read_keeps_the_tenant_filter() {
        let cases = [
            (
                Engine::MariaDb,
                "books",
                Value::Int(3),
                "SELECT * FROM `bdocs_locked_ms`.`ns_books` AS `table_main` WHERE `_uid` = :_uid AND `table_main`._tenant IN (:_tenant)",
            ),
            (
                Engine::MariaDb,
                "_metadata",
                Value::Int(3),
                "SELECT * FROM `bdocs_locked_ms`.`ns__metadata` AS `table_main` WHERE `_uid` = :_uid AND (`table_main`._tenant IN (:_tenant) OR `table_main`._tenant IS NULL)",
            ),
            (
                Engine::MariaDb,
                "books",
                Value::Null,
                "SELECT * FROM `bdocs_locked_ms`.`ns_books` AS `table_main` WHERE `_uid` = :_uid AND `table_main`._tenant IN (:_tenant)",
            ),
            (
                Engine::Postgres,
                "books",
                Value::Int(3),
                "SELECT * FROM \"bdocs_locked_ps\".\"ns_books\" AS \"table_main\" WHERE \"_uid\" = :_uid AND \"table_main\"._tenant IN (:_tenant)",
            ),
            (
                Engine::Postgres,
                "_metadata",
                Value::Int(3),
                "SELECT * FROM \"bdocs_locked_ps\".\"ns__metadata\" AS \"table_main\" WHERE \"_uid\" = :_uid AND (\"table_main\"._tenant IN (:_tenant) OR \"table_main\"._tenant IS NULL)",
            ),
        ];
        for (engine, name, tenant, select) in cases {
            let db = if engine == Engine::MariaDb { "bdocs_locked_ms" } else { "bdocs_locked_ps" };
            let Some(fx) = books(engine, db, true, tenant.clone()).await else { continue };
            let c = collection(name, vec![]);
            fx.adapter.get_document(&c, "dune", vec![], true).await.unwrap();
            fx.adapter.get_document(&c, "dune", vec![], false).await.unwrap();
            let statements = fx.statements();
            assert_eq!(statements.iter().map(|s| s.0.clone()).collect::<Vec<_>>(), [format!("{select} FOR UPDATE"), select.to_owned()]);
            for statement in &statements {
                assert_eq!(statement.1, vec![s("dune"), tenant.clone()]);
            }
        }
    }
}

/// Upserts `pairs` onto stored document `id` (the stored version as the
/// old one), and onto a document `created` nothing stores yet.
async fn upsert_both(fx: &support::Fx, c: &Collection, id: &str, pairs: impl Fn() -> Vec<(&'static str, Value)>) {
    let old = fx.adapter.get_document(c, id, vec![], false).await.unwrap();
    Upserts::upsert_documents(
        &fx.adapter,
        c,
        vec![Change::new(old, document(id, pairs())), Change::new(Document::empty(), document("created", pairs()))],
        None,
    )
    .await
    .unwrap();
}

/// Upserts `pairs` onto a document nothing stores yet.
async fn upsert_new(fx: &support::Fx, c: &Collection, id: &str, pairs: Vec<(&str, Value)>) -> utopia_database::Result<Document> {
    Upserts::upsert_document(&fx.adapter, c, Change::new(Document::empty(), document(id, pairs))).await
}

/// PHP: `Tests\Unit\Adapter\UpsertNewDocumentParityTest`, at the adapter:
/// a new document gets what a stored one with the attribute's default
/// gets.
mod upsert_new_document_parity {
    use super::*;

    fn items() -> Collection {
        collection(
            "items",
            vec![
                Attribute::integer("count").default(7),
                Attribute::integer("numbers").array(true).default(list(vec![1, 2, 2, 3, 5].into_iter().map(Value::Int).collect())),
                Attribute::string("words", 16).array(true).default(strings(&["a", "b", "b"])),
                Attribute::integer("empty").array(true),
            ],
        )
    }

    #[tokio::test]
    async fn a_new_document_gets_what_an_existing_document_with_the_same_value_gets() {
        let cases: Vec<(&str, Operator, serde_json::Value)> = vec![
            ("count", Operator::increment(3, Some(10.into())), serde_json::json!(10)),
            ("count", Operator::increment(5, Some(10.into())), serde_json::json!(7)),
            ("count", Operator::decrement(5, Some(5.into())), serde_json::json!(7)),
            ("count", Operator::multiply(3, Some(20.into())), serde_json::json!(7)),
            ("count", Operator::divide(2, Some(5.into())).unwrap(), serde_json::json!(7)),
            ("count", Operator::power(2, Some(40.into())), serde_json::json!(7)),
            ("count", Operator::power(2, Some(49.into())), serde_json::json!(49)),
            ("numbers", Operator::array_filter("greaterThan", 2), serde_json::json!([3, 5])),
            ("numbers", Operator::array_filter("greaterThanEqual", 3), serde_json::json!([3, 5])),
            ("numbers", Operator::array_filter("lessThan", 3), serde_json::json!([1, 2, 2])),
            ("numbers", Operator::array_filter("lessThanEqual", 2), serde_json::json!([1, 2, 2])),
            ("numbers", Operator::array_filter("equal", 2), serde_json::json!([2, 2])),
            ("numbers", Operator::array_filter("notEqual", 2), serde_json::json!([1, 3, 5])),
            ("numbers", Operator::array_filter("isNull", Value::Null), serde_json::json!([])),
            ("numbers", Operator::array_filter("isNotNull", Value::Null), serde_json::json!([1, 2, 2, 3, 5])),
            ("words", Operator::array_filter("equal", "b"), serde_json::json!(["b", "b"])),
            ("empty", Operator::array_filter("greaterThan", 0), serde_json::json!([])),
        ];
        for (attribute, operator, expected) in cases {
            let fx = fx(Engine::Sqlite, "upsert_parity", false).await.unwrap();
            fx.create_table("items", &[("count", Col::Int), ("numbers", Col::Json), ("words", Col::Json), ("empty", Col::Json)])
                .await;
            let c = items();
            fx.adapter
                .create_document(
                    &c,
                    document(
                        "existing",
                        vec![
                            ("count", Value::Int(7)),
                            ("numbers", list(vec![1, 2, 2, 3, 5].into_iter().map(Value::Int).collect())),
                            ("words", strings(&["a", "b", "b"])),
                        ],
                    ),
                )
                .await
                .unwrap();
            upsert_both(&fx, &c, "existing", || vec![(attribute, op(operator.clone()))]).await;
            let existing = decoded(&fx.attribute(&c, "existing", attribute).await);
            let created = decoded(&fx.attribute(&c, "created", attribute).await);
            assert_eq!(existing, expected, "{attribute} {:?} existing", operator.method());
            assert_eq!(created, expected, "{attribute} {:?} new", operator.method());
        }
    }
}

/// PHP: `Tests\Unit\Adapter\UpsertNewDocumentOperatorTest`, at the adapter
/// (values as stored, before the database layer casts them).
mod upsert_new_document_operator {
    use super::*;

    const MAX: i64 = i64::MAX;
    const MIN: i64 = i64::MIN;

    fn tasks() -> Collection {
        collection(
            "tasks",
            vec![
                Attribute::datetime("due").default(s("2026-01-31T12:30:00.000+00:00")),
                Attribute::datetime("reminder"),
                Attribute::boolean("active").default(true),
                Attribute::boolean("archived"),
                Attribute::big_integer("counter").default(MAX - 5),
                Attribute::big_integer("floor").default(MIN + 5),
            ],
        )
    }

    async fn tasks_fx() -> support::Fx {
        let fx = fx(Engine::Sqlite, "upsert_operators", false).await.unwrap();
        fx.create_table(
            "tasks",
            &[
                ("due", Col::Datetime),
                ("reminder", Col::Datetime),
                ("active", Col::Bool),
                ("archived", Col::Bool),
                ("counter", Col::BigInt),
                ("floor", Col::BigInt),
            ],
        )
        .await;
        fx
    }

    #[tokio::test]
    async fn an_operator_on_a_new_document_applies_to_the_default() {
        let cases: Vec<(&str, Operator, Value)> = vec![
            ("due", Operator::date_add_days(1), s("2026-02-01 12:30:00.000")),
            ("due", Operator::date_sub_days(31), s("2025-12-31 12:30:00.000")),
            ("due", Operator::date_add_days(0), s("2026-01-31 12:30:00.000")),
            ("reminder", Operator::date_add_days(3), Value::Null),
            ("reminder", Operator::date_sub_days(3), Value::Null),
            ("active", Operator::toggle(), s("0")),
            ("archived", Operator::toggle(), s("1")),
            ("counter", Operator::increment(5, Some(MAX.into())), s(&MAX.to_string())),
            ("counter", Operator::increment(10, Some(MAX.into())), s(&(MAX - 5).to_string())),
            ("counter", Operator::multiply(2, Some(MAX.into())), s(&(MAX - 5).to_string())),
            ("floor", Operator::decrement(10, Some(MIN.into())), s(&(MIN + 5).to_string())),
        ];
        for (attribute, operator, expected) in cases {
            let fx = tasks_fx().await;
            let c = tasks();
            upsert_new(&fx, &c, "created", vec![(attribute, op(operator.clone()))]).await.unwrap();
            assert_eq!(fx.attribute(&c, "created", attribute).await, expected, "{attribute} {:?}", operator.method());
        }
    }

    #[tokio::test]
    async fn days_added_to_a_new_document_match_the_days_added_to_an_existing_one() {
        let fx = tasks_fx().await;
        let c = tasks();
        fx.adapter.create_document(&c, document("existing", vec![("due", s("2026-01-31 12:30:00.000"))])).await.unwrap();
        upsert_both(&fx, &c, "existing", || vec![("due", op(Operator::date_add_days(2)))]).await;
        for id in ["existing", "created"] {
            let due = fx.attribute(&c, id, "due").await;
            assert_eq!(
                utopia_database::datetime::set_timezone(due.to_php_string().unwrap().as_bytes()).unwrap(),
                "2026-02-02 12:30:00.000"
            );
        }
    }

    #[tokio::test]
    async fn an_operator_only_a_later_document_of_the_batch_carries_is_applied() {
        let fx = tasks_fx().await;
        let c = tasks();
        Upserts::upsert_documents(
            &fx.adapter,
            &c,
            vec![
                Change::new(Document::empty(), document("plain", vec![("archived", Value::Bool(true))])),
                Change::new(Document::empty(), document("toggled", vec![("active", op(Operator::toggle()))])),
            ],
            None,
        )
        .await
        .unwrap();
        assert_eq!(fx.attribute(&c, "plain", "archived").await, s("1"));
        assert_eq!(fx.attribute(&c, "toggled", "active").await, s("0"));
    }

    #[tokio::test]
    async fn an_empty_batch_writes_nothing() {
        let fx = tasks_fx().await;
        let c = tasks();
        assert!(fx.adapter.create_documents(&c, vec![]).await.unwrap().is_empty());
        assert!(Upserts::upsert_documents(&fx.adapter, &c, vec![], None).await.unwrap().is_empty());
        assert!(fx.statements().is_empty());
        assert!(fx.stored("tasks", "_uid").await.is_empty());
    }

    #[tokio::test]
    async fn an_upsert_that_breaks_a_unique_index_is_a_unique_violation() {
        let fx = fx(Engine::Sqlite, "upsert_operators", false).await.unwrap();
        fx.create_table("accounts", &[("email", Col::Str)]).await;
        fx.adapter.pdo().lock().await.exec("CREATE UNIQUE INDEX `unique_email` ON `ns_accounts` (`email`)").await.unwrap();
        let c = collection("accounts", vec![Attribute::string("email", 64)]);
        fx.adapter.create_document(&c, document("first", vec![("email", s("shared@example.com"))])).await.unwrap();
        let error = upsert_new(&fx, &c, "second", vec![("email", s("shared@example.com"))]).await.unwrap_err();
        assert_eq!(error.kind(), utopia_database::Kind::Unique);
        assert_eq!(error.previous().map(|p| p.kind()), Some(utopia_database::Kind::Pdo));
        assert!(fx.adapter.get_document(&c, "second", vec![], false).await.unwrap().is_empty());
    }

    #[tokio::test]
    async fn now_set_on_a_new_document_is_the_time_of_the_write() {
        let fx = tasks_fx().await;
        let c = tasks();
        let before = utopia_database::datetime::now();
        upsert_new(&fx, &c, "created", vec![("reminder", op(Operator::date_set_now()))]).await.unwrap();
        let after = utopia_database::datetime::now();
        let reminder = fx.attribute(&c, "created", "reminder").await;
        let reminder = utopia_database::value::to_text(&reminder);
        assert!(reminder >= before[..19].to_owned() && reminder <= after, "{before} <= {reminder} <= {after}");
    }
}

/// PHP: `Tests\Unit\Adapter\UpsertDocumentTest` (the pool cases are the
/// pool's).
mod upsert_document {
    use super::*;

    async fn counters() -> (support::Fx, Collection) {
        let fx = fx(Engine::Sqlite, "upserts", false).await.unwrap();
        fx.create_table("counters", &[("title", Col::Str), ("views", Col::Int)]).await;
        (fx, collection("counters", vec![Attribute::string("title", 64), Attribute::integer("views")]))
    }

    fn counter(id: &str, title: &str, views: i64) -> Document {
        let now = utopia_database::datetime::now();
        doc(vec![
            ("$id", s(id)),
            ("$permissions", list(vec![])),
            ("$createdAt", s(&now)),
            ("$updatedAt", s(&now)),
            ("title", s(title)),
            ("views", Value::Int(views)),
        ])
    }

    async fn stored(fx: &support::Fx, c: &Collection, id: &str) -> (Value, Value) {
        (fx.attribute(c, id, "title").await, fx.attribute(c, id, "views").await)
    }

    #[tokio::test]
    async fn upsert_document_inserts_a_document_nothing_stored_yet() {
        let (fx, c) = counters().await;
        let written = Upserts::upsert_document(&fx.adapter, &c, Change::new(Document::empty(), counter("one", "first", 1))).await.unwrap();
        assert_eq!(written.id(), "one");
        assert_eq!(stored(&fx, &c, "one").await, (s("first"), s("1")));
    }

    #[tokio::test]
    async fn upsert_document_replaces_the_stored_document() {
        let (fx, c) = counters().await;
        Upserts::upsert_document(&fx.adapter, &c, Change::new(Document::empty(), counter("one", "first", 1))).await.unwrap();
        let old = fx.adapter.get_document(&c, "one", vec![], false).await.unwrap();
        let written = Upserts::upsert_document(&fx.adapter, &c, Change::new(old, counter("one", "second", 5))).await.unwrap();
        assert_eq!(written.get("title"), Some(&s("second")));
        assert_eq!(stored(&fx, &c, "one").await, (s("second"), s("5")));
        assert_eq!(fx.stored("counters", "_uid").await.len(), 1);
    }

    #[tokio::test]
    async fn upsert_documents_writes_every_change_in_order() {
        let (fx, c) = counters().await;
        let written = Upserts::upsert_documents(
            &fx.adapter,
            &c,
            vec![
                Change::new(Document::empty(), counter("one", "first", 1)),
                Change::new(Document::empty(), counter("two", "second", 2)),
            ],
            None,
        )
        .await
        .unwrap();
        assert_eq!(written.iter().map(|d| d.id().to_owned()).collect::<Vec<_>>(), ["one", "two"]);
        assert_eq!(stored(&fx, &c, "one").await, (s("first"), s("1")));
        assert_eq!(stored(&fx, &c, "two").await, (s("second"), s("2")));
        assert!(Upserts::upsert_documents(&fx.adapter, &c, vec![], None).await.unwrap().is_empty());
    }

    #[tokio::test]
    async fn an_increase_adds_the_value_to_the_stored_one() {
        let (fx, c) = counters().await;
        for views in [2, 3] {
            Upserts::upsert_documents(&fx.adapter, &c, vec![Change::new(Document::empty(), counter("one", "first", views))], Some("views"))
                .await
                .unwrap();
        }
        assert_eq!(stored(&fx, &c, "one").await, (s("first"), s("5")));
    }

    #[tokio::test]
    async fn without_an_increase_the_value_is_replaced() {
        let (fx, c) = counters().await;
        for views in [2, 3] {
            Upserts::upsert_documents(&fx.adapter, &c, vec![Change::new(Document::empty(), counter("one", "first", views))], None)
                .await
                .unwrap();
        }
        assert_eq!(stored(&fx, &c, "one").await, (s("first"), s("3")));
    }
}

/// PHP: `Tests\Unit\Adapter\SQLiteUpsertArrayOperatorLimitTest`.
mod sqlite_upsert_array_operator_limit {
    use super::*;

    async fn lists() -> (support::Fx, Collection) {
        let fx = fx(Engine::Sqlite, "array_limit", false).await.unwrap();
        fx.create_table("lists", &[("numbers", Col::Json)]).await;
        let c = collection("lists", vec![Attribute::integer("numbers").array(true)]);
        fx.adapter
            .create_document(&c, document("first", vec![("numbers", list(vec![Value::Int(1), Value::Int(2), Value::Int(3)]))]))
            .await
            .unwrap();
        (fx, c)
    }

    async fn upsert(fx: &support::Fx, c: &Collection, operator: Operator) -> utopia_database::Result<Vec<Document>> {
        let existing = fx.adapter.get_document(c, "first", vec![], false).await.unwrap();
        Upserts::upsert_documents(&fx.adapter, c, vec![Change::new(existing, document("first", vec![("numbers", op(operator))]))], None)
            .await
    }

    #[tokio::test]
    async fn an_oversized_array_operand_is_refused_and_leaves_the_array() {
        let size = utopia_database::operator::MAX_ARRAY_OPERATOR_SIZE + 1;
        let operand = || (1..=size as i64).map(Value::Int).collect::<Vec<_>>();
        let operators: Vec<Operator> = vec![
            Operator::array_append(operand()),
            Operator::array_prepend(operand()),
            Operator::array_intersect(operand()),
            Operator::array_diff(operand()),
        ];
        for operator in operators {
            let (fx, c) = lists().await;
            let error = upsert(&fx, &c, operator).await.unwrap_err();
            assert_eq!(
                error.message(),
                format!(
                    "Array size {size} exceeds maximum allowed size of {} for array operations",
                    utopia_database::operator::MAX_ARRAY_OPERATOR_SIZE
                )
            );
            assert!(error.is_a(utopia_database::Kind::Database));
            assert_eq!(decoded(&fx.attribute(&c, "first", "numbers").await), serde_json::json!([1, 2, 3]));
        }
    }

    #[tokio::test]
    async fn an_array_operand_at_the_limit_is_applied() {
        let (fx, c) = lists().await;
        let nines = vec![Value::Int(9); utopia_database::operator::MAX_ARRAY_OPERATOR_SIZE];
        upsert(&fx, &c, Operator::array_append(nines)).await.unwrap();
        let numbers = decoded(&fx.attribute(&c, "first", "numbers").await);
        assert_eq!(numbers.as_array().unwrap().len(), utopia_database::operator::MAX_ARRAY_OPERATOR_SIZE + 3);
    }
}

/// PHP: `Tests\Unit\Adapter\SQLiteUpsertIntegerOverflowTest`.
mod sqlite_upsert_integer_overflow {
    use super::*;

    async fn counters() -> (support::Fx, Collection) {
        let fx = fx(Engine::Sqlite, "upsert_overflow", false).await.unwrap();
        fx.create_table("counters", &[("high", Col::BigInt), ("low", Col::BigInt), ("digits", Col::Str)]).await;
        let c = collection(
            "counters",
            vec![
                Attribute::big_integer("high").default(i64::MAX - 5),
                Attribute::big_integer("low").default(i64::MIN + 5),
                Attribute::string("digits", 64).default(s("9223372036854775807")),
            ],
        );
        (fx, c)
    }

    #[tokio::test]
    async fn an_operator_that_overflows_a_new_document_is_refused() {
        for (attribute, operator) in [
            ("high", Operator::increment(10, None)),
            ("high", Operator::multiply(2, None)),
            ("low", Operator::decrement(10, None)),
        ] {
            let (fx, c) = counters().await;
            let error = upsert_new(&fx, &c, "created", vec![(attribute, op(operator))]).await.unwrap_err();
            assert_eq!(error.kind(), utopia_database::Kind::Limit);
            assert_eq!(error.message(), "Value out of range");
            assert!(fx.adapter.get_document(&c, "created", vec![], false).await.unwrap().is_empty());
        }
    }

    #[tokio::test]
    async fn an_operator_within_the_range_is_stored() {
        let (fx, c) = counters().await;
        upsert_new(&fx, &c, "created", vec![("high", op(Operator::increment(5, None)))]).await.unwrap();
        assert_eq!(fx.attribute(&c, "created", "high").await, s(&i64::MAX.to_string()));
    }

    #[tokio::test]
    async fn a_string_that_spells_a_large_number_is_not_refused() {
        let (fx, c) = counters().await;
        upsert_new(&fx, &c, "created", vec![("digits", op(Operator::string_concat("0")))]).await.unwrap();
        assert_eq!(fx.attribute(&c, "created", "digits").await, s("92233720368547758070"));
    }
}

/// PHP: `Tests\Unit\Adapter\SQLiteUpsertArrayRemoveTest`.
mod sqlite_upsert_array_remove {
    use super::*;

    #[tokio::test]
    async fn an_upsert_of_an_existing_document_removes_the_element_as_an_update_does() {
        let ints = |v: &[i64]| list(v.iter().copied().map(Value::Int).collect());
        let floats = |v: &[f64]| list(v.iter().copied().map(Value::Float).collect());
        let cases: Vec<(&str, Value, Value, serde_json::Value)> = vec![
            ("numbers", ints(&[1, 2, 2, 3]), Value::Int(2), serde_json::json!([1, 3])),
            ("ratios", floats(&[0.5, 1.5, 0.5]), Value::Float(0.5), serde_json::json!([1.5])),
            ("words", strings(&["a", "b", "b"]), s("b"), serde_json::json!(["a"])),
            ("words", strings(&["1", "2", "2"]), s("2"), serde_json::json!(["1"])),
        ];
        for (attribute, stored, removed, expected) in cases {
            let fx = fx(Engine::Sqlite, "upsert_array_remove", false).await.unwrap();
            fx.create_table("items", &[("numbers", Col::Json), ("ratios", Col::Json), ("words", Col::Json)]).await;
            let c = collection(
                "items",
                vec![
                    Attribute::integer("numbers").array(true),
                    Attribute::float("ratios").array(true),
                    Attribute::string("words", 16).array(true),
                ],
            );
            for id in ["upserted", "updated"] {
                fx.adapter.create_document(&c, document(id, vec![(attribute, stored.clone())])).await.unwrap();
            }
            let old = fx.adapter.get_document(&c, "upserted", vec![], false).await.unwrap();
            Upserts::upsert_document(
                &fx.adapter,
                &c,
                Change::new(old, document("upserted", vec![(attribute, op(Operator::array_remove(removed.clone())))])),
            )
            .await
            .unwrap();
            let current = fx.adapter.get_document(&c, "updated", vec![], false).await.unwrap();
            let update = merged(&current, vec![(attribute, op(Operator::array_remove(removed.clone())))]);
            fx.adapter.update_document(&c, "updated", update, true).await.unwrap();
            assert_eq!(decoded(&fx.attribute(&c, "updated", attribute).await), expected, "{attribute} updated");
            assert_eq!(decoded(&fx.attribute(&c, "upserted", attribute).await), expected, "{attribute} upserted");
        }
    }
}

/// PHP: `Tests\Unit\Adapter\SQLitePowerOperatorTest`.
mod sqlite_power_operator {
    use super::*;

    async fn scores() -> (support::Fx, Collection) {
        let fx = fx(Engine::Sqlite, "power", false).await.unwrap();
        fx.create_table("scores", &[("value", Col::Int)]).await;
        let c = collection("scores", vec![Attribute::integer("value")]);
        fx.adapter.create_document(&c, document("first", vec![("value", Value::Int(3))])).await.unwrap();
        (fx, c)
    }

    #[tokio::test]
    async fn power_with_a_non_numeric_exponent_is_refused_and_leaves_the_value() {
        for exponent in [s("two"), Value::Bool(true), list(vec![Value::Int(2)])] {
            let (fx, c) = scores().await;
            let first = fx.adapter.get_document(&c, "first", vec![], false).await.unwrap();
            let updates = doc(vec![("value", op(Operator::new(utopia_database::operator::OperatorType::Power, "value", vec![exponent])))]);
            let error = fx.adapter.update_documents(&c, &updates, vec![first], &[]).await.unwrap_err();
            assert_eq!(error.kind(), utopia_database::Kind::Operator);
            assert_eq!(error.message(), "Power exponent must be numeric");
            assert_eq!(fx.attribute(&c, "first", "value").await, s("3"));
        }
    }

    #[tokio::test]
    async fn power_with_a_numeric_exponent_raises_the_value() {
        let (fx, c) = scores().await;
        let current = fx.adapter.get_document(&c, "first", vec![], false).await.unwrap();
        fx.adapter.update_document(&c, "first", merged(&current, vec![("value", op(Operator::power(2, None)))]), true).await.unwrap();
        assert_eq!(fx.attribute(&c, "first", "value").await, s("9"));
    }
}

/// PHP: `Tests\Unit\Adapter\PowerNumericTextTest` (the operator's own
/// cases, Memory and Redis are the operator's and those adapters' ports).
mod power_numeric_text {
    use super::*;

    #[tokio::test]
    async fn a_numeric_text_exponent_is_applied_and_non_numeric_text_refused() {
        let fx = fx(Engine::Sqlite, "power_text", false).await.unwrap();
        fx.create_table("items", &[("count", Col::Int), ("ratio", Col::Double)]).await;
        let c = collection("items", vec![Attribute::integer("count").default(4), Attribute::float("ratio")]);
        fx.adapter
            .create_document(&c, document("first", vec![("count", Value::Int(3)), ("ratio", Value::Float(1.5))]))
            .await
            .unwrap();
        let update = |pairs: Vec<(&'static str, Value)>| {
            let fx = &fx;
            let c = &c;
            async move {
                let current = fx.adapter.get_document(c, "first", vec![], false).await.unwrap();
                fx.adapter.update_document(c, "first", merged(&current, pairs), true).await
            }
        };
        update(vec![("count", op(Operator::power("2", None))), ("ratio", op(Operator::power("2", None)))]).await.unwrap();
        assert_eq!(fx.attribute(&c, "first", "count").await, s("9"));
        assert_eq!(fx.attribute(&c, "first", "ratio").await.to_double(), 2.25);
        update(vec![("count", op(Operator::power("2", Some("50".into()))))]).await.unwrap();
        assert_eq!(fx.attribute(&c, "first", "count").await, s("9"));
        upsert_new(&fx, &c, "created", vec![("count", op(Operator::power("2", None)))]).await.unwrap();
        assert_eq!(fx.attribute(&c, "created", "count").await, s("16"));
        let error = update(vec![("count", op(Operator::power("two", None)))]).await.unwrap_err();
        assert_eq!(error.message(), "Power exponent must be numeric");
        assert_eq!(fx.attribute(&c, "first", "count").await, s("9"));
    }

    #[tokio::test]
    async fn a_numeric_text_exponent_is_sent_as_a_number() {
        for (engine, db) in [(Engine::MariaDb, "bdocs_power_text_m"), (Engine::MySql, "bdocs_power_text_y"), (Engine::Postgres, "bdocs_power_text_p")] {
            let Some(fx) = fx(engine, db, false).await else { continue };
            fx.create_table("items", &[("count", Col::Int)]).await;
            let c = collection("items", vec![Attribute::integer("count")]);
            fx.adapter
                .update_documents(&c, &doc(vec![("count", op(Operator::power("2", None)))]), vec![doc(vec![("$id", s("first")), ("$sequence", s("1"))])], &[])
                .await
                .unwrap();
            let statements = fx.statements();
            assert_eq!(statements.len(), 1);
            assert!(statements[0].0.contains("POWER("));
            assert!(statements[0].1.contains(&Value::Int(2)));
            assert!(!statements[0].1.contains(&s("2")));
        }
    }
}

/// PHP: `Tests\Unit\Adapter\OperatorLimitExactnessTest`.
mod operator_limit_exactness {
    use super::*;

    #[tokio::test]
    async fn a_whole_number_float_limit_is_compared_exactly() {
        let cases: Vec<(&str, Operator, Value)> = vec![
            ("high", Operator::increment(20, Some(9.0e18.into())), Value::Int(8999999999999999990)),
            ("high", Operator::increment(10, Some(9.0e18.into())), Value::Int(9000000000000000000)),
            ("low", Operator::decrement(20, Some((-9.0e18).into())), Value::Int(-8999999999999999990)),
            ("low", Operator::decrement(10, Some((-9.0e18).into())), Value::Int(-9000000000000000000)),
            ("high", Operator::multiply(2, Some(9.0e18.into())), Value::Int(8999999999999999990)),
            ("ratio", Operator::increment(1, Some(2.25.into())), Value::Float(1.5)),
            ("ratio", Operator::increment(0.5, Some(2.25.into())), Value::Float(2.0)),
        ];
        for (attribute, operator, expected) in cases {
            let fx = fx(Engine::Sqlite, "limits", false).await.unwrap();
            fx.create_table("counters", &[("high", Col::BigInt), ("low", Col::BigInt), ("ratio", Col::Double)]).await;
            let c = collection(
                "counters",
                vec![
                    Attribute::big_integer("high").default(8999999999999999990i64),
                    Attribute::big_integer("low").default(-8999999999999999990i64),
                    Attribute::double("ratio").default(1.5),
                ],
            );
            fx.adapter
                .create_document(
                    &c,
                    document(
                        "existing",
                        vec![
                            ("high", Value::Int(8999999999999999990)),
                            ("low", Value::Int(-8999999999999999990)),
                            ("ratio", Value::Float(1.5)),
                        ],
                    ),
                )
                .await
                .unwrap();
            let current = fx.adapter.get_document(&c, "existing", vec![], false).await.unwrap();
            fx.adapter.update_document(&c, "existing", merged(&current, vec![(attribute, op(operator.clone()))]), true).await.unwrap();
            upsert_new(&fx, &c, "created", vec![(attribute, op(operator.clone()))]).await.unwrap();
            for id in ["existing", "created"] {
                let value = fx.attribute(&c, id, attribute).await;
                match &expected {
                    Value::Float(f) => assert_eq!(value.to_double(), *f, "{attribute} {id}"),
                    other => assert_eq!(value, s(&utopia_database::value::to_text(other)), "{attribute} {id} {:?}", operator.method()),
                }
            }
        }
    }

    #[tokio::test]
    async fn a_whole_number_float_limit_is_bound_as_an_integer() {
        for (engine, db) in [(Engine::MariaDb, "bdocs_limits_m"), (Engine::MySql, "bdocs_limits_y"), (Engine::Postgres, "bdocs_limits_p")] {
            let Some(fx) = fx(engine, db, false).await else { continue };
            fx.create_table("counters", &[("high", Col::BigInt)]).await;
            let c = collection("counters", vec![Attribute::big_integer("high")]);
            for operator in [
                Operator::increment(1, Some(9.0e18.into())),
                Operator::multiply(2, Some(9.0e18.into())),
                Operator::power(2, Some(9.0e18.into())),
            ] {
                fx.adapter
                    .update_documents(&c, &doc(vec![("high", op(operator))]), vec![doc(vec![("$id", s("first")), ("$sequence", s("1"))])], &[])
                    .await
                    .unwrap();
                let bound = fx.bound();
                assert!(bound.contains(&Value::Int(9000000000000000000)), "{engine:?} {bound:?}");
                assert!(bound.iter().all(|v| !matches!(v, Value::Float(_))));
            }
        }
    }
}

/// PHP: `Tests\Unit\Adapter\SQLArrayWritesTest` (the `contains` find is the
/// find port's).
mod sql_array_writes {
    use super::*;

    async fn items() -> (support::Fx, Collection) {
        let fx = fx(Engine::Sqlite, "array_writes", false).await.unwrap();
        fx.create_table("items", &[("tags", Col::Json), ("numbers", Col::Json), ("words", Col::Json), ("active", Col::Bool)]).await;
        let c = collection(
            "items",
            vec![
                Attribute::string("tags", 16).array(true),
                Attribute::integer("numbers").array(true).default(list(vec![1, 2, 2, 3].into_iter().map(Value::Int).collect())),
                Attribute::string("words", 16).array(true).default(strings(&["a", "b", "b"])),
                Attribute::boolean("active"),
            ],
        );
        (fx, c)
    }

    #[tokio::test]
    async fn created_documents_keep_their_arrays() {
        let (fx, c) = items().await;
        fx.adapter
            .create_documents(
                &c,
                vec![
                    document("first", vec![("tags", strings(&["red", "blue"])), ("numbers", list(vec![Value::Int(7), Value::Int(8)]))]),
                    document("second", vec![("tags", strings(&["green"])), ("numbers", list(vec![]))]),
                ],
            )
            .await
            .unwrap();
        assert_eq!(fx.stored("items", "tags").await, vec![s("[\"red\",\"blue\"]"), s("[\"green\"]")]);
        assert_eq!(fx.stored("items", "numbers").await, vec![s("[7,8]"), s("[]")]);
    }

    #[tokio::test]
    async fn updated_documents_store_arrays_as_json_and_booleans_as_integers() {
        let (fx, c) = items().await;
        fx.adapter
            .create_documents(
                &c,
                vec![
                    document("first", vec![("tags", strings(&["old"])), ("active", Value::Bool(false))]),
                    document("second", vec![("active", Value::Bool(false))]),
                ],
            )
            .await
            .unwrap();
        let documents = fx.adapter.get_sequences(&c, vec![doc(vec![("$id", s("first"))]), doc(vec![("$id", s("second"))])]).await.unwrap();
        let updated = fx
            .adapter
            .update_documents(&c, &doc(vec![("tags", strings(&["a", "b"])), ("active", Value::Bool(true))]), documents, &[])
            .await
            .unwrap();
        assert_eq!(updated, 2);
        assert_eq!(fx.stored("items", "tags").await, vec![s("[\"a\",\"b\"]"); 2]);
        assert_eq!(fx.stored("items", "active").await, vec![s("1"); 2]);
    }

    #[tokio::test]
    async fn an_update_with_nothing_to_set_changes_nothing() {
        let (fx, c) = items().await;
        fx.adapter.create_document(&c, document("first", vec![("tags", strings(&["kept"]))])).await.unwrap();
        let first = fx.adapter.get_document(&c, "first", vec![], false).await.unwrap();
        assert_eq!(fx.adapter.update_documents(&c, &Document::empty(), vec![first], &[]).await.unwrap(), 0);
        assert_eq!(fx.stored("items", "tags").await, vec![s("[\"kept\"]")]);
    }

    #[tokio::test]
    async fn an_array_operator_on_a_new_document_applies_to_the_default() {
        let cases: Vec<(&str, Operator, serde_json::Value)> = vec![
            ("numbers", Operator::array_unique(), serde_json::json!(["1", "2", "3"])),
            ("numbers", Operator::array_remove(2), serde_json::json!(["1", "3"])),
            ("numbers", Operator::array_intersect([2, 3]), serde_json::json!(["2", "2", "3"])),
            ("numbers", Operator::array_diff([1]), serde_json::json!(["2", "2", "3"])),
            ("words", Operator::array_unique(), serde_json::json!(["a", "b"])),
            ("words", Operator::array_remove("b"), serde_json::json!(["a"])),
            ("words", Operator::array_intersect(["b"]), serde_json::json!(["b", "b"])),
            ("words", Operator::array_diff(["a"]), serde_json::json!(["b", "b"])),
        ];
        for (attribute, operator, expected) in cases {
            let (fx, c) = items().await;
            upsert_new(&fx, &c, "created", vec![(attribute, op(operator.clone()))]).await.unwrap();
            assert_eq!(decoded(&fx.attribute(&c, "created", attribute).await), expected, "{attribute} {:?}", operator.method());
        }
    }

    #[tokio::test]
    async fn a_nested_operand_matches_no_element_of_a_new_document() {
        let (fx, c) = items().await;
        upsert_new(&fx, &c, "created", vec![("numbers", op(Operator::array_intersect([list(vec![Value::Int(2)])])))]).await.unwrap();
        assert_eq!(decoded(&fx.attribute(&c, "created", "numbers").await), serde_json::json!([]));
    }
}
