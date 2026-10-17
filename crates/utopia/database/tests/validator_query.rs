//! The validators of one query (`Validator\Query\*`).
//!
//! PHP: `tests/unit/Validator/Query/*.php`, `tests/unit/Validator/CursorDocumentTest.php` and
//! `tests/unit/Validator/QueryValidatorCoverageTest.php` of utopia-php/database 8.1.3.

mod support;

use serde_json::json;
use support::*;
use utopia_database::Query;
use utopia_database::attribute::Attribute;
use utopia_database::query::DEFAULT_ALIAS;
use utopia_database::relationship::{Relationship, RelationshipSide};
use utopia_database::validator::query::aggregate::Aggregate;
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
use utopia_query::Method;

/// PHP: `tests/unit/Validator/Query/AggregateTest.php`.
mod aggregate {
    use super::*;

    fn validator() -> Aggregate {
        Aggregate::new(&attrs(json!([{"$id": "price", "key": "price", "type": "double", "array": false}])), true, false)
    }

    #[test]
    fn value_success() {
        let v = validator();
        assert!(v.check(&Query::sum("price", "")));
        assert!(v.check(&Query::avg("price", "avg_price")));
        assert!(v.check(&Query::count("*", "cnt")));
        assert!(v.check(&Query::count("*", "")));
        assert!(v.check(&Query::count_distinct("$id", "")));
    }

    #[test]
    fn unknown_attribute_rejected() {
        let v = validator();
        assert!(!v.check(&Query::sum("nonexistent", "")));
        assert_eq!(v.description(), "Attribute not found in schema: nonexistent");
    }

    #[test]
    fn invalid_alias_rejected() {
        let v = validator();
        assert!(!v.check(&Query::sum("price", "a b")));
        assert_eq!(v.description(), "Invalid aggregate alias");
        assert!(!v.check(&Query::sum("price", "1alias")));
        assert_eq!(v.description(), "Invalid aggregate alias");
    }

    #[test]
    fn unknown_attribute_allowed_without_attribute_support() {
        let v = Aggregate::new(&[], false, false);
        assert!(v.check(&Query::sum("anything", "")));
    }
}

/// PHP: `tests/unit/Validator/Query/CursorTest.php`.
mod cursor {
    use super::*;

    #[test]
    fn value_success() {
        let v = Cursor::default();
        assert!(v.check(&q(Method::CursorAfter, "", json!(["asdf"]))));
        assert!(v.check(&q(Method::CursorBefore, "", json!(["asdf"]))));
    }

    #[test]
    fn value_failure() {
        let v = Cursor::default();
        assert!(!v.check(&Query::limit(-1)));
        assert_eq!(v.description(), "Invalid query");
        assert!(!v.check(&Query::limit(101)));
        assert!(!v.check(&Query::offset(-1)));
        assert!(!v.check(&Query::offset(5001)));
        assert!(!v.check(&Query::equal("attr", ["v"])));
        assert!(!v.check(&Query::order_asc("attr", None)));
        assert!(!v.check(&Query::order_desc("attr", None)));
    }

    #[test]
    fn non_query_value_returns_false() {
        let v = Cursor::default();
        assert!(!v.is_valid(&s("some_string")));
        assert!(!v.is_valid(&utopia_database::Value::Int(42)));
        assert!(!v.is_valid(&utopia_database::Value::Null));
        assert!(!v.is_valid(&list(vec![s("array")])));
    }

    #[test]
    fn invalid_cursor_value_fails_uid_validation() {
        let v = Cursor::default();
        assert!(!v.check(&q(Method::CursorAfter, "", json!(["x".repeat(300)]))));
        assert!(v.description().contains("Invalid cursor"));
        assert!(!v.check(&q(Method::CursorBefore, "", json!([""]))));
    }
}

/// PHP: `tests/unit/Validator/CursorDocumentTest.php`.
mod cursor_document {
    use super::*;

    #[test]
    fn a_document_is_accepted() {
        assert!(Cursor::default().check(&cursor_after(json!({"$id": "movie1"}))));
        assert!(Cursor::default().check(&cursor_before(json!({"$id": "movie1"}))));
    }

    #[test]
    fn a_document_id_is_accepted() {
        let v = Cursor::default();
        assert!(v.check(&q(Method::CursorAfter, "", json!(["movie1"]))));
        assert!(v.check(&q(Method::CursorBefore, "", json!(["movie1"]))));
    }

    #[test]
    fn an_array_is_rejected() {
        let v = Cursor::default();
        assert!(!v.check(&q(Method::CursorAfter, "", json!([{"$id": "movie1"}]))));
        assert!(v.description().contains("Invalid cursor"));
        assert!(!v.check(&q(Method::CursorBefore, "", json!([{"$id": "movie1"}]))));
    }

    // PHP 8.3.6 Validator/Query/Aggregate.php:125-129: min() and max() of $permissions, stored as a JSON list, are
    // refused as of an array attribute; count() still counts it.
    #[test]
    fn min_and_max_of_permissions_are_refused() {
        let v = Aggregate::new(
            &attrs(json!([{"$id": "price", "key": "price", "type": "double", "array": false}])),
            true,
            false,
        );
        assert!(!v.check(&Query::min("$permissions", "")));
        assert_eq!(
            v.description(),
            "Aggregate min requires an attribute whose values are ordered, not an array, object, boolean, spatial or vector one: $permissions"
        );
        assert!(!v.check(&Query::max("$permissions", "")));
        assert!(v.check(&Query::count("$permissions", "")), "{}", v.description());
        assert!(v.check(&Query::max("$createdAt", "")), "{}", v.description());
    }
}

