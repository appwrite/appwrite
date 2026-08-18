//! Ports `tests/Query/QueryTest.php`, `MethodTest.php`, Exception tests, and API helpers.

use serde_json::json;
use utopia_query::builder::{Builder, MySql, Statement};
use utopia_query::enums::{CursorDirection, NullsPosition, OrderDirection};
use utopia_query::error::QueryError;
use utopia_query::method::Method;
use utopia_query::query::{FingerprintInput, Query};
use utopia_query::value::QueryValue;

#[test]
fn constructor_defaults() {
    let query = Query::new("equal", "", ());
    assert_eq!(query.get_method(), Method::Equal);
    assert_eq!(query.get_attribute(), "");
    assert!(query.get_values().is_empty());
}

#[test]
fn constructor_with_all_params() {
    let query = Query::new("equal", "name", ["John"]);
    assert_eq!(query.get_method(), Method::Equal);
    assert_eq!(query.get_attribute(), "name");
    assert_eq!(query.get_values(), &[QueryValue::from("John")]);
}

#[test]
fn constructor_order_asc_default_attribute() {
    let query = Query::new_method(Method::OrderAsc);
    assert_eq!(query.get_attribute(), "");
}

#[test]
fn get_value_and_defaults() {
    let query = Query::new("equal", "name", ["John", "Jane"]);
    assert_eq!(query.get_value(), QueryValue::from("John"));
    let empty = Query::new("equal", "name", ());
    assert_eq!(empty.get_value_or("fallback"), QueryValue::from("fallback"));
    assert_eq!(empty.get_value(), QueryValue::Null);
}

#[test]
fn setters_are_fluent() {
    let mut query = Query::new("equal", "name", ["John"]);
    query.set_method("notEqual");
    assert_eq!(query.get_method(), Method::NotEqual);
    query.set_attribute("age");
    assert_eq!(query.get_attribute(), "age");
    query.set_values(["Jane", "Doe"]);
    assert_eq!(
        query.get_values(),
        &[QueryValue::from("Jane"), QueryValue::from("Doe")]
    );
    query.set_value("Only");
    assert_eq!(query.get_values(), &[QueryValue::from("Only")]);
}

#[test]
fn attribute_type_and_on_array() {
    let mut query = Query::new("equal", "name", ());
    query.set_attribute_type("string");
    assert_eq!(query.get_attribute_type(), "string");
    assert!(!query.on_array());
    query.set_on_array(true);
    assert!(query.on_array());
}

#[test]
fn method_enum_values() {
    assert_eq!(OrderDirection::Asc.as_str(), "ASC");
    assert_eq!(OrderDirection::Desc.as_str(), "DESC");
    assert_eq!(OrderDirection::Random.as_str(), "RANDOM");
    assert_eq!(CursorDirection::After.as_str(), "after");
    assert_eq!(CursorDirection::Before.as_str(), "before");
}

#[test]
fn vector_methods_are_vector() {
    assert!(Method::VectorDot.is_vector());
    assert!(Method::VectorCosine.is_vector());
    assert!(Method::VectorEuclidean.is_vector());
    let count = Method::cases().iter().filter(|m| m.is_vector()).count();
    assert_eq!(count, 3);
}

#[test]
fn all_method_cases_are_valid() {
    assert!(Query::is_method(Method::Equal.as_str()));
    assert!(Query::is_method(Method::Regex.as_str()));
    assert!(Query::is_method(Method::And.as_str()));
    assert!(Query::is_method(Method::Or.as_str()));
    assert!(Query::is_method(Method::ElemMatch.as_str()));
    assert!(Query::is_method(Method::VectorDot.as_str()));
}

