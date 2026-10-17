//! A join without an alias is read under the ID of the table it joins
//! (utopia-php/database 8.3.6, utopia-php/query 0.7.2), and refused with the
//! reason when that ID cannot be an alias.
//!
//! PHP: `tests/unit/Validator/Query/JoinAliasTest.php`,
//! `tests/unit/QuerySerializationTest.php` and `tests/unit/JoinAliasTest.php`
//! of utopia-php/database 8.3.6; lines cited are of `src/Database` at 8.3.6.

mod support;

use serde_json::json;
use support::*;
use utopia_database::Query;
use utopia_database::query::DEFAULT_ALIAS;
use utopia_database::validator::query::join::Join;
use utopia_query::Method;

fn on(left: &str, right: &str) -> Vec<Query> {
    vec![Query::on(left, right, "=")]
}

/// Query.php:61-63: the database's constructor reads a join without an
/// alias under its collection, as the query library's factories do.
#[test]
fn a_join_built_without_an_alias_is_read_under_its_collection() {
    let join = Query::new(Method::Join, "orders", qa(json!([])), "");
    assert_eq!(join.alias(), "orders");
    assert!(join.is_alias_defaulted());
    let parsed =
        Query::parse_query(&qa(json!({"method": "crossJoin", "attribute": "orders", "values": []})), false).unwrap();
    assert_eq!(parsed.alias(), "orders");
    assert!(parsed.is_alias_defaulted());
    assert!(!Query::join("orders", "ord", on("$id", "ord.user")).unwrap().is_alias_defaulted());
}

/// Validator/Query/Join.php:128-148, 162-165: a table ID that cannot be an
/// alias is refused with the reason and a request for an alias.
#[test]
fn a_table_id_that_cannot_be_the_alias_is_refused() {
    let pattern = |table: &str| {
        format!(
            "Table ID \"{table}\" cannot be used as the join alias: an alias must start with a letter or an underscore and contain only letters, digits and underscores. Pass an alias for the join"
        )
    };
    let reserved = |table: &str| {
        format!(
            "Table ID \"{table}\" cannot be used as the join alias: it is reserved for the main collection. Pass an alias for the join"
        )
    };
    let upper = DEFAULT_ALIAS.to_uppercase();
    for (join, message) in [
        (Query::join("my-orders", "", on("$id", "customerId")).unwrap(), pattern("my-orders")),
        (Query::left_join("orders.v2", "", on("$id", "customerId")).unwrap(), pattern("orders.v2")),
        (Query::cross_join("2024orders", "").unwrap(), pattern("2024orders")),
        (Query::join(DEFAULT_ALIAS, "", on("$id", "customerId")).unwrap(), reserved(DEFAULT_ALIAS)),
        (Query::cross_join(upper.as_str(), "").unwrap(), reserved(&upper)),
    ] {
        let validator = Join::default();
        assert!(join.is_alias_defaulted());
        assert!(!validator.check(&join));
        assert_eq!(validator.description(), message);
    }
}

/// Join.php:221-223: a table ID that is a relationship key is refused with
/// its reason; an explicit alias keeps its message.
#[test]
fn a_table_id_that_is_a_relationship_key_is_refused() {
    let attributes = attrs(json!([
        {"$id": "customerId", "key": "customerId", "type": "string"},
        {"$id": "customers", "key": "customers", "type": "relationship", "options": {"relationType": "manyToOne", "side": "parent", "relatedCollection": "customers"}},
    ]));
    let validator = Join::new(Some(&attributes), true);
    assert!(!validator.check(&Query::join("customers", "", on("customerId", "$id")).unwrap()));
    assert_eq!(
        validator.description(),
        "Table ID \"customers\" cannot be used as the join alias: it is the key of the relationship attribute \"customers\". Pass an alias for the join"
    );
    validator.reset_join_aliases();
    assert!(!validator.check(&Query::join("people", "customers", on("customerId", "$id")).unwrap()));
    assert_eq!(
        validator.description(),
        "Join alias \"customers\" is the key of the relationship attribute \"customers\": give the join another alias"
    );
}

/// Join.php:153-158: a defaulted alias another join already uses names the
/// table; an explicit one keeps its message.
#[test]
fn describes_a_duplicate_alias_by_how_it_was_given() {
    assert_eq!(
        Join::describe_duplicate_alias("b", true),
        "Table ID \"b\" cannot be used as the join alias: another join of the query already uses it as its alias. Pass an alias for the join"
    );
    assert_eq!(Join::describe_duplicate_alias("b", false), "Join alias \"b\" is declared more than once");
    assert_eq!(Join::describe_invalid_alias("", true).as_deref(), Some("Join alias is required"));
    assert_eq!(Join::describe_invalid_alias("orders", true), None);
}
