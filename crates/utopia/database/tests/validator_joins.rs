//! Joins and joined attributes across the query validators.
//!
//! PHP: `tests/unit/Validator/{JoinConditionColumnsTest, JoinedColumnTest}.php` and
//! `tests/unit/Validator/Query/Joined/AttributesTest.php` of utopia-php/database 8.1.3.

mod support;

use std::sync::Arc;

use serde_json::{Value as Json, json};
use support::*;
use utopia_database::Query;
use utopia_database::attribute::Attribute;
use utopia_database::capability::Capability;
use utopia_database::query::DEFAULT_ALIAS;
use utopia_database::relationship::{Relationship, RelationshipSide};
use utopia_database::validator::queries::base::Queries;
use utopia_database::validator::queries::document::Document as DocumentQueries;
use utopia_database::validator::queries::documents::Documents;
use utopia_database::validator::query::aggregate::Aggregate;
use utopia_database::validator::query::filter::{Filter, FilterOptions};
use utopia_database::validator::query::group_by::GroupBy;
use utopia_database::validator::query::join::Join;
use utopia_database::validator::query::order::Order;
use utopia_database::validator::query::select::Select;
use utopia_query::Method;

fn on(left: &str, right: &str) -> Vec<Query> {
    vec![Query::on(left, right, "=")]
}

fn attribute(key: &str, kind: &str, filters: Json, array: bool) -> Json {
    json!({
        "$id": key, "key": key, "type": kind, "size": if kind == "string" { 256 } else { 0 },
        "required": false, "signed": true, "array": array, "filters": filters,
    })
}

fn plain(key: &str, kind: &str) -> Json {
    attribute(key, kind, json!([]), false)
}

fn relationship(key: &str, kind: &str, side: &str) -> Json {
    json!({
        "$id": key, "key": key, "type": "relationship", "size": 0, "required": false, "signed": true,
        "array": false, "filters": [],
        "options": {
            "relatedCollection": "related", "relationType": kind, "twoWay": false, "twoWayKey": "back",
            "onDelete": "restrict", "side": side,
        },
    })
}

fn collection(id: &str, attributes: Vec<Json>) -> utopia_database::Document {
    doc(json!({"$id": id, "attributes": attributes, "indexes": []}))
}

/// One of the validators a test runs the same list through.
enum Lists {
    Documents(Documents),
    Document(DocumentQueries),
    Base(Queries),
}

impl Lists {
    fn check(&self, queries: &[Query]) -> bool {
        match self {
            Lists::Documents(v) => v.check(queries),
            Lists::Document(v) => v.check(queries),
            Lists::Base(v) => v.check(queries),
        }
    }

    fn description(&self) -> String {
        match self {
            Lists::Documents(v) => v.description(),
            Lists::Document(v) => v.description(),
            Lists::Base(v) => v.description(),
        }
    }
}

/// PHP: `tests/unit/Validator/JoinConditionColumnsTest.php`.
mod join_condition_columns {
    use super::*;

    fn customers() -> Vec<Attribute> {
        attrs(json!([
            plain("name", "string"),
            plain("score", "integer"),
            attribute("secret", "string", json!(["encrypt"]), false),
            relationship("library", "oneToOne", "parent"),
            relationship("books", "oneToMany", "parent"),
        ]))
    }

    fn collections() -> Vec<utopia_database::Document> {
        vec![
            collection(
                "notes",
                vec![
                    plain("customerId", "string"),
                    plain("body", "string"),
                    plain("score", "integer"),
                    attribute("secret", "string", json!(["encrypt"]), false),
                ],
            ),
            collection("replies", vec![plain("noteId", "string")]),
            collection("libraries", vec![plain("name", "string"), relationship("person", "oneToOne", "child")]),
            collection("books", vec![plain("title", "string"), relationship("owner", "oneToMany", "child")]),
        ]
    }

    fn documents(shared_tables: bool) -> Documents {
        let v = Documents::new(
            &customers(),
            &[],
            &profile_shared(
                &[
                    Capability::DefinedAttributes,
                    Capability::UnsignedBigInt,
                    Capability::Joins,
                    Capability::Aggregations,
                    Capability::OrderRandom,
                ],
                shared_tables,
            ),
            5000,
        );
        v.set_joined_collections(&collections()).unwrap();
        v
    }

    fn validators() -> Vec<(&'static str, Lists)> {
        let document = DocumentQueries::new(
            &customers(),
            &profile(&[Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::Joins]),
            5000,
        );
        document.set_joined_collections(&collections()).unwrap();
        let joins = Queries::new(vec![Arc::new(Join::new(Some(&customers()), true))], 0);
        joins.set_joined_collections(&collections()).unwrap();
        vec![
            ("documents", Lists::Documents(documents(false))),
            ("document", Lists::Document(document)),
            ("join", Lists::Base(joins)),
        ]
    }