/// PHP: `tests/unit/Validator/Query/FilterObjectPathTest.php`.
mod filter_object_path {
    use super::*;

    fn validator() -> Filter {
        Filter::new(&[Attribute::object("meta"), Attribute::string("secret", 64)], "integer")
    }

    #[test]
    fn accepts_paths_of_plain_keys() {
        let v = validator();
        for query in [
            Query::equal("meta.a", ["x"]),
            Query::equal("meta.user-info.home_city2", ["x"]),
            Query::starts_with("meta.user.email", "alice@"),
            Query::is_null("meta.a"),
            Query::or([Query::equal("meta.a", ["x"]), Query::equal("meta.b", ["y"])]),
            Query::equal("meta", qa(json!([{"a": "x"}]))),
        ] {
            assert!(v.check(&query), "{}", v.description());
        }
    }

    #[test]
    fn refuses_paths_with_keys_outside_the_allowed_chars() {
        let v = validator();
        for query in [
            Query::equal("meta.a' IN ('x') OR secret='s2' OR 'x", ["x"]),
            Query::equal("meta.a'||(select 1)||'", ["x"]),
            Query::equal("meta.a' OR 1=1 --", ["x"]),
            Query::equal("meta.a'b.c", ["x"]),
            Query::equal("meta.first name", ["x"]),
            Query::equal("meta..a", ["x"]),
            Query::equal("meta.", ["x"]),
            Query::equal("meta.a\n", ["x"]),
            Query::less_than("meta.a'", "5"),
            Query::starts_with("meta.a'", "x"),
            Query::is_null("meta.a'"),
            Query::or([Query::equal("meta.a", ["x"]), Query::equal("meta.b'", ["y"])]),
            Query::and([Query::equal("meta.a", ["x"]), Query::is_not_null("meta.b'")]),
        ] {
            assert!(!v.check(&query), "{:?}", query.attribute());
        }
    }

    #[test]
    fn refuses_unsafe_paths_without_defined_attributes() {
        let v = Filter::with_options(
            &[Attribute::object("meta")],
            "integer",
            FilterOptions { support_for_attributes: false, ..FilterOptions::default() },
        );
        assert!(v.check(&Query::equal("meta.a", ["x"])));
        assert!(!v.check(&Query::equal("meta.a' OR 'x", ["x"])));
    }
}

/// PHP: `tests/unit/Validator/Query/FilterTest.php`.
mod filter {
    use super::*;

    fn validator() -> Filter {
        let attributes = attrs(json!([
            {"$id": "string", "key": "string", "type": "string", "array": false},
            {"$id": "string_array", "key": "string_array", "type": "string", "array": true},
            {"$id": "integer_array", "key": "integer_array", "type": "integer", "array": true},
            {"$id": "integer", "key": "integer", "type": "integer", "array": false},
            {"$id": "bigint_unsigned", "key": "bigint_unsigned", "type": "bigint", "array": false, "signed": false},
            {"$id": "bigint_signed", "key": "bigint_signed", "type": "bigint", "array": false, "signed": true},
            {"$id": "bigint_legacy", "key": "bigint_legacy", "type": "bigint", "array": false, "signed": true},
        ]));
        Filter::with_options(
            &attributes,
            "integer",
            FilterOptions { support_unsigned_big_int: true, ..FilterOptions::default() },
        )
    }

    #[test]
    fn success() {
        let v = validator();
        assert!(v.check(&Query::between("string", "1975-12-06", "2050-12-06")));
        assert!(v.check(&Query::is_not_null("string")));
        assert!(v.check(&Query::is_null("string")));
        assert!(v.check(&Query::starts_with("string", "super")));
        assert!(v.check(&Query::ends_with("string", "man")));
        assert!(v.check(&Query::contains_any("string_array", ["super"])));
        assert!(v.check(&Query::contains_any("integer_array", [100, 10, -1])));
        assert!(v.check(&Query::contains_any("string_array", ["1", "10", "-1"])));
        assert!(v.check(&Query::contains_string("string", ["super"])));
        assert!(v.check(&Query::equal("bigint_unsigned", ["18446744073709551615"])));
        assert!(v.check(&Query::equal("bigint_signed", ["-9223372036854775808"])));
        assert!(v.check(&Query::equal("bigint_legacy", ["9223372036854775807"])));
    }

    #[test]
    fn failure() {
        let v = validator();
        assert!(!v.check(&Query::select(["attr"])));
        assert_eq!(v.description(), "Invalid query");
        for query in [
            Query::limit(1),
            Query::limit(0),
            Query::limit(100),
            Query::limit(-1),
            Query::limit(101),
            Query::offset(1),
            Query::offset(0),
            Query::offset(5000),
            Query::offset(-1),
            Query::offset(5001),
            Query::equal("dne", ["v"]),
            Query::equal("", ["v"]),
            Query::order_asc("string", None),
            Query::order_desc("string", None),
            q(Method::CursorAfter, "", json!(["asdf"])),
            q(Method::CursorBefore, "", json!(["asdf"])),
            Query::contains_string("integer", ["super"]),
            Query::equal("integer_array", [100, -1]),
            Query::contains_any("integer_array", [10.6]),
        ] {
            assert!(!v.check(&query), "{:?}", query.method());
        }
    }

    #[test]
    fn type_mismatch() {
        let v = validator();
        assert!(!v.check(&Query::equal("string", [false])));
        assert_eq!(v.description(), "Query value is invalid for attribute \"string\"");
        assert!(!v.check(&Query::equal("string", [1])));
        assert_eq!(v.description(), "Query value is invalid for attribute \"string\"");
    }

