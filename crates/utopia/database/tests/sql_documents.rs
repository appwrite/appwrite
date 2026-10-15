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