    fn note() -> Query {
        Query::join("notes", "note", on("$id", "customerId")).unwrap()
    }

    #[test]
    fn join_over_columns_the_tables_have_is_valid() {
        let cases: Vec<Vec<Query>> = vec![
            vec![note()],
            vec![
                Query::left_join(
                    "notes",
                    "note",
                    vec![Query::on("$id", "note.customerId", "="), Query::on("$createdAt", "$updatedAt", "<")],
                )
                .unwrap(),
            ],
            vec![note(), Query::join("replies", "reply", on("note.$id", "noteId")).unwrap()],
            vec![
                Query::cross_join("replies", "reply").unwrap(),
                Query::right_join("notes", "note", on("reply.noteId", "$id")).unwrap(),
            ],
            vec![Query::join("notes", "note", vec![Query::on("$sequence", "$sequence", "<")]).unwrap()],
            vec![Query::join("libraries", "lib", on("library", "$id")).unwrap()],
            vec![Query::left_join("books", "book", on("$id", "owner")).unwrap()],
            vec![Query::join("notes", "j0", on("$id", "customerId")).unwrap()],
        ];
        for joins in cases {
            for (label, v) in validators() {
                assert!(v.check(&joins), "{label}: {}", v.description());
            }
        }
    }

    #[test]
    fn join_naming_no_column_is_invalid() {
        let not_found = "Invalid query: Attribute not found in schema: ";
        let left = "Invalid query: The left column of a join condition must belong to the main collection or to a join declared before it: ";
        let right = "Invalid query: The right column of a join condition must belong to the joined collection: ";
        let cases: Vec<(Vec<Query>, String)> = vec![
            (vec![Query::join("notes", "note", on("nothing", "customerId")).unwrap()], format!("{not_found}nothing")),
            (vec![Query::join("notes", "note", on("$id", "nothing")).unwrap()], format!("{not_found}nothing")),
            (
                vec![Query::left_join("notes", "note", on("nothing", "customerId")).unwrap()],
                format!("{not_found}nothing"),
            ),
            (
                vec![
                    Query::left_join(
                        "notes",
                        "note",
                        vec![Query::on("$id", "customerId", "="), Query::on("$id", "note.nothing", "=")],
                    )
                    .unwrap(),
                ],
                format!("{not_found}note.nothing"),
            ),
            (
                vec![note(), Query::join("replies", "reply", on("note.nothing", "noteId")).unwrap()],
                format!("{not_found}note.nothing"),
            ),
            (
                vec![Query::join("notes", "note", on("$permissions", "customerId")).unwrap()],
                format!("{not_found}$permissions"),
            ),
            (vec![Query::join("notes", "note", on("$id", "$collection")).unwrap()], format!("{not_found}$collection")),
            (
                vec![Query::join("books", "book", on("books", "$id")).unwrap()],
                "Invalid query: Cannot join on virtual relationship attribute: books".to_owned(),
            ),
            (
                vec![Query::join("libraries", "lib", on("$id", "person")).unwrap()],
                "Invalid query: Cannot join on virtual relationship attribute: person".to_owned(),
            ),
            (
                vec![Query::join("replies", "reply", on("note.$id", "noteId")).unwrap(), note()],
                format!("{left}note.$id"),
            ),
            (
                vec![Query::join("notes", "note", on("note.customerId", "$id")).unwrap()],
                format!("{left}note.customerId"),
            ),
            (vec![Query::join("notes", "note", on("other.$id", "customerId")).unwrap()], format!("{left}other.$id")),
            (
                vec![Query::join("notes", "note", on(&format!("{DEFAULT_ALIAS}.$id"), "customerId")).unwrap()],
                format!("{left}{DEFAULT_ALIAS}.$id"),
            ),
            (vec![note(), Query::join("replies", "reply", on("$id", "note.$id")).unwrap()], format!("{right}note.$id")),
            (
                vec![Query::join("notes", "note", on("$id", &format!("{DEFAULT_ALIAS}.name"))).unwrap()],
                format!("{right}{DEFAULT_ALIAS}.name"),
            ),
            (
                vec![Query::join("notes", "note", on("$id", "")).unwrap()],
                "Invalid query: Join ON requires left and right columns".to_owned(),
            ),
            (
                vec![Query::left_join("notes", "note", vec![q(Method::On, "", json!(["$id", "=", 5]))]).unwrap()],
                "Invalid query: Join ON requires left and right columns".to_owned(),
            ),
            (
                vec![Query::join("notes", "note", vec![Query::on("$id", "customerId", "~")]).unwrap()],
                "Invalid query: Invalid join operator: ~".to_owned(),
            ),
        ];
        for (joins, message) in cases {
            for (label, v) in validators() {
                assert!(!v.check(&joins), "{label}: the join was accepted ({message})");
                assert_eq!(v.description(), message, "{label}");
            }
        }
    }

