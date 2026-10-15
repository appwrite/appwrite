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
