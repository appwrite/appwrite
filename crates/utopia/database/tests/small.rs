//! Ported from utopia-php/database 8.1.3 `tests/unit/ChangeTest.php`,
//! `ConnectionTest.php`, `DateTimeTest.php` and `StorageTest.php`.
//!
//! Not ported: `ChangeTest::testDocumentsCannotBeReplacedOnceSet` (PHP's
//! `readonly` property error; a Rust `Change` is immutable unless bound
//! `mut`, which the compiler enforces).

mod common;

use common::{document, v};
use utopia_database::connection::{has_error, is_lost};
use utopia_database::{Change, Document, Error, Kind, datetime, document as d, storage};

// ChangeTest

#[test]
fn test_constructor_with_old_and_new() {
    let old = document! {"$id" => "doc1", "name" => "Old Name"};
    let new = document! {"$id" => "doc1", "name" => "New Name"};
    let change = Change::new(old.clone(), new.clone());
    assert_eq!(change.old, old);
    assert_eq!(change.new, new);
}

#[test]
fn test_old_and_new_carry_their_documents() {
    let change = Change::new(
        document! {"$id" => "test", "status" => "draft"},
        document! {"$id" => "test", "status" => "published"},
    );
    assert_eq!(change.old.get("status"), Some(&v("draft")));
    assert_eq!(change.new.get("status"), Some(&v("published")));
    assert_eq!(change.old.id(), "test");
    assert_eq!(change.new.id(), "test");
}

#[test]
fn test_with_empty_documents() {
    let change = Change::new(Document::empty(), Document::empty());
    assert!(change.old.is_empty());
    assert!(change.new.is_empty());
}

// ConnectionTest

fn driver_error(state: &str, code: i64) -> Error {
    Error::new(Kind::Pdo, format!("SQLSTATE[{state}]: driver code {code}"))
        .with_string_code(state.as_bytes())
        .with_driver_code(code)
}

#[test]
fn test_driver_error_codes_of_a_lost_connection_are_detected() {
    for (state, code) in [
        ("HY000", 4031),
        ("HY000", 2006),
        ("HY000", 2013),
        ("HY000", 2002),
        ("08S01", 1053),
        ("08006", 7),
        ("57P01", 7),
        ("57P02", 7),
        ("57P03", 7),
        ("57P04", 7),
        ("57P05", 7),
    ] {
        assert!(is_lost(&driver_error(state, code)), "{state} {code}");
    }
}

#[test]
fn test_driver_error_codes_of_a_live_connection_are_not_detected() {
    for (state, code) in
        [("70100", 1969), ("HY000", 3024), ("57014", 7), ("23000", 1062), ("42000", 1064), ("40001", 1213)]
    {
        assert!(!is_lost(&driver_error(state, code)), "{state} {code}");
    }
}

#[test]
fn test_connect_failure_carrying_the_driver_code_is_detected() {
    let error = Error::new(Kind::Pdo, "SQLSTATE[HY000] [2002] refused").with_code(2002).with_driver_code(2002);
    assert!(is_lost(&error));
}

#[test]
fn test_driver_code_alone_is_not_the_error_info() {
    // `hasLostConnectionCode()` reads `errorInfo`, never `getCode()`.
    let error = Error::new(Kind::Pdo, "refused").with_code(2006);
    assert!(!is_lost(&error));
    assert!(has_error("MySQL server has gone away"));
    assert!(is_lost(&Error::database("Lost connection to MySQL server during query")));
}

// DateTimeTest

#[test]
fn test_now_after_advances_at_millisecond_precision() {
    assert_eq!(datetime::now_after(Some("2999-01-01 00:00:00.123")).unwrap(), "2999-01-01 00:00:00.124");
}

#[test]
fn test_now_after_uses_current_time_for_prior_timestamp() {
    let result = datetime::now_after(Some("2000-01-01 00:00:00.000")).unwrap();
    assert!(result.as_str() > "2000-01-01 00:00:00.000");
    assert_eq!(datetime::now_after(None).unwrap().len(), 23);
}

#[test]
fn test_format_tz_returns_unparseable_input_unchanged() {
    assert_eq!(datetime::format_tz(Some("not a date")).as_deref(), Some("not a date"));
    assert_eq!(datetime::format_tz(None), None);
    assert_eq!(
        datetime::format_tz(Some("2024-05-06 07:08:09.123+02:00")).as_deref(),
        Some("2024-05-06T07:08:09.123+02:00")
    );
}

const NOT_A_DATE: &str =
    "Failed to parse time string (not a date) at position 0 (n): The timezone could not be found in the database";

#[track_caller]
fn assert_wraps_malformed(error: Error) {
    assert_eq!(error.kind(), Kind::Database);
    assert_eq!(error.message(), NOT_A_DATE);
    assert_eq!(error.code(), 0);
    let previous = error.previous().expect("a previous error");
    assert_eq!(previous.kind(), Kind::DateMalformedString);
    assert_eq!(previous.message(), error.message());
}

#[test]
fn test_now_after_rejects_an_unparseable_previous_timestamp() {
    assert_wraps_malformed(datetime::now_after(Some("not a date")).unwrap_err());
}

#[test]
fn test_set_timezone_wraps_an_unparseable_value() {
    assert_wraps_malformed(datetime::set_timezone("not a date").unwrap_err());
}

// StorageTest

#[test]
fn test_column_mapping() {
    assert_eq!(storage::column(d::ID), storage::UID);
    assert_eq!(storage::column(d::SEQUENCE), storage::SEQUENCE);
    assert_eq!(storage::column("$internalId"), "$internalId");
    assert_eq!(storage::column("title"), "title");
}

#[test]
fn test_maps_invert() {
    for (attribute, column) in storage::ATTRIBUTE_MAP {
        assert_eq!(storage::attribute(column), attribute);
    }
}

#[test]
fn test_permissions_table() {
    assert_eq!(storage::permissions_table("movies"), "movies_perms");
}
