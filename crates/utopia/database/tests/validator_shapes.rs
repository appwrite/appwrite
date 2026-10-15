//! Query shapes across the query list validators, and narrow lists.
//!
//! PHP: `tests/unit/Validator/{QueryShapeAggregateTest, QueryShapeHavingTest,
//! QueryShapeJoinCapTest, QueryShapeLengthTest, NarrowTest}.php` (with the
//! `QueryShapeAttributes` trait) of utopia-php/database 8.1.3.

mod support;

use std::sync::Arc;

use serde_json::{Value as Json, json};
use support::*;
use utopia_database::attribute::{Attribute, IntegerWidth};
use utopia_database::capability::Capability;
use utopia_database::validator::queries::base::Queries;
use utopia_database::validator::queries::document::Document as DocumentQueries;
use utopia_database::validator::queries::documents::Documents;
use utopia_database::validator::queries::indexed::Indexed;
use utopia_database::validator::queries::narrow::Narrow;
use utopia_database::validator::query::aggregate::Aggregate;
use utopia_database::validator::query::filter::{Filter, FilterOptions};
use utopia_database::validator::query::group_by::GroupBy;
use utopia_database::validator::query::having::Having;
use utopia_database::validator::query::join::Join;
use utopia_database::validator::query::limit::Limit;
use utopia_database::validator::query::order::Order;
use utopia_database::validator::query::select::Select;
use utopia_database::{Index, Query, Value};
use utopia_query::Method;
use utopia_query::schema::ColumnType;

fn on(left: &str, right: &str) -> Vec<Query> {
    vec![Query::on(left, right, "=")]
}

/// PHP: `tests/unit/Validator/QueryShapeAttributes.php`.
mod shape {
    use super::*;

    pub const MAX_VALUES: i64 = 100;

    pub fn attributes() -> Vec<Attribute> {
        let list = [
            ("name", ColumnType::String, false),
            ("body", ColumnType::String, false),
            ("price", ColumnType::Integer, false),
            ("stock", ColumnType::BigInteger, false),
            ("rating", ColumnType::Double, false),
            ("active", ColumnType::Boolean, false),
            ("created", ColumnType::Datetime, false),
            ("tags", ColumnType::String, true),
            ("scores", ColumnType::Integer, true),
        ];
        list.into_iter()
            .map(|(key, kind, array)| {
                attr(json!({
                    "$id": key, "key": key, "type": kind.as_str(),
                    "size": if kind == ColumnType::String { 1000 } else { 0 },
                    "signed": true, "array": array,
                }))
            })
            .collect()
    }

    pub fn filter() -> Filter {
        Filter::with_options(
            &attributes(),
            "integer",
            FilterOptions { max_values_count: MAX_VALUES, ..FilterOptions::default() },
        )
    }

    pub fn validator(indexes: &[Index]) -> Indexed {
        let attributes = attributes();
        Indexed::new(
            &attributes,
            indexes,
            vec![
                Arc::new(Limit::default()),
                Arc::new(filter()),
                Arc::new(Order::new(&attributes, true, true)),
                Arc::new(Select::new(&attributes, true, false)),
                Arc::new(Join::default()),
                Arc::new(Aggregate::new(&attributes, true, false)),
                Arc::new(GroupBy::new(&attributes, true, false)),
                Arc::new(Having::default()),
            ],
        )
    }
}

/// PHP: `tests/unit/Validator/QueryShapeAggregateTest.php`.
mod shape_aggregate {
    use super::*;

    fn aggregate(method: &str, attribute: &str) -> Query {
        match method {
            "sum" => Query::sum(attribute, "result"),
            "avg" => Query::avg(attribute, "result"),
            "stddev" => Query::stddev(attribute, "result"),
            "stddevPop" => Query::stddev_pop(attribute, "result"),
            "stddevSamp" => Query::stddev_samp(attribute, "result"),
            "variance" => Query::variance(attribute, "result"),
            "varPop" => Query::var_pop(attribute, "result"),
            "varSamp" => Query::var_samp(attribute, "result"),
            "bitAnd" => Query::bit_and(attribute, "result"),
            "bitOr" => Query::bit_or(attribute, "result"),
            "bitXor" => Query::bit_xor(attribute, "result"),
            "count" => Query::count(attribute, "result"),
            "countDistinct" => Query::count_distinct(attribute, "result"),
            "min" => Query::min(attribute, "result"),
            "max" => Query::max(attribute, "result"),
            other => panic!("Unknown aggregate: {other}"),
        }
    }