    #[test]
    fn empty_values() {
        let v = validator();
        assert!(!v.check(&Query::contains_string("string", Vec::<String>::new())));
        assert_eq!(v.description(), "Contains queries require at least one value.");
        assert!(!v.check(&Query::equal("string", Vec::<String>::new())));
        assert_eq!(v.description(), "Equal queries require at least one value.");
    }

    #[test]
    fn max_values_count() {
        let v = validator();
        let max = v.max_values_count();
        let values: Vec<i64> = (1..=max + 1).collect();
        assert!(!v.check(&Query::equal("integer", values)));
        assert_eq!(v.description(), format!("Query on attribute has greater than {max} values: integer"));
    }

    #[test]
    fn not_contains() {
        let v = validator();
        assert!(v.check(&Query::not_contains("string", ["unwanted"])));
        assert!(v.check(&Query::not_contains("string_array", ["spam", "unwanted"])));
        assert!(v.check(&Query::not_contains("integer_array", [100, 200])));
        assert!(!v.check(&Query::not_contains("string", Vec::<String>::new())));
        assert_eq!(v.description(), "NotContains queries require at least one value.");
    }

    #[test]
    fn not_search() {
        let v = validator();
        assert!(v.check(&Query::not_search("string", "unwanted")));
        assert!(!v.check(&Query::not_search("string_array", "unwanted")));
        assert_eq!(v.description(), "Cannot query notSearch on attribute \"string_array\" because it is an array.");
        assert!(!v.check(&q(Method::NotSearch, "string", json!(["word1", "word2"]))));
        assert_eq!(v.description(), "NotSearch queries require exactly one value.");
    }

    #[test]
    fn not_starts_with() {
        let v = validator();
        assert!(v.check(&Query::not_starts_with("string", "temp")));
        assert!(!v.check(&Query::not_starts_with("string_array", "temp")));
        assert_eq!(v.description(), "Cannot query notStartsWith on attribute \"string_array\" because it is an array.");
        assert!(!v.check(&q(Method::NotStartsWith, "string", json!(["prefix1", "prefix2"]))));
        assert_eq!(v.description(), "NotStartsWith queries require exactly one value.");
    }

    #[test]
    fn not_ends_with() {
        let v = validator();
        assert!(v.check(&Query::not_ends_with("string", ".tmp")));
        assert!(!v.check(&Query::not_ends_with("string_array", ".tmp")));
        assert_eq!(v.description(), "Cannot query notEndsWith on attribute \"string_array\" because it is an array.");
        assert!(!v.check(&q(Method::NotEndsWith, "string", json!(["suffix1", "suffix2"]))));
        assert_eq!(v.description(), "NotEndsWith queries require exactly one value.");
    }

    #[test]
    fn not_between() {
        let v = validator();
        assert!(v.check(&Query::not_between("integer", 0, 50)));
        assert!(!v.check(&Query::not_between("integer_array", 1, 10)));
        assert_eq!(v.description(), "Cannot query notBetween on attribute \"integer_array\" because it is an array.");
        assert!(!v.check(&q(Method::NotBetween, "integer", json!([10]))));
        assert_eq!(v.description(), "NotBetween queries require exactly two values.");
        assert!(!v.check(&q(Method::NotBetween, "integer", json!([10, 20, 30]))));
        assert_eq!(v.description(), "NotBetween queries require exactly two values.");
    }

    #[test]
    fn dotted_join_alias_is_accepted_after_allow_join_aliases() {
        let v = validator();
        v.allow_join_aliases(&["sec"]);
        assert!(v.check(&Query::equal("sec.amount", [777])));
        assert!(v.check(&Query::equal("sec.$id", ["abc"])));
    }

    #[test]
    fn unknown_join_alias_is_rejected() {
        let v = validator();
        v.allow_join_aliases(&["sec"]);
        assert!(!v.check(&Query::equal("other.amount", [777])));
        assert_eq!(v.description(), "Attribute not found in schema: other");
    }

    #[test]
    fn unqualified_join_attribute_is_still_rejected() {
        let v = validator();
        v.allow_join_aliases(&["sec"]);
        assert!(!v.check(&Query::equal("amount", [777])));
        assert_eq!(v.description(), "Attribute not found in schema: amount");
    }

    #[test]
    fn join_alias_is_rejected_after_reset() {
        let v = validator();
        v.allow_join_aliases(&["sec"]);
        v.reset_join_aliases();
        assert!(!v.check(&Query::equal("sec.amount", [777])));
        assert_eq!(v.description(), "Attribute not found in schema: sec");
    }

    #[test]
    fn nested_and_or_join_alias_is_accepted() {
        let v = validator();
        v.allow_join_aliases(&["meta"]);
        assert!(v.check(&Query::and([
            Query::equal("string", ["Main"]),
            Query::or([Query::equal("meta.score", [10]), Query::equal("integer", [2])]),
        ])));
    }

    #[test]
    fn nested_and_or_unknown_join_alias_is_rejected() {
        let v = validator();
        v.allow_join_aliases(&["meta"]);
        assert!(!v.check(&Query::and([
            Query::equal("string", ["Main"]),
            Query::or([Query::equal("other.score", [10]), Query::equal("integer", [2])]),
        ])));
        assert_eq!(v.description(), "Attribute not found in schema: other");
    }

    #[test]
    fn and_or_string_child_is_rejected() {
        let v = validator();
        let children = || {
            vec![
                utopia_query::string(Query::equal("string", ["Main"]).to_json().unwrap()),
                utopia_query::string(Query::equal("integer", [2]).to_json().unwrap()),
            ]
        };
        assert!(!v.check(&q_nested(Method::And, "", children(), "")));
        assert_eq!(v.description(), "And queries can only contain filter queries");
        assert!(!v.check(&q_nested(Method::Or, "", children(), "")));
        assert_eq!(v.description(), "Or queries can only contain filter queries");
    }

