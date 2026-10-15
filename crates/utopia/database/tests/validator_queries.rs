//! The validators of query lists (`Validator\Queries\*`).
//!
//! PHP: `tests/unit/Validator/{QueriesTest, QueryTest, QueriesCompatibilityTest,
//! DocumentQueriesTest, DocumentsQueriesTest, DocumentsQueriesGrammarTest,
//! DocumentsQueriesUnsignedBigIntTest, QueryValidationTest}.php` and
//! `tests/unit/Validator/Queries/IndexedTest.php` of utopia-php/database 8.1.3.

mod support;

use std::sync::Arc;

use serde_json::json;
use support::*;
use utopia_database::Query;
use utopia_database::attribute::Attribute;
use utopia_database::capability::Capability;
use utopia_database::validator::queries::base::Queries;
use utopia_database::validator::queries::document::Document as DocumentQueries;
use utopia_database::validator::queries::documents::Documents;
use utopia_database::validator::queries::indexed::Indexed;
use utopia_database::validator::query::aggregate::Aggregate;
use utopia_database::validator::query::base::QueryValidator;
use utopia_database::validator::query::cursor::Cursor;
use utopia_database::validator::query::distinct::Distinct;
use utopia_database::validator::query::filter::{Filter, FilterOptions};
use utopia_database::validator::query::group_by::GroupBy;
use utopia_database::validator::query::having::Having;
use utopia_database::validator::query::join::Join;
use utopia_database::validator::query::limit::Limit;
use utopia_database::validator::query::offset::Offset;
use utopia_database::validator::query::order::Order;
use utopia_database::validator::query::select::Select;
use utopia_database::validator::uid::Uid;
use utopia_query::Method;

fn on(left: &str, right: &str) -> Vec<Query> {
    vec![Query::on(left, right, "=")]
}

fn name_attribute() -> Vec<Attribute> {
    attrs(json!([{"$id": "name", "key": "name", "type": "string", "array": false}]))
}

fn price_attribute() -> Vec<Attribute> {
    attrs(json!([{"$id": "price", "key": "price", "type": "double", "array": false}]))
}

fn reviews() -> utopia_database::Document {
    doc(json!({
        "$id": "reviews",
        "attributes": [{"$id": "score", "key": "score", "type": "integer", "array": false}],
    }))
}

/// PHP: `tests/unit/Validator/QueriesTest.php`.
mod queries {
    use super::*;

    #[test]
    fn empty_queries() {
        assert!(Queries::default().check::<Query>(&[]));
    }

    #[test]
    fn invalid_method() {
        assert!(!Queries::default().check(&[Query::equal("attr", ["value"])]));
        assert!(!Queries::new(vec![Arc::new(Limit::default())], 0).check(&[Query::equal("attr", ["value"])]));
    }

    #[test]
    fn invalid_value() {
        assert!(!Queries::new(vec![Arc::new(Limit::default())], 0).check(&[Query::limit(-1)]));
    }

    #[test]
    fn valid() {
        let attributes = attrs(json!([
            {"$id": "name", "key": "name", "type": "string", "array": false},
            {"$id": "meta", "key": "meta", "type": "object", "array": false},
        ]));
        let v = Queries::new(
            vec![
                Arc::new(Cursor::default()),
                Arc::new(Filter::new(&attributes, "integer")),
                Arc::new(Limit::default()),
                Arc::new(Offset::default()),
                Arc::new(Order::new(&attributes, true, true)),
            ],
            0,
        );
        for query in [
            cursor_after(json!({"$id": "asdf"})),
            Query::equal("name", ["value"]),
            Query::limit(10),
            Query::offset(10),
            Query::order_asc("name", None),
            Query::equal("meta", qa(json!([{"a": [1, 2]}, {"b": [212]}]))),
        ] {
            assert!(v.check(&[query]), "{}", v.description());
        }
        assert!(!v.check(&[Query::equal("meta", qa(json!([{"a": {"0": 1, "b": [212]}}])))]));
        assert!(v.check(&[Query::contains_any(
            "meta",
            qa(json!([{"role": {"name": ["test1", "test2"], "ex": {"new": "test1"}}}]))
        )]));
    }

    #[test]
    fn non_array_value_returns_false() {
        let v = Queries::default();
        assert!(!v.is_valid(&s("not_an_array")));
        assert_eq!(v.description(), "Queries must be an array");
        assert!(!v.is_valid(&utopia_database::Value::Int(42)));
        assert!(!v.is_valid(&utopia_database::Value::Null));
    }

    #[test]
    fn query_count_exceeds_length() {
        let v = Queries::new(vec![Arc::new(Limit::default())], 2);
        assert!(!v.check(&[Query::limit(10), Query::limit(20), Query::limit(30)]));
    }

    #[test]
    fn aggregation_queries_add_aliases_to_order_validators() {
        let attributes = price_attribute();
        let v = Queries::new(
            vec![Arc::new(Aggregate::new(&attributes, true, false)), Arc::new(Order::new(&attributes, true, true))],
            0,
        );
        assert!(v.check(&[Query::avg("price", "avg_price"), Query::order_asc("avg_price", None)]));
    }

    #[test]
    fn variance_and_stddev_method_type_mapping() {
        let v = Queries::new(vec![Arc::new(Aggregate::new(&[], false, false))], 0);
        assert!(v.check(&[Query::variance("col", "var_col")]));
        assert!(v.check(&[Query::stddev("col", "std_col")]));
    }

    #[test]
    fn distinct_method_type_mapping() {
        assert!(Queries::new(vec![Arc::new(Distinct::default())], 0).check(&[Query::distinct()]));
    }

    #[test]
    fn group_by_method_type_mapping() {
        assert!(
            Queries::new(vec![Arc::new(GroupBy::new(&[], false, false))], 0).check(&[Query::group_by(["category"])])
        );
    }

    #[test]
    fn having_method_type_mapping() {
        assert!(
            Queries::new(vec![Arc::new(Having::default())], 0)
                .check(&[Query::having([Query::greater_than("count", 5)])])
        );
    }

    #[test]
    fn join_method_type_mapping() {
        assert!(
            Queries::new(vec![Arc::new(Join::default())], 0)
                .check(&[Query::join("orders", "j0", on("user_id", "id")).unwrap()])
        );
    }

    fn aggregate_group_by_join() -> Queries {
        let attributes = price_attribute();
        Queries::new(
            vec![
                Arc::new(Aggregate::new(&attributes, true, false)),
                Arc::new(GroupBy::new(&attributes, true, false)),
                Arc::new(Join::default()),
            ],
            0,
        )
    }

    #[test]
    fn aggregate_and_group_by_accept_joined_attributes() {
        let v = aggregate_group_by_join();
        v.set_joined_collections(&[reviews()]).unwrap();
        assert!(v.check(&[
            Query::left_join("reviews", "rev", on("productId", "$id")).unwrap(),
            Query::sum("rev.score", ""),
            Query::group_by(["score"]),
        ]));
    }