    #[test]
    fn numeric_aggregates_require_a_numeric_attribute_that_is_not_an_array() {
        for method in [
            "sum",
            "avg",
            "stddev",
            "stddevPop",
            "stddevSamp",
            "variance",
            "varPop",
            "varSamp",
            "bitAnd",
            "bitOr",
            "bitXor",
        ] {
            let v = shape::validator(&[]);
            for attribute in ["name", "active", "created", "tags", "scores", "$id", "$sequence", "$createdAt"] {
                assert!(!v.check(&[aggregate(method, attribute)]), "{method} on {attribute} must be rejected");
                assert!(v.description().starts_with(&format!("Invalid query: Aggregate {method} requires ")));
                assert!(v.description().ends_with(&format!(" attribute that is not an array: {attribute}")));
            }
            for attribute in ["price", "stock"] {
                assert!(v.check(&[aggregate(method, attribute)]), "{}", v.description());
            }
        }
    }

    #[test]
    fn bitwise_aggregates_require_an_integer_attribute() {
        let v = shape::validator(&[]);
        for method in ["bitAnd", "bitOr", "bitXor"] {
            assert!(!v.check(&[aggregate(method, "rating")]));
            assert_eq!(
                v.description(),
                format!("Invalid query: Aggregate {method} requires an integer attribute that is not an array: rating")
            );
        }
        for method in ["sum", "avg", "stddev", "varSamp"] {
            assert!(v.check(&[aggregate(method, "rating")]), "{}", v.description());
        }
    }

