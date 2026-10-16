//! The SQL adapters' reads: `find()`, `count()` and `sum()`.
//!
//! `fixtures/sql_find/spec.json` lists the collections, the documents and
//! the reads; `fixtures/sql_find/oracle.php` ran them through PHP 8.3.0's
//! adapters on every engine, with and without shared tables, and recorded
//! in `fixtures/sql_find/expected.json` the statements each one set up its
//! tables with, then for each read: every statement it ran (event and SQL,
//! as the transforms saw them), the SQL and bindings the profiler logged,
//! and its outcome (documents, count, sum, or the error). The tests replay
//! the setup and check that every read runs the same statements, with the
//! same bindings, and gives the same outcome.
//!
//! Reads against SQLite run in memory; the other engines connect to the
//! compat database servers and are skipped when one is unreachable.
#![cfg(all(feature = "sqlite", feature = "postgres", feature = "mariadb", feature = "mysql"))]

mod sql_find {
    //! PHP: the reads of `tests/unit/SQLFindTest`, `SQLiteSearchJoinTest`,
    //! `SQLiteSearchAggregateTest`, `SQLiteArrayContainsTest`,
    //! `SQLiteLikeEscapeTest`, `QueryBuilderAuthorizationTest`,
    //! `QueryBuilderTenancyTest`, `tests/unit/Joins/*` and
    //! `tests/unit/Adapter/{SQLJoinedReadsTest, SQLiteVectorQueryTest,
    //! PostgresVectorCursorTest, FullOuterJoinRandomOrderTest,
    //! FilteredAggregateStatementTest, UnfilteredAggregateStatementTest}`,
    //! as statements recorded from the PHP adapters.

    use std::sync::{Arc, Mutex, OnceLock};

    use utopia_database::adapter::sql::pdo::ParamType;
    use utopia_database::adapter::sql::{Engine, Sql};
    use utopia_database::adapter::{Adapter, Find};
    use utopia_database::collection::Collection;
    use utopia_database::event::Event;
    use utopia_database::hook::Transform;
    use utopia_database::permission::PermissionType;
    use utopia_database::profiler::Profiler;
    use utopia_database::validator::authorization::Authorization;
    use utopia_database::value::{Array, Value};
    use utopia_database::{Document, Query};
    use utopia_query::{CursorDirection, OrderDirection};

    fn decode(json: &[u8]) -> Value {
        php_std::json::decode_ext(json, Some(true), 512, php_std::json::Flags::NONE).expect("JSON")
    }