    #[test]
    fn exists_without_attributes_is_rejected() {
        let v = validator();
        assert!(!v.check(&Query::exists(utopia_query::Array::new())));
        assert_eq!(v.description(), "Exists queries require at least one value.");
        assert!(!v.check(&Query::not_exists(utopia_query::Array::new())));
        assert_eq!(v.description(), "NotExists queries require at least one value.");
    }

    #[test]
    fn or_with_non_document_cursor_is_rejected_as_non_filter() {
        let v = validator();
        assert!(!v.check(&Query::or([
            Query::equal("string", ["Main"]),
            Query::cursor_after_value(qv(json!({"$id": "row"}))),
        ])));
        assert_eq!(v.description(), "Or queries can only contain filter queries");
    }

    #[test]
    fn and_with_non_document_cursor_is_rejected_as_non_filter() {
        let v = validator();
        assert!(!v.check(&Query::and([Query::equal("string", ["Main"]), Query::cursor_before_value(qv(json!({})))])));
        assert_eq!(v.description(), "And queries can only contain filter queries");
    }

    #[test]
    fn elem_match_with_non_document_cursor_is_rejected_as_non_filter() {
        let v = Filter::with_options(
            &[],
            "integer",
            FilterOptions { support_for_attributes: false, ..FilterOptions::default() },
        );
        assert!(!v.check(&Query::elem_match(
            "items",
            [Query::equal("name", ["Main"]), Query::cursor_after_value(qv(json!({"$id": "row"})))]
        )));
        assert_eq!(v.description(), "elemMatch queries can only contain filter queries");
    }

    #[test]
    fn or_with_document_cursor_is_rejected_as_non_filter() {
        let v = validator();
        assert!(!v.check(&Query::or([Query::equal("string", ["Main"]), cursor_after(json!({"$id": "row"}))])));
        assert_eq!(v.description(), "Or queries can only contain filter queries");
    }

    #[test]
    fn anything_but_a_query_fails_as_php_does() {
        let v = validator();
        let error = v.is_valid(&s("x")).unwrap_err();
        assert_eq!(error.message(), "Call to a member function getMethod() on string");
    }
}

/// PHP: `tests/unit/Validator/Query/FilterTypeBranchesTest.php`.
mod filter_type_branches {
    use super::*;

    fn validator() -> Filter {
        let attributes = attrs(json!([
            {"$id": "vector", "key": "vector", "type": "vector", "array": false, "size": 3},
            {"$id": "point", "key": "point", "type": "point", "array": false},
            {"$id": "object", "key": "object", "type": "object", "array": false},
            {"$id": "text", "key": "text", "type": "string", "array": false},
        ]));
        Filter::with_options(
            &attributes,
            "integer",
            FilterOptions { support_unsigned_big_int: true, ..FilterOptions::default() },
        )
    }

    #[test]
    fn vector_requires_a_numeric_array_of_the_declared_size() {
        let v = validator();
        assert!(v.check(&Query::equal("vector", qa(json!([[1.0, 2.0, 3.0]])))));
        assert!(!v.check(&Query::equal("vector", ["not-an-array"])));
        assert_eq!(v.description(), "Vector query value must be an array");
        assert!(!v.check(&Query::equal("vector", qa(json!([[1.0, "two", 3.0]])))));
        assert_eq!(v.description(), "Vector query value must contain only numeric values");
        assert!(!v.check(&Query::equal("vector", qa(json!([[1.0, 2.0]])))));
        assert_eq!(v.description(), "Vector query value must have 3 elements");
    }

    #[test]
    fn spatial_requires_an_array_value() {
        let v = validator();
        assert!(v.check(&Query::equal("point", qa(json!([[1.0, 2.0]])))));
        assert!(!v.check(&Query::equal("point", ["1,2"])));
        assert_eq!(v.description(), "Spatial data must be an array");
    }

    #[test]
    fn distance_must_be_finite() {
        let v = validator();
        let point = || qa(json!([1.0, 2.0]));
        for distance in [f64::NAN, f64::INFINITY, f64::NEG_INFINITY] {
            for query in [
                Query::distance_equal("point", point(), distance, false),
                Query::distance_not_equal("point", point(), distance, false),
                Query::distance_greater_than("point", point(), distance, false),
                Query::distance_less_than("point", point(), distance, true),
            ] {
                assert!(!v.check(&query));
                assert_eq!(v.description(), "Distance query requires a finite distance");
            }
        }
    }

    #[test]
    fn finite_distance_is_valid() {
        let v = validator();
        assert!(v.check(&Query::distance_less_than("point", qa(json!([1.0, 2.0])), 1000, true)));
        assert!(v.check(&Query::distance_greater_than("point", qa(json!([1.0, 2.0])), 0.5, false)));
    }

    #[test]
    fn object_containment_rejects_mixed_key_arrays() {
        let v = validator();
        assert!(v.check(&Query::equal("object", qa(json!([{"a": 1}])))));
        assert!(v.check(&Query::equal("object", ["plain-string"])));
        assert!(!v.check(&Query::equal("object", qa(json!([{"a": 1, "0": "b"}])))));
        assert_eq!(v.description(), "Invalid object query structure for attribute \"object\"");
        assert!(!v.check(&Query::equal("object", qa(json!([{"nested": {"x": 1, "0": "y"}}])))));
    }

