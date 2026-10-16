//! The database's builders (`Utopia\Database\Builder\*`): the PHP unit
//! tests of tests/unit/Builder, and statements checked against PHP 8.1.3.

use std::sync::{Arc, Mutex};

use utopia_database::builder::{self, Scope, ScopeBinding, SqlBuilder};
use utopia_database::{Error, Kind};
use utopia_query::Query;
use utopia_query::builder::Condition;
use utopia_query::{Array, Value, list, string};

/// `json_encode([$sql, $bindings])` as the PHP oracle printed it.
fn json(sql: &str, bindings: &[Value]) -> String {
    let mut pair = Array::new();
    pair.push(string(sql)).unwrap();
    pair.push(Value::Array(bindings.iter().cloned().collect())).unwrap();
    php_std::json::encode(&Value::Array(pair), php_std::json::Flags::NONE, 512).unwrap()
}

fn condition(c: &Condition) -> String {
    json(&c.expression, &c.bindings)
}

fn typed(mut query: Query, kind: &str) -> Query {
    query.set_attribute_type(kind);
    query
}

fn on_array(mut query: Query) -> Query {
    query.set_on_array(true);
    query
}

fn strings(values: &[&str]) -> Array {
    list(values.iter().copied().map(string))
}

/// The database error a builder error carries, as the adapters read it.
fn database_error(e: utopia_query::Error) -> Error {
    match &e {
        utopia_query::Error::Query { previous: Some(previous), .. } => {
            previous.downcast_ref::<Error>().cloned().expect("a database error")
        }
        other => panic!("not a database error: {other:?}"),
    }
}

mod oracle {
    //! Statements compared byte for byte with PHP 8.1.3's.
    use super::*;

    #[test]
    fn sqlite_like_declares_its_escape() {
        let built = builder::sqlite().from("users", "").filter([Query::starts_with("name", "a_")]).build().unwrap();
        assert_eq!(
            json(&built.query, &built.bindings),
            r#"["SELECT * FROM `users` WHERE `name` LIKE ? ESCAPE '\\'",["a\\_%"]]"#
        );
    }