    fn fixture(name: &str) -> &'static Value {
        static SPEC: OnceLock<Value> = OnceLock::new();
        static EXPECTED: OnceLock<Value> = OnceLock::new();
        let dir = concat!(env!("CARGO_MANIFEST_DIR"), "/tests/fixtures/sql_find/");
        let cell = if name == "spec" { &SPEC } else { &EXPECTED };
        cell.get_or_init(|| decode(&std::fs::read(format!("{dir}{name}.json")).expect("fixture")))
    }

    fn json(value: &Value) -> String {
        let flags = php_std::json::Flags::UNESCAPED_SLASHES | php_std::json::Flags::PRESERVE_ZERO_FRACTION;
        php_std::json::encode(value, flags, 512).expect("encodable")
    }

    fn get<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
        match value {
            Value::Array(a) => a.get_str(key),
            _ => None,
        }
    }

    fn array(value: Option<&Value>) -> Array {
        match value {
            Some(Value::Array(a)) => a.clone(),
            _ => Array::new(),
        }
    }

    fn text(value: &Value) -> String {
        utopia_database::value::to_text(value)
    }

    fn list(values: Vec<Value>) -> Value {
        Value::Array(values.into_iter().collect())
    }

    struct Recorder(Arc<Mutex<Vec<Value>>>);

    impl Transform for Recorder {
        fn transform(&self, event: Event, query: String) -> utopia_database::Result<String> {
            let entry = list(vec![Value::Str(event.as_str().into()), Value::Str(query.as_str().into())]);
            self.0.lock().unwrap().push(entry);
            Ok(query)
        }
    }

    fn engine_name(engine: Engine) -> &'static str {
        match engine {
            Engine::Postgres => "postgres",
            Engine::MariaDb => "mariadb",
            Engine::MySql => "mysql",
            Engine::Sqlite => "sqlite",
        }
    }

    async fn connect(engine: Engine) -> Option<Sql> {
        let (dsn, user, pass) = match engine {
            Engine::Postgres => ("pgsql:host=localhost;port=16432", Some("root"), Some("password")),
            Engine::MariaDb => ("mysql:host=localhost;port=16306;charset=utf8mb4", Some("root"), Some("password")),
            Engine::MySql => ("mysql:host=localhost;port=16307;charset=utf8mb4", Some("root"), Some("password")),
            Engine::Sqlite => ("sqlite::memory:", None, None),
        };
        match Sql::connect(engine, dsn, user, pass).await {
            Ok(adapter) => Some(adapter),
            Err(error) => {
                eprintln!("skipping {}: {}", engine_name(engine), error.message());
                None
            }
        }
    }

    /// Replays the statements PHP set the tables up with.
    async fn replay(adapter: &Sql, setup: &Value) {
        let Value::Array(statements) = setup else { panic!("setup") };
        let mut pdo = adapter.pdo().lock().await;
        for statement in statements.values() {
            let Value::Array(statement) = statement else { panic!("statement") };
            let mut values = statement.values();
            let sql = text(values.next().unwrap());
            let mut prepared = pdo.prepare(&sql).expect("prepare");
            if let Some(Value::Array(bindings)) = values.next() {
                for binding in bindings.values() {
                    let Value::Array(binding) = binding else { panic!("binding") };
                    let mut parts = binding.values();
                    let param = parts.next().unwrap().clone();
                    let value = parts.next().unwrap().clone();
                    let ty = match parts.next() {
                        Some(Value::Int(0)) => ParamType::Null,
                        Some(Value::Int(1)) => ParamType::Int,
                        Some(Value::Int(5)) => ParamType::Bool,
                        _ => ParamType::Str,
                    };
                    match param {
                        Value::Int(i) => prepared.bind_value(i as usize, value, ty).unwrap(),
                        other => prepared.bind_value(text(&other), value, ty).unwrap(),
                    }
                }
            }
            prepared.execute(&mut pdo).await.unwrap_or_else(|e| panic!("{sql}: {}", e.message()));
        }
    }

    fn query(value: &Value, types: Option<&Value>) -> Query {
        let Value::Array(array) = value else { panic!("query") };
        let mut query =
            Query::parse_query(&utopia_database::value::to_query_array(array), false).expect("parsable query");
        if let Some(Value::Array(kind)) = types.and_then(|t| get(t, query.attribute())) {
            let mut values = kind.values();
            query.set_attribute_type(text(values.next().unwrap()));
            query.set_on_array(values.next().unwrap().truthy());
        }
        query
    }

    fn collection(spec: &Value, scenario: &Value, shared: bool) -> Collection {
        let id = get(scenario, "collection").map_or("authors".to_owned(), text);
        let mut definition = array(get(get(spec, "collections").unwrap(), &id));
        definition.remove(&"engines".into());
        if get(scenario, "collectionExtra").is_some_and(Value::truthy) {
            let joined = get(spec, "joined").unwrap();
            definition.set("joinAttributes".into(), get(joined, "joinAttributes").unwrap().clone());
            if shared {
                definition.set("joinIndexed".into(), get(joined, "joinIndexed").unwrap().clone());
            }
        }
        for key in ["joinDocumentSecurity", "collectionGranted"] {
            if let Some(value) = get(scenario, key) {
                definition.set(key.into(), value.clone());
            }
        }
        Collection::from_array(definition).expect("collection")
    }

    fn outcome(result: utopia_database::Result<Value>, no_result: bool) -> Value {
        match result {
            Ok(value) => list(vec![Value::Str("ok".into()), if no_result { Value::Null } else { value }]),
            Err(error) => list(vec![
                Value::Str("error".into()),
                Value::Str(error.php_class().into()),
                Value::Str(error.message().into()),
            ]),
        }
    }

    fn documents(documents: Vec<Document>) -> Value {
        list(documents.into_iter().map(|d| Value::Array(d.into_array())).collect())
    }

    async fn run(engine: Engine, shared: bool) {
        let Some(adapter) = connect(engine).await else {
            return;
        };
        let mode = if shared { "shared" } else { "plain" };
        let expected = get(fixture("expected"), &format!("{}/{mode}", engine_name(engine))).expect("recorded");
        let spec = fixture("spec");

        let database = format!("l3a_find_{mode}");
        adapter.set_database(&database);
        adapter.set_namespace("ns");
        if shared {
            adapter.set_shared_tables(true);
            adapter.set_tenant(Value::Int(7));
        }
        let authorization = Authorization::new(true);
        authorization.disable();
        adapter.set_authorization(Arc::new(authorization));
        if engine != Engine::Sqlite {
            let _ = adapter.delete(&database).await;
            adapter.create(&database).await.expect("database");
        }
        replay(&adapter, get(expected, "setup").unwrap()).await;

        let profiler = Arc::new(Profiler::new());
        profiler.enable();
        adapter.set_profiler(Some(profiler.clone()));
        let seen = Arc::new(Mutex::new(Vec::new()));
        adapter.add_transform("rec", Arc::new(Recorder(seen.clone())));

        let scenarios = get(expected, "scenarios").unwrap();
        let mut failures = Vec::new();
        let mut compared = 0;
        let Some(Value::Array(list_of)) = get(spec, "scenarios") else { panic!("scenarios") };
        for scenario in list_of.values() {
            let name = text(get(scenario, "name").unwrap());
            let Some(recorded) = get(scenarios, &name) else {
                continue;
            };
            compared += 1;

            let collection = collection(spec, scenario, shared);
            let types = get(scenario, "types");
            let queries: Vec<Query> = array(get(scenario, "queries")).values().map(|q| query(q, types)).collect();

            let authorization = Authorization::new(true);
            match get(scenario, "auth") {
                Some(Value::Array(roles)) => {
                    for role in roles.values() {
                        authorization.add_role(&text(role));
                    }
                }
                _ => {
                    authorization.disable();
                }
            }
            adapter.set_authorization(Arc::new(authorization));
            profiler.reset();
            seen.lock().unwrap().clear();

            let no_result = get(scenario, "noResult").is_some_and(Value::truthy);
            let result = match text(get(scenario, "call").unwrap()).as_str() {
                "find" => {
                    let limit = match get(scenario, "limit") {
                        None => Some(25),
                        Some(Value::Null) => None,
                        Some(v) => Some(utopia_database::value::to_long(v)),
                    };
                    let options = Find {
                        limit,
                        offset: get(scenario, "offset").map(utopia_database::value::to_long),
                        order_attributes: array(get(scenario, "orderAttributes")).values().map(text).collect(),
                        order_types: array(get(scenario, "orderTypes"))
                            .values()
                            .map(|t| OrderDirection::try_from_value(&text(t)).unwrap())
                            .collect(),
                        cursor: array(get(scenario, "cursor")),
                        cursor_direction: CursorDirection::try_from_value(
                            &get(scenario, "cursorDirection").map_or("after".to_owned(), text),
                        )
                        .unwrap(),
                        for_permission: PermissionType::from_name(
                            &get(scenario, "forPermission").map_or("read".to_owned(), text),
                        )
                        .unwrap(),
                    };
                    adapter.find(&collection, queries, options).await.map(documents)
                }
                "count" => adapter
                    .count(&collection, queries, get(scenario, "max").map(utopia_database::value::to_long))
                    .await
                    .map(Value::Int),
                "sum" => {
                    adapter
                        .sum(
                            &collection,
                            &text(get(scenario, "attribute").unwrap()),
                            queries,
                            get(scenario, "max").map(utopia_database::value::to_long),
                        )
                        .await
                }
                other => panic!("unknown call {other}"),
            };

            let actual_outcome = json(&outcome(result, no_result));
            let expected_outcome = json(get(recorded, "outcome").unwrap());
            if actual_outcome != expected_outcome {
                failures.push(format!("{name}: outcome\n  PHP:  {expected_outcome}\n  Rust: {actual_outcome}"));
            }

            let statements = json(&list(seen.lock().unwrap().clone()));
            let expected_statements = json(get(recorded, "statements").unwrap());
            if statements != expected_statements {
                failures.push(format!("{name}: statements\n  PHP:  {expected_statements}\n  Rust: {statements}"));
            }

            let logs = json(&list(
                profiler
                    .logs()
                    .into_iter()
                    .map(|log| {
                        let bindings: Vec<Value> = log.bindings.values().cloned().collect();
                        list(vec![Value::Str(log.query.as_str().into()), list(bindings)])
                    })
                    .collect(),
            ));
            let expected_logs = json(get(recorded, "logs").unwrap());
            if logs != expected_logs {
                failures.push(format!("{name}: bindings\n  PHP:  {expected_logs}\n  Rust: {logs}"));
            }
        }
        let Value::Array(recorded) = scenarios else { panic!("scenarios") };
        assert_eq!(compared, recorded.len(), "every recorded read ran");
        assert!(failures.is_empty(), "{} differences:\n{}", failures.len(), failures.join("\n"));
    }

    #[utopia_async::test]
    async fn sqlite_plain() {
        run(Engine::Sqlite, false).await;
    }

    #[utopia_async::test]
    async fn sqlite_shared() {
        run(Engine::Sqlite, true).await;
    }

    #[utopia_async::test]
    async fn postgres_plain() {
        run(Engine::Postgres, false).await;
    }

    #[utopia_async::test]
    async fn postgres_shared() {
        run(Engine::Postgres, true).await;
    }

    #[utopia_async::test]
    async fn mariadb_plain() {
        run(Engine::MariaDb, false).await;
    }

    #[utopia_async::test]
    async fn mariadb_shared() {
        run(Engine::MariaDb, true).await;
    }

    #[utopia_async::test]
    async fn mysql_plain() {
        run(Engine::MySql, false).await;
    }

    #[utopia_async::test]
    async fn mysql_shared() {
        run(Engine::MySql, true).await;
    }
}