    #[test]
    fn a_dotted_object_path_is_validated_as_a_string() {
        assert!(validator().check(&Query::equal("object.nested", ["a-string"])));
    }

    #[test]
    fn every_value_is_checked_not_just_the_first() {
        let v = validator();
        assert!(!v.check(&Query::equal("vector", qa(json!([[1.0, 2.0, 3.0], "not-an-array"])))));
        assert_eq!(v.description(), "Vector query value must be an array");
    }
}

/// PHP: `tests/unit/Validator/Query/GroupByTest.php`.
mod group_by {
    use super::*;

    fn validator() -> GroupBy {
        GroupBy::new(
            &attrs(json!([
                {"$id": "name", "key": "name", "type": "string", "array": false},
                {"$id": "status", "key": "status", "type": "string", "array": false},
            ])),
            true,
            false,
        )
    }

    #[test]
    fn value_success() {
        let v = validator();
        assert!(v.check(&Query::group_by(["name"])));
        assert!(v.check(&Query::group_by(["name", "status"])));
        assert!(v.check(&Query::group_by(["$id"])));
    }

    #[test]
    fn value_failure() {
        let v = validator();
        assert!(!v.check(&Query::group_by(Vec::<String>::new())));
        assert_eq!(v.description(), "GroupBy requires at least one attribute");
        assert!(!v.check(&q(Method::GroupBy, "", json!([123]))));
        assert_eq!(v.description(), "GroupBy attributes must be non-empty strings");
        assert!(!v.check(&q(Method::GroupBy, "", json!([""]))));
        assert_eq!(v.description(), "GroupBy attributes must be non-empty strings");
    }

    #[test]
    fn unknown_attribute_rejected() {
        let v = validator();
        assert!(!v.check(&Query::group_by(["nonexistent"])));
        assert_eq!(v.description(), "Attribute not found in schema: nonexistent");
        assert!(!v.check(&Query::group_by(["name", "nonexistent"])));
        assert_eq!(v.description(), "Attribute not found in schema: nonexistent");
    }

    #[test]
    fn unknown_attribute_allowed_without_attribute_support() {
        assert!(GroupBy::new(&[], false, false).check(&Query::group_by(["anything"])));
    }
}

/// PHP: `tests/unit/Validator/Query/HavingTest.php`.
mod having {
    use super::*;

    #[test]
    fn value_success() {
        assert!(Having::default().check(&Query::having([Query::equal("count", [1])])));
    }

    #[test]
    fn value_failure() {
        let v = Having::default();
        assert!(!v.check(&Query::having([])));
        assert_eq!(v.description(), "Having requires at least one condition");
        assert!(!v.check(&q(Method::Having, "", json!(["count > 1"]))));
        assert_eq!(v.description(), "Having conditions must be Query instances");
    }
}

/// PHP: `tests/unit/Validator/Query/JoinAliasTest.php`.
mod join_alias {
    use super::*;

    fn on() -> Vec<Query> {
        vec![Query::on("$id", "customerId", "=")]
    }

    #[test]
    fn accepts_alias() {
        for join in [
            Query::join("orders", "j0", on()).unwrap(),
            Query::join("orders", "ord", on()).unwrap(),
            Query::left_join("orders", "_order_2", on()).unwrap(),
            Query::cross_join("orders", "ord").unwrap(),
            Query::right_join("orders", "ord", on()).unwrap(),
            // 8.3.6: a join without an alias is read under its table ID.
            Query::join("orders", "", on()).unwrap(),
            Query::cross_join("orders", "").unwrap(),
            q_nested(Method::Join, "orders", vec![nested(Query::on("$id", "customerId", "="))], ""),
        ] {
            let v = Join::default();
            assert!(v.check(&join), "{}", v.description());
        }
    }

    #[test]
    fn rejects_alias() {
        let main = DEFAULT_ALIAS;
        let upper = DEFAULT_ALIAS.to_uppercase();
        let invalid =
            "Join alias must start with a letter or an underscore and contain only letters, digits and underscores";
        for (join, message) in [
            (
                Query::join("orders", main, on()).unwrap(),
                format!("Join alias \"{main}\" is reserved for the main collection"),
            ),
            (
                Query::join("orders", upper.as_str(), on()).unwrap(),
                format!("Join alias \"{upper}\" is reserved for the main collection"),
            ),
            (
                Query::cross_join("orders", main).unwrap(),
                format!("Join alias \"{main}\" is reserved for the main collection"),
            ),
            (
                Query::full_outer_join("orders", main, on()).unwrap(),
                format!("Join alias \"{main}\" is reserved for the main collection"),
            ),
            (Query::join("orders", "my-alias", on()).unwrap(), invalid.to_owned()),
            (Query::left_join("orders", "1st", on()).unwrap(), invalid.to_owned()),
            (Query::join("orders", "a.b", on()).unwrap(), invalid.to_owned()),
            (Query::cross_join("orders", "x`y").unwrap(), invalid.to_owned()),
        ] {
            let v = Join::default();
            assert!(!v.check(&join));
            assert_eq!(v.description(), message);
        }
    }
}

/// PHP: `tests/unit/Validator/Query/LimitTest.php`.
mod limit {
    use super::*;

    #[test]
    fn value_success() {
        let v = Limit::new(100);
        assert!(v.check(&Query::limit(1)));
        assert!(v.check(&Query::limit(100)));
    }

    #[test]
    fn value_failure() {
        let v = Limit::new(100);
        assert!(!v.check(&Query::limit(0)));
        assert_eq!(v.description(), "Invalid limit: Value must be a valid range between 1 and 100");
        assert!(!v.check(&Query::limit(0)));
        assert!(!v.check(&Query::limit(-1)));
        assert!(!v.check(&Query::limit(101)));
    }
}

