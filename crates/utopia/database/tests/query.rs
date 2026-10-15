//! Ported from utopia-php/database 8.1.3 `tests/unit/QueryTest.php` and
//! `tests/unit/QuerySerializationTest.php`.
//!
//! Not ported:
//! - `QueryTest::test_is_method` with `Method` cases (Rust's `is_method`
//!   takes a name; a `Method` value is always a method).
//! - `QueryTest::testFingerprintRejectsInvalidElements`: an element that is
//!   neither a string nor a query cannot be built in Rust ([`QueryOrJson`]).
//! - `QuerySerializationTest::testDocumentCursorSerialisesToItsId` and
//!   `testArrayCursorIsRefusedByTheCursorValidator`: their `Validator\Query\Cursor`
//!   half (that validator is ported elsewhere); the `toArray()` half is ported.
//! - `QueryShapeDatabaseTest` and `QueryConversionTest`: every test needs a
//!   `Database` (SQLite or Memory adapter).

mod common;

use common::{arr, assert_error, document, list, v};
use utopia_database::value::{self as dbvalue, Value};
use utopia_database::{Document, Kind, Query};
use utopia_query::{Method, OrderDirection, QueryOrJson, Value as QValue};

fn qv(x: impl common::IntoValue) -> QValue {
    dbvalue::to_query(&v(x))
}

fn qlist(items: Vec<QValue>) -> utopia_query::Array {
    utopia_query::Array::from_list(items)
}

fn values(q: &Query) -> Vec<QValue> {
    q.values().values().cloned().collect()
}

fn child(q: &Query, i: i64) -> Query {
    match q.values().get(&utopia_database::value::ArrayKey::Int(i)) {
        Some(QValue::Ext(utopia_query::Object::Query(c))) => Query::from((**c).clone()),
        other => panic!("not a query: {other:?}"),
    }
}

fn round_trip(q: Query) -> Query {
    Query::parse(&q.to_json().unwrap(), false).unwrap()
}

// QueryTest

#[test]
fn test_create() {
    let q = Query::new(Method::Equal, "title", qlist(vec![qv("Iron Man")]), "");
    assert_eq!(q.method(), Method::Equal);
    assert_eq!(q.attribute(), "title");
    assert_eq!(q.value(), Some(&qv("Iron Man")));

    let q = Query::new(Method::OrderDesc, "score", qlist(vec![]), "");
    assert_eq!(q.method(), Method::OrderDesc);
    assert_eq!(q.attribute(), "score");
    assert!(q.values().is_empty());

    let q = Query::new(Method::Limit, "", qlist(vec![qv(10)]), "");
    assert_eq!(q.method(), Method::Limit);
    assert_eq!(q.attribute(), "");
    assert_eq!(q.value(), Some(&qv(10)));

    let q = Query::equal("title", ["Iron Man"]);
    assert_eq!((q.method(), q.attribute()), (Method::Equal, "title"));
    assert_eq!(q.value(), Some(&qv("Iron Man")));

    let q = Query::greater_than("score", 10);
    assert_eq!((q.method(), q.attribute()), (Method::GreaterThan, "score"));
    assert_eq!(q.value(), Some(&qv(10)));

    let vector = qlist(vec![qv(0.1), qv(0.2), qv(0.3)]);
    for (q, method) in [
        (Query::vector_dot("embedding", vector.clone()), Method::VectorDot),
        (Query::vector_cosine("embedding", vector.clone()), Method::VectorCosine),
        (Query::vector_euclidean("embedding", vector.clone()), Method::VectorEuclidean),
    ] {
        assert_eq!((q.method(), q.attribute()), (method, "embedding"));
        assert_eq!(values(&q), vec![QValue::Array(vector.clone())]);
    }

    let q = Query::search("search", "John Doe");
    assert_eq!((q.method(), q.attribute()), (Method::Search, "search"));
    assert_eq!(q.value(), Some(&qv("John Doe")));

    let q = Query::order_asc("score", None);
    assert_eq!((q.method(), q.attribute()), (Method::OrderAsc, "score"));
    assert!(q.values().is_empty());

    let q = Query::limit(10);
    assert_eq!((q.method(), q.attribute()), (Method::Limit, ""));
    assert_eq!(values(&q), vec![qv(10)]);

    let cursor = Document::empty();
    let q = Query::cursor_after(&cursor);
    assert_eq!((q.method(), q.attribute()), (Method::CursorAfter, ""));
    assert_eq!(q.values().len(), 1);
    assert_eq!(q.document_value(), Some(cursor));

    for (q, method) in [(Query::is_null("title"), Method::IsNull), (Query::is_not_null("title"), Method::IsNotNull)] {
        assert_eq!((q.method(), q.attribute()), (method, "title"));
        assert!(q.values().is_empty());
    }

    let q = Query::not_contains("tags", ["test", "example"]);
    assert_eq!((q.method(), q.attribute()), (Method::NotContains, "tags"));
    assert_eq!(values(&q), vec![qv("test"), qv("example")]);

    let q = Query::not_search("content", "keyword");
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::NotSearch, "content", vec![qv("keyword")]));
    let q = Query::not_starts_with("title", "prefix");
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::NotStartsWith, "title", vec![qv("prefix")]));
    let q = Query::not_ends_with("url", ".html");
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::NotEndsWith, "url", vec![qv(".html")]));
    let q = Query::not_between("score", 10, 20);
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::NotBetween, "score", vec![qv(10), qv(20)]));

    let start = "2023-01-01T00:00:00.000Z";
    let end = "2023-12-31T23:59:59.999Z";
    let q = Query::created_before(start);
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::LessThan, "$createdAt", vec![qv(start)]));
    let q = Query::created_after(start);
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::GreaterThan, "$createdAt", vec![qv(start)]));
    let q = Query::updated_before(end);
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::LessThan, "$updatedAt", vec![qv(end)]));
    let q = Query::updated_after(end);
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::GreaterThan, "$updatedAt", vec![qv(end)]));
    let q = Query::created_between(start, end);
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::Between, "$createdAt", vec![qv(start), qv(end)]));
    let q = Query::updated_between(start, end);
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::Between, "$updatedAt", vec![qv(start), qv(end)]));

    let q = Query::order_random();
    assert_eq!((q.method(), q.attribute()), (Method::OrderRandom, ""));
    assert!(q.values().is_empty());
}