    #[test]
    fn aggregate_and_group_by_reject_unknown_attributes_without_a_join() {
        let v = aggregate_group_by_join();
        assert!(!v.check(&[Query::sum("score", "")]));
        assert!(!v.check(&[Query::group_by(["score"])]));
    }

    #[test]
    fn aggregate_and_group_by_reject_an_undeclared_join_alias() {
        let v = aggregate_group_by_join();
        let join = || Query::left_join("reviews", "rev", on("productId", "$id")).unwrap();
        assert!(!v.check(&[join(), Query::sum("revv.score", "")]));
        assert!(!v.check(&[join(), Query::group_by(["revv.score"])]));
        assert!(!v.check(&[join(), Query::sum("rev.score.nested", "")]));
    }

    #[test]
    fn join_alias_stand_down_does_not_leak_into_the_next_query_set() {
        let v = aggregate_group_by_join();
        assert!(v.check(&[
            Query::left_join("reviews", "rev", on("productId", "$id")).unwrap(),
            Query::sum("rev.score", "")
        ]));
        assert!(!v.check(&[
            Query::left_join("reviews", "other", on("productId", "$id")).unwrap(),
            Query::sum("rev.score", "")
        ]));
    }

    #[test]
    fn joined_attribute_stand_down_does_not_leak_into_the_next_query_set() {
        let v = aggregate_group_by_join();
        v.set_joined_collections(&[reviews()]).unwrap();
        assert!(
            v.check(&[Query::left_join("reviews", "rev", on("productId", "$id")).unwrap(), Query::sum("score", "")])
        );
        assert!(!v.check(&[Query::sum("score", "")]));
    }