/// PHP: `tests/unit/Validator/Query/OffsetTest.php`.
mod offset {
    use super::*;

    #[test]
    fn value_success() {
        let v = Offset::new(5000);
        assert!(v.check(&Query::offset(1)));
        assert!(v.check(&Query::offset(0)));
        assert!(v.check(&Query::offset(5000)));
    }

    #[test]
    fn value_failure() {
        let v = Offset::new(5000);
        assert!(!v.check(&Query::offset(-1)));
        assert_eq!(v.description(), "Invalid offset: Value must be a valid range between 0 and 5,000");
        assert!(!v.check(&Query::offset(5001)));
        assert!(!v.check(&q(Method::Offset, "", json!(["foo"]))));
        assert_eq!(v.description(), "Invalid offset: Value must be a valid number");
        assert!(!v.check(&Query::equal("attr", ["v"])));
        assert!(!v.check(&Query::order_asc("attr", None)));
        assert!(!v.check(&Query::order_desc("attr", None)));
        assert!(!v.check(&Query::limit(100)));
    }
}

/// PHP: `tests/unit/Validator/Query/OrderTest.php`.
mod order {
    use super::*;

    fn validator() -> Order {
        Order::new(
            &attrs(json!([
                {"$id": "attr", "key": "attr", "type": "string", "array": false},
                {"$id": "$sequence", "key": "$sequence", "type": "string", "array": false},
            ])),
            true,
            true,
        )
    }

    #[test]
    fn value_success() {
        let v = validator();
        assert!(v.check(&Query::order_asc("attr", None)));
        assert!(v.check(&Query::order_asc("", None)));
        assert!(v.check(&Query::order_desc("attr", None)));
        assert!(v.check(&Query::order_desc("", None)));
    }

    #[test]
    fn value_failure() {
        let v = validator();
        assert!(!v.check(&Query::limit(-1)));
        assert_eq!(v.description(), "Invalid query");
        for query in [
            Query::limit(101),
            Query::offset(-1),
            Query::offset(5001),
            Query::equal("attr", ["v"]),
            Query::equal("dne", ["v"]),
            Query::equal("", ["v"]),
            Query::order_desc("dne", None),
            Query::order_asc("dne", None),
        ] {
            assert!(!v.check(&query));
        }
    }

    #[test]
    fn dotted_attribute_with_relationship_base() {
        let profile =
            Attribute::relationship("profile", Relationship::one_to_one("profiles"), RelationshipSide::Parent).unwrap();
        let v = Order::new(&[Attribute::from_document(&profile.to_document().unwrap()).unwrap()], true, true);
        assert!(!v.check(&Query::order_asc("profile.name", None)));
        assert_eq!(v.description(), "Cannot order by nested attribute: profile");
    }

    #[test]
    fn dotted_attribute_not_in_schema() {
        let v = validator();
        assert!(!v.check(&Query::order_asc("unknown.field", None)));
        assert_eq!(v.description(), "Attribute not found in schema: unknown");
    }

    #[test]
    fn non_query_input_returns_false() {
        let v = validator();
        assert!(!v.is_valid(&s("not_a_query")));
        assert!(!v.is_valid(&utopia_database::Value::Int(42)));
        assert!(!v.is_valid(&utopia_database::Value::Null));
    }

    #[test]
    fn order_random_is_valid() {
        assert!(validator().check(&Query::order_random()));
    }

    #[test]
    fn add_aggregation_aliases() {
        let v = validator();
        v.add_aggregation_aliases(&["total_count", "avg_price"]);
        assert!(v.check(&Query::order_asc("total_count", None)));
        assert!(v.check(&Query::order_desc("avg_price", None)));
    }

    #[test]
    fn dotted_join_alias_is_accepted_after_allow_join_aliases() {
        let v = validator();
        v.allow_join_aliases(&["sec"]);
        assert!(v.check(&Query::order_asc("sec.amount", None)));
        assert!(v.check(&Query::order_desc("sec.$id", None)));
    }

    #[test]
    fn unknown_join_alias_is_rejected() {
        let v = validator();
        v.allow_join_aliases(&["sec"]);
        assert!(!v.check(&Query::order_asc("other.amount", None)));
        assert_eq!(v.description(), "Attribute not found in schema: other");
    }

    #[test]
    fn join_alias_is_rejected_after_reset() {
        let v = validator();
        v.allow_join_aliases(&["sec"]);
        v.reset_join_aliases();
        assert!(!v.check(&Query::order_asc("sec.amount", None)));
        assert_eq!(v.description(), "Attribute not found in schema: sec");
    }
}

/// PHP: `tests/unit/Validator/Query/SelectTest.php`.
mod select {
    use super::*;

    fn validator() -> Select {
        let artist =
            Attribute::relationship("artist", Relationship::many_to_one("artists"), RelationshipSide::Parent).unwrap();
        let mut attributes = attrs(json!([{"$id": "attr", "key": "attr", "type": "string", "array": false}]));
        attributes.push(Attribute::from_document(&artist.to_document().unwrap()).unwrap());
        Select::new(&attributes, true, false)
    }

    #[test]
    fn value_success() {
        let v = validator();
        assert!(v.check(&Query::select(["*", "attr"])));
        assert!(v.check(&Query::select(["artist.name"])));
    }

    #[test]
    fn value_failure() {
        let v = validator();
        assert!(!v.check(&Query::limit(1)));
        assert_eq!(v.description(), "Invalid query");
        assert!(!v.check(&Query::select(["name.artist"])));
    }