mod builder_statement {
    //! PHP: `Tests\Unit\Adapter\BuilderStatementTest`'s reads the adapter
    //! builds itself (`find`, `findAuthorized`, `fullOuterJoin`,
    //! `fullOuterJoinRandom`, `fullOuterJoinCount`, `count`, `sum`) against
    //! `Data/Builder/statements.json` (copied to
    //! `fixtures/sql_find/statements.json`). PHP ran them on a stub PDO; here
    //! a transform records each statement and stops it before it runs, so the
    //! SQL is compared (the bindings of the same reads are compared by
    //! `sql_find` against real tables).

    use std::sync::{Arc, Mutex, OnceLock};

    use utopia_database::adapter::sql::{Engine, Sql};
    use utopia_database::adapter::{Adapter, Find};
    use utopia_database::collection::Collection;
    use utopia_database::event::Event;
    use utopia_database::hook::Transform;
    use utopia_database::validator::authorization::Authorization;
    use utopia_database::value::{Array, Value};
    use utopia_database::{Error, Query};
    use utopia_query::OrderDirection;

    fn corpus() -> &'static Value {
        static CORPUS: OnceLock<Value> = OnceLock::new();
        CORPUS.get_or_init(|| {
            let path = concat!(env!("CARGO_MANIFEST_DIR"), "/tests/fixtures/sql_find/statements.json");
            php_std::json::decode_ext(&std::fs::read(path).unwrap(), Some(true), 512, php_std::json::Flags::NONE)
                .unwrap()
        })
    }

    fn get<'a>(value: &'a Value, key: &str) -> &'a Value {
        match value {
            Value::Array(a) => a.get_str(key).unwrap_or_else(|| panic!("{key}")),
            _ => panic!("{key}"),
        }
    }

    fn first(value: &Value) -> &Value {
        match value {
            Value::Array(a) => a.values().next().expect("an entry"),
            _ => panic!("a list"),
        }
    }

    /// Records each statement and stops it.
    struct Stop(Arc<Mutex<Vec<String>>>);

    impl Transform for Stop {
        fn transform(&self, _event: Event, query: String) -> utopia_database::Result<String> {
            self.0.lock().unwrap().push(query);
            Err(Error::database("stopped"))
        }
    }

    async fn adapter(engine: Engine, shared: bool) -> Option<(Sql, Arc<Mutex<Vec<String>>>)> {
        let (dsn, user, pass) = match engine {
            Engine::Postgres => ("pgsql:host=localhost;port=16432", Some("root"), Some("password")),
            Engine::MariaDb => ("mysql:host=localhost;port=16306;charset=utf8mb4", Some("root"), Some("password")),
            Engine::MySql => ("mysql:host=localhost;port=16307;charset=utf8mb4", Some("root"), Some("password")),
            Engine::Sqlite => ("sqlite::memory:", None, None),
        };
        let adapter = match Sql::connect(engine, dsn, user, pass).await {
            Ok(adapter) => adapter,
            Err(error) => {
                eprintln!("skipping: {}", error.message());
                return None;
            }
        };
        adapter.set_database("database");
        adapter.set_namespace("namespace");
        if shared {
            adapter.set_shared_tables(true);
            adapter.set_tenant(Value::Int(7));
        }
        let authorization = Authorization::new(true);
        authorization.disable();
        adapter.set_authorization(Arc::new(authorization));
        let seen = Arc::new(Mutex::new(Vec::new()));
        adapter.add_transform("stop", Arc::new(Stop(seen.clone())));
        Some((adapter, seen))
    }

    fn collection() -> Collection {
        let mut data = Array::new();
        data.set("$id".into(), Value::Str("authors".into()));
        data.set("attributes".into(), Value::Array(Array::new()));
        data.set("indexes".into(), Value::Array(Array::new()));
        // PHP's definition has no `documentSecurity`, which reads as false;
        // a Rust collection always has one.
        data.set("documentSecurity".into(), Value::Bool(false));
        Collection::from_array(data).unwrap()
    }

    fn on() -> Vec<Query> {
        vec![Query::on("$id", "authorId", "=")]
    }

    fn find(limit: i64) -> Find {
        Find { limit: Some(limit), ..Find::default() }
    }

    fn last(seen: &Arc<Mutex<Vec<String>>>) -> String {
        seen.lock().unwrap().drain(..).next().unwrap_or_default()
    }

    async fn check(engine: Engine, key: &str, shared: bool) {
        let mode = if shared { "shared" } else { "plain" };
        let internal = get(get(corpus(), &format!("{key}/{mode}")), "internal");
        let Some((adapter, seen)) = adapter(engine, shared).await else {
            return;
        };
        let collection = collection();
        let full_outer_join = || Query::full_outer_join("books", "book", on()).unwrap();
        let mut actual: Vec<(&str, String)> = Vec::new();

        let _ = adapter.find(&collection, vec![Query::equal("name", vec!["one"])], find(10)).await;
        actual.push(("find", last(&seen)));

        let authorization = Authorization::new(true);
        authorization.add_role("any");
        adapter.set_authorization(Arc::new(authorization));
        let _ = adapter.find(&collection, vec![Query::join("books", "book", on()).unwrap()], find(10)).await;
        actual.push(("findAuthorized", last(&seen)));
        let authorization = Authorization::new(true);
        authorization.disable();
        adapter.set_authorization(Arc::new(authorization));

        let _ = adapter.find(&collection, vec![full_outer_join()], find(10)).await;
        actual.push(("fullOuterJoin", last(&seen)));

        let random =
            Find { order_attributes: vec![String::new()], order_types: vec![OrderDirection::Random], ..find(10) };
        let _ = adapter.find(&collection, vec![full_outer_join()], random).await;
        actual.push(("fullOuterJoinRandom", last(&seen)));

        let _ = adapter.count(&collection, vec![full_outer_join()], Some(5)).await;
        actual.push(("fullOuterJoinCount", last(&seen)));

        let _ = adapter.count(&collection, vec![Query::equal("name", vec!["one"])], Some(5)).await;
        actual.push(("count", last(&seen)));

        let _ = adapter.sum(&collection, "pages", vec![Query::equal("name", vec!["one"])], Some(5)).await;
        actual.push(("sum", last(&seen)));

        for (name, statement) in actual {
            let expected = first(first(get(get(internal, name), "statements")));
            assert_eq!(statement, utopia_database::value::to_text(expected), "{key}/{mode} {name}");
        }
    }

    #[utopia_async::test]
    async fn reads_compile_as_recorded() {
        for shared in [false, true] {
            check(Engine::Sqlite, "SQLite", shared).await;
            check(Engine::Postgres, "Postgres", shared).await;
            check(Engine::MariaDb, "MariaDB", shared).await;
            check(Engine::MySql, "MySQL", shared).await;
        }
    }
}