    #[test]
    fn select_before_join_accepts_dotted_alias() {
        let attributes = name_attribute();
        let v = Queries::new(vec![Arc::new(Select::new(&attributes, true, false)), Arc::new(Join::default())], 0);
        assert!(
            v.check(&[Query::select(["ord.amount"]), Query::join("orders", "ord", on("$id", "customer_uid")).unwrap()]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn filter_before_join_accepts_dotted_alias() {
        let attributes = name_attribute();
        let v = Queries::new(vec![Arc::new(Filter::new(&attributes, "integer")), Arc::new(Join::default())], 0);
        assert!(
            v.check(&[
                Query::equal("sec.amount", [777]),
                Query::join("orders", "sec", on("$id", "customer_uid")).unwrap()
            ]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn nested_join_alias_is_collected() {
        let attributes = name_attribute();
        let v = Queries::new(
            vec![
                Arc::new(Select::new(&attributes, true, false)),
                Arc::new(Filter::new(&attributes, "integer")),
                Arc::new(Join::default()),
            ],
            0,
        );
        assert!(
            v.check(&[
                Query::select(["ord.amount"]),
                Query::left_join(
                    "orders",
                    "ord",
                    vec![Query::on("$id", "customer_uid", "="), Query::equal("ord.status", ["paid"])]
                )
                .unwrap(),
            ]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn nested_join_on_filter_unknown_alias_is_invalid() {
        let attributes = name_attribute();
        let v = Queries::new(vec![Arc::new(Filter::new(&attributes, "integer")), Arc::new(Join::default())], 0);
        assert!(
            !v.check(&[Query::left_join(
                "orders",
                "ord",
                vec![Query::on("$id", "customer_uid", "="), Query::equal("missing.status", ["paid"])]
            )
            .unwrap()])
        );
        assert!(v.description().contains("Attribute not found in schema"));
    }

    #[test]
    fn order_before_join_accepts_dotted_alias() {
        let attributes = name_attribute();
        let v = Queries::new(vec![Arc::new(Order::new(&attributes, true, true)), Arc::new(Join::default())], 0);
        assert!(
            v.check(&[
                Query::order_asc("sec.amount", None),
                Query::join("orders", "sec", on("$id", "customer_uid")).unwrap()
            ]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn unknown_join_alias_filter_is_rejected() {
        let attributes = name_attribute();
        let v = Queries::new(vec![Arc::new(Filter::new(&attributes, "integer")), Arc::new(Join::default())], 0);
        assert!(!v.check(&[
            Query::equal("other.amount", [777]),
            Query::join("orders", "sec", on("$id", "customer_uid")).unwrap()
        ]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: other");
    }

    fn name_rank() -> Queries {
        let attributes = attrs(json!([
            {"$id": "name", "key": "name", "type": "string", "array": false},
            {"$id": "rank", "key": "rank", "type": "integer", "array": false},
        ]));
        Queries::new(vec![Arc::new(Filter::new(&attributes, "integer")), Arc::new(Join::default())], 0)
    }

    fn nested_meta(attribute: &str) -> [Query; 2] {
        [
            Query::join("meta", "meta", on("$id", "mainId")).unwrap(),
            Query::and([
                Query::equal("name", ["Main"]),
                Query::or([Query::equal(attribute, [10]), Query::equal("rank", [2])]),
            ]),
        ]
    }

    #[test]
    fn nested_and_or_join_alias_is_accepted() {
        let v = name_rank();
        assert!(v.check(&nested_meta("meta.score")), "{}", v.description());
    }

    #[test]
    fn nested_and_or_unknown_join_alias_is_rejected() {
        let v = name_rank();
        assert!(!v.check(&nested_meta("other.score")));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: other");
    }

    #[test]
    fn nested_and_or_multi_segment_join_column_is_rejected() {
        let v = name_rank();
        assert!(!v.check(&nested_meta("meta.foo.bar")));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: meta");
    }

    #[test]
    fn join_alias_reset_between_is_valid_calls() {
        let v = name_rank();
        assert!(
            v.check(&[Query::join("meta", "meta", on("$id", "mainId")).unwrap(), Query::equal("meta.score", [10])]),
            "{}",
            v.description()
        );
        assert!(
            !v.check(&[Query::join("peer", "peer", on("$id", "mainId")).unwrap(), Query::equal("meta.score", [8686])])
        );
        assert!(v.description().contains("Attribute not found"));
        assert!(v.description().contains("meta"));
    }

    #[test]
    fn nested_non_string_query_is_rejected() {
        let v = Queries::new(vec![Arc::new(Filter::new(&name_attribute(), "integer"))], 0);
        assert!(!v.check(&[q_nested(
            Method::And,
            "",
            vec![utopia_query::Value::Int(123), nested(Query::equal("name", ["Main"]))],
            ""
        )]));
        assert_eq!(v.description(), "Invalid query: nested query must be a string");
    }

    #[test]
    fn is_array() {
        assert!(Queries::default().is_array());
    }

    #[test]
    fn get_type() {
        assert_eq!(Queries::default().type_name(), "object");
    }

    #[test]
    fn non_string_entries_fail_as_php_parse_does() {
        let v = Queries::new(vec![Arc::new(Limit::default())], 0);
        let caller =
            "called in /usr/src/code/vendor/utopia-php/database/src/Database/Validator/Queries/Base.php on line 186";
        for (entry, message) in [
            (utopia_database::Value::Int(42), "Invalid query: Invalid query. Must be an array, got integer".to_owned()),
            (
                utopia_database::Value::Float(1.5),
                "Invalid query: Invalid query. Must be an array, got double".to_owned(),
            ),
            (
                utopia_database::Value::Bool(true),
                "Invalid query: Invalid query. Must be an array, got integer".to_owned(),
            ),
            (utopia_database::Value::Bool(false), "Invalid query: Invalid query: Syntax error".to_owned()),
            (
                utopia_database::Value::Null,
                format!(
                    "Invalid query: Utopia\\Database\\Query::parse(): Argument #1 ($query) must be of type string, null given, {caller}"
                ),
            ),
            (
                list(vec![s("a")]),
                format!(
                    "Invalid query: Utopia\\Database\\Query::parse(): Argument #1 ($query) must be of type string, array given, {caller}"
                ),
            ),
        ] {
            assert!(!v.is_valid(&list(vec![entry])));
            assert_eq!(v.description(), message);
        }
    }
}

/// PHP: `tests/unit/Validator/QueryTest.php` (`test_query_get_by_type` covers
/// `Query::getByType()`, not a validator, and is left to the query tests).
mod query {
    use super::*;

    fn attributes() -> Vec<Attribute> {
        attrs(json!([
            {"$id": "title", "key": "title", "type": "string", "size": 256, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "description", "key": "description", "type": "string", "size": 1000000, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "rating", "key": "rating", "type": "integer", "size": 5, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "price", "key": "price", "type": "double", "size": 5, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "published", "key": "published", "type": "boolean", "size": 5, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "tags", "key": "tags", "type": "string", "size": 55, "required": true, "signed": true, "array": true, "filters": []},
            {"$id": "birthDay", "key": "birthDay", "type": "datetime", "size": 0, "required": false, "signed": false, "array": false, "filters": ["datetime"]},
        ]))
    }

    fn validator() -> Documents {
        Documents::new(
            &attributes(),
            &[],
            &profile(&[Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::OrderRandom]),
            5000,
        )
    }

    #[test]
    fn query() {
        let v = validator();
        for query in [
            Query::equal("$id", ["Iron Man", "Ant Man"]),
            Query::equal("$id", ["Iron Man"]),
            Query::equal("description", ["Best movie ever"]),
            Query::greater_than("rating", 4),
            Query::not_equal("title", "Iron Man"),
            Query::less_than("price", 6.50),
            Query::less_than_equal("price", 6),
            Query::contains_any("tags", ["action1", "action2"]),
            Query::contains_any("tags", ["action1"]),
            cursor_after(json!({"$id": "docId"})),
            cursor_before(json!({"$id": "docId"})),
            Query::order_asc("title", None),
            Query::order_desc("title", None),
            Query::is_null("title"),
            Query::is_not_null("title"),
            Query::between("price", 1.5, 10.9),
            Query::between("birthDay", "2024-01-01", "2023-01-01"),
            Query::starts_with("title", "Fro"),
            Query::ends_with("title", "Zen"),
            Query::select(["title", "description"]),
            Query::not_equal("title", ""),
        ] {
            assert!(v.check(&[query]), "{}", v.description());
        }
    }

    #[test]
    fn attribute_not_found() {
        let v = validator();
        assert!(!v.check(&[Query::equal("name", ["Iron Man"])]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: name");
        assert!(!v.check(&[Query::order_asc("name", None)]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: name");
    }

    #[test]
    fn attribute_wrong_type() {
        let v = validator();
        assert!(!v.check(&[Query::equal("title", [1776])]));
        assert_eq!(v.description(), "Invalid query: Query value is invalid for attribute \"title\"");
    }

    #[test]
    fn query_date() {
        assert!(validator().check(&[Query::greater_than("birthDay", "1960-01-01 10:10:10")]));
    }

    #[test]
    fn query_limit() {
        let v = validator();
        assert!(v.check(&[Query::limit(25)]));
        assert!(!v.check(&[Query::limit(-1)]));
    }

    #[test]
    fn query_offset() {
        let v = validator();
        assert!(v.check(&[Query::offset(25)]));
        assert!(!v.check(&[Query::offset(-1)]));
    }

    #[test]
    fn query_order() {
        let v = validator();
        assert!(v.check(&[Query::order_asc("title", None)]));
        assert!(v.check(&[Query::order_asc("", None)]));
        assert!(!v.check(&[Query::order_asc("doesNotExist", None)]));
    }

    #[test]
    fn query_cursor() {
        assert!(validator().check(&[cursor_after(json!({"$id": "asdf"}))]));
    }

    #[test]
    fn query_empty() {
        let v = validator();
        assert!(v.check(&[Query::equal("title", [""])]));
        assert!(v.check(&[Query::equal("published", [false])]));
        assert!(v.check(&[Query::equal("price", [0])]));
        assert!(v.check(&[Query::greater_than("price", 0)]));
        assert!(v.check(&[Query::greater_than("published", false)]));
        assert!(!v.check(&[Query::equal("price", Vec::<i64>::new())]));
        assert!(v.check(&[Query::is_null("price")]));
    }

    #[test]
    fn or_query() {
        let v = validator();
        assert!(!v.check(&[Query::or([Query::equal("title", [""])])]));
        assert_eq!(v.description(), "Invalid query: Or queries require at least two queries");
        assert!(!v.check(&[Query::or([Query::equal("price", [0]), Query::equal("not_found", [""])])]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: not_found");
        assert!(!v.check(&[Query::equal("price", [10]), Query::or([Query::select(["price"]), Query::limit(1)])]));
        assert_eq!(v.description(), "Invalid query: Or queries can only contain filter queries");
    }
}

/// PHP: `tests/unit/Validator/QueriesCompatibilityTest.php`.
mod compatibility {
    use super::*;

    fn documents(shared_tables: bool) -> Documents {
        Documents::new(
            &attrs(json!([
                {"$id": "name", "key": "name", "type": "string", "size": 128, "array": false},
                {"$id": "age", "key": "age", "type": "integer", "size": 4, "array": false},
                {"$id": "tags", "key": "tags", "type": "string", "size": 64, "array": true},
            ])),
            &[],
            &profile_shared(&[Capability::DefinedAttributes, Capability::OrderRandom], shared_tables),
            5000,
        )
    }

    #[test]
    fn nested_child_is_validated_before_its_parent() {
        let v = documents(false);
        assert!(!v.check(&[r#"{"method":"and","values":[{"method":"equal","attribute":"nope","values":["a"]}]}"#]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: nope");
        assert!(!v.check(&[
            r#"{"method":"or","values":[{"method":"equal","attribute":"nope","values":["a"]},{"method":"limit","values":[1]}]}"#
        ]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: nope");
        assert!(!v.check(&[
            r#"{"method":"elemMatch","attribute":"tags","values":[{"method":"equal","attribute":"x","values":["a"]}]}"#
        ]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: x");
    }

    #[test]
    fn first_invalid_query_wins_over_a_later_parse_failure() {
        let v = documents(false);
        assert!(!v.check(&[r#"{"method":"equal","attribute":"nope","values":["a"]}"#, "not json"]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: nope");
        assert!(!v.check(&["not json", r#"{"method":"equal","attribute":"nope","values":["a"]}"#]));
        assert_eq!(v.description(), "Invalid query: Invalid query: Syntax error");
    }

    #[test]
    fn method_unknown_to_7x_keeps_the_parse_prefix() {
        let v =
            Queries::new(vec![Arc::new(Limit::default()), Arc::new(Offset::default()), Arc::new(Cursor::default())], 0);
        assert!(!v.check(&[r#"{"method":"count","attribute":"name","values":[]}"#]));
        assert_eq!(v.description(), "Invalid query: Invalid query method: count");
        assert!(!v.check(&[r#"{"method":"jsonContains","attribute":"name","values":["a"]}"#]));
        assert_eq!(v.description(), "Invalid query: Invalid query method: jsonContains");
        assert!(!v.check(&[
            r#"{"method":"and","values":[{"method":"equal","attribute":"name","values":["a"]},{"method":"equal","attribute":"name","values":["b"]}]}"#
        ]));
        assert_eq!(v.description(), "Invalid query method: equal");
    }

    #[test]
    fn raw_is_an_invalid_method() {
        let v = documents(false);
        assert!(!v.check(&[r#"{"method":"raw","values":["1=1"]}"#]));
        assert_eq!(v.description(), "Invalid query: Invalid query method: raw");
        let error = Query::parse(
            r#"{"method":"or","values":[{"method":"raw","values":["1=1"]},{"method":"equal","attribute":"name","values":["a"]}]}"#,
            false,
        )
        .unwrap_err();
        assert_eq!(error.kind(), utopia_database::Kind::Query);
        assert_eq!(error.message(), "Invalid query method: raw");
    }

    #[test]
    fn tenant_passes_select_validation_without_shared_tables() {
        assert!(documents(false).check(&[r#"{"method":"select","values":["$tenant","name"]}"#]));
        assert!(documents(true).check(&[r#"{"method":"select","values":["$tenant"]}"#]));
    }

    #[test]
    fn uid_description_names_every_character_it_accepts() {
        let uid = Uid::default();
        assert!(uid.is_valid(&s("a.b-c_d")));
        assert!(!uid.is_valid(&s(".abc")));
        assert!(!uid.is_valid(&s("-abc")));
        assert!(!uid.is_valid(&s("_abc")));
        assert_eq!(
            uid.description(),
            "UID must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a leading period, hyphen, or underscore"
        );
    }
}

/// PHP: `tests/unit/Validator/DocumentQueriesTest.php`.
mod document_queries {
    use super::*;

    const JOINS: [Capability; 3] = [Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::Joins];

    fn title_price() -> Vec<Attribute> {
        attrs(json!([
            {"$id": "title", "key": "title", "type": "string", "size": 256, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "price", "key": "price", "type": "double", "size": 5, "required": true, "signed": true, "array": false, "filters": []},
        ]))
    }

    fn validator() -> DocumentQueries {
        DocumentQueries::new(
            &attrs(json!([{"key": "name", "type": "string"}, {"key": "amount", "type": "integer"}])),
            &profile(&JOINS),
            5000,
        )
    }

    #[test]
    fn valid_queries() {
        let v = DocumentQueries::new(&title_price(), &profile(&JOINS), 5000);
        assert!(v.check(&[Query::select(["title"])]));
        assert!(v.check(&[Query::select(["title"]), Query::select(["price.relation"])]));
    }

    #[test]
    fn invalid_queries() {
        let v = DocumentQueries::new(&title_price(), &profile(&JOINS), 5000);
        assert!(!v.check(&[Query::limit(1)]));
    }

    #[test]
    fn join_is_valid() {
        let v = validator();
        assert!(v.check(&[Query::join("orders", "j0", on("$id", "customerId")).unwrap()]), "{}", v.description());
    }

    #[test]
    fn select_with_join_alias_is_valid() {
        let v = validator();
        assert!(
            v.check(&[Query::select(["ord.amount"]), Query::join("orders", "ord", on("$id", "customerId")).unwrap()]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn select_unprefixed_join_attribute_is_invalid() {
        let v = validator();
        assert!(
            !v.check(&[Query::select(["score"]), Query::left_join("reviews", "j0", on("$id", "prod_uid")).unwrap()])
        );
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: score");
    }

    #[test]
    fn select_with_left_join_alias_is_valid() {
        let v = validator();
        assert!(
            v.check(&[
                Query::select(["rev.score"]),
                Query::left_join("reviews", "rev", on("$id", "prod_uid")).unwrap()
            ]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn extended_methods_are_invalid() {
        for query in [
            Query::count("*", "cnt"),
            Query::group_by(["name"]),
            Query::having([Query::greater_than("amount", 1)]),
            Query::distinct(),
            Query::union([Query::equal("name", ["x"])]),
            Query::union_all([Query::equal("name", ["x"])]),
        ] {
            let v = validator();
            assert!(!v.check(&[query]));
            assert!(v.description().contains("Invalid query method"));
        }
    }

    #[test]
    fn natural_join_is_invalid() {
        let v = validator();
        assert!(!v.check(&[Query::natural_join("orders", "j0").unwrap()]));
        assert!(v.description().contains("Natural joins are not supported"));
    }

    #[test]
    fn nested_join_on_is_valid() {
        let v = validator();
        assert!(v.check(&[Query::left_join("orders", "ord", on("$id", "customerId")).unwrap()]), "{}", v.description());
    }

    #[test]
    fn nested_join_on_with_filter_is_valid_without_filter_validator() {
        let v = validator();
        assert!(
            v.check(&[Query::left_join(
                "orders",
                "ord",
                vec![Query::on("$id", "customerId", "="), Query::equal("ord.status", ["paid"])]
            )
            .unwrap()]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn nested_join_on_requires_columns() {
        let v = validator();
        assert!(!v.check(&[Query::left_join("orders", "ord", on("", "customerId")).unwrap()]));
        assert!(v.description().contains("Join ON requires left and right columns"));
    }

    #[test]
    fn select_with_nested_join_alias_is_valid() {
        let v = validator();
        assert!(
            v.check(&[
                Query::select(["ord.amount"]),
                Query::left_join("orders", "ord", on("$id", "customerId")).unwrap()
            ]),
            "{}",
            v.description()
        );
    }
}

/// PHP: `tests/unit/Validator/DocumentsQueriesTest.php`.
mod documents_queries {
    use super::*;

    fn validator(capabilities: &[Capability]) -> Documents {
        let attributes = attrs(json!([
            {"$id": "title", "key": "title", "type": "string", "size": 256, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "description", "key": "description", "type": "string", "size": 1000000, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "rating", "key": "rating", "type": "integer", "size": 5, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "price", "key": "price", "type": "double", "size": 5, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "is_bool", "key": "is_bool", "type": "boolean", "size": 0, "required": false, "signed": false, "array": false, "filters": []},
            {"$id": "id", "key": "id", "type": "id", "size": 0, "required": false, "signed": false, "array": false, "filters": []},
        ]));
        let indexes = [
            index(
                json!({"$id": "testindex2", "type": "key", "attributes": ["title", "description", "price"], "orders": ["ASC", "DESC"]}),
            ),
            index(json!({"$id": "testindex3", "type": "fulltext", "attributes": ["title"], "orders": []})),
        ];
        Documents::new(&attributes, &indexes, &profile(capabilities), 5000)
    }

    const DEFAULT: [Capability; 3] =
        [Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::OrderRandom];
    const JOINS: [Capability; 4] =
        [Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::Joins, Capability::OrderRandom];

    #[test]
    fn valid_queries() {
        let v = validator(&DEFAULT);
        let queries = [
            Query::not_equal("id", "1000000"),
            Query::equal("description", ["Best movie ever"]),
            Query::equal("description", [""]),
            Query::equal("is_bool", [false]),
            Query::less_than_equal("price", 6.50),
            Query::less_than("price", 6.50),
            Query::greater_than("rating", 4),
            Query::greater_than("rating", 0),
            Query::greater_than_equal("rating", 6),
            Query::between("price", 1.50, 6.50),
            Query::search("title", "SEO"),
            Query::starts_with("title", "Good"),
            Query::ends_with("title", "Night"),
            Query::is_null("title"),
            Query::is_not_null("title"),
            cursor_after(json!({"$id": "a"})),
            cursor_before(json!({"$id": "b"})),
            Query::order_asc("title", None),
            Query::limit(10),
            Query::offset(10),
            Query::order_desc("", None),
        ];
        assert!(v.check(&queries), "{}", v.description());
    }

    #[test]
    fn invalid_queries() {
        let v = validator(&DEFAULT);
        assert!(!v.check(&[r#"{"method":"notEqual","attribute":"title","values":["Iron Man","Ant Man"]}"#]));
        assert_eq!(v.description(), "Invalid query: NotEqual queries require exactly one value.");
        assert!(!v.check(&[Query::search("description", "iron")]));
        assert_eq!(v.description(), "Searching by attribute \"description\" requires a fulltext index.");
        assert!(!v.check(&[Query::equal("not_found", [4])]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: not_found");
        assert!(!v.check(&[Query::limit(-1)]));
        assert_eq!(
            v.description(),
            "Invalid query: Invalid limit: Value must be a valid range between 1 and 9,223,372,036,854,775,807"
        );
        assert!(!v.check(&[Query::equal("title", Vec::<String>::new())]));
        assert_eq!(v.description(), "Invalid query: Equal queries require at least one value.");
    }

    #[test]
    fn filter_with_join_alias_is_valid() {
        let v = validator(&JOINS);
        assert!(
            v.check(&[
                Query::equal("sec.amount", [777]),
                Query::join("orders", "sec", on("$id", "customerId")).unwrap()
            ]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn order_with_join_alias_is_valid() {
        let v = validator(&JOINS);
        assert!(
            v.check(&[
                Query::order_asc("sec.amount", None),
                Query::join("orders", "sec", on("$id", "customerId")).unwrap()
            ]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn unknown_join_alias_filter_is_invalid() {
        let v = validator(&JOINS);
        assert!(!v.check(&[
            Query::equal("other.amount", [777]),
            Query::join("orders", "sec", on("$id", "customerId")).unwrap()
        ]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: other");
    }

    #[test]
    fn nested_and_or_join_alias_is_valid() {
        let v = validator(&JOINS);
        assert!(
            v.check(&[
                Query::join("meta", "meta", on("$id", "mainId")).unwrap(),
                Query::and([
                    Query::equal("title", ["Main"]),
                    Query::or([Query::equal("meta.score", [10]), Query::equal("rating", [2])]),
                ]),
            ]),
            "{}",
            v.description()
        );
    }
}

/// PHP: `tests/unit/Validator/DocumentsQueriesGrammarTest.php`.
mod documents_queries_grammar {
    use super::*;

    fn validator(capabilities: &[Capability]) -> Documents {
        Documents::new(
            &attrs(
                json!([{"$id": "rating", "key": "rating", "type": "integer", "size": 5, "required": false, "signed": true, "array": false, "filters": []}]),
            ),
            &[],
            &profile(capabilities),
            5000,
        )
    }

    fn joins() -> Vec<(Query, &'static str)> {
        vec![
            (Query::join("orders", "o", on("$id", "customerId")).unwrap(), "join"),
            (Query::left_join("orders", "o", on("$id", "customerId")).unwrap(), "leftJoin"),
            (Query::right_join("orders", "o", on("$id", "customerId")).unwrap(), "rightJoin"),
            (Query::cross_join("orders", "o").unwrap(), "crossJoin"),
            (Query::full_outer_join("orders", "o", on("$id", "customerId")).unwrap(), "fullOuterJoin"),
        ]
    }

    fn aggregations() -> Vec<(Query, &'static str)> {
        vec![
            (Query::count("*", "total"), "count"),
            (Query::sum("rating", "total"), "sum"),
            (Query::group_by(["rating"]), "groupBy"),
            (Query::having([Query::greater_than("rating", 1)]), "having"),
            (Query::distinct(), "distinct"),
        ]
    }

    const DEFAULT: [Capability; 3] =
        [Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::OrderRandom];
    const JOINS: [Capability; 4] =
        [Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::Joins, Capability::OrderRandom];
    const AGGREGATIONS: [Capability; 4] =
        [Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::Aggregations, Capability::OrderRandom];

    #[test]
    fn default_grammar_rejects_extended_methods() {
        for (query, method) in joins().into_iter().chain(aggregations()) {
            let v = validator(&DEFAULT);
            assert!(!v.check(&[query]));
            assert_eq!(v.description(), format!("Invalid query: Invalid query method: {method}"));
        }
    }

    #[test]
    fn joins_are_accepted_when_enabled() {
        for (query, method) in joins() {
            let v = validator(&JOINS);
            assert!(v.check(&[query]), "{method}: {}", v.description());
        }
    }

    #[test]
    fn aggregations_are_accepted_when_enabled() {
        for (query, method) in aggregations() {
            let v = validator(&AGGREGATIONS);
            let queries = if method == "having" { vec![Query::group_by(["rating"]), query] } else { vec![query] };
            assert!(v.check(&queries), "{method}: {}", v.description());
        }
    }

    #[test]
    fn enabling_joins_does_not_enable_aggregations() {
        for (query, method) in aggregations() {
            let v = validator(&JOINS);
            assert!(!v.check(&[query]));
            assert_eq!(v.description(), format!("Invalid query: Invalid query method: {method}"));
        }
    }

    #[test]
    fn enabling_aggregations_does_not_enable_joins() {
        for (query, method) in joins() {
            let v = validator(&AGGREGATIONS);
            assert!(!v.check(&[query]));
            assert_eq!(v.description(), format!("Invalid query: Invalid query method: {method}"));
        }
    }
}

/// PHP: `tests/unit/Validator/DocumentsQueriesUnsignedBigIntTest.php`.
mod documents_queries_unsigned_big_int {
    use super::*;

    const ABOVE_SIGNED_MAX: &str = "10000000000000000000";

    fn attributes() -> Vec<Attribute> {
        attrs(
            json!([{"$id": "counter", "key": "counter", "type": "bigint", "size": 0, "required": false, "signed": false, "array": false, "filters": []}]),
        )
    }

    #[test]
    fn documents_validator_accepts_unsigned_values_above_signed_max_by_default() {
        let v = Documents::new(
            &attributes(),
            &[],
            &profile(&[Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::OrderRandom]),
            5000,
        );
        assert!(v.check(&[Query::equal("counter", [ABOVE_SIGNED_MAX])]), "{}", v.description());
    }

    #[test]
    fn filter_validator_accepts_unsigned_values_above_signed_max_by_default() {
        let v = Filter::new(&attributes(), "integer");
        assert!(v.check(&Query::equal("counter", [ABOVE_SIGNED_MAX])), "{}", v.description());
    }

    #[test]
    fn adapters_without_unsigned_bigint_still_reject_values_above_signed_max() {
        let v = Documents::new(
            &attributes(),
            &[],
            &profile(&[Capability::DefinedAttributes, Capability::OrderRandom]),
            5000,
        );
        assert!(!v.check(&[Query::equal("counter", [ABOVE_SIGNED_MAX])]));
        assert_eq!(v.description(), "Invalid query: Query value is invalid for attribute \"counter\"");
    }
}

/// PHP: `tests/unit/Validator/Queries/IndexedTest.php`.
mod indexed {
    use super::*;

    fn validators(attributes: &[Attribute]) -> Vec<Arc<dyn QueryValidator>> {
        vec![
            Arc::new(Cursor::default()),
            Arc::new(Filter::new(attributes, "integer")),
            Arc::new(Limit::default()),
            Arc::new(Offset::default()),
            Arc::new(Order::new(attributes, true, true)),
        ]
    }

    fn meta() -> utopia_database::Document {
        doc(json!({
            "$id": "meta",
            "attributes": [
                {"$id": "mainId", "key": "mainId", "type": "string", "array": false},
                {"$id": "body", "key": "body", "type": "string", "array": false},
            ],
            "indexes": [{"$id": "body_fulltext", "type": "fulltext", "attributes": ["body"]}],
        }))
    }

    fn embedding() -> Vec<Attribute> {
        attrs(json!([{"$id": "embedding", "key": "embedding", "type": "vector", "size": 3, "array": false}]))
    }

    #[test]
    fn empty_queries() {
        assert!(Indexed::default().check::<Query>(&[]));
    }

    #[test]
    fn invalid_query() {
        assert!(!Indexed::default().check(&["this.is.invalid"]));
    }

    #[test]
    fn invalid_method() {
        assert!(!Indexed::default().check(&["equal(\"attr\", \"value\")"]));
        assert!(!Indexed::new(&[], &[], vec![Arc::new(Limit::default())]).check(&["equal(\"attr\", \"value\")"]));
    }

    #[test]
    fn invalid_value() {
        assert!(!Indexed::new(&[], &[], vec![Arc::new(Limit::default())]).check(&["limit(-1)"]));
    }

    #[test]
    fn valid() {
        let attributes = name_attribute();
        let indexes = [
            index(json!({"type": "key", "attributes": ["name"]})),
            index(json!({"type": "fulltext", "attributes": ["name"]})),
        ];
        let v = Indexed::new(&attributes, &indexes, validators(&attributes));
        let parse = |json: &str| Query::parse(json, false).unwrap();
        for query in [
            cursor_after(json!({"$id": "abc"})),
            parse(r#"{"method":"cursorAfter","attribute":"","values":["abc"]}"#),
            parse(r#"{"method":"cursorAfter","values":["abc"]}"#),
            Query::equal("name", ["value"]),
            parse(r#"{"method":"equal","attribute":"name","values":["value"]}"#),
            Query::limit(10),
            parse(r#"{"method":"limit","values":[10]}"#),
            Query::offset(10),
            parse(r#"{"method":"offset","values":[10]}"#),
            Query::order_asc("name", None),
            parse(r#"{"method":"orderAsc","attribute":"name"}"#),
            Query::search("name", "value"),
            parse(r#"{"method":"search","attribute":"name","values":["value"]}"#),
        ] {
            assert!(v.check(&[query]), "{}", v.description());
        }
    }

    #[test]
    fn missing_index() {
        let attributes = attrs(json!([{"key": "name", "type": "string", "array": false}]));
        let v = Indexed::new(
            &attributes,
            &[index(json!({"type": "key", "attributes": ["name"]}))],
            validators(&attributes),
        );
        assert!(!v.check(&[Query::equal("dne", ["value"])]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: dne");
        assert!(!v.check(&[Query::order_asc("dne", None)]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: dne");
        assert!(!v.check(&[Query::search("dne", "phrase")]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: dne");
        assert!(!v.check(&[Query::search("name", "phrase")]));
        assert_eq!(v.description(), "Searching by attribute \"name\" requires a fulltext index.");
    }

    #[test]
    fn join_side_search_skips_main_fulltext_index() {
        let attributes = name_attribute();
        let v = Indexed::new(
            &attributes,
            &[],
            vec![Arc::new(Filter::new(&attributes, "integer")), Arc::new(Join::default())],
        );
        v.set_joined_collections(&[meta()]).unwrap();
        assert!(
            v.check(&[
                Query::left_join("meta", "meta", on("$id", "mainId")).unwrap(),
                Query::search("meta.body", "needle")
            ]),
            "{}",
            v.description()
        );
        assert!(!v.check(&[Query::search("name", "needle")]));
        assert_eq!(v.description(), "Searching by attribute \"name\" requires a fulltext index.");
    }

    #[test]
    fn nested_join_on_refuses_search_with_or_without_a_fulltext_index() {
        let attributes = name_attribute();
        let v = Indexed::new(
            &attributes,
            &[],
            vec![Arc::new(Filter::new(&attributes, "integer")), Arc::new(Join::default())],
        );
        v.set_joined_collections(&[meta()]).unwrap();
        for search in ["name", "meta.body"] {
            assert!(!v.check(&[q_nested(
                Method::LeftJoin,
                "meta",
                vec![nested(Query::on("$id", "mainId", "=")), nested(Query::search(search, "needle"))],
                "meta"
            )]));
            assert_eq!(v.description(), "Invalid query: Unsupported join ON condition: search");
        }
    }

    #[test]
    fn nested_join_on_refuses_a_vector_query_next_to_another() {
        let attributes = embedding();
        let v = Indexed::new(
            &attributes,
            &[],
            vec![Arc::new(Filter::new(&attributes, "integer")), Arc::new(Join::default())],
        );
        assert!(!v.check(&[
            Query::vector_dot("embedding", qa(json!([0.1, 0.2, 0.3]))),
            q_nested(
                Method::LeftJoin,
                "meta",
                vec![
                    nested(Query::on("$id", "mainId", "=")),
                    nested(Query::vector_cosine("embedding", qa(json!([0.3, 0.4, 0.5]))))
                ],
                "meta"
            ),
        ]));
        assert_eq!(v.description(), "Invalid query: Unsupported join ON condition: vectorCosine");
    }

    #[test]
    fn nested_join_on_refuses_a_single_vector_query() {
        let attributes = embedding();
        let v = Indexed::new(
            &attributes,
            &[],
            vec![Arc::new(Filter::new(&attributes, "integer")), Arc::new(Join::default())],
        );
        assert!(!v.check(&[q_nested(
            Method::LeftJoin,
            "meta",
            vec![
                nested(Query::on("$id", "mainId", "=")),
                nested(Query::vector_cosine("embedding", qa(json!([0.3, 0.4, 0.5]))))
            ],
            "meta"
        )]));
        assert_eq!(v.description(), "Invalid query: Unsupported join ON condition: vectorCosine");
    }

    #[test]
    fn two_attributes_fulltext() {
        let attributes = attrs(json!([
            {"$id": "ft1", "key": "ft1", "type": "string", "array": false},
            {"$id": "ft2", "key": "ft2", "type": "string", "array": false},
        ]));
        let v = Indexed::new(
            &attributes,
            &[index(json!({"type": "fulltext", "attributes": ["ft1", "ft2"]}))],
            validators(&attributes),
        );
        assert!(!v.check(&[Query::search("ft1", "value")]));
    }

    #[test]
    fn json_parse() {
        let error = Query::parse(r#"{"method":"equal","attribute":"name","values":["value"]"#, false).unwrap_err();
        assert_eq!(error.message(), "Invalid query: Syntax error");
    }

    #[test]
    fn single_vector_query_passes() {
        let attributes = embedding();
        let v = Indexed::new(&attributes, &[], vec![Arc::new(Filter::new(&attributes, "integer"))]);
        assert!(v.check(&[Query::vector_cosine("embedding", qa(json!([0.1, 0.2, 0.3])))]));
    }

    #[test]
    fn nested_queries_containing_vector_methods() {
        let mut attributes = embedding();
        attributes.extend(name_attribute());
        let v = Indexed::new(&attributes, &[], vec![Arc::new(Filter::new(&attributes, "integer"))]);
        assert!(v.check(&[
            Query::or([Query::equal("name", ["alice"]), Query::equal("name", ["bob"])]),
            Query::vector_dot("embedding", qa(json!([0.1, 0.2, 0.3]))),
        ]));
    }

    #[test]
    fn unparseable_string_query_returns_error() {
        let v = Indexed::new(&[], &[], vec![Arc::new(Limit::default())]);
        assert!(!v.check(&["totally broken }{"]));
        assert!(v.description().contains("Invalid query"));
    }

    #[test]
    fn nested_non_having_with_invalid_sub_queries() {
        let v = Indexed::new(&[], &[], vec![Arc::new(Filter::new(&[], "integer"))]);
        assert!(!v.check(&[Query::or([Query::equal("nonexistent", ["value"])])]));
    }

    #[test]
    fn nested_search_requires_fulltext_index() {
        let attributes = attrs(json!([
            {"$id": "name", "key": "name", "type": "string", "array": false},
            {"$id": "title", "key": "title", "type": "string", "array": false},
        ]));
        let v = Indexed::new(
            &attributes,
            &[index(json!({"type": "fulltext", "attributes": ["title"]}))],
            vec![Arc::new(Filter::new(&attributes, "integer"))],
        );
        assert!(v.check(&[Query::or([Query::search("title", "foo"), Query::equal("name", ["bar"])])]));
        assert!(!v.check(&[Query::or([Query::search("name", "foo"), Query::equal("title", ["bar"])])]));
        assert_eq!(v.description(), "Searching by attribute \"name\" requires a fulltext index.");
    }

    #[test]
    fn multiple_vector_queries_fails() {
        let attributes = embedding();
        let v = Indexed::new(&attributes, &[], vec![Arc::new(Filter::new(&attributes, "integer"))]);
        assert!(!v.check(&[
            Query::vector_cosine("embedding", qa(json!([0.1, 0.2, 0.3]))),
            Query::vector_euclidean("embedding", qa(json!([0.4, 0.5, 0.6]))),
        ]));
        assert_eq!(v.description(), "Cannot use multiple vector queries in a single request");
    }
}

/// PHP: `tests/unit/Validator/QueryValidationTest.php`, the cases that need no
/// database (the others run `find()`, `count()`, `sum()`, `aggregate()` and
/// `getDocument()` on SQLite and Memory adapters).
mod query_validation {
    use super::*;

    #[test]
    fn the_join_validator_names_the_relationship_an_alias_collides_with() {
        let attributes = attrs(json!([
            {"$id": "owner", "key": "owner", "type": "string"},
            {"$id": "author", "key": "author", "type": "relationship", "options": {"relationType": "manyToOne", "side": "parent", "relatedCollection": "authors"}},
        ]));
        let v = Join::new(Some(&attributes), true);
        assert!(!v.check(&Query::join("users", "author", on("owner", "$id")).unwrap()));
        assert_eq!(
            v.description(),
            "Join alias \"author\" is the key of the relationship attribute \"author\": give the join another alias"
        );
        v.reset_join_aliases();
        assert!(v.check(&Query::join("users", "Author", on("owner", "$id")).unwrap()));
        v.reset_join_aliases();
        assert!(v.check(&Query::join("users", "owner", on("owner", "$id")).unwrap()));
    }

    #[test]
    fn the_aggregate_validator_refuses_extrema_of_unordered_types() {
        let definition =
            |key: &str, kind: &str, array: bool| attr(json!({"$id": key, "key": key, "type": kind, "array": array}));
        let v = Aggregate::new(
            &[
                definition("meta", "object", false),
                definition("place", "point", false),
                definition("route", "linestring", false),
                definition("area", "polygon", false),
                definition("embedding", "vector", false),
                definition("flag", "boolean", false),
                definition("codes", "integer", true),
                definition("name", "string", false),
                definition("size", "integer", false),
                definition("ratio", "double", false),
                definition("born", "datetime", false),
            ],
            true,
            false,
        );
        for attribute in ["meta", "place", "route", "area", "embedding", "flag", "codes"] {
            for query in [Query::min(attribute, ""), Query::max(attribute, "")] {
                assert!(!v.check(&query), "{attribute}");
                assert!(v.description().ends_with(&format!(": {attribute}")));
            }
            assert!(v.check(&Query::count(attribute, "")), "{attribute}: count needs no order");
        }
        for attribute in ["name", "size", "ratio", "born", "$id", "$createdAt"] {
            assert!(v.check(&Query::min(attribute, "")), "{attribute}: {}", v.description());
            assert!(v.check(&Query::max(attribute, "")), "{attribute}: {}", v.description());
        }
    }

    /// The queries `find()`, `count()`, `sum()` and `aggregate()` refuse or
    /// run in the PHP test, through the `Documents` validator those reads
    /// build; the verdicts were recorded from PHP 8.1.3's `Documents`.
    #[test]
    fn the_documents_validator_judges_the_reads_of_the_database_cases_as_php() {
        use utopia_database::relationship::{Relationship, RelationshipSide};

        let items_relationship = Relationship::one_to_many("items").two_way(true).key("items").two_way_key("owner");
        let owners = vec![
            Attribute::string("name", 32),
            Attribute::integer("score"),
            Attribute::boolean("active"),
            Attribute::string("tags", 32).array(true),
            Attribute::relationship("items", items_relationship.clone(), RelationshipSide::Parent).unwrap(),
        ];
        let items = [
            Attribute::string("title", 32),
            Attribute::integer("price"),
            Attribute::boolean("featured"),
            Attribute::string("labels", 32).array(true),
            Attribute::string("ownerRef", 32),
            Attribute::relationship("owner", items_relationship.inverse("owners"), RelationshipSide::Child).unwrap(),
        ];
        let items: Vec<utopia_database::Value> =
            items.iter().map(|a| utopia_database::Value::from(a.to_document().unwrap())).collect();
        let collection = utopia_database::Document::from_pairs([
            ("$id", s("items")),
            ("attributes", list(items)),
            ("indexes", list(Vec::new())),
        ])
        .unwrap();

        let v = Documents::new(
            &owners,
            &[],
            &profile(&[
                Capability::DefinedAttributes,
                Capability::UnsignedBigInt,
                Capability::Joins,
                Capability::Aggregations,
                Capability::OrderRandom,
            ]),
            5000,
        );
        v.set_joined_collections(&[collection]).unwrap();
        let join = || Query::join("items", "it", on("$id", "ownerRef")).unwrap();
        let ordered =
            "requires an attribute whose values are ordered, not an array, object, boolean, spatial or vector one";
        let cases: Vec<(Vec<Query>, String)> = vec![
            (
                vec![q(Method::Exists, "name", json!(["_permissions"]))],
                "Invalid query: Attribute not found in schema: _permissions".into(),
            ),
            (vec![Query::exists(qa(json!(["_uid"])))], "Invalid query: Attribute not found in schema: _uid".into()),
            (
                vec![Query::not_exists(qa(json!(["_permissions"])))],
                "Invalid query: Attribute not found in schema: _permissions".into(),
            ),
            (
                vec![Query::exists(qa(json!(["name", "missing"])))],
                "Invalid query: Attribute not found in schema: missing".into(),
            ),
            (
                vec![Query::exists(qa(json!(["items"])))],
                "Invalid query: Cannot query on virtual relationship attribute".into(),
            ),
            (
                vec![Query::exists(qa(json!(["items.title"])))],
                "Invalid query: Exists queries take attributes of the collection or of a join alias: items.title"
                    .into(),
            ),
            (vec![Query::not_exists(qa(json!([7])))], "Invalid query: NotExists queries take attribute names".into()),
            (vec![Query::exists(qa(json!(["name"])))], "ok".into()),
            (vec![Query::exists(qa(json!(["name", "score", "$createdAt"])))], "ok".into()),
            (vec![Query::not_exists("name")], "ok".into()),
            (vec![Query::exists(qa(json!(["tags"])))], "ok".into()),
            (vec![join(), Query::exists(qa(json!(["it.title"])))], "ok".into()),
            (
                vec![Query::group_by(["items"]), Query::count("*", "rows")],
                "Invalid query: Cannot group by virtual relationship attribute: items".into(),
            ),
            (
                vec![Query::count("items", "rows")],
                "Invalid query: Cannot aggregate virtual relationship attribute: items".into(),
            ),
            (
                vec![Query::count_distinct("items", "rows")],
                "Invalid query: Cannot aggregate virtual relationship attribute: items".into(),
            ),
            (
                vec![Query::min("items", "least")],
                "Invalid query: Cannot aggregate virtual relationship attribute: items".into(),
            ),
            (
                vec![Query::max("items", "most")],
                "Invalid query: Cannot aggregate virtual relationship attribute: items".into(),
            ),
            (vec![Query::max("active", "most")], format!("Invalid query: Aggregate max {ordered}: active")),
            (vec![Query::min("tags", "least")], format!("Invalid query: Aggregate min {ordered}: tags")),
            (
                vec![join(), Query::max("it.featured", "most")],
                format!("Invalid query: Aggregate max {ordered}: it.featured"),
            ),
            (
                vec![join(), Query::min("it.labels", "least")],
                format!("Invalid query: Aggregate min {ordered}: it.labels"),
            ),
            (vec![join(), Query::max("featured", "most")], format!("Invalid query: Aggregate max {ordered}: featured")),
            (
                vec![
                    join(),
                    Query::min("name", "first"),
                    Query::max("score", "best"),
                    Query::max("$createdAt", "latest"),
                    Query::max("it.price", "dearest"),
                    Query::min("it.title", "title"),
                ],
                "ok".into(),
            ),
            (vec![join(), Query::select(["name", "it.owner"]), Query::order_desc("it.owner", None)], "ok".into()),
            (
                vec![
                    Query::join(
                        "items",
                        "it",
                        vec![Query::on("$id", "ownerRef", "="), Query::equal("it.title", ["pen"])],
                    )
                    .unwrap(),
                ],
                "ok".into(),
            ),
            (
                vec![
                    Query::join(
                        "items",
                        "it",
                        vec![Query::on("$id", "ownerRef", "="), Query::contains("it.labels", ["x"])],
                    )
                    .unwrap(),
                ],
                "ok".into(),
            ),
        ];
        for (i, (queries, expected)) in cases.into_iter().enumerate() {
            let verdict = if v.check(&queries) { "ok".to_owned() } else { v.description() };
            assert_eq!(verdict, expected, "case {i}");
        }
    }

    #[test]
    fn document_queries_reject_joins_unless_enabled() {
        let attributes = name_attribute();
        let joins = [
            Query::join("notes", "note", on("$id", "customerId")).unwrap(),
            Query::join("notes", "note", vec![Query::on("$id", "customerId", "="), Query::equal("note.body", ["x"])])
                .unwrap(),
        ];
        let enabled = DocumentQueries::new(
            &attributes,
            &profile(&[Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::Joins]),
            5000,
        );
        let disabled = DocumentQueries::new(
            &attributes,
            &profile(&[Capability::DefinedAttributes, Capability::UnsignedBigInt]),
            5000,
        );
        for join in joins {
            assert!(enabled.check(std::slice::from_ref(&join)), "{}", enabled.description());
            assert!(!disabled.check(&[join]));
            assert_eq!(disabled.description(), "Invalid query: Invalid query method: join");
        }
        assert!(disabled.check(&[Query::select(["name"])]), "{}", disabled.description());
    }
}

/// `FilterOptions` keeps PHP's defaults.
#[test]
fn filter_options_default_as_php() {
    let options = FilterOptions::default();
    assert_eq!(options.max_values_count, 5000);
    assert!(options.support_for_attributes);
    assert!(options.support_unsigned_big_int);
}