    #[test]
    fn columns_of_a_collection_the_validator_does_not_know_are_not_checked() {
        let v = Queries::new(vec![Arc::new(Join::default())], 0);
        assert!(v.check(&[Query::join("orders", "j0", on("user_id", "id")).unwrap()]), "{}", v.description());
        assert!(
            v.check(&[
                Query::join("orders", "ord", on("user_id", "id")).unwrap(),
                Query::join("items", "item", on("ord.anything", "orderId")).unwrap(),
            ]),
            "{}",
            v.description()
        );
        assert!(!v.check(&[
            Query::join("items", "item", on("ord.anything", "orderId")).unwrap(),
            Query::join("orders", "ord", on("user_id", "id")).unwrap(),
        ]));
        assert_eq!(
            v.description(),
            "Invalid query: The left column of a join condition must belong to the main collection or to a join declared before it: ord.anything"
        );
    }

    #[test]
    fn schemaless_joins_check_only_which_tables_a_column_belongs_to() {
        let v = Queries::new(vec![Arc::new(Join::new(Some(&customers()), false))], 0);
        v.set_joined_collections(&collections()).unwrap();
        assert!(v.check(&[Query::join("notes", "note", on("anything", "whatever")).unwrap()]), "{}", v.description());
        assert!(!v.check(&[Query::join("notes", "note", on("reply.anything", "whatever")).unwrap()]));
        assert_eq!(
            v.description(),
            "Invalid query: The left column of a join condition must belong to the main collection or to a join declared before it: reply.anything"
        );
    }