mod capture {
    //! PHP: the statement assertions of `tests/unit/SQLFindTest`,
    //! `tests/unit/Adapter/{PostgresVectorCursorTest, SQLiteVectorQueryTest,
    //! FilteredAggregateStatementTest, UnfilteredAggregateStatementTest}`,
    //! `MySQLVisibilityTest` and the join shapes of `tests/unit/Joins/*`
    //! (bounded sorts, unindexed joins, large joins, aggregates over joins,
    //! full outer join emulation, join aliases, outer join chains), as the
    //! reads of `fixtures/sql_find/capture.json`. `oracle_capture.php`
    //! recorded the first statement each prepares on every engine (or the
    //! error raised before it) in `capture_expected.json`; a transform
    //! records the statement and stops it, so no table is needed.

    use std::sync::{Arc, Mutex, OnceLock};

    use utopia_database::adapter::sql::{Engine, Sql};
    use utopia_database::adapter::{Adapter, Find};
    use utopia_database::collection::Collection;
    use utopia_database::event::Event;
    use utopia_database::hook::Transform;
    use utopia_database::permission::PermissionType;
    use utopia_database::validator::authorization::Authorization;
    use utopia_database::value::{Array, Value};
    use utopia_database::{Error, Query};
    use utopia_query::{CursorDirection, OrderDirection};