#[test]
fn fingerprint_same_shape() {
    let equal_alice = r#"{"method":"equal","attribute":"name","values":["Alice"]}"#;
    let equal_bob = r#"{"method":"equal","attribute":"name","values":["Bob"]}"#;
    let equal_email = r#"{"method":"equal","attribute":"email","values":["a@b.c"]}"#;
    let not_equal_alice = r#"{"method":"notEqual","attribute":"name","values":["Alice"]}"#;
    let gt_age_18 = r#"{"method":"greaterThan","attribute":"age","values":[18]}"#;
    let gt_age_42 = r#"{"method":"greaterThan","attribute":"age","values":[42]}"#;

    let fp_alice_age = Query::fingerprint(&[
        FingerprintInput::Str(equal_alice),
        FingerprintInput::Str(gt_age_18),
    ])
    .unwrap();
    let fp_bob_age = Query::fingerprint(&[
        FingerprintInput::Str(equal_bob),
        FingerprintInput::Str(gt_age_42),
    ])
    .unwrap();
    assert_eq!(fp_alice_age, fp_bob_age);

    let fp_email = Query::fingerprint(&[
        FingerprintInput::Str(equal_email),
        FingerprintInput::Str(gt_age_18),
    ])
    .unwrap();
    assert_ne!(fp_alice_age, fp_email);

    let fp_not_equal = Query::fingerprint(&[
        FingerprintInput::Str(not_equal_alice),
        FingerprintInput::Str(gt_age_18),
    ])
    .unwrap();
    assert_ne!(fp_alice_age, fp_not_equal);

    let fp_reordered = Query::fingerprint(&[
        FingerprintInput::Str(gt_age_18),
        FingerprintInput::Str(equal_alice),
    ])
    .unwrap();
    assert_eq!(fp_alice_age, fp_reordered);

    let parsed = [
        Query::equal("name", ["Alice"]),
        Query::greater_than("age", 18),
    ];
    let fp_parsed = Query::fingerprint_queries(&parsed).unwrap();
    assert_eq!(fp_alice_age, fp_parsed);

    assert_eq!(
        Query::fingerprint(&[]).unwrap(),
        format!("{:x}", md5::compute(b""))
    );
}

#[test]
fn fingerprint_nested_logical() {
    let and_eq_name = Query::and([Query::equal("name", ["Alice"])]);
    let and_eq_email = Query::and([Query::equal("email", ["a@b.c"])]);
    assert_ne!(
        Query::fingerprint_queries(&[and_eq_name.clone()]).unwrap(),
        Query::fingerprint_queries(&[and_eq_email]).unwrap()
    );
    let and_eq_name_bob = Query::and([Query::equal("name", ["Bob"])]);
    assert_eq!(
        Query::fingerprint_queries(&[and_eq_name.clone()]).unwrap(),
        Query::fingerprint_queries(&[and_eq_name_bob]).unwrap()
    );
}

#[test]
fn shape_leaf_and_logical() {
    assert_eq!(Query::equal("name", ["Alice"]).shape(), "equal:name");
    assert_eq!(Query::greater_than("age", 18).shape(), "greaterThan:age");
    let and_q = Query::and([
        Query::equal("name", ["Alice"]),
        Query::greater_than("age", 18),
    ]);
    assert_eq!(and_q.shape(), "and:(equal:name|greaterThan:age)");
    let elem = Query::elem_match("tags", [Query::equal("name", ["php"])]);
    assert_eq!(elem.shape(), "elemMatch:tags(equal:name)");
}