#[test]
fn test_order_without_an_attribute_orders_by_sequence() {
    assert_eq!(Query::order_asc("", None).attribute(), "$sequence");
    assert_eq!(Query::order_desc("", None).attribute(), "$sequence");
    assert_eq!(Query::new(Method::OrderDesc, "", qlist(vec![]), "").attribute(), "$sequence");
    assert_eq!(Query::parse(r#"{"method":"orderAsc"}"#, false).unwrap().attribute(), "$sequence");
    let nested = Query::parse(r#"{"method":"and","values":[{"method":"orderDesc"}]}"#, false).unwrap();
    assert_eq!(child(&nested, 0).attribute(), "$sequence");
    assert_error(
        Query::named("nope", "", qlist(vec![]), ""),
        Kind::ValueError,
        "\"nope\" is not a valid backing value for enum Utopia\\Query\\Method",
    );
}

#[test]
fn test_parse() {
    let json = Query::equal("title", ["Iron Man"]).to_json().unwrap();
    assert_eq!(json, r#"{"method":"equal","attribute":"title","values":["Iron Man"]}"#);
    let q = Query::parse(&json, false).unwrap();
    assert_eq!((q.method(), q.attribute(), q.value()), (Method::Equal, "title", Some(&qv("Iron Man"))));

    let q = round_trip(Query::less_than("year", 2001));
    assert_eq!((q.method(), q.attribute(), q.value()), (Method::LessThan, "year", Some(&qv(2001))));

    let q = round_trip(Query::equal("published", [true]));
    assert_eq!((q.method(), q.attribute(), q.value()), (Method::Equal, "published", Some(&qv(true))));
    let q = round_trip(Query::equal("published", [false]));
    assert_eq!(q.value(), Some(&qv(false)));

    let q = round_trip(Query::equal("actors", [" Johnny Depp ", " Brad Pitt", "Al Pacino "]));
    assert_eq!(values(&q), vec![qv(" Johnny Depp "), qv(" Brad Pitt"), qv("Al Pacino ")]);
    let q = round_trip(Query::equal("actors", ["Brad Pitt", "Johnny Depp"]));
    assert_eq!(values(&q), vec![qv("Brad Pitt"), qv("Johnny Depp")]);

    let q = round_trip(Query::contains_string("writers", ["Tim O'Reilly"]));
    assert_eq!((q.method(), q.attribute(), q.value()), (Method::Contains, "writers", Some(&qv("Tim O'Reilly"))));

    let q = round_trip(Query::greater_than("score", 8.5));
    assert_eq!((q.method(), q.value()), (Method::GreaterThan, Some(&qv(8.5))));

    let q = round_trip(Query::not_contains("tags", ["unwanted", "spam"]));
    assert_eq!((q.method(), values(&q)), (Method::NotContains, vec![qv("unwanted"), qv("spam")]));
    let q = round_trip(Query::not_search("content", "unwanted content"));
    assert_eq!((q.method(), values(&q)), (Method::NotSearch, vec![qv("unwanted content")]));
    let q = round_trip(Query::not_starts_with("title", "temp"));
    assert_eq!((q.method(), values(&q)), (Method::NotStartsWith, vec![qv("temp")]));
    let q = round_trip(Query::not_ends_with("filename", ".tmp"));
    assert_eq!((q.method(), values(&q)), (Method::NotEndsWith, vec![qv(".tmp")]));
    let q = round_trip(Query::not_between("score", 0, 50));
    assert_eq!((q.method(), values(&q)), (Method::NotBetween, vec![qv(0), qv(50)]));
    let q = round_trip(Query::not_equal("director", "null"));
    assert_eq!((q.method(), q.value()), (Method::NotEqual, Some(&qv("null"))));
    let q = round_trip(Query::is_null("director"));
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::IsNull, "director", vec![]));
    let q = round_trip(Query::is_not_null("director"));
    assert_eq!((q.method(), values(&q)), (Method::IsNotNull, vec![]));
    let q = round_trip(Query::starts_with("director", "Quentin"));
    assert_eq!((q.method(), values(&q)), (Method::StartsWith, vec![qv("Quentin")]));
    let q = round_trip(Query::ends_with("director", "Tarantino"));
    assert_eq!((q.method(), values(&q)), (Method::EndsWith, vec![qv("Tarantino")]));
    let q = round_trip(Query::select(["title", "director"]));
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::Select, "", vec![qv("title"), qv("director")]));

    let start = "2023-01-01T00:00:00.000Z";
    let end = "2023-12-31T23:59:59.999Z";
    let q = round_trip(Query::created_before(start));
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::LessThan, "$createdAt", vec![qv(start)]));
    let q = round_trip(Query::created_after(start));
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::GreaterThan, "$createdAt", vec![qv(start)]));
    let q = round_trip(Query::updated_before(end));
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::LessThan, "$updatedAt", vec![qv(end)]));
    let q = round_trip(Query::updated_after(end));
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::GreaterThan, "$updatedAt", vec![qv(end)]));
    let q = round_trip(Query::created_between(start, end));
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::Between, "$createdAt", vec![qv(start), qv(end)]));
    let q = round_trip(Query::updated_between(start, end));
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::Between, "$updatedAt", vec![qv(start), qv(end)]));
    let q = round_trip(Query::between("age", 15, 18));
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::Between, "age", vec![qv(15), qv(18)]));
    let q = round_trip(Query::between("lastUpdate", "DATE1", "DATE2"));
    assert_eq!(values(&q), vec![qv("DATE1"), qv("DATE2")]);

    let q = round_trip(Query::equal("attr", [1]));
    assert_eq!((q.attribute(), values(&q)), ("attr", vec![qv(1)]));
    let q = round_trip(Query::equal("attr", [0]));
    assert_eq!((q.attribute(), values(&q)), ("attr", vec![qv(0)]));
    let q = round_trip(Query::equal("1", ["[Hello] World"]));
    assert_eq!((q.attribute(), values(&q)), ("1", vec![qv("[Hello] World")]));
    let q = round_trip(Query::equal("1", ["Hello /\\ World"]));
    assert_eq!((q.attribute(), values(&q)), ("1", vec![qv("Hello /\\ World")]));

    let json =
        Query::or([Query::equal("actors", ["Brad Pitt"]), Query::equal("actors", ["Johnny Depp"])]).to_json().unwrap();
    let q = Query::parse(&json, false).unwrap();
    assert_eq!(q.values().len(), 2);
    assert_eq!(q.method(), Method::Or);
    assert_eq!(child(&q, 0).method(), Method::Equal);
    assert_eq!(child(&q, 0).attribute(), "actors");
    assert_eq!(
        json,
        r#"{"method":"or","values":[{"method":"equal","attribute":"actors","values":["Brad Pitt"]},{"method":"equal","attribute":"actors","values":["Johnny Depp"]}]}"#
    );

    assert_error(
        Query::parse(r#"{"method":["equal"],"attribute":["title"],"values":["test"]}"#, false),
        Kind::Query,
        "Invalid query method. Must be a string, got array",
    );
    assert_error(
        Query::parse(r#"{"method":"equal","attribute":["title"],"values":["test"]}"#, false),
        Kind::Query,
        "Invalid query attribute. Must be a string, got array",
    );
    assert_error(
        Query::parse(r#"{"method":"equal","attribute":"title","values":"test"}"#, false),
        Kind::Query,
        "Invalid query values. Must be an array, got string",
    );
    assert_error(Query::parse("false", false), Kind::Query, "Invalid query. Must be an array, got boolean");

    let q = round_trip(Query::order_random());
    assert_eq!((q.method(), q.attribute(), values(&q)), (Method::OrderRandom, "", vec![]));

    let q = round_trip(Query::having([Query::greater_than_equal("total", 1)]));
    assert_eq!(q.method(), Method::Having);
    assert_eq!(q.values().len(), 1);
    assert_eq!(child(&q, 0).method(), Method::GreaterThanEqual);
    assert_eq!(child(&q, 0).attribute(), "total");
}

#[test]
fn test_parse_refuses_raw_queries() {
    assert_error(
        Query::parse(r#"{"method":"raw","attribute":"1 = 1"}"#, false),
        Kind::Query,
        "Invalid query method: raw",
    );
    assert!(Query::parse(r#"{"method":"raw","attribute":"1 = 1"}"#, true).is_ok());
    assert_error(Query::parse("{", false), Kind::Query, "Invalid query: Syntax error");
}

#[test]
fn test_parse_nested_string_values() {
    let json = format!(
        r#"{{"method":"or","values":[{},{}]}}"#,
        serde_json::to_string(&Query::equal("actors", ["Brad Pitt"]).to_json().unwrap()).unwrap(),
        serde_json::to_string(&Query::equal("actors", ["Johnny Depp"]).to_json().unwrap()).unwrap(),
    );
    let q = Query::parse(&json, false).unwrap();
    assert_eq!(q.method(), Method::Or);
    assert_eq!(q.values().len(), 2);
    assert_eq!(child(&q, 0).method(), Method::Equal);
    assert_eq!(child(&q, 0).attribute(), "actors");
    assert_eq!(values(&child(&q, 0)), vec![qv("Brad Pitt")]);
    assert_eq!(child(&q, 1).method(), Method::Equal);
    assert_eq!(values(&child(&q, 1)), vec![qv("Johnny Depp")]);

    assert_error(
        Query::parse(r#"{"method":"or","values":[123]}"#, false),
        Kind::Query,
        "Invalid nested query. Must be an array or string, got integer",
    );
}

#[test]
fn test_is_method() {
    for name in [
        "equal",
        "notEqual",
        "lessThan",
        "lessThanEqual",
        "greaterThan",
        "greaterThanEqual",
        "contains",
        "notContains",
        "search",
        "notSearch",
        "startsWith",
        "notStartsWith",
        "endsWith",
        "notEndsWith",
        "orderDesc",
        "orderAsc",
        "limit",
        "offset",
        "cursorAfter",
        "cursorBefore",
        "orderRandom",
        "isNull",
        "isNotNull",
        "between",
        "notBetween",
        "select",
        "or",
        "and",
    ] {
        assert!(Query::is_method(name), "{name}");
    }
    assert!(!Query::is_method("invalid"));
    assert!(!Query::is_method("lte "));
}

#[test]
fn test_new_query_types_in_types_array() {
    for m in [
        Method::NotContains,
        Method::NotSearch,
        Method::NotStartsWith,
        Method::NotEndsWith,
        Method::NotBetween,
        Method::OrderRandom,
    ] {
        assert!(Method::CASES.contains(&m));
    }
}

fn fingerprint(items: &[&str]) -> String {
    let queries: Vec<QueryOrJson<'_>> = items.iter().map(|s| QueryOrJson::Json(s)).collect();
    Query::fingerprint(&queries).unwrap()
}

fn fingerprint_of(queries: &[Query]) -> String {
    Query::fingerprint_queries(queries).unwrap()
}

#[test]
fn test_fingerprint() {
    let equal_alice = r#"{"method":"equal","attribute":"name","values":["Alice"]}"#;
    let equal_bob = r#"{"method":"equal","attribute":"name","values":["Bob"]}"#;
    let equal_email = r#"{"method":"equal","attribute":"email","values":["a@b.c"]}"#;
    let not_equal_alice = r#"{"method":"notEqual","attribute":"name","values":["Alice"]}"#;
    let gt_age18 = r#"{"method":"greaterThan","attribute":"age","values":[18]}"#;
    let gt_age42 = r#"{"method":"greaterThan","attribute":"age","values":[42]}"#;

    let a = fingerprint(&[equal_alice, gt_age18]);
    assert_eq!(a, fingerprint(&[equal_bob, gt_age42]));
    assert_ne!(a, fingerprint(&[equal_email, gt_age18]));
    assert_ne!(a, fingerprint(&[not_equal_alice, gt_age18]));
    assert_eq!(a, fingerprint(&[gt_age18, equal_alice]));

    let parsed = [Query::equal("name", ["Alice"]), Query::greater_than("age", 18)];
    assert_eq!(a, fingerprint_of(&parsed));
    let mixed = [QueryOrJson::Query(&parsed[0]), QueryOrJson::Json(gt_age18)];
    assert_eq!(a, Query::fingerprint(&mixed).unwrap());

    assert_eq!(fingerprint(&[]), "d41d8cd98f00b204e9800998ecf8427e");
}

#[test]
fn test_fingerprint_nested_logical_queries() {
    let fp = |q: Query| fingerprint_of(&[q]);
    let and_eq_name = Query::and([Query::equal("name", ["Alice"])]);
    let and_eq_email = Query::and([Query::equal("email", ["a@b.c"])]);
    assert_ne!(fp(and_eq_name.clone()), fp(and_eq_email));

    let and_eq_name_bob = Query::and([Query::equal("name", ["Bob"])]);
    assert_eq!(fp(and_eq_name.clone()), fp(and_eq_name_bob));

    let and_a = Query::and([Query::equal("name", ["Alice"]), Query::greater_than("age", 18)]);
    let and_b = Query::and([Query::greater_than("age", 42), Query::equal("name", ["Bob"])]);
    assert_eq!(fp(and_a.clone()), fp(and_b));

    let or_a = Query::or([Query::equal("name", ["Alice"]), Query::greater_than("age", 18)]);
    assert_ne!(fp(and_a.clone()), fp(or_a));

    assert_ne!(fp(and_eq_name), fp(and_a));

    let elem_tags = Query::elem_match("tags", [Query::equal("name", ["php"])]);
    let elem_categories = Query::elem_match("categories", [Query::equal("name", ["php"])]);
    assert_ne!(fp(elem_tags.clone()), fp(elem_categories));

    let elem_tags_other = Query::elem_match("tags", [Query::equal("name", ["js"])]);
    assert_eq!(fp(elem_tags), fp(elem_tags_other));
}

#[test]
fn test_shape() {
    assert_eq!(Query::equal("name", ["Alice"]).shape().unwrap(), "equal:name");
    assert_eq!(Query::greater_than("age", 18).shape().unwrap(), "greaterThan:age");

    let and = Query::and([Query::equal("name", ["Alice"]), Query::greater_than("age", 18)]);
    assert_eq!(and.shape().unwrap(), "and:(equal:name|greaterThan:age)");

    let elem = Query::elem_match("tags", [Query::equal("name", ["php"])]);
    assert_eq!(elem.shape().unwrap(), "elemMatch:tags(equal:name)");

    let deep = Query::and([
        Query::or([Query::equal("a", ["x"]), Query::and([Query::equal("b", ["y"]), Query::less_than("c", 5)])]),
        Query::greater_than("d", 10),
    ]);
    assert_eq!(deep.shape().unwrap(), "and:(greaterThan:d|or:(and:(equal:b|lessThan:c)|equal:a))");
}

#[test]
fn test_shape_keeps_a_shared_child_under_every_parent() {
    let shared = Query::equal("a", ["x"]);
    let tree = Query::and([shared.clone(), Query::or([shared, Query::greater_than("b", 1)])]);
    assert_eq!(tree.shape().unwrap(), "and:(equal:a|or:(equal:a|greaterThan:b))");
}

#[test]
fn test_shape_refuses_a_tree_that_would_explode_the_walk() {
    // PHP shares one child object 40 levels deep (2^40 nodes to walk); Rust
    // values are owned, so the tree is built just past the cap instead.
    let mut node = Query::equal("a", ["x"]);
    for _ in 0..13 {
        node = Query::and([node.clone(), node]);
    }
    let e = node.shape().unwrap_err();
    assert_eq!(e.kind(), Kind::Query);
    assert!(e.message().starts_with("Query is too deeply nested to fingerprint"), "{}", e.message());
    assert_eq!(
        e.message(),
        "Query is too deeply nested to fingerprint: exceeded 10000 nodes, which means a cycle or a child shared across too many parents"
    );
}

#[test]
fn test_group_by_type_keeps_the_orders_in_the_order_given() {
    let cursor = document! {"$id" => "first"};
    let parsed = Query::group_by_type(&[
        Query::order_desc("views", None),
        Query::equal("title", ["first"]),
        Query::order_asc("title", None),
        Query::limit(5),
        Query::cursor_after(&cursor),
    ])
    .unwrap();
    assert_eq!(parsed.order_attributes, ["views", "title"]);
    assert_eq!(parsed.order_types, [OrderDirection::Desc, OrderDirection::Asc]);
    assert_eq!(parsed.filters.len(), 1);
    assert_eq!(parsed.limit, Some(5));
    assert_eq!(parsed.cursor, Some(cursor));
}

#[test]
fn test_group_by_type_refuses_a_cursor_that_is_not_a_document() {
    assert_error(
        Query::group_by_type(&[Query::cursor_after_value(qv(arr! {"$id" => "first"}))]),
        Kind::Query,
        "Invalid query: Invalid cursor: a cursor must be a document, array given",
    );
}

#[test]
fn test_group_by_type_names_a_query_cursor_as_a_database_query() {
    let nested = utopia_query::Value::Ext(utopia_query::Object::Query(Box::new(Query::limit(1).into())));
    assert_error(
        Query::group_by_type(&[Query::cursor_after_value(nested)]),
        Kind::Query,
        "Invalid query: Invalid cursor: a cursor must be a document, Utopia\\Database\\Query given",
    );
}

#[test]
fn test_group_by_type_tells_an_aggregation_from_a_document_list() {
    let group = |qs: &[Query]| Query::group_by_type(qs).unwrap().is_aggregation();
    assert!(!group(&[Query::equal("category", ["news"]), Query::order_asc("views", None), Query::limit(2)]));
    assert!(!group(&[]));
    assert!(group(&[Query::count("*", "")]));
    assert!(group(&[Query::group_by(["category"])]));
}

#[test]
fn test_group_by_type_keeps_the_first_limit_offset_and_cursor() {
    let first = document! {"$id" => "a"};
    let parsed = Query::group_by_type(&[
        Query::limit(1),
        Query::limit(2),
        Query::offset(3),
        Query::offset(4),
        Query::cursor_before(&first),
        Query::cursor_after(&document! {"$id" => "b"}),
        Query::select(["x"]),
        Query::distinct(),
    ])
    .unwrap();
    assert_eq!(parsed.limit, Some(1));
    assert_eq!(parsed.offset, Some(3));
    assert_eq!(parsed.cursor, Some(first));
    assert_eq!(parsed.cursor_direction, Some(utopia_query::CursorDirection::Before));
    assert_eq!(parsed.selections.len(), 1);
    assert!(parsed.distinct);
}

// QuerySerializationTest

#[test]
fn test_parse_rejects_a_nested_query_whose_values_are_not_a_list() {
    // Messages as PHP 8.5 with utopia-php/database 8.1.3 reports them (the
    // object's string member is parsed as a nested query string).
    for (json, message) in [
        (r#"{"method":"and","values":"x"}"#, "Invalid query values. Must be an array, got string"),
        (r#"{"method":"or","values":5}"#, "Invalid query values. Must be an array, got integer"),
        (r#"{"method":"elemMatch","attribute":"items","values":{"method":"equal"}}"#, "Invalid query: Syntax error"),
    ] {
        assert_error(Query::parse(json, false), Kind::Query, message);
    }
}

#[test]
fn test_parse_refuses_nested_raw_queries() {
    for json in [
        r#"{"method":"and","values":[{"method":"raw","attribute":"1=1"}]}"#,
        r#"{"method":"and","values":["{\"method\":\"raw\",\"attribute\":\"1=1\"}"]}"#,
        r#"{"method":"leftJoin","attribute":"o","alias":"a","values":[{"method":"raw","attribute":"1=1"}]}"#,
    ] {
        assert_error(Query::parse(json, false), Kind::Query, "Invalid query method: raw");
    }
    let q = Query::parse(
        r#"{"method":"leftJoin","attribute":"o","alias":"a","values":[{"method":"and","values":["{\"method\":\"isNull\",\"attribute\":\"x\"}"]}]}"#,
        false,
    )
    .unwrap();
    assert_eq!(
        q.to_json().unwrap(),
        r#"{"method":"leftJoin","attribute":"o","alias":"a","values":[{"method":"and","values":[{"method":"isNull","attribute":"x","values":[]}]}]}"#
    );
    assert_eq!(Query::count("*", "").to_json().unwrap(), r#"{"method":"count","attribute":"*","values":[]}"#);
}

#[test]
fn test_parse_decodes_string_children_of_a_nested_query() {
    let q = Query::parse(
        r#"{"method":"and","values":["{\"method\":\"equal\",\"attribute\":\"a\",\"values\":[1]}",{"method":"equal","attribute":"b","values":[2]}]}"#,
        false,
    )
    .unwrap();
    assert_eq!(q.values().len(), 2);
    assert_eq!(child(&q, 0).attribute(), "a");
    assert_eq!(child(&q, 1).attribute(), "b");
}

#[test]
fn test_to_array_rejects_a_non_query_child() {
    for (method, bad, kind) in [
        (Method::Or, qv("x"), "string"),
        (Method::And, qv(arr! {"method" => "equal"}), "array"),
        (Method::Having, qv(7), "int"),
    ] {
        let q = Query::new(method, "", qlist(vec![QValue::from(Query::equal("a", ["x"]).into_inner()), bad]), "");
        assert_error(
            q.to_array(),
            Kind::Query,
            &format!("Invalid child query in {} at index 1: expected Query, got {kind}", method.as_str()),
        );
    }
}

#[test]
fn test_to_string_rejects_a_non_query_child() {
    let q = Query::new(Method::Or, "", qlist(vec![QValue::from(Query::equal("a", ["x"]).into_inner()), qv("x")]), "");
    assert_error(q.to_json(), Kind::Query, "Invalid child query in or at index 1: expected Query, got string");
}

fn qarr(a: common_arr::A) -> utopia_query::Array {
    match dbvalue::to_query(&Value::Array(a)) {
        QValue::Array(a) => a,
        _ => unreachable!(),
    }
}

mod common_arr {
    pub type A = utopia_database::value::Array;
}

#[test]
fn test_join_to_array_writes_the_alias_and_every_on_condition() {
    let join = Query::left_join(
        "orders",
        "ord",
        vec![Query::on("$id", "ord.user", "="), Query::equal("ord.status", ["paid"])],
    )
    .unwrap();
    assert_eq!(
        join.to_array().unwrap(),
        qarr(arr! {
            "method" => "leftJoin",
            "attribute" => "orders",
            "alias" => "ord",
            "values" => list![
                arr! {"method" => "on", "values" => list!["$id", "=", "ord.user"]},
                arr! {"method" => "equal", "attribute" => "ord.status", "values" => list!["paid"]},
            ],
        })
    );
}

#[test]
fn test_parsed_join_keeps_its_alias_and_on_conditions() {
    let join = Query::left_join(
        "orders",
        "ord",
        vec![Query::on("$id", "ord.user", "!="), Query::equal("ord.status", ["paid"])],
    )
    .unwrap();
    let parsed = Query::parse(&join.to_json().unwrap(), false).unwrap();
    assert_eq!(parsed.method(), Method::LeftJoin);
    assert_eq!(parsed.attribute(), "orders");
    assert_eq!(parsed.alias(), "ord");
    let on = parsed.join_on_queries();
    assert_eq!(on.len(), 2);
    assert_eq!(on[0].values().values().cloned().collect::<Vec<_>>(), vec![qv("$id"), qv("!="), qv("ord.user")]);
    assert_eq!(on[1].method(), Method::Equal);
    assert_eq!(join.to_array().unwrap(), parsed.to_array().unwrap());
}

#[test]
fn test_parsed_cross_join_keeps_its_alias() {
    let parsed = Query::parse_query(&Query::cross_join("regions", "r").unwrap().to_array().unwrap(), false).unwrap();
    assert_eq!(parsed.method(), Method::CrossJoin);
    assert_eq!(parsed.alias(), "r");
    assert!(parsed.values().is_empty());
}

#[test]
fn test_parsed_column_form_join_becomes_an_on_condition() {
    let parsed = Query::parse_query(
        &qarr(arr! {"method" => "join", "attribute" => "orders", "values" => list!["$id", ">", "ord.total", "ord"]}),
        false,
    )
    .unwrap();
    assert_eq!(parsed.alias(), "ord");
    assert_eq!(
        Query::join("orders", "ord", vec![Query::on("$id", "ord.total", ">")]).unwrap().to_array().unwrap(),
        parsed.to_array().unwrap()
    );
}

#[test]
fn test_parsed_join_without_an_alias_is_a_query_exception() {
    assert_error(
        Query::parse_query(
            &qarr(arr! {
                "method" => "join",
                "attribute" => "orders",
                "values" => list![arr! {"method" => "on", "values" => list!["$id", "=", "user"]}],
            }),
            false,
        ),
        Kind::Query,
        "Join alias is required: join orders",
    );
}

#[test]
fn test_parsed_aggregate_keeps_its_alias() {
    for (array, alias) in [
        (Query::sum("price", "revenue").to_array().unwrap(), "revenue"),
        (qarr(arr! {"method" => "sum", "attribute" => "price", "values" => list!["revenue"]}), "revenue"),
        (Query::sum("price", "").to_array().unwrap(), ""),
    ] {
        let parsed = Query::parse_query(&array, false).unwrap();
        assert_eq!(parsed.method(), Method::Sum);
        assert_eq!(parsed.attribute(), "price");
        assert_eq!(parsed.alias(), alias);
        assert!(parsed.values().is_empty());
    }
}

#[test]
fn test_document_cursor_serialises_to_its_id() {
    let cursor = Query::cursor_after(&document! {"$id" => "doc1"});
    assert_eq!(cursor.to_array().unwrap(), qarr(arr! {"method" => "cursorAfter", "values" => list!["doc1"]}));
    assert_eq!(cursor.to_json().unwrap(), r#"{"method":"cursorAfter","values":["doc1"]}"#);
}

#[test]
fn test_array_cursor_serialises_as_it_is() {
    let cursor = Query::cursor_before_value(qv(arr! {"$id" => "doc1"}));
    assert_eq!(
        cursor.to_array().unwrap(),
        qarr(arr! {"method" => "cursorBefore", "values" => list![arr! {"$id" => "doc1"}]})
    );
}

#[test]
fn test_is_spatial_attribute_follows_the_attribute_type() {
    for (kind, spatial) in [
        ("point", true),
        ("linestring", true),
        ("polygon", true),
        ("string", false),
        ("vector", false),
        ("", false),
        ("circle", false),
    ] {
        let mut q = Query::equal("shape", ["x"]);
        q.set_attribute_type(kind);
        assert_eq!(q.is_spatial_attribute(), spatial, "{kind}");
        assert_eq!(q.is_object_attribute(), kind == "object");
    }
    let mut q = Query::equal("shape", ["x"]);
    q.set_attribute_type("object");
    assert!(q.is_object_attribute());
}

#[test]
fn test_to_array_leaves_out_an_empty_or_zero_attribute() {
    assert_eq!(Query::equal("0", ["x"]).to_array().unwrap(), qarr(arr! {"method" => "equal", "values" => list!["x"]}));
    assert_eq!(
        Query::and(Vec::<Query>::new()).to_array().unwrap(),
        qarr(arr! {"method" => "and"}),
        "a logical query without children has no values"
    );
}