    #[test]
    fn a_non_string_selection_is_refused_by_type() {
        for (values, expected) in [
            (json!([["attr"]]), "Attribute selection must be a string, got array"),
            (json!([["*"]]), "Attribute selection must be a string, got array"),
            (json!(["attr", ["x"]]), "Attribute selection must be a string, got array"),
            (json!([{"a": 1}]), "Attribute selection must be a string, got array"),
            (json!([1]), "Attribute selection must be a string, got int"),
            (json!([null]), "Attribute selection must be a string, got null"),
        ] {
            let v = validator();
            assert!(!v.check(&q(Method::Select, "", values)));
            assert_eq!(v.description(), expected);
        }
    }

    #[test]
    fn two_nested_selections_report_the_type_not_a_false_duplicate() {
        let v = validator();
        let query = Query::parse(r#"{"method":"select","values":[["a"],["b"]]}"#, false).unwrap();
        assert!(!v.check(&query));
        assert_eq!(v.description(), "Attribute selection must be a string, got array");
    }

    #[test]
    fn the_legitimate_flat_form_still_passes() {
        let v = validator();
        assert!(v.check(&Query::select(["attr"])));
        assert!(v.check(&Query::select(["*"])));
        assert!(v.check(&Query::select(["$id", "$createdAt"])));
        assert!(v.check(&Query::select(["artist.name"])));
    }

    #[test]
    fn dotted_join_alias_is_accepted_after_allow_join_aliases() {
        let v = validator();
        v.allow_join_aliases(&["ord"]);
        assert!(v.check(&Query::select(["ord.amount"])));
        assert!(v.check(&Query::select(["ord.$id"])));
    }

    #[test]
    fn unqualified_amount_is_still_rejected() {
        let v = validator();
        v.allow_join_aliases(&["ord"]);
        assert!(!v.check(&Query::select(["amount"])));
        assert_eq!(v.description(), "Attribute not found in schema: amount");
    }
}

/// PHP: `tests/unit/Validator/QueryValidatorCoverageTest.php`.
mod coverage {
    use std::sync::Arc;

    use super::*;
    use utopia_database::validator::queries::base::Queries;
    use utopia_database::validator::queries::indexed::Indexed;

    const MAX_VALUES: i64 = 2;

    fn filter(support_for_attributes: bool) -> Filter {
        Filter::with_options(
            &attrs(json!([
                {"$id": "location", "key": "location", "type": "point", "array": false},
                {"$id": "embedding", "key": "embedding", "type": "vector", "size": 3, "array": false},
                {
                    "$id": "author", "key": "author", "type": "relationship", "array": false,
                    "options": {
                        "relatedCollection": "authors", "relationType": "manyToOne", "twoWay": false,
                        "twoWayKey": "books", "side": "parent"
                    }
                },
            ])),
            "integer",
            FilterOptions { max_values_count: MAX_VALUES, support_for_attributes, ..FilterOptions::default() },
        )
    }

    #[test]
    fn a_join_alias_of_an_unknown_collection_keeps_the_value_limit() {
        let f = filter(true);
        f.allow_join_aliases(&["joined"]);
        assert!(f.check(&Query::equal("joined.anything", ["a", "b"])), "{}", f.description());
        assert!(!f.check(&Query::equal("joined.anything", ["a", "b", "c"])));
        assert_eq!(f.description(), "Query on attribute has greater than 2 values: joined.anything");
    }

    #[test]
    fn an_undeclared_attribute_in_schemaless_mode_keeps_the_value_limit() {
        let f = filter(false);
        assert!(f.check(&Query::equal("undeclared", ["a", "b"])), "{}", f.description());
        assert!(!f.check(&Query::equal("undeclared", ["a", "b", "c"])));
        assert_eq!(f.description(), "Query on attribute has greater than 2 values: undeclared");
    }

    #[test]
    fn an_attribute_type_without_a_value_rule_is_refused() {
        for kind in ["decimal", "json", "mystery"] {
            let error =
                Attribute::from_document(&doc(json!({"$id": "amount", "key": "amount", "type": kind, "array": false})))
                    .unwrap_err();
            assert_eq!(error.kind(), utopia_database::Kind::Structure);
            assert_eq!(error.message(), format!("Unknown attribute type: {kind}"));
        }
    }

    #[test]
    fn an_elem_match_with_an_invalid_nested_filter_is_rejected() {
        let f = filter(false);
        assert!(
            f.check(&q_nested(Method::ElemMatch, "items", vec![nested(Query::equal("sku", ["a"]))], "")),
            "{}",
            f.description()
        );
        assert!(!f.check(&q_nested(
            Method::ElemMatch,
            "items",
            vec![nested(Query::equal("sku", ["a"])), nested(q(Method::Equal, "sku", json!([])))],
            ""
        )));
        assert_eq!(f.description(), "Equal queries require at least one value.");
    }

    #[test]
    fn an_elem_match_child_must_be_a_query() {
        let f = filter(false);
        assert!(!f.check(&q(
            Method::ElemMatch,
            "items",
            json!(["{\"method\":\"equal\",\"attribute\":\"sku\",\"values\":[\"a\"]}"])
        )));
        assert_eq!(f.description(), "elemMatch queries can only contain filter queries");
    }

    #[test]
    fn a_spatial_query_needs_a_value() {
        for method in [
            Method::Crosses,
            Method::Intersects,
            Method::Overlaps,
            Method::NotTouches,
            Method::Covers,
            Method::SpatialEquals,
        ] {
            let f = filter(true);
            assert!(!f.check(&q(method, "location", json!([]))));
            let mut name = method.as_str().to_owned();
            name[..1].make_ascii_uppercase();
            assert_eq!(f.description(), format!("{name} queries require at least one value."));
            assert!(f.check(&q(method, "location", json!([[1.0, 2.0]]))), "{}", f.description());
        }
    }