    #[test]
    fn document_queries_check_the_columns_of_a_join_condition() {
        let v = DocumentQueries::new(
            &customers(),
            &profile(&[Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::Joins]),
            5000,
        );
        v.set_joined_collections(&collections()).unwrap();
        assert!(v.check(&[Query::left_join("notes", "note", on("$id", "customerId")).unwrap()]), "{}", v.description());
        assert!(!v.check(&[Query::left_join("notes", "note", on("$id", "nothing")).unwrap()]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: nothing");
    }

    #[test]
    fn validator_forgets_the_joins_of_the_previous_query_set() {
        let v = documents(false);
        assert!(v.check(&[note()]), "{}", v.description());
        assert!(!v.check(&[Query::join("replies", "reply", on("note.$id", "noteId")).unwrap()]));
    }

    #[test]
    fn internal_attribute_without_a_column_is_invalid() {
        let cases: Vec<(Vec<Query>, &str, bool)> = vec![
            (vec![Query::count("$collection", "total")], "$collection", false),
            (vec![Query::count("*", "rows"), Query::group_by(["$collection"])], "$collection", false),
            (vec![Query::count("$tenant", "total")], "$tenant", true),
            (vec![Query::count("*", "rows"), Query::group_by(["$tenant"])], "$tenant", true),
            (vec![note(), Query::count("note.$tenant", "total")], "note.$tenant", true),
            (vec![note(), Query::count("*", "rows"), Query::group_by(["note.$tenant"])], "note.$tenant", true),
            (vec![note(), Query::select(["name", "note.$tenant"])], "note.$tenant", true),
        ];
        for (queries, attribute, valid_under_shared_tables) in cases {
            let v = documents(false);
            assert!(!v.check(&queries), "accepted without shared tables");
            assert_eq!(v.description(), format!("Invalid query: Attribute not found in schema: {attribute}"));
            let shared = documents(true);
            assert_eq!(shared.check(&queries), valid_under_shared_tables, "{}", shared.description());
        }
    }

    #[test]
    fn collection_stays_selectable_on_the_main_collection() {
        for shared_tables in [false, true] {
            let v = documents(shared_tables);
            assert!(v.check(&[Query::select(["name", "$collection"])]), "{}", v.description());
            assert!(!v.check(&[note(), Query::select(["note.$collection"])]));
        }
    }

    #[test]
    fn tenant_is_rejected_by_validators_built_without_shared_tables() {
        let attributes = customers();
        assert!(!Aggregate::new(&attributes, true, false).check(&Query::count("$tenant", "total")));
        assert!(Aggregate::new(&attributes, true, true).check(&Query::count("$tenant", "total")));
        assert!(!Aggregate::new(&attributes, true, true).check(&Query::count("$collection", "total")));
        assert!(!GroupBy::new(&attributes, true, false).check(&Query::group_by(["$tenant"])));
        assert!(GroupBy::new(&attributes, true, true).check(&Query::group_by(["$tenant"])));
    }

    #[test]
    fn select_lets_the_main_tenant_through_without_shared_tables() {
        let attributes = customers();
        assert!(Select::new(&attributes, true, false).check(&Query::select(["$tenant"])));
        assert!(Select::new(&attributes, true, true).check(&Query::select(["$tenant"])));
        assert!(documents(false).check(&[Query::select(["name", "$tenant"])]));
    }

    #[test]
    fn encrypted_joined_attribute_cannot_be_filtered() {
        let v = documents(false);
        for filter in [
            Query::equal("note.secret", ["x"]),
            Query::is_null("note.secret"),
            Query::and([Query::equal("note.body", ["x"]), Query::starts_with("note.secret", "x")]),
        ] {
            assert!(!v.check(&[note(), filter]), "an encrypted joined attribute was filtered");
            assert_eq!(v.description(), "Invalid query: Cannot query encrypted attribute: note.secret");
        }
        assert!(
            v.check(&[note(), Query::select(["name", "note.secret"]), Query::order_asc("note.secret", None)]),
            "{}",
            v.description()
        );
        assert!(!v.check(&[Query::equal("secret", ["x"])]));
        assert_eq!(v.description(), "Invalid query: Cannot query encrypted attribute: secret");
    }

    #[test]
    fn aggregate_alias_names_one_column_of_the_result() {
        let grouped = |alias: &str, attribute: &str| {
            format!(
                "Invalid query: Aggregate alias \"{alias}\" is the name the groupBy attribute \"{attribute}\" is returned under"
            )
        };
        let cases: Vec<(Vec<Query>, Option<String>)> = vec![
            (vec![Query::count("*", "name"), Query::group_by(["name"])], Some(grouped("name", "name"))),
            (
                vec![note(), Query::count("*", "body"), Query::group_by(["name", "note.body"])],
                Some(grouped("body", "note.body")),
            ),
            (
                vec![Query::count("*", "_createdAt"), Query::group_by(["$createdAt"])],
                Some(grouped("_createdAt", "$createdAt")),
            ),
            (
                vec![Query::count("*", "total"), Query::sum("score", "total")],
                Some("Invalid query: Aggregate alias \"total\" is given to more than one aggregate".to_owned()),
            ),
            (vec![Query::sum("score", "name"), Query::group_by(["score"])], None),
            (
                vec![
                    note(),
                    Query::count("*", "rows"),
                    Query::sum("note.score", "score"),
                    Query::group_by(["name", "note.body"]),
                ],
                None,
            ),
        ];
        for (queries, message) in cases {
            let v = documents(false);
            assert_eq!(v.check(&queries), message.is_none(), "{}", v.description());
            if let Some(message) = message {
                assert_eq!(v.description(), message);
            }
        }
    }
}

/// PHP: `tests/unit/Validator/JoinedColumnTest.php`.
mod joined_column {
    use super::*;

    fn main_attributes() -> Vec<Json> {
        vec![plain("name", "string"), plain("visits", "integer")]
    }

    fn notes() -> utopia_database::Document {
        collection(
            "notes",
            vec![
                plain("customerId", "string"),
                plain("body", "string"),
                plain("score", "integer"),
                plain("ratio", "double"),
                attribute("tags", "string", json!([]), true),
                json!({
                    "$id": "customer", "key": "customer", "type": "relationship",
                    "options": {"relatedCollection": "customers", "relationType": "manyToMany", "side": "parent"},
                }),
            ],
        )
    }

    fn orders() -> utopia_database::Document {
        collection("orders", vec![plain("customerId", "string"), plain("amount", "integer")])
    }

    fn validator(joined: Vec<utopia_database::Document>, support_for_attributes: bool) -> Queries {
        let mut list = main_attributes();
        list.extend([
            plain("$id", "string"),
            plain("$sequence", "id"),
            plain("$createdAt", "datetime"),
            plain("$updatedAt", "datetime"),
        ]);
        let attributes = attrs(Json::Array(list));
        let v = Queries::new(
            vec![
                Arc::new(Filter::with_options(
                    &attributes,
                    "integer",
                    FilterOptions { support_for_attributes, ..FilterOptions::default() },
                )),
                Arc::new(Select::new(&attributes, support_for_attributes, false)),
                Arc::new(Order::new(&attributes, support_for_attributes, true)),
                Arc::new(Join::default()),
                Arc::new(Aggregate::new(&attributes, support_for_attributes, false)),
                Arc::new(GroupBy::new(&attributes, support_for_attributes, false)),
            ],
            0,
        );
        v.set_joined_collections(&joined).unwrap();
        v
    }

    fn join() -> Query {
        Query::join("notes", "note", on("$id", "customerId")).unwrap()
    }

    fn with_join(queries: Vec<Query>) -> Vec<Query> {
        let mut all = vec![join()];
        all.extend(queries);
        all
    }

    type Shape = fn(&str) -> Vec<Query>;

    fn internal_types() -> Vec<(&'static str, Shape)> {
        vec![
            ("filter", |a| vec![Query::is_not_null(a)]),
            ("select", |a| vec![Query::select([a])]),
            ("order", |a| vec![Query::order_asc(a, None)]),
            ("aggregate", |a| vec![Query::count(a, "result")]),
            ("groupBy", |a| vec![Query::count("*", "rows"), Query::group_by([a])]),
        ]
    }

    #[test]
    fn internal_attribute_under_an_alias_follows_the_rule_of_the_main_collection() {
        for attribute in ["$id", "$sequence", "$createdAt", "$updatedAt", "$permissions", "$tenant"] {
            for (kind, queries) in internal_types() {
                if attribute == "$tenant" && kind == "select" {
                    continue;
                }
                let v = validator(vec![notes()], true);
                let unaliased = v.check(&queries(attribute));
                let aliased = v.check(&with_join(queries(&format!("note.{attribute}"))));
                assert_eq!(unaliased, aliased, "{attribute} in {kind}: {}", v.description());
            }
        }
    }

    #[test]
    fn tenant_select_passes_only_on_the_main_collection_without_shared_tables() {
        let v = validator(vec![notes()], true);
        assert!(v.check(&[Query::select(["$tenant"])]), "{}", v.description());
        assert!(!v.check(&with_join(vec![Query::select(["note.$tenant"])])));
    }

    #[test]
    fn collection_of_a_joined_row_cannot_be_referenced() {
        let v = validator(vec![notes()], true);
        assert!(v.check(&[Query::select(["$collection"])]), "{}", v.description());
        for queries in [
            vec![Query::select(["note.$collection"])],
            vec![Query::count("note.$collection", "result")],
            vec![Query::count("*", "rows"), Query::group_by(["note.$collection"])],
        ] {
            assert!(!v.check(&with_join(queries)));
            assert_eq!(v.description(), "Invalid query: Attribute not found in schema: note.$collection");
        }
    }

    fn every_query_type() -> Vec<fn(&str) -> Query> {
        vec![
            |a| Query::equal(a, ["x"]),
            |a| Query::or([Query::equal("name", ["x"]), Query::equal(a, ["x"])]),
            |a| Query::select(["name", a]),
            |a| Query::order_desc(a, None),
            |a| Query::count_distinct(a, "result"),
            |a| Query::group_by(["name", a]),
        ]
    }

    #[test]
    fn column_the_joined_collection_does_not_declare_is_rejected() {
        for query in every_query_type() {
            let v = validator(vec![notes()], true);
            assert!(v.check(&with_join(vec![query("note.body")])), "{}", v.description());
            assert!(!v.check(&with_join(vec![query("note.visits")])));
            assert_eq!(v.description(), "Invalid query: Attribute not found in schema: note.visits");
            assert!(!v.check(&with_join(vec![query("note.customer")])));
            assert_eq!(v.description(), "Invalid query: Attribute not found in schema: note.customer");
        }
    }

    #[test]
    fn any_plain_column_is_accepted_under_an_alias_whose_collection_is_unknown() {
        for query in every_query_type() {
            let v = validator(Vec::new(), true);
            assert!(v.check(&with_join(vec![query("note.anything")])), "{}", v.description());
        }
    }

    #[test]
    fn schemaless_validators_accept_any_joined_column() {
        for query in every_query_type() {
            let v = validator(vec![notes()], false);
            assert!(v.check(&with_join(vec![query("note.anything")])), "{}", v.description());
        }
    }

    #[test]
    fn each_alias_resolves_to_its_own_collection() {
        let v = validator(vec![notes(), orders()], true);
        let joins = || vec![join(), Query::join("orders", "purchase", on("$id", "customerId")).unwrap()];
        let mut queries = joins();
        queries.extend([Query::equal("purchase.amount", [1]), Query::equal("note.body", ["x"])]);
        assert!(v.check(&queries), "{}", v.description());
        let mut queries = joins();
        queries.push(Query::equal("purchase.body", ["x"]));
        assert!(!v.check(&queries));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: purchase.body");
    }

    #[test]
    fn collection_joined_twice_is_checked_under_both_aliases() {
        let v = validator(vec![notes()], true);
        let joins = || vec![join(), Query::left_join("notes", "again", on("$id", "customerId")).unwrap()];
        let mut queries = joins();
        queries.push(Query::select(["note.body", "again.body"]));
        assert!(v.check(&queries), "{}", v.description());
        let mut queries = joins();
        queries.push(Query::select(["again.nothing"]));
        assert!(!v.check(&queries));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: again.nothing");
    }

    #[test]
    fn join_condition_is_checked_against_the_joined_collection() {
        let v = validator(vec![notes()], true);
        assert!(
            v.check(&[Query::left_join(
                "notes",
                "note",
                vec![Query::on("$id", "customerId", "="), Query::equal("note.body", ["x"])]
            )
            .unwrap()]),
            "{}",
            v.description()
        );
        assert!(
            !v.check(&[Query::left_join(
                "notes",
                "note",
                vec![Query::on("$id", "customerId", "="), Query::equal("note.nothing", ["x"])]
            )
            .unwrap()])
        );
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: note.nothing");
    }

    #[test]
    fn arithmetic_and_bitwise_aggregates_type_a_joined_attribute_by_its_collection() {
        for (aggregate, valid) in [
            (Query::stddev("note.score", "result"), true),
            (Query::var_pop("note.ratio", "result"), true),
            (Query::variance("score", "result"), true),
            (Query::bit_and("note.score", "result"), true),
            (Query::bit_xor("score", "result"), true),
            (Query::bit_or("note.ratio", "result"), false),
            (Query::stddev_samp("note.body", "result"), false),
            (Query::var_samp("tags", "result"), false),
            (Query::min("note.body", "result"), true),
            (Query::max("note.tags", "result"), false),
        ] {
            let v = validator(vec![notes()], true);
            assert_eq!(v.check(&[join(), aggregate]), valid, "{}", v.description());
        }
    }

    #[test]
    fn main_collection_attribute_keeps_its_own_type_when_a_join_declares_it_too() {
        let v =
            validator(vec![collection("notes", vec![plain("customerId", "string"), plain("visits", "string")])], true);
        assert!(v.check(&[join(), Query::sum("visits", "result")]), "{}", v.description());
        assert!(!v.check(&[join(), Query::sum("note.visits", "result")]));
        assert_eq!(
            v.description(),
            "Invalid query: Aggregate sum requires a numeric attribute that is not an array: note.visits"
        );
    }

    fn document_queries() -> DocumentQueries {
        let v = DocumentQueries::new(
            &attrs(Json::Array(main_attributes())),
            &profile(&[Capability::DefinedAttributes, Capability::UnsignedBigInt, Capability::Joins]),
            5000,
        );
        v.set_joined_collections(&[notes()]).unwrap();
        v
    }

    #[test]
    fn document_queries_check_join_conditions_against_the_joined_collection() {
        let v = document_queries();
        assert!(
            v.check(&[
                Query::left_join(
                    "notes",
                    "note",
                    vec![
                        Query::on("$id", "customerId", "="),
                        Query::or([Query::equal("note.body", ["x"]), Query::equal("note.$id", ["y"])]),
                        Query::greater_than("$sequence", 1),
                    ]
                )
                .unwrap(),
                Query::select(["name", "note.body", "note.$permissions"]),
            ]),
            "{}",
            v.description()
        );
        for (condition, message) in [
            (Query::equal("note.nothing", ["x"]), "Invalid query: Attribute not found in schema: note.nothing"),
            (
                Query::equal("note.$permissions", ["x"]),
                "Invalid query: Attribute not found in schema: note.$permissions",
            ),
            (Query::equal("visits", ["many"]), "Invalid query: Query value is invalid for attribute \"visits\""),
            (
                Query::or([Query::equal("note.body", ["x"]), Query::equal("note.nothing", ["y"])]),
                "Invalid query: Attribute not found in schema: note.nothing",
            ),
        ] {
            assert!(!v.check(&[
                Query::left_join("notes", "note", vec![Query::on("$id", "customerId", "="), condition]).unwrap()
            ]));
            assert_eq!(v.description(), message);
        }
        assert!(!v.check(&[
            Query::left_join("notes", "note", on("$id", "customerId")).unwrap(),
            Query::select(["note.nothing"]),
        ]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: note.nothing");
    }

    #[test]
    fn document_queries_keep_rejecting_top_level_filters() {
        let v = document_queries();
        assert!(!v.check(&[
            Query::left_join("notes", "note", on("$id", "customerId")).unwrap(),
            Query::equal("note.body", ["x"])
        ]));
        assert_eq!(v.description(), "Invalid query method: equal");
    }
}

/// PHP: `tests/unit/Validator/Query/Joined/AttributesTest.php`.
mod joined_attributes {
    use super::*;

    const AMBIGUOUS_AMOUNT: &str =
        "Invalid query: Attribute \"amount\" is ambiguous across joins; qualify it with a join alias";

    fn customer_relationship() -> Json {
        let attribute =
            Attribute::relationship("customer", Relationship::many_to_one("customers"), RelationshipSide::Parent)
                .unwrap()
                .to_document()
                .unwrap();
        utopia_database::value::to_plain(&utopia_database::Value::from(attribute)).to_json()
    }

    fn orders() -> utopia_database::Document {
        collection(
            "orders",
            vec![
                plain("customerId", "string"),
                plain("amount", "integer"),
                plain("status", "string"),
                plain("memo", "string"),
                customer_relationship(),
            ],
        )
    }

    fn refunds() -> utopia_database::Document {
        collection("refunds", vec![plain("customerId", "string"), plain("amount", "integer")])
    }

    fn notes() -> utopia_database::Document {
        doc(json!({
            "$id": "notes",
            "attributes": [plain("customerId", "string"), plain("body", "string")],
            "indexes": [{"$id": "body_fulltext", "key": "body_fulltext", "type": "fulltext", "attributes": ["body"]}],
        }))
    }

    fn profiles() -> utopia_database::Document {
        collection("profiles", vec![plain("customerId", "string"), plain("visits", "integer")])
    }

    fn validator(joined: Vec<utopia_database::Document>) -> Documents {
        let v = Documents::new(
            &attrs(json!([plain("name", "string"), plain("visits", "integer")])),
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
        if !joined.is_empty() {
            v.set_joined_collections(&joined).unwrap();
        }
        v
    }

    #[test]
    fn bare_attribute_of_the_main_collection_stays_valid_when_a_join_declares_it_too() {
        let v = validator(vec![profiles()]);
        assert!(
            v.check(&[
                Query::join("profiles", "profile", on("$id", "customerId")).unwrap(),
                Query::sum("visits", "total"),
                Query::group_by(["name", "visits"]),
            ]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn bare_attribute_resolves_to_the_one_join_that_declares_it() {
        let v = validator(vec![notes(), orders()]);
        assert!(
            v.check(&[
                Query::join("notes", "note", on("$id", "customerId")).unwrap(),
                Query::join("orders", "purchase", on("$id", "customerId")).unwrap(),
                Query::sum("amount", "total"),
                Query::group_by(["status"]),
            ]),
            "{}",
            v.description()
        );
        assert!(
            v.check(&[
                Query::join("notes", "j0", on("$id", "customerId")).unwrap(),
                Query::join("orders", "j1", on("$id", "customerId")).unwrap(),
                Query::avg("amount", "average"),
                Query::group_by(["status"]),
            ]),
            "a join without an alias still declares its attributes: {}",
            v.description()
        );
    }

    #[test]
    fn bare_attribute_no_collection_declares_is_not_found() {
        let v = validator(vec![orders()]);
        assert!(!v.check(&[
            Query::left_join("orders", "j", on("$id", "customerId")).unwrap(),
            Query::group_by(["anything_at_all"]),
        ]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: anything_at_all");
        assert!(!v.check(&[
            Query::left_join("orders", "j", on("$id", "customerId")).unwrap(),
            Query::sum("also_anything", "total"),
        ]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: also_anything");
    }

    #[test]
    fn bare_attribute_several_joins_declare_is_ambiguous() {
        let v = validator(vec![orders(), refunds()]);
        let joins = || {
            vec![
                Query::join("orders", "alpha", on("$id", "customerId")).unwrap(),
                Query::join("refunds", "beta", on("$id", "customerId")).unwrap(),
            ]
        };
        let with = |extra: Vec<Query>| {
            let mut all = joins();
            all.extend(extra);
            all
        };
        assert!(!v.check(&with(vec![Query::sum("amount", "total")])));
        assert_eq!(v.description(), AMBIGUOUS_AMOUNT);
        assert!(!v.check(&with(vec![Query::group_by(["amount"])])));
        assert_eq!(v.description(), AMBIGUOUS_AMOUNT);
        assert!(
            v.check(&with(vec![Query::sum("beta.amount", "total"), Query::group_by(["alpha.amount"])])),
            "{}",
            v.description()
        );
    }

    #[test]
    fn collection_joined_under_two_aliases_makes_its_attributes_ambiguous() {
        let v = validator(vec![orders()]);
        let with = |extra: Vec<Query>| {
            let mut all = vec![
                Query::join("orders", "first", on("$id", "customerId")).unwrap(),
                Query::left_join("orders", "second", on("$id", "customerId")).unwrap(),
            ];
            all.extend(extra);
            all
        };
        assert!(!v.check(&with(vec![Query::max("amount", "largest")])));
        assert_eq!(v.description(), AMBIGUOUS_AMOUNT);
        assert!(
            v.check(&with(vec![Query::max("first.amount", "largest"), Query::min("second.amount", "smallest")])),
            "{}",
            v.description()
        );
    }

    #[test]
    fn bare_attribute_cannot_resolve_through_a_join_whose_collection_is_unknown() {
        let v = validator(Vec::new());
        assert!(!v.check(&[
            Query::join("orders", "purchase", on("$id", "customerId")).unwrap(),
            Query::sum("amount", "total"),
        ]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: amount");
        assert!(
            v.check(&[
                Query::join("orders", "purchase", on("$id", "customerId")).unwrap(),
                Query::sum("purchase.amount", "total"),
            ]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn joined_relationship_attribute_is_not_resolved_by_its_bare_name() {
        let v = validator(vec![orders()]);
        assert!(!v.check(&[
            Query::join("orders", "purchase", on("$id", "customerId")).unwrap(),
            Query::group_by(["customer"]),
        ]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: customer");
    }

    #[test]
    fn internal_attributes_resolve_to_the_main_collection() {
        let v = validator(vec![orders()]);
        assert!(
            v.check(&[
                Query::left_join("orders", "purchase", on("$id", "customerId")).unwrap(),
                Query::count("$id", "customers"),
                Query::group_by(["$createdAt"]),
            ]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn search_on_a_join_alias_requires_a_fulltext_index_on_the_joined_attribute() {
        let v = validator(vec![orders(), notes()]);
        let purchase = || Query::left_join("orders", "purchase", on("$id", "customerId")).unwrap();
        assert!(!v.check(&[purchase(), Query::search("purchase.memo", "gift")]));
        assert_eq!(v.description(), "Searching by attribute \"purchase.memo\" requires a fulltext index.");
        assert!(!v.check(&[purchase(), Query::not_search("purchase.memo", "gift")]));
        assert_eq!(v.description(), "Searching by attribute \"purchase.memo\" requires a fulltext index.");
        assert!(
            v.check(&[
                Query::left_join("notes", "note", on("$id", "customerId")).unwrap(),
                Query::search("note.body", "needle")
            ]),
            "{}",
            v.description()
        );
    }

    #[test]
    fn search_in_a_join_condition_is_refused_with_or_without_a_fulltext_index() {
        let v = validator(vec![orders(), notes()]);
        for (collection, alias, attribute) in [("orders", "purchase", "purchase.memo"), ("notes", "note", "note.body")]
        {
            assert!(!v.check(&[q_nested(
                Method::LeftJoin,
                collection,
                vec![nested(Query::on("$id", "customerId", "=")), nested(Query::search(attribute, "needle"))],
                alias
            )]));
            assert_eq!(v.description(), "Invalid query: Unsupported join ON condition: search");
        }
    }

    #[test]
    fn search_on_a_join_alias_is_invalid_when_the_joined_indexes_are_unknown() {
        let v = validator(Vec::new());
        assert!(!v.check(&[
            Query::left_join("notes", "note", on("$id", "customerId")).unwrap(),
            Query::search("note.body", "needle"),
        ]));
        assert_eq!(v.description(), "Searching by attribute \"note.body\" requires a fulltext index.");
    }

    #[test]
    fn joined_collections_do_not_widen_a_query_set_without_joins() {
        let v = validator(vec![orders()]);
        assert!(
            v.check(&[
                Query::join("orders", "purchase", on("$id", "customerId")).unwrap(),
                Query::sum("amount", "total"),
            ]),
            "{}",
            v.description()
        );
        assert!(!v.check(&[Query::sum("amount", "total")]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: amount");
        assert!(!v.check(&[Query::group_by(["status"])]));
        assert_eq!(v.description(), "Invalid query: Attribute not found in schema: status");
    }
}
