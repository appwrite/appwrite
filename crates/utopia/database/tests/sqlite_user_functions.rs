//! The SQLite adapter registers `REGEXP` only on a handle with
//! `createFunction()`: `Adapter/SQLite.php` `registerUserFunctions()`
//! (line 297) skips a plain `PDO`, so a regex query on one fails with
//! `no such function: REGEXP`.

#![cfg(feature = "sqlite")]

use utopia_database::adapter::sql::pdo::{Options, Pdo};

const QUERY: &str = "SELECT 'abc' REGEXP '^a'";

#[utopia_async::test]
async fn a_handle_without_user_functions_has_no_regexp() {
    let options = Options { user_functions: false, ..Options::default() };
    let mut pdo = Pdo::connect_with("sqlite::memory:", None, None, options).await.unwrap();
    assert!(!pdo.register_regexp());
    let Err(error) = pdo.query(QUERY).await else { panic!("REGEXP is not registered") };
    assert!(error.message().contains("no such function: REGEXP"), "{}", error.message());
}

#[utopia_async::test]
async fn a_handle_with_user_functions_registers_regexp() {
    let mut pdo = Pdo::connect_with("sqlite::memory:", None, None, Options::default()).await.unwrap();
    assert!(pdo.register_regexp());
    assert!(pdo.query(QUERY).await.is_ok());
}

/// PHP 8.3.6 Adapter/SQLite.php:278-280: a pattern that does not compile
/// fails the statement as an invalid query; one that compiles matches as
/// before.
#[utopia_async::test]
async fn a_pattern_that_does_not_compile_is_an_invalid_query() {
    let mut pdo = Pdo::connect_with("sqlite::memory:", None, None, Options::default()).await.unwrap();
    assert!(pdo.register_regexp());
    let Err(error) = pdo.query("SELECT 'abc' REGEXP '(('").await else { panic!("the pattern compiled") };
    assert_eq!((error.kind(), error.message()), (utopia_database::Kind::Query, "Invalid regular expression"));
    assert!(pdo.query(QUERY).await.is_ok());
}

/// PHP 8.3.6 Adapter/SQL.php:249-261 (`fetchExactly()`): PDO prints a fetched
/// double at the `precision` ini, 14 digits, and the round-trip digits while
/// it is set to -1.
#[utopia_async::test]
async fn a_double_is_fetched_exactly_at_the_round_trip_precision() {
    let mut pdo = Pdo::connect_with("sqlite::memory:", None, None, Options::default()).await.unwrap();
    let mut statement = pdo.query("SELECT 0.1 + 0.2 AS a, 0.1 + 0.2 AS b").await.unwrap();
    let printed = statement.fetch_num().unwrap();
    assert_eq!(printed.values().next().unwrap().to_php_string().unwrap().as_bytes(), b"0.3");
    let mut statement = pdo.query("SELECT 0.1 + 0.2 AS a").await.unwrap();
    let exact = statement.fetch_exactly(|s| s.fetch_num()).unwrap();
    assert_eq!(exact.values().next().unwrap().to_php_string().unwrap().as_bytes(), b"0.30000000000000004");
}