    #[test]
    fn joined_attributes_are_typed_by_their_own_collection() {
        let v = shape::validator(&[]);
        assert!(
            v.check(&[
                Query::left_join("reviews", "review", on("$id", "product")).unwrap(),
                Query::sum("review.score", "total"),
                Query::bit_and("review.flags", "bits"),
            ]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn count_accepts_every_attribute_and_extrema_every_ordered_one() {
        let v = shape::validator(&[]);
        for method in ["count", "countDistinct", "min", "max"] {
            for attribute in ["name", "created", "rating", "$id"] {
                assert!(v.check(&[aggregate(method, attribute)]), "{}", v.description());
            }
        }
        for method in ["count", "countDistinct"] {
            assert!(v.check(&[aggregate(method, "active")]), "{}", v.description());
        }
        for method in ["min", "max"] {
            assert!(!v.check(&[aggregate(method, "active")]), "{method} of a boolean");
        }
    }

    #[test]
    fn only_count_aggregates_every_row() {
        let v = shape::validator(&[]);
        assert!(v.check(&[Query::count("*", "rows")]), "{}", v.description());
        for method in ["countDistinct", "min", "max", "sum", "avg", "bitOr"] {
            assert!(!v.check(&[aggregate(method, "*")]), "{method} of \"*\" must be rejected");
            assert_eq!(v.description(), "Invalid query: Only count can aggregate \"*\"");
        }
    }
}

/// PHP: `tests/unit/Validator/QueryShapeHavingTest.php`.
mod shape_having {
    use super::*;

    fn values(count: i64) -> Vec<String> {
        (1..=count).map(|i| format!("value{i}")).collect()
    }

    #[test]
    fn having_accepts_aliases_and_grouped_attributes() {
        let cases: Vec<Vec<Query>> = vec![
            vec![
                Query::sum("price", "total"),
                Query::group_by(["name"]),
                Query::having([Query::greater_than("total", 100)]),
            ],
            vec![Query::count("*", "rows"), Query::group_by(["name"]), Query::having([Query::between("rows", 1, 5)])],
            vec![
                Query::sum("price", "total"),
                Query::group_by(["name"]),
                Query::having([Query::equal("total", qa(json!([1, 2.5])))]),
            ],
            vec![Query::avg("rating", "mean"), Query::group_by(["name"]), Query::having([Query::is_null("mean")])],
            vec![
                Query::max("name", "last"),
                Query::group_by(["price"]),
                Query::having([Query::greater_than("last", "m")]),
            ],
            vec![
                Query::sum("price", "total"),
                Query::group_by(["name"]),
                Query::having([Query::equal("name", ["a", "b"])]),
            ],
            vec![
                Query::sum("price", "total"),
                Query::group_by(["name", "price"]),
                Query::having([Query::or([Query::equal("name", ["a"]), Query::greater_than("price", 5)])]),
            ],
            vec![
                Query::count("*", "rows"),
                Query::sum("price", "total"),
                Query::group_by(["name"]),
                Query::having([Query::greater_than_equal("rows", 2), Query::less_than("total", 500)]),
            ],
            vec![
                Query::left_join("orders", "ord", on("$id", "customer")).unwrap(),
                Query::count("*", "rows"),
                Query::group_by(["ord.status"]),
                Query::having([Query::equal("ord.status", ["paid"])]),
            ],
        ];
        for queries in cases {
            let v = shape::validator(&[]);
            assert!(v.check(&queries), "{}", v.description());
        }
    }

    #[test]
    fn having_rejects_conditions_the_filter_rules_refuse() {
        let total = || Query::sum("price", "total");
        let by_name = || Query::group_by(["name"]);
        let cases: Vec<(Vec<Query>, &str)> = vec![
            (
                vec![total(), by_name(), Query::having([Query::equal("no_such_attribute", ["x"])])],
                "Having can only compare an aggregate alias or a groupBy attribute: no_such_attribute",
            ),
            (
                vec![total(), by_name(), Query::having([Query::equal("body", ["x"])])],
                "Having can only compare an aggregate alias or a groupBy attribute: body",
            ),
            (
                vec![Query::having([Query::equal("name", ["x"])])],
                "Having can only compare an aggregate alias or a groupBy attribute: name",
            ),
            (
                vec![total(), Query::group_by(["price"]), Query::having([Query::equal("price", ["abc"])])],
                "Query value is invalid for attribute \"price\"",
            ),
            (
                vec![
                    Query::count("*", "rows"),
                    Query::group_by(["active"]),
                    Query::having([Query::greater_than("active", "yes")]),
                ],
                "Query value is invalid for attribute \"active\"",
            ),
            (
                vec![total(), by_name(), Query::having([Query::greater_than("total", "abc")])],
                "Query value is invalid for aggregate alias \"total\"",
            ),
            (
                vec![Query::count("*", "rows"), by_name(), Query::having([Query::equal("rows", [true])])],
                "Query value is invalid for aggregate alias \"rows\"",
            ),
            (
                vec![
                    Query::max("name", "last"),
                    Query::group_by(["price"]),
                    Query::having([Query::greater_than("last", 5)]),
                ],
                "Query value is invalid for attribute \"name\"",
            ),
            (
                vec![total(), by_name(), Query::having([Query::starts_with("total", "1")])],
                "Aggregate alias \"total\" cannot be compared with startsWith",
            ),
            (
                vec![
                    total(),
                    by_name(),
                    Query::having([Query::or([Query::greater_than("total", 10), Query::less_than("total", 1)])]),
                ],
                "Aggregate alias \"total\" can only be compared at the top level of having",
            ),
            (
                vec![total(), by_name(), Query::having([q(Method::GreaterThan, "total", json!([1, 2]))])],
                "GreaterThan queries require exactly one value.",
            ),
            (
                vec![total(), by_name(), Query::having([q(Method::Between, "total", json!([1]))])],
                "Between queries require exactly two values.",
            ),
            (
                vec![total(), by_name(), Query::having([q(Method::Equal, "total", json!([]))])],
                "Equal queries require at least one value.",
            ),
            (
                vec![total(), by_name(), Query::having([Query::sum("price", "again")])],
                "Having conditions must be filter queries",
            ),
            (
                vec![total(), by_name(), Query::having([Query::having([Query::greater_than("total", 1)])])],
                "Having conditions must be filter queries",
            ),
        ];
        for (queries, message) in cases {
            let v = shape::validator(&[]);
            assert!(!v.check(&queries), "{message}");
            assert_eq!(v.description(), format!("Invalid query: {message}"));
        }
    }

    #[test]
    fn having_requires_a_fulltext_index_to_search() {
        let queries =
            [Query::count("*", "rows"), Query::group_by(["body"]), Query::having([Query::search("body", "word")])];
        let v = shape::validator(&[]);
        assert!(!v.check(&queries));
        assert_eq!(v.description(), "Searching by attribute \"body\" requires a fulltext index.");
        let indexed =
            shape::validator(&[index(json!({"$id": "body_fulltext", "type": "fulltext", "attributes": ["body"]}))]);
        assert!(indexed.check(&queries), "{}", indexed.description());
    }

    #[test]
    fn having_caps_the_values_of_every_condition() {
        let v = shape::validator(&[]);
        assert!(
            v.check(&[
                Query::count("*", "rows"),
                Query::group_by(["name"]),
                Query::having([Query::equal("name", values(shape::MAX_VALUES))]),
            ]),
            "{}",
            v.description()
        );
        assert!(!v.check(&[
            Query::count("*", "rows"),
            Query::group_by(["name"]),
            Query::having([Query::equal("name", values(shape::MAX_VALUES + 1))]),
        ]));
        assert_eq!(
            v.description(),
            format!("Invalid query: Query on attribute has greater than {} values: name", shape::MAX_VALUES)
        );
        assert!(!v.check(&[
            Query::count("*", "rows"),
            Query::group_by(["name"]),
            Query::having([Query::equal("rows", (1..=shape::MAX_VALUES + 1).collect::<Vec<_>>())]),
        ]));
        assert_eq!(
            v.description(),
            format!("Invalid query: Query on aggregate alias has greater than {} values: rows", shape::MAX_VALUES)
        );
    }

    #[test]
    fn having_state_does_not_leak_into_the_next_query_set() {
        let v = shape::validator(&[]);
        assert!(
            v.check(&[
                Query::sum("price", "total"),
                Query::group_by(["name"]),
                Query::having([Query::greater_than("total", 1), Query::equal("name", ["a"])]),
            ]),
            "{}",
            v.description()
        );
        assert!(!v.check(&[Query::group_by(["price"]), Query::having([Query::greater_than("total", 1)])]));
        assert!(!v.check(&[Query::sum("price", "total"), Query::having([Query::equal("name", ["a"])])]));
    }
}

/// PHP: `tests/unit/Validator/QueryShapeJoinCapTest.php`.
mod shape_join_cap {
    use super::*;

    fn cross_joins(count: usize) -> Vec<Query> {
        (1..=count).map(|i| Query::cross_join("other", format!("joined{i}")).unwrap()).collect()
    }

    #[test]
    fn at_most_eight_joins_per_query() {
        let v = Queries::new(vec![Arc::new(Join::default()), Arc::new(Limit::default())], 0);
        assert!(v.check(&cross_joins(8)), "{}", v.description());
        assert!(!v.check(&cross_joins(9)));
        assert_eq!(v.description(), "Too many joins: at most 8 are allowed");
        let mut many = cross_joins(61);
        many.push(Query::limit(1));
        assert!(!v.check(&many));
        assert_eq!(v.description(), "Too many joins: at most 8 are allowed");
        let mut mixed = cross_joins(4);
        mixed.extend([
            Query::join("orders", "first", on("$id", "customer")).unwrap(),
            Query::left_join("orders", "second", on("$id", "customer")).unwrap(),
            Query::right_join("orders", "third", on("$id", "customer")).unwrap(),
            Query::full_outer_join("orders", "fourth", on("$id", "customer")).unwrap(),
            Query::join("orders", "fifth", on("$id", "fifth.customer")).unwrap(),
        ]);
        assert!(!v.check(&mixed), "every kind of join counts towards the cap");
        assert_eq!(v.description(), "Too many joins: at most 8 are allowed");
    }

    #[test]
    fn join_cap_applies_to_single_document_queries() {
        let v = DocumentQueries::new(
            &shape::attributes(),
            &profile(&[Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::Joins]),
            5000,
        );
        assert!(v.check(&cross_joins(8)), "{}", v.description());
        assert!(!v.check(&cross_joins(9)));
        assert_eq!(v.description(), "Too many joins: at most 8 are allowed");
    }
}

/// PHP: `tests/unit/Validator/QueryShapeLengthTest.php`.
mod shape_length {
    use super::*;

    fn three() -> [Query; 3] {
        [Query::equal("name", ["a"]), Query::equal("name", ["b"]), Query::equal("name", ["c"])]
    }

    fn filtered(length: usize, support_for_attributes: bool) -> Queries {
        Queries::new(
            vec![Arc::new(Filter::with_options(
                &shape::attributes(),
                "integer",
                FilterOptions { support_for_attributes, ..FilterOptions::default() },
            ))],
            length,
        )
    }

    #[test]
    fn length_caps_every_nested_group() {
        let v = filtered(2, true);
        assert!(
            v.check(&[Query::or([Query::equal("name", ["a"]), Query::equal("name", ["b"])])]),
            "{}",
            v.description()
        );
        assert!(!v.check(&three()), "the top level holds three queries");
        assert!(!v.check(&[Query::or(three())]), "an or group holds three queries");
        assert!(!v.check(&[Query::and(three())]), "an and group holds three queries");
        assert!(!v.check(&[Query::and([Query::or(three()), Query::equal("name", ["d"])])]));

        let schemaless = filtered(2, false);
        assert!(
            schemaless.check(&[Query::elem_match("items", [Query::equal("sku", ["a"]), Query::equal("sku", ["b"])])]),
            "{}",
            schemaless.description()
        );
        assert!(!schemaless.check(&[Query::elem_match(
            "items",
            [Query::equal("sku", ["a"]), Query::equal("sku", ["b"]), Query::equal("sku", ["c"])]
        )]));
    }

    #[test]
    fn length_rejections_name_the_group_that_is_too_long() {
        let v = filtered(2, true);
        assert!(!v.check(&three()));
        assert_eq!(v.description(), "Too many queries: at most 2 are allowed");
        assert!(!v.check(&[Query::and([Query::or(three()), Query::equal("name", ["d"])])]));
        assert_eq!(v.description(), "Too many queries in or: at most 2 are allowed");
        assert!(!v.check(&[Query::and(three())]));
        assert_eq!(v.description(), "Too many queries in and: at most 2 are allowed");
    }

    #[test]
    fn length_caps_having_conditions() {
        let attributes = shape::attributes();
        let v = Queries::new(
            vec![
                Arc::new(Filter::new(&attributes, "integer")),
                Arc::new(Aggregate::new(&attributes, true, false)),
                Arc::new(GroupBy::new(&attributes, true, false)),
                Arc::new(Having::default()),
            ],
            3,
        );
        assert!(!v.check(&[
            Query::sum("price", "total"),
            Query::group_by(["name"]),
            Query::having([
                Query::greater_than("total", 1),
                Query::less_than("total", 9),
                Query::equal("name", ["a"]),
                Query::not_equal("name", "b"),
            ]),
        ]));
        assert_eq!(v.description(), "Too many queries in having: at most 3 are allowed");
    }
}

/// PHP: `tests/unit/Validator/NarrowTest.php`.
mod narrow {
    use super::*;

    const MAX_VALUES: i64 = 3;

    fn v(query: Query) -> Value {
        Value::from(query)
    }

    fn relationship(key: &str, kind: &str, two_way: bool, side: &str) -> Attribute {
        let data = utopia_database::value::from_plain(php_std::Value::from_json(&json!({
            "key": key, "type": "relationship", "options": {
                "relatedCollection": "people", "relationType": kind, "twoWay": two_way,
                "twoWayKey": format!("{key}Back"), "onDelete": "restrict", "side": side,
            },
        })));
        let Value::Array(data) = data else { unreachable!() };
        Attribute::from_array(&data).unwrap()
    }

    fn attributes() -> Vec<Attribute> {
        vec![
            Attribute::string("title", 64),
            Attribute::integer("count"),
            Attribute::integer("unsigned").signed(false),
            Attribute::integer("big").width(IntegerWidth::Bits64),
            Attribute::big_integer("huge"),
            Attribute::big_integer("hugeUnsigned").signed(false),
            Attribute::float("price"),
            Attribute::boolean("active"),
            Attribute::datetime("published"),
            Attribute::string("tags", 32).array(true),
            Attribute::string("secret", 64).filters(["encrypt"]),
            Attribute::string("dup", 8),
            Attribute::integer("dup"),
            Attribute::object("meta"),
            Attribute::point("location"),
            Attribute::vector("embedding", 3),
            Attribute::integer("$id"),
            relationship("author", "oneToOne", false, "child"),
            relationship("books", "oneToMany", true, "parent"),
            relationship("editor", "manyToOne", true, "child"),
            relationship("readers", "manyToMany", true, "parent"),
            relationship("owner", "manyToOne", true, "parent"),
        ]
    }

    fn profile_for(support_for_attributes: bool, order_random: bool) -> utopia_database::adapter::Profile {
        let mut capabilities = vec![Capability::UnsignedBigInt, Capability::Joins, Capability::Aggregations];
        if support_for_attributes {
            capabilities.push(Capability::DefinedAttributes);
        }
        if order_random {
            capabilities.push(Capability::OrderRandom);
        }
        profile_with(ProfileOptions {
            capabilities,
            max_date_time: Some("9999-12-31 23:59:59"),
            ..ProfileOptions::default()
        })
    }

    fn narrow(queries: &[Value], support_for_attributes: bool, order_random: bool) -> Option<Narrow> {
        Narrow::of(queries, &attributes(), &profile_for(support_for_attributes, order_random), MAX_VALUES)
    }

    fn documents(support_for_attributes: bool, order_random: bool) -> Documents {
        Documents::new(
            &attributes(),
            &[
                Index::fulltext("title_fulltext", ["title"]),
                Index::key("count_key", ["count"], Vec::new(), Vec::new()).unwrap(),
            ],
            &profile_for(support_for_attributes, order_random),
            MAX_VALUES,
        )
    }

    fn narrow_lists() -> Vec<(&'static str, Vec<Query>)> {
        vec![
            ("no queries", vec![]),
            ("equal string", vec![Query::equal("title", ["Dune"])]),
            ("equal string with a number", vec![Query::equal("title", [1])]),
            ("equal too many values", vec![Query::equal("title", ["a", "b", "c", "d"])]),
            ("equal no values", vec![Query::equal("title", Vec::<String>::new())]),
            ("equal integer", vec![Query::equal("count", [5])]),
            ("equal integer with a string", vec![Query::equal("count", ["five"])]),
            ("integer over 32 bits", vec![Query::greater_than("count", 2147483648_i64)]),
            ("big integer column over 32 bits", vec![Query::greater_than("big", 2147483648_i64)]),
            ("unsigned below zero", vec![Query::less_than("unsigned", -1)]),
            ("bigint signed", vec![Query::equal("huge", ["9223372036854775807"])]),
            ("bigint unsigned", vec![Query::equal("hugeUnsigned", ["18446744073709551615"])]),
            ("float", vec![Query::less_than_equal("price", 9.5)]),
            ("float with a string", vec![Query::less_than_equal("price", "cheap")]),
            ("boolean", vec![Query::equal("active", [true])]),
            ("boolean with a string", vec![Query::equal("active", ["yes"])]),
            ("datetime", vec![Query::greater_than_equal("published", "2020-01-01T00:00:00.000+00:00")]),
            ("datetime out of range", vec![Query::greater_than_equal("published", "10000-01-01T00:00:00.000+00:00")]),
            ("datetime not a date", vec![Query::greater_than_equal("published", "yesterday-ish")]),
            ("between", vec![Query::between("price", 1, 2)]),
            ("between one value", vec![q(Method::Between, "price", json!([1]))]),
            ("not between", vec![Query::not_between("count", 1, 9)]),
            ("single value with two", vec![q(Method::LessThan, "count", json!([1, 2]))]),
            ("starts with", vec![Query::starts_with("title", "Du")]),
            ("not starts with", vec![Query::not_starts_with("title", "Du")]),
            ("ends with", vec![Query::ends_with("title", "ne")]),
            ("not ends with", vec![Query::not_ends_with("title", "ne")]),
            ("regex", vec![Query::regex("title", "^D")]),
            ("contains on an array", vec![Query::contains("tags", ["sci-fi"])]),
            ("contains on a string", vec![Query::contains("title", ["un"])]),
            ("contains on an integer", vec![Query::contains("count", [1])]),
            ("contains no values", vec![Query::contains("tags", Vec::<String>::new())]),
            ("contains any", vec![Query::contains_any("tags", ["a", "b"])]),
            ("contains all", vec![Query::contains_all("tags", ["a", "b"])]),
            ("not contains", vec![Query::not_contains("tags", ["a"])]),
            ("equal on an array", vec![Query::equal("tags", ["a"])]),
            ("is null", vec![Query::is_null("title")]),
            ("is not null on an array", vec![Query::is_not_null("tags")]),
            ("not equal", vec![Query::not_equal("title", "Dune")]),
            ("encrypted", vec![Query::equal("secret", ["x"])]),
            ("missing attribute", vec![Query::equal("missing", ["x"])]),
            ("empty attribute", vec![Query::equal("", ["x"])]),
            ("virtual one to one child", vec![Query::equal("author", ["x"])]),
            ("virtual one to many parent", vec![Query::equal("books", ["x"])]),
            ("virtual many to one child", vec![Query::equal("editor", ["x"])]),
            ("many to many", vec![Query::equal("readers", ["x"])]),
            ("many to one parent", vec![Query::equal("owner", ["x"])]),
            ("object containment", vec![Query::equal("meta", qa(json!([{"level": 1}])))]),
            ("object mixed list", vec![Query::equal("meta", qa(json!([{"a": {"0": 1, "b": [2]}}])))]),
            ("point with equal", vec![Query::equal("location", qa(json!([[1, 2]])))]),
            ("point not an array", vec![Query::equal("location", ["here"])]),
            ("vector with equal", vec![Query::equal("embedding", qa(json!([[1, 2, 3]])))]),
            ("id", vec![Query::equal("$id", ["dune"])]),
            ("sequence", vec![Query::equal("$sequence", ["12"])]),
            ("sequence not a number", vec![Query::equal("$sequence", ["twelve"])]),
            ("created at", vec![Query::greater_than("$createdAt", "2020-01-01T00:00:00.000+00:00")]),
            ("updated at not a date", vec![Query::less_than("$updatedAt", "soon")]),
            ("permissions are not an attribute", vec![Query::equal("$permissions", ["read(\"any\")"])]),
            ("duplicated key, last definition wins", vec![Query::equal("dup", ["x"])]),
            ("duplicated key with a number", vec![Query::equal("dup", [5])]),
            ("limit", vec![Query::limit(25)]),
            ("limit zero", vec![Query::limit(0)]),
            ("offset", vec![Query::offset(10)]),
            ("offset below zero", vec![Query::offset(-1)]),
            ("cursor after", vec![cursor_after(json!({"$id": "dune"}))]),
            ("cursor before an id", vec![cursor_before(json!({"$id": "dune"}))]),
            ("cursor too long", vec![cursor_after(json!({"$id": "x".repeat(40)}))]),
            ("order asc", vec![Query::order_asc("title", None)]),
            ("order desc on a missing attribute", vec![Query::order_desc("missing", None)]),
            ("order asc on an internal attribute", vec![Query::order_asc("$sequence", None)]),
            ("order on an empty attribute", vec![Query::order_asc("", None)]),
            ("order random", vec![Query::order_random()]),
            (
                "a page",
                vec![
                    Query::equal("title", ["Dune"]),
                    Query::greater_than("count", 1),
                    Query::order_desc("$createdAt", None),
                    Query::limit(10),
                    Query::offset(20),
                ],
            ),
            (
                "the first refusal is the reported one",
                vec![Query::equal("title", ["Dune"]), Query::equal("missing", ["x"]), Query::equal("count", ["five"])],
            ),
            (
                "a refusal after an order",
                vec![Query::order_asc("title", None), Query::limit(5), Query::equal("active", ["yes"])],
            ),
            (
                "cursor page",
                vec![
                    cursor_after(json!({"$id": "dune"})),
                    Query::order_asc("price", None),
                    Query::is_not_null("title"),
                ],
            ),
        ]
    }

    #[test]
    fn a_narrow_list_is_judged_as_the_documents_validator_judges_it() {
        for (name, queries) in narrow_lists() {
            let queries: Vec<Value> = queries.into_iter().map(v).collect();
            assert!(Narrow::accepts(&queries), "{name}");
            for support_for_attributes in [true, false] {
                for order_random in [true, false] {
                    let documents = documents(support_for_attributes, order_random);
                    let narrow = narrow(&queries, support_for_attributes, order_random).expect("a narrow list");
                    let valid = documents.check(&queries);
                    let context = format!("{name}: {support_for_attributes}, {order_random}");
                    assert_eq!(narrow.check(&queries), valid, "{context}: {}", documents.description());
                    if !valid {
                        assert_eq!(narrow.description(), documents.description(), "{context}");
                    }
                }
            }
        }
    }

    /// The documents validator's verdict on every narrow list, in every
    /// mode, as PHP 8.1.3 gives it (`tests/fixtures/narrow_documents_php.txt`,
    /// recorded by running the PHP test's lists through `Documents`).
    #[test]
    fn the_documents_verdicts_match_php() {
        let mut lines = Vec::new();
        for (name, queries) in narrow_lists() {
            for s in [true, false] {
                for o in [true, false] {
                    let d = documents(s, o);
                    let ok = d.check(&queries);
                    let verdict = if ok { "ok".to_owned() } else { d.description() };
                    lines.push(format!("{name}|{}{}|{verdict}", u8::from(s), u8::from(o)));
                }
            }
        }
        let expected: Vec<&str> = include_str!("fixtures/narrow_documents_php.txt").lines().collect();
        assert_eq!(lines, expected);
    }

    #[test]
    fn any_other_list_is_left_to_the_documents_validator() {
        let lists: Vec<(&str, Vec<Value>)> = vec![
            ("dotted filter", vec![v(Query::equal("author.name", ["x"]))]),
            ("dotted order", vec![v(Query::order_asc("meta.level", None))]),
            ("search", vec![v(Query::search("title", "dune"))]),
            ("not search", vec![v(Query::not_search("title", "dune"))]),
            ("or", vec![v(Query::or([Query::equal("title", ["a"]), Query::equal("title", ["b"])]))]),
            ("and", vec![v(Query::and([Query::equal("title", ["a"]), Query::equal("count", [1])]))]),
            ("exists", vec![v(Query::exists(qa(json!(["title"]))))]),
            ("select", vec![v(Query::select(["title"]))]),
            ("vector", vec![v(Query::vector_dot("embedding", qa(json!([1.0, 2.0, 3.0]))))]),
            ("distance", vec![v(Query::distance_less_than("location", qa(json!([1, 2])), 10, false))]),
            ("spatial", vec![v(Query::intersects("location", qa(json!([1, 2]))))]),
            ("aggregate", vec![v(Query::count("*", ""))]),
            ("group by", vec![v(Query::group_by(["title"]))]),
            ("join", vec![v(Query::join("other", "j0", on("title", "title")).unwrap())]),
            ("a string", vec![s("equal(\"title\", [\"Dune\"])")]),
            (
                "one other query among narrow ones",
                vec![v(Query::equal("title", ["Dune"])), v(Query::select(["title"])), v(Query::limit(1))],
            ),
        ];
        for (name, queries) in lists {
            assert!(!Narrow::accepts(&queries), "{name}");
            assert!(narrow(&queries, true, true).is_none(), "{name}");
        }
    }

    #[test]
    fn each_list_is_checked_against_the_attributes_it_was_given() {
        let queries = [Query::equal("title", ["Dune"])];
        let profile = profile_for(true, true);
        assert!(Narrow::of(&queries, &[Attribute::string("title", 64)], &profile, MAX_VALUES).unwrap().check(&queries));

        let refused = Narrow::of(&queries, &[Attribute::integer("title")], &profile, MAX_VALUES).unwrap();
        assert!(!refused.check(&queries));
        assert_eq!(refused.description(), "Invalid query: Query value is invalid for attribute \"title\"");

        let missing = Narrow::of(&queries, &[], &profile, MAX_VALUES).unwrap();
        assert!(!missing.check(&queries));
        assert_eq!(missing.description(), "Invalid query: Attribute not found in schema: title");
    }

    #[test]
    fn a_list_it_was_not_made_of_is_checked_by_its_validators() {
        let n = Narrow::of(&[Query::equal("title", ["Dune"])], &attributes(), &profile_for(true, true), MAX_VALUES)
            .unwrap();
        assert!(!n.check(&[Query::limit(5)]));
        assert_eq!(n.description(), "Invalid query method: limit");
        assert!(!n.check(&[Query::equal("count", [5])]));
        assert_eq!(n.description(), "Invalid query: Attribute not found in schema: count");
        assert!(!n.is_valid(&s("not a list")));
        assert_eq!(n.description(), "Queries must be an array");
        assert!(n.check(&[Query::equal("title", ["Dune"])]));
    }

    #[test]
    fn nothing_an_earlier_list_registered_reaches_the_next_one() {
        let n = Narrow::of(
            &[Query::order_asc("title", None), Query::equal("title", ["Dune"])],
            &attributes(),
            &profile_for(true, true),
            MAX_VALUES,
        )
        .unwrap();
        assert!(!n.check(&[
            Query::count("*", "total"),
            Query::join("other", "o", on("title", "title")).unwrap(),
            Query::order_asc("total", None),
        ]));
        assert!(!n.check(&[Query::order_asc("total", None)]));
        assert_eq!(n.description(), "Invalid query: Attribute not found in schema: total");

        let nickname: Json = json!({"$id": "nickname", "key": "nickname", "type": "string", "size": 16});
        n.set_joined_collections(&[doc(json!({"$id": "other", "attributes": [nickname]}))]).unwrap();
        assert!(
            !n.check(&[
                Query::join("other", "o", on("title", "nickname")).unwrap(),
                Query::order_asc("nickname", None),
            ])
        );
        assert!(!n.check(&[Query::order_asc("nickname", None)]));
        assert_eq!(n.description(), "Invalid query: Attribute not found in schema: nickname");
    }
}