#[test]
fn parse_valid_json() {
    let query = Query::parse(r#"{"method":"equal","attribute":"name","values":["John"]}"#).unwrap();
    assert_eq!(query.get_method(), Method::Equal);
    assert_eq!(query.get_attribute(), "name");
    assert_eq!(query.get_values(), &[QueryValue::from("John")]);
}

#[test]
fn parse_invalid_json() {
    let err = Query::parse("not json").unwrap_err();
    assert!(err.get_message().starts_with("Invalid query"));
}

#[test]
fn parse_invalid_method() {
    let err = Query::parse(r#"{"method":"foobar","attribute":"x","values":[]}"#).unwrap_err();
    assert_eq!(err.get_message(), "Invalid query method: foobar");
}

#[test]
fn parse_invalid_method_type() {
    let err = Query::parse(r#"{"method":123,"attribute":"x","values":[]}"#).unwrap_err();
    assert!(err
        .get_message()
        .starts_with("Invalid query method. Must be a string"));
}

#[test]
fn parse_rejects_raw_by_default() {
    let err = Query::parse(r#"{"method":"raw","attribute":"1=1","values":[]}"#).unwrap_err();
    assert!(err.is_validation());
    assert!(err.get_message().contains("Raw queries cannot be parsed"));
}

#[test]
fn to_array_and_string() {
    let query = Query::equal("name", ["John"]);
    let array = query.to_array();
    assert_eq!(array["method"], "equal");
    assert_eq!(array["attribute"], "name");
    assert_eq!(array["values"], json!(["John"]));
    let s = query.to_string().unwrap();
    let parsed = Query::parse(&s).unwrap();
    assert_eq!(parsed.get_method(), query.get_method());
    assert_eq!(parsed.get_attribute(), query.get_attribute());
}

#[test]
fn to_array_empty_attribute() {
    let query = Query::limit(25);
    let array = query.to_array();
    assert!(array.get("attribute").is_none());
    assert_eq!(array["method"], "limit");
    assert_eq!(array["values"], json!([25]));
}

#[test]
fn is_method_valid_and_invalid() {
    assert!(Query::is_method("equal"));
    assert!(Query::is_method("or"));
    assert!(Query::is_method("raw"));
    assert!(!Query::is_method("invalid"));
    assert!(!Query::is_method(""));
    assert!(!Query::is_method("EQUAL"));
}

#[test]
fn spatial_and_nested_flags() {
    assert!(Query::crosses("geo", vec![0, 0]).is_spatial_query());
    assert!(!Query::equal("name", ["x"]).is_spatial_query());
    assert!(Query::or([Query::equal("x", [1])]).is_nested());
    assert!(!Query::equal("x", [1]).is_nested());
}

#[test]
fn factories() {
    assert_eq!(Query::distinct().get_method(), Method::Distinct);
    let raw = Query::raw("score > ?", [10]);
    assert_eq!(raw.get_method(), Method::Raw);
    assert_eq!(raw.get_attribute(), "score > ?");
    assert_eq!(
        Query::union([Query::equal("x", [1])]).get_method(),
        Method::Union
    );
    assert!(Query::having([Query::equal("x", [1])]).is_nested());
}

#[test]
fn page_and_validate() {
    let [limit, offset] = Query::page(2, 25).unwrap();
    assert_eq!(limit.get_method(), Method::Limit);
    assert_eq!(offset.get_value(), QueryValue::Int(25));
    assert!(Query::page(0, 25).is_err());
    let errors = Query::validate(&[Query::equal("secret", ["x"])], &["name"]);
    assert_eq!(errors.len(), 1);
}

#[test]
fn merge_and_diff() {
    let a = vec![Query::equal("name", ["John"])];
    let b = vec![Query::greater_than("age", 18)];
    let merged = Query::merge(&a, &b);
    assert_eq!(merged.len(), 2);
    assert_eq!(merged[0].get_method(), Method::Equal);
    assert_eq!(merged[1].get_method(), Method::GreaterThan);

    let a = vec![Query::limit(10)];
    let b = vec![Query::limit(50)];
    let merged = Query::merge(&a, &b);
    assert_eq!(merged.len(), 1);
    assert_eq!(merged[0].get_value(), QueryValue::Int(50));

    let shared = Query::equal("name", ["John"]);
    let a = vec![shared.clone(), Query::greater_than("age", 18)];
    let b = vec![shared];
    let diff = Query::diff(&a, &b);
    assert_eq!(diff.len(), 1);
    assert_eq!(diff[0].get_method(), Method::GreaterThan);
}

#[test]
fn exception_codes() {
    let e = QueryError::exception_with_code("test", "42");
    assert_eq!(e.get_code(), 42);
    let e = QueryError::exception_with_code("test", "abc");
    assert_eq!(e.get_code(), 0);
    let e = QueryError::exception_with_code("test", 123i32);
    assert_eq!(e.get_code(), 123);
    let e = QueryError::exception("test");
    assert_eq!(e.get_code(), 0);
    assert_eq!(e.get_message(), "test");
}

#[test]
fn method_sql_functions() {
    assert_eq!(Method::Sum.sql_function(), Some("SUM"));
    assert_eq!(Method::Count.sql_function(), Some("COUNT"));
    assert_eq!(Method::CountDistinct.sql_function(), Some("COUNT"));
    assert_eq!(Method::StddevPop.sql_function(), Some("STDDEV_POP"));
    assert_eq!(Method::BitXor.sql_function(), Some("BIT_XOR"));
    assert_eq!(Method::Equal.sql_function(), None);
    for method in Method::cases() {
        if method.is_aggregate() {
            assert!(method.sql_function().is_some(), "{}", method.as_str());
        }
    }
}

#[test]
fn mysql_fluent_select() {
    let mut b = MySql::new();
    b.select(["name", "email"])
        .from_table("users")
        .filter([
            Query::equal("status", ["active"]),
            Query::greater_than("age", 18),
        ])
        .sort_asc("name", None)
        .limit(25)
        .offset(0);
    let result = b.build().unwrap();
    assert_eq!(
        result.query,
        "SELECT `name`, `email` FROM `users` WHERE `status` IN (?) AND `age` > ? ORDER BY `name` ASC LIMIT ? OFFSET ?"
    );
    assert_eq!(
        result.bindings,
        vec![
            QueryValue::from("active"),
            QueryValue::Int(18),
            QueryValue::Int(25),
            QueryValue::Int(0),
        ]
    );
    assert_binding_count(&result);
}

#[test]
fn mysql_standalone_compile() {
    let mut builder = Builder::mysql();
    let filter = Query::greater_than("age", 18);
    let sql = filter.compile(&mut builder).unwrap();
    assert_eq!(sql, "`age` > ?");
    assert_eq!(builder.get_bindings(), vec![QueryValue::Int(18)]);
}

#[test]
fn mysql_equal_not_equal_like() {
    let mut b = MySql::new();
    b.from_table("users")
        .filter([Query::equal("status", ["active"])]);
    let r = b.build().unwrap();
    assert_eq!(r.query, "SELECT * FROM `users` WHERE `status` IN (?)");
    assert_eq!(r.bindings, vec![QueryValue::from("active")]);

    let mut b = MySql::new();
    b.from_table("users")
        .filter([Query::not_equal("role", "guest")]);
    let r = b.build().unwrap();
    assert_eq!(r.query, "SELECT * FROM `users` WHERE `role` != ?");

    let mut b = MySql::new();
    b.from_table("users")
        .filter([Query::starts_with("email", "admin")]);
    let r = b.build().unwrap();
    assert_eq!(r.query, "SELECT * FROM `users` WHERE `email` LIKE ?");
    assert_eq!(r.bindings, vec![QueryValue::from("admin%")]);
}

#[test]
fn mysql_search_and_null() {
    let mut b = MySql::new();
    b.from_table("docs")
        .filter([Query::search("content", "hello")]);
    let r = b.build().unwrap();
    assert!(r
        .query
        .contains("MATCH(`content`) AGAINST(? IN BOOLEAN MODE)"));

    let mut b = MySql::new();
    b.from_table("users").filter([Query::is_null("deletedAt")]);
    let r = b.build().unwrap();
    assert_eq!(r.query, "SELECT * FROM `users` WHERE `deletedAt` IS NULL");
}

#[test]
fn mysql_insert_update_delete() {
    let mut b = MySql::new();
    b.into_table("users").set_pairs([
        ("name", QueryValue::from("Ada")),
        ("age", QueryValue::Int(36)),
    ]);
    let r = b.insert().unwrap();
    assert!(r.query.starts_with("INSERT INTO `users`"));
    assert_eq!(r.bindings.len(), 2);

    let mut b = MySql::new();
    b.from_table("users")
        .set_pairs([("name", QueryValue::from("Ada"))])
        .filter([Query::equal("id", [1])]);
    let r = b.update().unwrap();
    assert!(r.query.starts_with("UPDATE `users` SET"));
    assert!(r.query.contains("WHERE"));

    let mut b = MySql::new();
    b.from_table("users").filter([Query::equal("id", [1])]);
    let r = b.delete().unwrap();
    assert_eq!(r.query, "DELETE FROM `users` WHERE `id` IN (?)");
}

#[test]
fn empty_select_is_star() {
    let mut b = MySql::new();
    b.select(Vec::<&str>::new()).from_table("t");
    let r = b.build().unwrap();
    assert!(r.bindings.is_empty());
    assert!(!r.query.is_empty());
}

#[test]
fn postgres_and_sqlite_quote() {
    let mut b = utopia_query::builder::PostgreSql::new();
    b.from_table("users").filter([Query::equal("id", [1])]);
    let r = b.build().unwrap();
    assert!(r.query.contains("\"users\""));

    let mut b = utopia_query::builder::Sqlite::new();
    b.from_table("users");
    let r = b.build().unwrap();
    assert_eq!(r.query, "SELECT * FROM \"users\"");
}

#[test]
fn tokenizer_and_parser() {
    let mut tok = utopia_query::tokenizer::Tokenizer::mysql();
    let tokens = tok.tokenize("SELECT id FROM users WHERE age > 18").unwrap();
    let filtered = utopia_query::tokenizer::Tokenizer::filter(tokens);
    let mut parser = utopia_query::ast::Parser::new();
    let stmt = parser.parse(filtered).unwrap();
    assert!(!stmt.columns.is_empty());
    let sql = utopia_query::ast::Serializer::mysql().serialize(&stmt);
    assert!(sql.contains("SELECT"));
}

#[test]
fn classifier_sql() {
    use utopia_query::classifier::{Classifier, PostgresClassifier, SqlClassifier};
    use utopia_query::Type;
    let c = SqlClassifier;
    assert_eq!(c.classify("SELECT 1"), Type::Read);
    assert_eq!(c.classify("INSERT INTO t VALUES (1)"), Type::Write);
    assert_eq!(c.classify("BEGIN"), Type::TransactionBegin);
    assert_eq!(c.classify("COMMIT"), Type::TransactionEnd);
    assert_eq!(c.classify("SET TRANSACTION"), Type::Transaction);
    assert_eq!(c.classify_sql("   \t\n  SELECT * FROM users"), Type::Read);
    assert_eq!(
        c.classify_sql("-- this is a comment\nSELECT * FROM users"),
        Type::Read
    );
    assert_eq!(
        c.classify_sql("/* block comment */ SELECT * FROM users"),
        Type::Read
    );
    assert_eq!(c.classify_sql(""), Type::Unknown);
    assert_eq!(c.classify_sql("   \t\n  "), Type::Unknown);
    assert_eq!(c.classify_sql("-- just a comment"), Type::Unknown);
    assert_eq!(c.classify_sql("SELECT(1)"), Type::Read);
    assert_eq!(c.classify_sql("COPY t TO STDOUT"), Type::Read);
    assert_eq!(c.classify_sql("COPY t FROM STDIN"), Type::Write);
    assert_eq!(
        c.classify_sql("WITH cte AS (SELECT 1) SELECT * FROM cte"),
        Type::Read
    );
    assert_eq!(
        c.classify_sql("WITH cte AS (SELECT 1) INSERT INTO t SELECT * FROM cte"),
        Type::Write
    );
    let pg = PostgresClassifier;
    assert_eq!(pg.classify_sql("BEGIN"), Type::TransactionBegin);
}

#[test]
fn schema_create_table() {
    use utopia_query::schema::{Column, ColumnType, Schema};
    let schema = Schema::mysql();
    let mut col = Column::new("id", ColumnType::Integer);
    col.primary().not_null();
    let stmt = schema.table("users").column(col.clone()).create().unwrap();
    assert!(stmt.query.contains("CREATE TABLE"));
    assert!(stmt.query.contains("`id`"));
}

#[test]
fn quotes_control_character() {
    let err = utopia_query::quotes::quote_identifier('`', "a\nb").unwrap_err();
    assert!(err.get_message().contains("control character"));
}

#[test]
fn group_by_time_bucket_validation() {
    assert!(Query::group_by_time_bucket("ts", "1h").is_ok());
    let err = Query::group_by_time_bucket("ts", "2h").unwrap_err();
    assert!(err
        .get_message()
        .contains("Invalid groupByTimeBucket interval"));
}

fn assert_binding_count(result: &Statement) {
    let placeholders = count_placeholders(&result.query);
    assert_eq!(
        placeholders,
        result.bindings.len(),
        "Placeholder count ({placeholders}) != binding count ({}) Query: {}",
        result.bindings.len(),
        result.query
    );
}

fn count_placeholders(sql: &str) -> usize {
    let bytes = sql.as_bytes();
    let mut n = 0;
    for i in 0..bytes.len() {
        if bytes[i] != b'?' {
            continue;
        }
        let prev_ok = i == 0 || bytes[i - 1] != b'?';
        let next_ok = !matches!(bytes.get(i + 1), Some(b'|' | b'&' | b'?'));
        if prev_ok && next_ok {
            n += 1;
        }
    }
    n
}

#[test]
fn order_nulls_position() {
    let q = Query::order_asc("deletedAt", Some(NullsPosition::Last));
    let mut b = MySql::new();
    b.from_table("users").filter([q.clone()]);
    // order queries are not filters; use sort
    let mut b = MySql::new();
    b.from_table("users")
        .sort_asc("deletedAt", Some(NullsPosition::Last));
    let r = b.build().unwrap();
    assert!(r.query.contains("NULLS LAST"));
}

#[test]
fn shape_deeply_nested() {
    let deep = Query::and([
        Query::or([
            Query::equal("a", ["x"]),
            Query::and([Query::equal("b", ["y"]), Query::less_than("c", 5)]),
        ]),
        Query::greater_than("d", 10),
    ]);
    assert_eq!(
        deep.shape(),
        "and:(greaterThan:d|or:(and:(equal:b|lessThan:c)|equal:a))"
    );
}

#[test]
fn mysql_cte_and_union() {
    let mut cte = MySql::new();
    cte.select(["id"]).from_table("users");
    let mut b = MySql::new();
    b.with("u", cte, vec![]).unwrap().from_table("u");
    let r = b.build().unwrap();
    assert!(r.query.starts_with("WITH "));
    assert!(r.query.contains("AS ("));

    let mut a = MySql::new();
    a.select(["id"]).from_table("a");
    let mut extra = MySql::new();
    extra.select(["id"]).from_table("b");
    a.union(extra).unwrap();
    let r = a.build().unwrap();
    assert!(r.query.contains("UNION"));
}

#[test]
fn mysql_where_raw_and_cast() {
    let mut b = MySql::new();
    b.from_table("users")
        .where_raw("score > ?", vec![QueryValue::Int(10)]);
    let r = b.build().unwrap();
    assert!(r.query.contains("score > ?"));
    assert_eq!(r.bindings, vec![QueryValue::Int(10)]);

    let mut b = MySql::new();
    b.select_cast("age", "UNSIGNED", "age_u")
        .unwrap()
        .from_table("users");
    let r = b.build().unwrap();
    assert!(r.query.contains("CAST(`age` AS UNSIGNED)"));
}

#[test]
fn empty_in_and_not_in() {
    let mut b = MySql::new();
    b.from_table("users")
        .filter([Query::equal("id", Vec::<i64>::new())]);
    let r = b.build().unwrap();
    assert!(r.query.contains("1 = 0"));

    let mut b = MySql::new();
    b.from_table("users")
        .filter([Query::not_equal("id", QueryValue::Array(vec![]))]);
    let r = b.build().unwrap();
    assert!(r.query.contains("1 = 1"));
}

#[test]
fn integration_mysql_requires_dsn() {
    std::net::TcpStream::connect(("127.0.0.1", 8706))
        .expect("MySQL container (docker compose -f docker-compose.test.yml up -d mysql)");
}

#[test]
fn integration_postgres_requires_dsn() {
    std::net::TcpStream::connect(("127.0.0.1", 8701))
        .expect("Postgres container (docker compose -f docker-compose.test.yml up -d postgres)");
}

#[test]
fn integration_clickhouse_requires_dsn() {
    std::net::TcpStream::connect(("127.0.0.1", 8124)).expect(
        "ClickHouse container (docker compose -f docker-compose.test.yml up -d clickhouse)",
    );
}

#[test]
fn postgres_distinct_on_and_returning() {
    let mut b = utopia_query::builder::PostgreSql::new();
    b.distinct_on(vec!["email".into()])
        .from_table("users")
        .sort_asc("createdAt", None);
    let r = b.build().unwrap();
    assert!(r.query.starts_with("SELECT DISTINCT ON (\"email\")"));

    let mut b = utopia_query::builder::PostgreSql::new();
    b.from_table("users")
        .set_pairs([("name", QueryValue::from("Ada"))])
        .filter([Query::equal("id", [1])])
        .returning(vec!["id".into()]);
    let r = b.update().unwrap();
    assert!(r.query.contains("RETURNING"));
}

#[test]
fn clickhouse_limit_by() {
    let mut b = utopia_query::builder::ClickHouse::new();
    b.from_table("events")
        .limit(10)
        .limit_by(1, vec!["user_id".into()]);
    let r = b.build().unwrap();
    assert!(r.query.contains("LIMIT ? BY"));
}

#[test]
fn integration_mongodb_requires_uri() {
    std::net::TcpStream::connect(("127.0.0.1", 27018))
        .expect("MongoDB container (docker compose -f docker-compose.test.yml up -d mongo)");
}
