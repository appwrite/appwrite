//! Ported from utopia-php/database 8.1.3 `tests/unit/Exception/HierarchyTest.php`,
//! `Exception/StateTest.php` and `Exception/UniqueTest.php`.

use utopia_database::{Error, Kind};

// HierarchyTest

#[test]
fn test_parent() {
    let parents = [
        (Kind::Schema, Kind::Database),
        (Kind::Structure, Kind::Schema),
        (Kind::Type, Kind::Schema),
        (Kind::Character, Kind::Schema),
        (Kind::Truncate, Kind::Schema),
        (Kind::Index, Kind::Schema),
        (Kind::Dependency, Kind::Schema),
        (Kind::Limit, Kind::Schema),
        (Kind::Relationship, Kind::Schema),
        (Kind::Query, Kind::Database),
        (Kind::Order, Kind::Query),
        (Kind::Operator, Kind::Query),
        (Kind::Transaction, Kind::Database),
        (Kind::Contention, Kind::Transaction),
        (Kind::Timeout, Kind::Database),
        (Kind::Unconfirmed, Kind::Database),
        (Kind::Duplicate, Kind::Database),
        (Kind::Unique, Kind::Duplicate),
        (Kind::Mismatch, Kind::Duplicate),
        (Kind::Authorization, Kind::Database),
        (Kind::Conflict, Kind::Database),
        (Kind::NotFound, Kind::Database),
        (Kind::Restricted, Kind::Database),
        (Kind::Refused, Kind::Database),
    ];
    for (kind, parent) in parents {
        assert_eq!(kind.parent(), Some(parent), "{kind:?}");
    }
    assert_eq!(Kind::Database.parent(), None);
}

#[test]
fn test_outside_the_transaction_subtree() {
    for kind in [Kind::Timeout, Kind::Unconfirmed, Kind::Refused] {
        assert!(!Error::new(kind, "").is_a(Kind::Transaction));
    }
}

#[test]
fn test_other_failures_are_not_schema_failures() {
    for e in [
        Error::query("Invalid query"),
        Error::order("Invalid order", None),
        Error::not_found("Collection not found"),
        Error::duplicate("Document already exists"),
        Error::transaction("Failed to start transaction"),
    ] {
        assert!(!e.is_a(Kind::Schema));
    }
}

#[test]
fn test_a_catch_of_query_catches_order_and_operator() {
    for e in [Error::order("Invalid order", Some("name".into())), Error::operator("Invalid operator")] {
        assert!(e.is_a(Kind::Query));
    }
}

#[test]
fn test_php_class_round_trip() {
    for kind in [Kind::Database, Kind::Unique, Kind::Order, Kind::Pdo, Kind::DateMalformedString] {
        assert_eq!(Kind::from_php_class(kind.php_class()), Some(kind));
    }
    assert_eq!(Kind::Database.php_class(), "Utopia\\Database\\Exception");
}

// StateTest

#[test]
fn test_the_code_and_the_state() {
    assert_eq!(Error::database("failed").with_code(1062).code(), 1062);
    assert_eq!(Error::database("failed").with_code(1062).state(), None);
    assert_eq!(Error::database("failed").with_code(0).code(), 0);
    for (code, expected_code, state) in
        [("HY000", 0, "HY000"), ("23000", 23000, "23000"), ("08S01", 0, "08S01"), ("", 0, "")]
    {
        let e = Error::database("failed").with_string_code(code.as_bytes());
        assert_eq!(e.code(), expected_code, "{code}");
        assert_eq!(e.state(), Some(state));
    }
}

#[test]
fn test_every_argument_is_optional() {
    let e = Error::database("");
    assert_eq!(e.message(), "");
    assert_eq!(e.code(), 0);
    assert_eq!(e.state(), None);
    assert!(e.previous().is_none());
}

#[test]
fn test_a_mapped_driver_error_keeps_its_sqlstate() {
    let driver = Error::new(Kind::Pdo, "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away")
        .with_string_code(b"HY000");
    let e = Error::duplicate("Document already exists").with_string_code(b"HY000").with_previous(driver.clone());
    assert_eq!(e.state(), Some("HY000"));
    assert_eq!(e.code(), 0);
    assert_eq!(e.previous(), Some(&driver));
}

#[test]
fn test_subclasses_keep_the_state() {
    let previous = Error::new(Kind::Runtime, "previous");
    let e = Error::structure("Invalid document structure").with_string_code(b"42S22").with_previous(previous.clone());
    assert_eq!(e.state(), Some("42S22"));
    assert_eq!(e.previous(), Some(&previous));
}

#[test]
fn test_order_takes_the_attribute_before_the_code() {
    let previous = Error::new(Kind::Runtime, "previous");
    let e = Error::order("Order attribute is empty", Some("name".into()))
        .with_string_code(b"HY000")
        .with_previous(previous.clone());
    assert_eq!(e.message(), "Order attribute is empty");
    assert_eq!(e.attribute(), Some("name"));
    assert_eq!(e.code(), 0);
    assert_eq!(e.state(), Some("HY000"));
    assert_eq!(e.previous(), Some(&previous));
}

#[test]
fn test_order_without_an_attribute() {
    let e = Error::order("Invalid order", None);
    assert_eq!(e.attribute(), None);
    assert_eq!(e.code(), 0);
    assert_eq!(e.state(), None);
}

// UniqueTest

#[test]
fn test_message_is_not_rewritten() {
    let previous = Error::new(Kind::Runtime, "previous");
    let e = Error::unique(utopia_database::error::UNIQUE_MESSAGE).with_code(42).with_previous(previous.clone());
    assert_eq!(e.message(), "Unique index violation");
    assert_eq!(e.code(), 42);
    assert_eq!(e.previous(), Some(&previous));
}

#[test]
fn test_specific_message_is_preserved() {
    assert_eq!(Error::unique("Custom unique conflict").message(), "Custom unique conflict");
}