    #[test]
    fn a_vector_query_takes_exactly_one_vector() {
        let f = filter(true);
        assert!(f.check(&q(Method::VectorDot, "embedding", json!([[1.0, 2.0, 3.0]]))), "{}", f.description());
        assert!(!f.check(&q(Method::VectorDot, "embedding", json!([[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]]))));
        assert_eq!(f.description(), "VectorDot queries require exactly one vector value.");
    }

    #[test]
    fn a_vector_query_on_a_joined_attribute_takes_exactly_one_vector_and_is_then_refused() {
        let f = filter(true);
        f.allow_join_aliases(&["joined"]);
        assert!(!f.check(&q(Method::VectorCosine, "joined.embedding", json!([[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]]))));
        assert_eq!(f.description(), "VectorCosine queries require exactly one vector value.");
        assert!(!f.check(&q(Method::VectorCosine, "joined.embedding", json!([[1.0, 2.0, 3.0]]))));
        assert_eq!(f.description(), "Vector queries cannot be used on a joined attribute: joined.embedding");
    }

    #[test]
    fn a_vector_query_on_a_relationship_path_is_checked_against_the_relationship() {
        let f = filter(true);
        assert!(!f.check(&q(Method::VectorEuclidean, "author.embedding", json!([[1.0, 2.0, 3.0]]))));
        assert_eq!(f.description(), "Vector queries can only be used on vector attributes");
    }

    #[test]
    fn string_queries_are_checked_against_the_indexes_like_query_objects() {
        let search = r#"{"method":"search","attribute":"name","values":["phrase"]}"#;
        let attributes = attrs(json!([{"$id": "name", "key": "name", "type": "string", "array": false}]));

        let without = Indexed::new(&attributes, &[], vec![Arc::new(Filter::new(&attributes, "integer"))]);
        assert!(!without.check(&[search]));
        assert_eq!(without.description(), "Searching by attribute \"name\" requires a fulltext index.");
        assert!(!without.check(&[Query::parse(search, false).unwrap()]));
        assert_eq!(without.description(), "Searching by attribute \"name\" requires a fulltext index.");

        let with = Indexed::new(
            &attributes,
            &[index(json!({"type": "fulltext", "attributes": ["name"]}))],
            vec![Arc::new(Filter::new(&attributes, "integer"))],
        );
        assert!(with.check(&[search]), "{}", with.description());
        assert!(!with.check(&[r#"{"method":"search""#]));
        assert!(with.description().starts_with("Invalid query: "));
    }

    #[test]
    fn string_children_of_a_logical_query_are_parsed_and_an_unparseable_one_is_rejected() {
        let v = Queries::new(vec![Arc::new(filter(true))], 0);
        let child = || utopia_query::string(r#"{"method":"equal","attribute":"embedding","values":[[1,2,3]]}"#);

        assert!(!v.check(&[q_nested(Method::Or, "", vec![child(), utopia_query::string(r#"{"method":"equal""#)], "")]));
        assert!(v.description().starts_with("Invalid query: "));
        assert!(!v.description().contains("can only contain filter queries"));

        assert!(!v.check(&[q_nested(Method::And, "", vec![child(), utopia_query::Value::Int(5)], "")]));
        assert_eq!(v.description(), "Invalid query: nested query must be a string");

        assert!(!v.check(&[q_nested(Method::Or, "", vec![child(), child()], "")]));
        assert!(v.description().contains("Or queries can only contain filter queries"));
    }

    #[test]
    fn a_query_method_validator_given_a_non_query_refuses_it() {
        let values = [s("limit(1)"), list(vec![s("limit")]), utopia_database::Value::Null];
        for value in &values {
            let aggregate = Aggregate::default();
            assert!(!aggregate.is_valid(value));
            assert_eq!(aggregate.description(), "Value must be a Query");
            let distinct = Distinct::default();
            assert!(!distinct.is_valid(value));
            assert_eq!(distinct.description(), "Value must be a Query");
            let group_by = GroupBy::default();
            assert!(!group_by.is_valid(value));
            assert_eq!(group_by.description(), "Value must be a Query");
            let having = Having::default();
            assert!(!having.is_valid(value));
            assert_eq!(having.description(), "Value must be a Query");
            let join = Join::default();
            assert!(!join.is_valid(value));
            assert_eq!(join.description(), "Value must be a Query");
        }
    }

    #[test]
    fn a_join_without_a_table_name_is_refused() {
        for table in ["", "0"] {
            let v = Join::default();
            assert!(!v.check(&Query::join(table, "author", vec![Query::on("authorId", "id", "=")]).unwrap()));
            assert_eq!(v.description(), "Join requires a table name");
            assert!(
                v.check(&Query::join("authors", "author", vec![Query::on("authorId", "id", "=")]).unwrap()),
                "{}",
                v.description()
            );
        }
    }

    #[test]
    fn the_limit_validator_refuses_another_method_and_a_non_numeric_limit() {
        let v = Limit::default();
        assert!(!v.check(&Query::offset(5)));
        assert_eq!(v.description(), "Invalid query method: offset");
        assert!(!v.check(&q(Method::Limit, "", json!(["abc"]))));
        assert!(v.description().starts_with("Invalid limit: "));
        assert!(!v.is_valid(&s("limit(5)")));
        assert!(v.check(&Query::limit(5)), "{}", v.description());
    }
}