    #[test]
    fn sqlite_substrings() {
        let mut b = builder::sqlite();
        let cases: Vec<(Query, &str)> = vec![
            (Query::contains_string("name", strings(&["a_b"])), r#"["`name` LIKE ? ESCAPE '\\'",["%a\\_b%"]]"#),
            (
                Query::contains_any("name", strings(&["a_b", "c%d"])),
                r#"["(`name` LIKE ? ESCAPE '\\' OR `name` LIKE ? ESCAPE '\\')",["%a\\_b%","%c\\%d%"]]"#,
            ),
            (
                Query::contains_all("name", strings(&["c%", "%d"])),
                r#"["(`name` LIKE ? ESCAPE '\\' AND `name` LIKE ? ESCAPE '\\')",["%c\\%%","%\\%d%"]]"#,
            ),
            (Query::not_contains("name", strings(&["_"])), r#"["`name` NOT LIKE ? ESCAPE '\\'",["%\\_%"]]"#),
            (
                Query::not_contains("name", strings(&["%", "\\"])),
                r#"["(`name` NOT LIKE ? ESCAPE '\\' AND `name` NOT LIKE ? ESCAPE '\\')",["%\\%%","%\\\\%"]]"#,
            ),
            (Query::not_ends_with("name", "%d"), r#"["`name` NOT LIKE ? ESCAPE '\\'",["%\\%d"]]"#),
            (Query::regex("name", "^a"), r#"["`name` REGEXP ?",["^a"]]"#),
        ];
        for (query, expected) in cases {
            assert_eq!(condition(&b.compile_filters(&[query]).unwrap()), expected);
        }
    }

    #[test]
    fn sqlite_collates_document_ids() {
        let filters = [
            Query::equal("_uid", strings(&["a", "b"])),
            Query::not_equal("x._document", strings(&["a"])),
            Query::not_equal("_uid", list([string("a"), string("b"), Value::Null])),
        ];
        assert_eq!(
            condition(&builder::sqlite().compile_filters(&filters).unwrap()),
            r#"["`_uid` COLLATE NOCASE IN (?, ?) AND `x`.`_document` COLLATE NOCASE != ? AND (`_uid` COLLATE NOCASE NOT IN (?, ?) AND `_uid` COLLATE NOCASE IS NOT NULL)",["a","b","a","a","b"]]"#
        );
    }

    #[test]
    fn sqlite_matches_array_elements() {
        let element = "EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json_extract(?, '$'))";
        let contains = on_array(Query::contains_any("tags", list([string("a"), Value::Int(1), Value::Null])));
        assert_eq!(
            condition(&builder::sqlite().compile_filters(&[contains]).unwrap()),
            format!(r#"["({element} OR {element} OR {element})",["\"a\"","1","null"]]"#)
        );
        let mut object = Array::new();
        object.set(php_std::ArrayKey::from("x"), Value::Int(1));
        let not_contains = on_array(Query::not_contains("tags", list([string("a"), Value::Array(object)])));
        assert_eq!(
            condition(&builder::sqlite().compile_filters(&[not_contains]).unwrap()),
            format!(r#"["(`tags` IS NOT NULL AND NOT ({element} OR {element}))",["\"a\"","{{\"x\":1}}"]]"#)
        );
        let all = on_array(Query::contains_all("tags", strings(&["a", "b"])));
        assert_eq!(
            condition(&builder::sqlite().compile_filters(&[all]).unwrap()),
            format!(r#"["({element} AND {element})",["\"a\"","\"b\""]]"#)
        );
    }

    #[test]
    fn sqlite_collates_join_comparisons() {
        let join = Query::join(
            "perms",
            "p",
            vec![
                Query::on("main._uid", "p._document", "="),
                Query::or([Query::on("main.x", "p._uid", "!="), Query::on("main.y", "p.z", "=")]),
            ],
        )
        .unwrap();
        let built = builder::sqlite()
            .from("t", "main")
            .queries([join])
            .filter([Query::equal("main._uid", strings(&["a"]))])
            .build()
            .unwrap();
        assert_eq!(
            json(&built.query, &built.bindings),
            r#"["SELECT * FROM `t` AS `main` JOIN `perms` AS `p` ON `main`.`_uid` COLLATE NOCASE = `p`.`_document` AND (`main`.`x` COLLATE NOCASE != `p`.`_uid` OR `main`.`y` = `p`.`z`) WHERE `main`.`_uid` COLLATE NOCASE IN (?)",["a"]]"#
        );
    }

    #[test]
    fn contains_all_patterns() {
        let filters = [Query::contains_all("title", strings(&["alpha", "be%ta"]))];
        assert_eq!(
            condition(&builder::mysql().compile_filters(&filters).unwrap()),
            r#"["(`title` LIKE ? OR `title` LIKE ?)",["alpha","be%ta"]]"#
        );
        assert_eq!(
            condition(&builder::postgres().compile_filters(&filters).unwrap()),
            r#"["(\"title\" ILIKE ? OR \"title\" ILIKE ?)",["alpha","be%ta"]]"#
        );
    }

    #[test]
    fn search_terms() {
        assert_eq!(
            condition(&builder::mariadb().compile_filters(&[Query::search("title", "baz, foo/bar;  qux")]).unwrap()),
            r#"["MATCH(`title`) AGAINST(? IN BOOLEAN MODE)",["baz foo bar qux*"]]"#
        );
        assert_eq!(
            condition(
                &builder::postgres()
                    .compile_filters(&[Query::search("title", "\"foo/bar baz\""), Query::not_search("t", "@García!")])
                    .unwrap()
            ),
            r#"["to_tsvector(regexp_replace(\"title\", '[^\\w]+', ' ', 'g')) @@ websearch_to_tsquery(?) AND NOT (to_tsvector(regexp_replace(\"t\", '[^\\w]+', ' ', 'g')) @@ websearch_to_tsquery(?))",["\"foo bar baz\"","Garc\u00eda"]]"#
        );
        assert_eq!(
            condition(&builder::postgres().compile_filters(&[Query::search("title", "  \t ")]).unwrap()),
            r#"["1 = 0",[]]"#
        );
    }

    #[test]
    fn postgres_distance_in_meters() {
        let query =
            typed(Query::distance_less_than("location", list([Value::Int(10), Value::Int(20)]), 1000, true), "point");
        assert_eq!(
            condition(&builder::postgres().compile_filters(&[query]).unwrap()),
            r#"["(\"location\" && ST_Expand(ST_GeomFromText(?, 4326), ?, ?) AND ST_Distance((\"location\"::geography), ST_SetSRID(ST_GeomFromText(?), 4326)::geography) < ?)",["POINT(10 20)",0.009560263084334466,0.009043717329571146,"POINT(10 20)",1000]]"#
        );
    }

    #[test]
    fn postgres_object_path() {
        let ok = typed(Query::equal("meta.user-info.home_city2", strings(&["x"])), "object");
        assert_eq!(
            condition(&builder::postgres().compile_filters(&[ok]).unwrap()),
            r#"["\"meta\"->'user-info'->>'home_city2' IN (?)",["x"]]"#
        );
        let bad = typed(Query::equal("meta..a", strings(&["x"])), "object");
        let error = database_error(builder::postgres().compile_filters(&[bad]).unwrap_err());
        assert_eq!(error.kind(), Kind::Query);
        assert_eq!(
            error.message(),
            "Invalid object path \"meta..a\": Object path keys must be non-empty and contain only a-z, A-Z, 0-9, underscore and hyphen"
        );
    }
}

mod contains_all_test {
    //! PHP: Tests\Unit\Builder\ContainsAllTest.
    use super::*;

    #[test]
    fn contains_all_on_a_string_matches_any_value_as_a_whole_pattern() {
        let filters = [Query::contains_all("title", strings(&["alpha", "be%ta"]))];
        let mut cases: Vec<(SqlBuilder, &str)> = vec![
            (SqlBuilder::MariaDb(builder::mariadb()), "LIKE"),
            (SqlBuilder::MySql(builder::mysql()), "LIKE"),
            (SqlBuilder::PostgreSql(builder::postgres()), "ILIKE"),
        ];
        for (b, like) in &mut cases {
            let condition = utopia_database::each_builder!(b, b => b.compile_filters(&filters)).unwrap();
            assert!(condition.expression.contains(&format!(" {like} ? OR ")));
            assert!(!condition.expression.contains(" AND "));
            assert_eq!(condition.bindings, vec![string("alpha"), string("be%ta")]);
        }
    }
}

mod search_term_test {
    //! PHP: Tests\Unit\Builder\SearchTermTest.
    use super::*;

    const POSTGRES_TERMS: [(&str, &str); 17] = [
        ("foo/bar", "foo or bar"),
        ("foo,bar", "foo or bar"),
        ("foo;bar", "foo or bar"),
        ("foo%bar", "foo or bar"),
        ("foo=bar", "foo or bar"),
        ("foo?bar", "foo or bar"),
        ("foo#bar", "foo or bar"),
        ("foo:bar", "foo or bar"),
        ("foo|bar", "foo or bar"),
        ("foo&bar", "foo or bar"),
        ("foo!bar", "foo or bar"),
        ("foo_bar", "foo_bar"),
        ("baz, foo/bar;  qux", "baz or foo or bar or qux"),
        ("+foo -bar* @3 <baz> ~qux (quux)", "foo or bar or 3 or baz or qux or quux"),
        ("@García!", "García"),
        ("\"foo/bar baz\"", "\"foo bar baz\""),
        ("\"foo/bar", "foo or bar"),
    ];

    const MYSQL_TERMS: [(&str, &str); 9] = [
        ("foo/bar", "foo bar*"),
        ("foo,bar", "foo bar*"),
        ("foo.bar", "foo bar*"),
        ("foo'bar", "foo bar*"),
        ("foo.", "foo*"),
        ("foo_bar", "foo_bar*"),
        ("baz, foo/bar;  qux", "baz foo bar qux*"),
        ("!!!foo...###", "foo*"),
        ("\"foo/bar baz\"", "\"foo bar baz\""),
    ];

    const WITHOUT_WORDS: [&str; 5] = ["/,;%=?#", "+-*@<>~()", "!!!...###", "\"/\"", "  \t "];

    fn bindings(b: &mut SqlBuilder, query: Query) -> Vec<Value> {
        utopia_database::each_builder!(b, b => b.compile_filters(&[query])).unwrap().bindings
    }

    fn builders() -> Vec<SqlBuilder> {
        vec![
            SqlBuilder::PostgreSql(builder::postgres()),
            SqlBuilder::MySql(builder::mysql()),
            SqlBuilder::MariaDb(builder::mariadb()),
        ]
    }

    #[test]
    fn postgre_sql_searches_each_word_of_a_term() {
        for (term, bound) in POSTGRES_TERMS {
            let mut b = SqlBuilder::PostgreSql(builder::postgres());
            assert_eq!(bindings(&mut b, Query::search("title", term)), vec![string(bound)], "{term}");
            assert_eq!(bindings(&mut b, Query::not_search("title", term)), vec![string(bound)], "{term}");
        }
    }

    #[test]
    fn my_sql_searches_each_word_of_a_term() {
        for (term, bound) in MYSQL_TERMS {
            let mut b = SqlBuilder::MySql(builder::mysql());
            assert_eq!(bindings(&mut b, Query::search("title", term)), vec![string(bound)], "{term}");
            assert_eq!(bindings(&mut b, Query::not_search("title", term)), vec![string(bound)], "{term}");
        }
    }

    #[test]
    fn maria_db_searches_each_word_of_a_term() {
        for (term, bound) in MYSQL_TERMS {
            let mut b = SqlBuilder::MariaDb(builder::mariadb());
            assert_eq!(bindings(&mut b, Query::search("title", term)), vec![string(bound)], "{term}");
            assert_eq!(bindings(&mut b, Query::not_search("title", term)), vec![string(bound)], "{term}");
        }
    }

    #[test]
    fn a_term_without_words_binds_nothing() {
        for term in WITHOUT_WORDS {
            for mut b in builders() {
                assert!(bindings(&mut b, Query::search("title", term)).is_empty(), "{term}");
                assert!(bindings(&mut b, Query::not_search("title", term)).is_empty(), "{term}");
            }
        }
    }

    #[test]
    fn a_term_is_always_bound_and_never_written_into_the_condition() {
        let term = "foo'); DROP TABLE docs; --";
        for mut b in builders() {
            let condition =
                utopia_database::each_builder!(&mut b, b => b.compile_filters(&[Query::search("title", term)]))
                    .unwrap();
            assert!(!condition.expression.contains("DROP"));
            assert!(!condition.expression.contains("foo"));
            assert_eq!(condition.bindings.len(), 1);
        }
    }
}

mod postgres_distance_test {
    //! PHP: Tests\Unit\Builder\PostgresDistanceTest.
    use super::*;

    fn point(x: f64, y: f64) -> Array {
        list([Value::Float(x), Value::Float(y)])
    }

    fn ints(x: i64, y: i64) -> Array {
        list([Value::Int(x), Value::Int(y)])
    }

    #[test]
    fn distance_less_than_in_degrees_leads_with_d_within() {
        let query = typed(Query::distance_less_than("location", ints(10, 20), 5, false), "point");
        let condition = builder::postgres().compile_filters(&[query]).unwrap();
        assert_eq!(
            condition.expression,
            r#"(ST_DWithin("location", ST_GeomFromText(?, 4326), ?) AND ST_Distance("location", ST_GeomFromText(?, 4326)) < ?)"#
        );
        assert_eq!(
            condition.bindings,
            vec![string("POINT(10 20)"), Value::Float(5.0), string("POINT(10 20)"), Value::Float(5.0)]
        );
    }

    #[test]
    fn distance_less_than_in_meters_from_a_point_leads_with_a_degree_box() {
        let query = typed(Query::distance_less_than("location", ints(10, 20), 1000, true), "point");
        let condition = builder::postgres().compile_filters(&[query]).unwrap();
        assert_eq!(
            condition.expression,
            r#"("location" && ST_Expand(ST_GeomFromText(?, 4326), ?, ?) AND ST_Distance(("location"::geography), ST_SetSRID(ST_GeomFromText(?), 4326)::geography) < ?)"#
        );
        assert_eq!(condition.bindings.len(), 5);
        assert_eq!(condition.bindings[0], string("POINT(10 20)"));
        assert_eq!(condition.bindings[3], string("POINT(10 20)"));
        assert_eq!(condition.bindings[4], Value::Float(1000.0));
        let (Value::Float(longitude), Value::Float(latitude)) = (&condition.bindings[1], &condition.bindings[2]) else {
            panic!("degrees are floats");
        };
        assert!((latitude - 1000.0 / 110574.0).abs() < 1e-12);
        assert!(*longitude > 1000.0 / 111319.0);
    }

    #[test]
    fn distance_less_than_in_meters_keeps_the_exact_check_only_when_no_box_holds_the_range() {
        let polygon = list([Value::Array(list([
            Value::Array(ints(0, 0)),
            Value::Array(ints(0, 1)),
            Value::Array(ints(1, 1)),
            Value::Array(ints(0, 0)),
        ]))]);
        let cases = [
            typed(Query::distance_less_than("location", ints(10, 20), 1000, true), "linestring"),
            typed(Query::distance_less_than("location", polygon, 1000, true), "point"),
            typed(
                Query::distance_less_than("location", list([Value::Int(10), Value::Float(89.99)]), 5000, true),
                "point",
            ),
            typed(Query::distance_less_than("location", point(179.99, 0.0), 5000, true), "point"),
            typed(Query::distance_less_than("location", ints(10, 20), f64::NAN, true), "point"),
            typed(Query::distance_less_than("location", ints(10, 20), f64::INFINITY, true), "point"),
            typed(Query::distance_less_than("location", ints(10, 20), f64::NEG_INFINITY, true), "point"),
        ];
        for query in cases {
            let condition = builder::postgres().compile_filters(&[query]).unwrap();
            assert_eq!(
                condition.expression,
                r#"ST_Distance(("location"::geography), ST_SetSRID(ST_GeomFromText(?), 4326)::geography) < ?"#
            );
            assert_eq!(condition.bindings.len(), 2);
        }
    }

    #[test]
    fn distance_greater_than_is_unchanged() {
        let query = typed(Query::distance_greater_than("location", ints(10, 20), 5, false), "point");
        let condition = builder::postgres().compile_filters(&[query]).unwrap();
        assert_eq!(condition.expression, r#"ST_Distance("location", ST_GeomFromText(?, 4326)) > ?"#);
    }
}

mod postgres_object_path_test {
    //! PHP: Tests\Unit\Builder\PostgresObjectPathTest.
    use super::*;

    const UNSAFE: [&str; 5] = [
        "meta.a' IN ('x') OR secret='s2' OR 'x",
        "meta.a'||(select 1)||'",
        "meta.a' OR 1=1 --",
        "meta.a'b.c",
        "meta..a",
    ];

    fn object(query: Query) -> Query {
        typed(query, "object")
    }

    #[test]
    fn a_filter_on_an_unsafe_object_path_is_a_query_error() {
        for path in UNSAFE {
            let error =
                builder::postgres().compile_filters(&[object(Query::equal(path, strings(&["x"])))]).unwrap_err();
            assert_eq!(database_error(error).kind(), Kind::Query, "{path}");
        }
    }

    #[test]
    fn a_statement_with_an_unsafe_object_path_inside_or_is_a_query_error() {
        for path in UNSAFE {
            let error = builder::postgres()
                .from("docs", "")
                .filter([Query::or([
                    object(Query::equal("meta.a", strings(&["x"]))),
                    object(Query::starts_with(path, "x")),
                ])])
                .build()
                .unwrap_err();
            assert_eq!(database_error(error).kind(), Kind::Query, "{path}");
        }
    }

    #[test]
    fn a_filter_on_a_plain_object_path_binds_its_value() {
        let condition = builder::postgres()
            .compile_filters(&[object(Query::equal("meta.user-info.home_city2", strings(&["x"])))])
            .unwrap();
        assert_eq!(condition.bindings, vec![string("x")]);
    }
}

mod scopes {
    //! `ScopesCollections`: a scoped builder reads one collection.
    use super::*;

    struct Prefix {
        resets: Arc<Mutex<usize>>,
    }

    impl Scope for Prefix {
        fn table(&self, collection: &str) -> String {
            format!("db.ns_{collection}")
        }

        fn join_table(&self, table: &str) -> String {
            self.table(table)
        }

        fn bind(&self, _collection: &str, _table: &str, _alias: &str) -> utopia_query::Result<ScopeBinding> {
            let resets = self.resets.clone();
            Ok(ScopeBinding {
                hooks: vec![utopia_query::hook::Map::new([("$id", "_uid")]).into()],
                before_each_build: vec![Arc::new(move || *resets.lock().unwrap() += 1)],
            })
        }

        fn error(&self, message: String) -> utopia_query::Error {
            builder::query_error(Error::query(message))
        }
    }

    fn scoped() -> (utopia_query::builder::MySql, Arc<Mutex<usize>>) {
        let resets = Arc::new(Mutex::new(0));
        let mut b = builder::mysql();
        b.scope(Arc::new(Prefix { resets: resets.clone() })).unwrap();
        (b, resets)
    }

    #[test]
    fn from_reads_the_collection_through_the_scope() {
        let (mut b, resets) = scoped();
        let join = Query::join("other", "o", vec![Query::on("main._uid", "o._uid", "=")]).unwrap();
        let built =
            b.from("users", "main").queries([join]).filter([Query::equal("$id", strings(&["a"]))]).build().unwrap();
        assert_eq!(
            built.query,
            "SELECT * FROM `db`.`ns_users` AS `main` JOIN `db`.`ns_other` AS `o` ON `main`.`_uid` = `o`.`_uid` WHERE `main`.`_uid` IN (?)"
        );
        assert_eq!(*resets.lock().unwrap(), 1);
        assert_eq!(b.pending()[0].attribute(), "other", "the join keeps the collection id it was given");
        b.build().unwrap();
        assert_eq!(*resets.lock().unwrap(), 2);
    }

    #[test]
    fn a_refusal_is_taken_from_the_call_that_made_it() {
        let (mut b, _) = scoped();
        b.from("users", "");
        assert!(b.take_refusal().is_none());
        b.from("users", "u");
        let error = database_error(b.take_refusal().unwrap());
        assert_eq!(error.message(), "The builder reads collection 'users', not 'users' as 'u': start another builder");
        b.from("users", "");
        assert!(b.take_refusal().is_none(), "the same collection and alias keep the binding");
        b.build().unwrap();
    }

    #[test]
    fn a_bound_builder_refuses_another_collection() {
        let (mut b, _) = scoped();
        b.from("users", "main");
        let error = database_error(b.from("posts", "").build().unwrap_err());
        assert_eq!(
            error.message(),
            "The builder reads collection 'users' as 'main', not 'posts': start another builder"
        );
        let (mut b, _) = scoped();
        b.from("users", "");
        let error = database_error(b.from_table("raw", "").err().unwrap());
        assert_eq!(
            error.message(),
            "The builder reads collection 'users' and cannot read table 'raw': start another builder"
        );
        let (mut b, _) = scoped();
        b.from("users", "");
        let error = database_error(utopia_query::builder::Builder::into(&mut b, "raw").insert().unwrap_err());
        assert_eq!(
            error.message(),
            "The builder reads collection 'users' and cannot insert into table 'raw': start another builder"
        );
        let (mut b, _) = scoped();
        b.from("users", "");
        let error = database_error(b.update_join("other", "a", "b", "").update().unwrap_err());
        assert_eq!(
            error.message(),
            "The builder reads collection 'users' and cannot update joining table 'other': start another builder"
        );
    }

    #[test]
    fn reset_keeps_the_scope() {
        let (mut b, _) = scoped();
        b.from("users", "");
        b.reset();
        assert_eq!(b.from("users", "").build().unwrap().query, "SELECT * FROM `db`.`ns_users`");
    }
}

// PHP: tests/unit/Builder/FloatOverflowTest.php (8.3.0): MySQL and MariaDB compile each floating aggregate through
// the engine's range check (`RaisesFloatOverflow`), PostgreSQL leaves them as they are.
mod float_overflow {
    use utopia_database::Query;
    use utopia_database::builder::{self, SqlBuilder};

    fn compiled(mut builder: SqlBuilder, query: Query) -> String {
        builder.compile_aggregate(&query).unwrap()
    }

    // `testAFloatingAggregateIsCompiledThroughTheEngineRangeCheck`.
    #[test]
    fn a_floating_aggregate_is_compiled_through_the_engine_range_check() {
        for make in [|| SqlBuilder::MariaDb(builder::mariadb()), || SqlBuilder::MySql(builder::mysql())] {
            assert_eq!(compiled(make(), Query::sum("reading", "total")), "SUM(`reading`) + 0 AS `total`");
            assert_eq!(compiled(make(), Query::avg("reading", "")), "AVG(`reading`) + 0 AS `AVG(``reading``)`");
            assert_eq!(
                compiled(make(), Query::stddev_pop("reading", "spread")),
                "SQRT(VAR_POP(`reading`) + 0) AS `spread`"
            );
            assert_eq!(compiled(make(), Query::count("reading", "n")), "COUNT(`reading`) AS `n`");
        }
    }

    // `testPostgresRaisesAnOverflowItselfSoItsAggregatesAreLeftAsTheyAre`.
    #[test]
    fn postgres_aggregates_are_left_as_they_are() {
        let postgres = || SqlBuilder::PostgreSql(builder::postgres());
        assert_eq!(compiled(postgres(), Query::sum("reading", "total")), r#"SUM("reading") AS "total""#);
        assert_eq!(compiled(postgres(), Query::stddev_pop("reading", "total")), r#"STDDEV_POP("reading") AS "total""#);
    }
}