    fn load(name: &str) -> Value {
        let path = format!("{}/tests/fixtures/sql_find/{name}", env!("CARGO_MANIFEST_DIR"));
        php_std::json::decode_ext(&std::fs::read(path).unwrap(), Some(true), 512, php_std::json::Flags::NONE).unwrap()
    }

    fn get<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
        match value {
            Value::Array(a) => a.get_str(key),
            _ => None,
        }
    }

    fn array(value: Option<&Value>) -> Array {
        match value {
            Some(Value::Array(a)) => a.clone(),
            _ => Array::new(),
        }
    }

    fn text(value: &Value) -> String {
        utopia_database::value::to_text(value)
    }

    fn list(values: Vec<Value>) -> Value {
        Value::Array(values.into_iter().collect())
    }

    struct Stop(Arc<Mutex<Vec<String>>>);

    impl Transform for Stop {
        fn transform(&self, _event: Event, query: String) -> utopia_database::Result<String> {
            self.0.lock().unwrap().push(query);
            Err(Error::database("stopped"))
        }
    }

    async fn connect(engine: Engine) -> Option<Sql> {
        let (dsn, user, pass) = match engine {
            Engine::Postgres => ("pgsql:host=localhost;port=16432", Some("root"), Some("password")),
            Engine::MariaDb => ("mysql:host=localhost;port=16306;charset=utf8mb4", Some("root"), Some("password")),
            Engine::MySql => ("mysql:host=localhost;port=16307;charset=utf8mb4", Some("root"), Some("password")),
            Engine::Sqlite => ("sqlite::memory:", None, None),
        };
        match Sql::connect(engine, dsn, user, pass).await {
            Ok(adapter) => Some(adapter),
            Err(error) => {
                eprintln!("skipping: {}", error.message());
                None
            }
        }
    }

    async fn run(engine: Engine, key: &str) {
        if connect(engine).await.is_none() {
            return;
        }
        static SCENARIOS: OnceLock<Value> = OnceLock::new();
        static EXPECTED: OnceLock<Value> = OnceLock::new();
        let scenarios = SCENARIOS.get_or_init(|| load("capture.json"));
        let expected = EXPECTED.get_or_init(|| load("capture_expected.json"));

        let mut failures = Vec::new();
        let mut compared = 0;
        let Value::Array(scenarios) = scenarios else { panic!("scenarios") };
        for scenario in scenarios.values() {
            if let Some(Value::Array(engines)) = get(scenario, "engines")
                && !engines.values().any(|e| text(e) == key)
            {
                continue;
            }
            let modes = match get(scenario, "modes") {
                Some(Value::Array(modes)) => modes.values().map(text).collect(),
                _ => vec!["plain".to_owned()],
            };
            for mode in modes {
                let name = format!("{} {key}/{mode}", text(get(scenario, "name").unwrap()));
                let adapter = connect(engine).await.unwrap();
                adapter.set_database(&get(scenario, "database").map_or("database".to_owned(), text));
                adapter.set_namespace(&get(scenario, "namespace").map_or("namespace".to_owned(), text));
                if mode == "shared" {
                    adapter.set_shared_tables(true);
                    adapter.set_tenant(get(scenario, "tenant").cloned().unwrap_or(Value::Int(7)));
                }
                let authorization = Authorization::new(true);
                match get(scenario, "auth") {
                    Some(Value::Array(roles)) => {
                        for role in roles.values() {
                            authorization.add_role(&text(role));
                        }
                    }
                    _ => {
                        authorization.disable();
                    }
                }
                adapter.set_authorization(Arc::new(authorization));
                let seen = Arc::new(Mutex::new(Vec::new()));
                adapter.add_transform("stop", Arc::new(Stop(seen.clone())));

                let mut definition = match get(scenario, "collection") {
                    Some(Value::Array(c)) => c.clone(),
                    _ => [("$id".into(), Value::Str("collection".into()))].into_iter().collect(),
                };
                // A PHP definition without `documentSecurity` reads as
                // false; a Rust collection always has one.
                if definition.get_str("documentSecurity").is_none() {
                    definition.set("documentSecurity".into(), Value::Bool(false));
                }
                let collection = Collection::from_array(definition).expect("collection");
                let queries: Vec<Query> = array(get(scenario, "queries"))
                    .values()
                    .map(|q| {
                        let Value::Array(q) = q else { panic!("query") };
                        Query::parse_query(&utopia_database::value::to_query_array(q), false).unwrap()
                    })
                    .collect();

                let result = match get(scenario, "call").map_or("find".to_owned(), text).as_str() {
                    "find" => {
                        let options = Find {
                            limit: match get(scenario, "limit") {
                                None => Some(25),
                                Some(Value::Null) => None,
                                Some(v) => Some(utopia_database::value::to_long(v)),
                            },
                            offset: get(scenario, "offset").map(utopia_database::value::to_long),
                            order_attributes: array(get(scenario, "orderAttributes")).values().map(text).collect(),
                            order_types: array(get(scenario, "orderTypes"))
                                .values()
                                .map(|t| OrderDirection::try_from_value(&text(t)).unwrap())
                                .collect(),
                            cursor: array(get(scenario, "cursor")),
                            cursor_direction: CursorDirection::try_from_value(
                                &get(scenario, "cursorDirection").map_or("after".to_owned(), text),
                            )
                            .unwrap(),
                            for_permission: PermissionType::from_name(
                                &get(scenario, "forPermission").map_or("read".to_owned(), text),
                            )
                            .unwrap(),
                        };
                        adapter.find(&collection, queries, options).await.map(|_| ())
                    }
                    "count" => adapter
                        .count(&collection, queries, get(scenario, "max").map(utopia_database::value::to_long))
                        .await
                        .map(|_| ()),
                    "sum" => adapter
                        .sum(
                            &collection,
                            &text(get(scenario, "attribute").unwrap()),
                            queries,
                            get(scenario, "max").map(utopia_database::value::to_long),
                        )
                        .await
                        .map(|_| ()),
                    other => panic!("unknown call {other}"),
                };

                let first = seen.lock().unwrap().first().cloned();
                let actual = match (first, result) {
                    (Some(sql), _) => list(vec![Value::Str("sql".into()), Value::Str(sql.as_str().into())]),
                    (None, Ok(())) => list(vec![Value::Str("none".into())]),
                    (None, Err(e)) => list(vec![
                        Value::Str("error".into()),
                        Value::Str(e.php_class().into()),
                        Value::Str(e.message().into()),
                    ]),
                };
                let flags = php_std::json::Flags::UNESCAPED_SLASHES;
                let actual = php_std::json::encode(&actual, flags, 512).unwrap();
                let wanted = php_std::json::encode(get(expected, &name).expect(&name), flags, 512).unwrap();
                compared += 1;
                if actual != wanted {
                    failures.push(format!("{name}\n  PHP:  {wanted}\n  Rust: {actual}"));
                }
            }
        }
        assert!(compared > 0);
        assert!(failures.is_empty(), "{} differences:\n{}", failures.len(), failures.join("\n"));
    }

    #[utopia_async::test]
    async fn sqlite() {
        run(Engine::Sqlite, "sqlite").await;
    }

    #[utopia_async::test]
    async fn postgres() {
        run(Engine::Postgres, "postgres").await;
    }

    #[utopia_async::test]
    async fn mariadb() {
        run(Engine::MariaDb, "mariadb").await;
    }

    #[utopia_async::test]
    async fn mysql() {
        run(Engine::MySql, "mysql").await;
    }
}
