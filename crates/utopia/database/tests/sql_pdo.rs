//! `adapter::sql::pdo`: PHP's `Utopia\Database\PDO` / `PDOStatement` over
//! the PDO drivers.
//!
//! Ports `tests/unit/PDOTest.php`, `PDOStatementTest.php`,
//! `Adapter/PDOConfigureTest.php` and `Adapter/PDOStatementFetchModeTest.php`
//! (their mocks of `\PDO` and `\PDOStatement` become [`Fault`]s injected
//! into real connections), and pins the emulation of PDO itself against
//! every engine: expected values are what PHP 8.5's PDO prints for the
//! same statements against the compat servers
//! (`tests/compat/database/services.sh`). The PostgreSQL and MySQL/MariaDB
//! cases skip, with a note, when their server is not reachable.

#![cfg(any(feature = "postgres", feature = "mariadb", feature = "sqlite"))]
#![allow(dead_code, unused_imports)]

use utopia_database::adapter::sql::pdo::{
    Attribute, Case, Driver, Fault, FetchMode, LOST_TRANSACTION, Options, ParamType, Pdo, PdoError, Statement,
};
use utopia_database::value::{Array, KeyRef, Str, Value};
use utopia_database::{Error, Kind};

/// A PHP-like dump of a value: `[a=>"1",0=>null]`.
fn dump(value: &Value) -> String {
    match value {
        Value::Null => "null".to_owned(),
        Value::Bool(b) => b.to_string(),
        Value::Int(i) => i.to_string(),
        Value::Str(s) => format!("{:?}", String::from_utf8_lossy(s.as_bytes())),
        Value::Array(a) => dump_array(a),
        other => format!("{other:?}"),
    }
}

fn dump_array(a: &Array) -> String {
    let items: Vec<String> = a
        .iter()
        .map(|(k, v)| {
            let key = match k {
                KeyRef::Int(i) => i.to_string(),
                KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
            };
            format!("{key}=>{}", dump(v))
        })
        .collect();
    format!("[{}]", items.join(","))
}

fn dump_rows(rows: &[Array]) -> String {
    format!("[{}]", rows.iter().map(dump_array).collect::<Vec<_>>().join(","))
}

fn s(v: &str) -> Value {
    Value::Str(Str::from(v))
}

/// A lost connection, as the PHP tests throw it.
fn gone_away() -> Error {
    PdoError::driver("HY000", 2006, "MySQL server has gone away").into_error()
}

/// `new PDOException('Max connect timeout reached')`.
fn timeout_reached() -> Error {
    PdoError::new("Max connect timeout reached").into_error()
}

async fn memory() -> Pdo {
    Pdo::connect("sqlite::memory:", None, None).await.expect("sqlite in memory")
}

async fn rows(pdo: &mut Pdo, sql: &str) -> String {
    let mut statement = pdo.query(sql).await.expect("query");
    dump_rows(&statement.fetch_all_assoc())
}

async fn column(pdo: &mut Pdo, sql: &str) -> Option<Value> {
    pdo.query(sql).await.expect("query").fetch_column(0).expect("column")
}

/// Whether the in-memory database still has `table`: a reconnect opens an
/// empty one.
async fn has_table(pdo: &mut Pdo, table: &str) -> bool {
    let sql = format!("SELECT count(*) FROM sqlite_master WHERE name = '{table}'");
    column(pdo, &sql).await == Some(s("1"))
}

/// A database file removed when the guard drops.
struct TempFile(std::path::PathBuf);

impl TempFile {
    fn new(name: &str) -> TempFile {
        let unique = format!(
            "utopia-pdo-{name}-{}-{}.db",
            std::process::id(),
            std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_nanos()).unwrap_or(0)
        );
        TempFile(std::env::temp_dir().join(unique))
    }

    fn dsn(&self) -> String {
        format!("sqlite:{}", self.0.display())
    }
}

impl Drop for TempFile {
    fn drop(&mut self) {
        let _ = std::fs::remove_file(&self.0);
    }
}

/// The values of `items`, read through a connection of its own.
async fn values(file: &TempFile) -> Vec<String> {
    let mut pdo = Pdo::connect(&file.dsn(), None, None).await.expect("open");
    let mut statement = pdo.query("SELECT value FROM items ORDER BY value").await.expect("query");
    statement
        .fetch_all_column(0)
        .expect("values")
        .into_iter()
        .map(|v| match v {
            Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            other => dump(&other),
        })
        .collect()
}

fn assert_refused<T: std::fmt::Debug>(result: utopia_database::Result<T>) {
    let error = result.expect_err("a statement after a lost transaction must be refused");
    assert!(error.message().contains("roll it back"), "{}", error.message());
}

/// `Tests\Unit\PDOTest`.
#[cfg(feature = "sqlite")]
mod pdo {
    use super::*;

    #[utopia_async::test]
    async fn method_call_is_forwarded_to_pdo() {
        let mut pdo = memory().await;
        let mut statement = pdo.query("SELECT 1").await.unwrap();
        assert_eq!(statement.query_string(), "SELECT 1");
        assert_eq!(statement.fetch_column(0).unwrap(), Some(s("1")));
    }

    #[utopia_async::test]
    async fn lost_connection_retries_call() {
        let mut pdo = memory().await;
        pdo.exec("CREATE TABLE marker (a)").await.unwrap();
        pdo.inject(Fault::Next(Error::new(Kind::Exception, "Lost connection")));

        let mut statement = pdo.query("SELECT 1").await.unwrap();

        assert_eq!(statement.fetch_column(0).unwrap(), Some(s("1")));
        assert!(!has_table(&mut pdo, "marker").await, "the call ran again on a new connection");
    }

    #[utopia_async::test]
    async fn non_lost_connection_exception_is_rethrown() {
        let mut pdo = memory().await;
        pdo.exec("CREATE TABLE marker (a)").await.unwrap();
        pdo.inject(Fault::Next(Error::new(Kind::Exception, "Other error")));

        let error = pdo.query("SELECT 1").await.unwrap_err();

        assert_eq!(error.kind(), Kind::Exception);
        assert_eq!(error.message(), "Other error");
        assert!(has_table(&mut pdo, "marker").await, "no reconnect");
    }

    #[utopia_async::test]
    async fn reconnect_creates_new_pdo_instance() {
        let mut pdo = memory().await;
        pdo.exec("CREATE TABLE marker (a)").await.unwrap();
        pdo.reconnect().await.unwrap();
        assert!(!has_table(&mut pdo, "marker").await, "Reconnect should create a new connection");
    }

    #[utopia_async::test]
    async fn method_call_for_prepare() {
        let mut pdo = memory().await;
        let statement = pdo.prepare("SELECT * FROM sqlite_master").unwrap();
        assert_eq!(statement.query_string(), "SELECT * FROM sqlite_master");
        assert_eq!(statement.row_count(), 0);
    }

    /// `testPrepareNativeReconnectsOutsideTransaction`: with emulated
    /// prepares nothing reaches the server at prepare time, so the loss
    /// surfaces when the statement executes, which recovers it.
    #[utopia_async::test]
    async fn prepare_native_reconnects_outside_transaction() {
        let mut pdo = memory().await;
        let mut statement = pdo.prepare("SELECT 1").unwrap();
        pdo.inject(Fault::Next(PdoError::new("server has gone away").into_error()));
        assert!(statement.execute(&mut pdo).await.unwrap());
        assert_eq!(statement.fetch_column(0).unwrap(), Some(s("1")));
    }

    /// `testPrepareNativeThrowsWhenNativePrepareReturnsFalse`: a native
    /// prepare cannot return `false` here; an empty statement is PHP's
    /// `ValueError`, and SQLite's prepare reports its error.
    #[utopia_async::test]
    async fn prepare_native_reports_a_statement_it_cannot_prepare() {
        let mut pdo = memory().await;
        let error = pdo.prepare("").unwrap_err();
        assert_eq!(error.kind(), Kind::ValueError);
        assert_eq!(error.message(), "PDO::prepare(): Argument #1 ($query) must not be empty");
        let error = pdo.prepare("INVALID").unwrap_err();
        assert_eq!(error.message(), "SQLSTATE[HY000]: General error: 1 near \"INVALID\": syntax error");
    }

    #[utopia_async::test]
    async fn reconnect_replays_the_configured_session() {
        let mut pdo = memory().await;
        pdo.configure("cache", "PRAGMA cache_size = 100").await.unwrap();
        pdo.configure("cache", "PRAGMA cache_size = 200").await.unwrap();
        pdo.configure("keys", "PRAGMA foreign_keys = ON").await.unwrap();

        pdo.reconnect().await.unwrap();

        assert_eq!(
            column(&mut pdo, "PRAGMA cache_size").await,
            Some(s("200")),
            "The latest statement for a setting must win"
        );
        assert_eq!(column(&mut pdo, "PRAGMA foreign_keys").await, Some(s("1")));
    }

    #[utopia_async::test]
    async fn call_retried_after_a_lost_connection_runs_on_the_configured_session() {
        let mut pdo = memory().await;
        pdo.configure("marker", "CREATE TEMP TABLE marker AS SELECT 7 AS value").await.unwrap();
        pdo.inject(Fault::Next(gone_away()));

        let mut statement = pdo.query("SELECT value FROM temp.marker").await.unwrap();

        assert_eq!(statement.fetch_column(0).unwrap(), Some(s("7")));
    }

    #[utopia_async::test]
    async fn reconnect_keeps_the_lost_connection_when_the_session_cannot_be_replayed() {
        let mut pdo = memory().await;
        pdo.exec("CREATE TEMP TABLE local (value INTEGER)").await.unwrap();
        pdo.configure("row", "INSERT INTO temp.local VALUES (1)").await.unwrap();

        let failure = pdo.reconnect().await.unwrap_err();

        assert_eq!(failure.kind(), Kind::Pdo, "The new connection has no temp.local to replay into");
        assert_eq!(failure.message(), "SQLSTATE[HY000]: General error: 1 no such table: temp.local");
        assert_eq!(
            column(&mut pdo, "SELECT count(*) FROM temp.local").await,
            Some(s("1")),
            "A connection missing the configured session must never be used"
        );
    }

    #[utopia_async::test]
    async fn reconnect_replays_attributes() {
        let mut pdo = memory().await;
        assert!(pdo.set_attribute(Attribute::Case(Case::Upper)));
        assert!(pdo.set_attribute(Attribute::DefaultFetchMode(FetchMode::Num)));

        pdo.reconnect().await.unwrap();

        assert_eq!(pdo.case(), Case::Upper);
        assert_eq!(pdo.default_fetch_mode(), FetchMode::Num);
        let mut statement = pdo.query("SELECT 1 AS value").await.unwrap();
        assert_eq!(dump(&statement.fetch().unwrap().unwrap()), "[0=>\"1\"]");
        let mut statement = pdo.query("SELECT 1 AS value").await.unwrap();
        assert_eq!(dump_array(&statement.fetch_assoc().unwrap()), "[VALUE=>\"1\"]");
    }

    #[utopia_async::test]
    async fn statements_after_a_lost_transaction_are_refused_until_it_is_rolled_back() {
        let file = TempFile::new("refused");
        let mut pdo = Pdo::connect(&file.dsn(), None, None).await.unwrap();
        pdo.exec("CREATE TABLE items (value INTEGER)").await.unwrap();
        pdo.begin_transaction().await.unwrap();
        pdo.exec("INSERT INTO items VALUES (1)").await.unwrap();
        pdo.inject(Fault::EndSession);

        assert!(pdo.exec("INSERT INTO items VALUES (2)").await.is_err());

        assert_refused(pdo.exec("INSERT INTO items VALUES (3)").await);
        assert_refused(pdo.query("SELECT value FROM items").await);
        assert_refused(pdo.prepare("INSERT INTO items VALUES (4)"));
        assert_refused(pdo.commit().await);
        assert_eq!(
            values(&file).await,
            Vec::<String>::new(),
            "Nothing may run on its own after the transaction was lost"
        );
        assert!(pdo.in_transaction(), "The caller still holds a transaction until it rolls back");

        assert!(pdo.roll_back().await.unwrap());
        assert!(!pdo.in_transaction());

        pdo.exec("INSERT INTO items VALUES (5)").await.unwrap();
        assert_eq!(values(&file).await, vec!["5"]);
    }

    #[utopia_async::test]
    async fn a_refused_statement_carries_the_lost_connection() {
        let file = TempFile::new("carries");
        let mut pdo = Pdo::connect(&file.dsn(), None, None).await.unwrap();
        pdo.exec("CREATE TABLE items (value INTEGER)").await.unwrap();
        pdo.begin_transaction().await.unwrap();
        pdo.inject(Fault::EndSession);

        let lost = pdo.exec("INSERT INTO items VALUES (1)").await.unwrap_err();
        let refusal = pdo.exec("INSERT INTO items VALUES (2)").await.unwrap_err();

        assert_eq!(lost.kind(), Kind::Pdo);
        assert_eq!(lost.message(), "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away");
        assert_eq!(refusal.kind(), Kind::Pdo);
        assert_eq!(refusal.message(), LOST_TRANSACTION);
        assert_eq!(refusal.code(), 0);
        assert_eq!(refusal.previous(), Some(&lost), "A refusal must carry the lost connection that caused it");
        assert!(
            !utopia_database::connection::is_lost(&refusal),
            "A refusal must not be taken for a lost connection itself"
        );
    }

    #[utopia_async::test]
    async fn a_rollback_statement_ends_a_lost_transaction() {
        let file = TempFile::new("rollback-statement");
        let mut pdo = Pdo::connect(&file.dsn(), None, None).await.unwrap();
        pdo.exec("CREATE TABLE items (value INTEGER)").await.unwrap();
        pdo.begin_transaction().await.unwrap();
        pdo.inject(Fault::EndSession);
        assert!(pdo.exec("INSERT INTO items VALUES (1)").await.is_err());

        pdo.prepare("ROLLBACK").unwrap();

        assert!(!pdo.in_transaction());
        pdo.exec("INSERT INTO items VALUES (2)").await.unwrap();
        assert_eq!(values(&file).await, vec!["2"]);
    }

    #[utopia_async::test]
    async fn an_explicit_reconnect_ends_a_lost_transaction() {
        let file = TempFile::new("explicit");
        let mut pdo = Pdo::connect(&file.dsn(), None, None).await.unwrap();
        pdo.exec("CREATE TABLE items (value INTEGER)").await.unwrap();
        pdo.begin_transaction().await.unwrap();
        pdo.inject(Fault::EndSession);
        assert!(pdo.exec("INSERT INTO items VALUES (1)").await.is_err());

        pdo.reconnect().await.unwrap();

        assert!(!pdo.in_transaction());
        pdo.exec("INSERT INTO items VALUES (2)").await.unwrap();
        assert_eq!(values(&file).await, vec!["2"]);
    }

    #[utopia_async::test]
    async fn a_rollback_that_finds_the_connection_lost_ends_the_transaction() {
        let file = TempFile::new("rollback-lost");
        let mut pdo = Pdo::connect(&file.dsn(), None, None).await.unwrap();
        pdo.exec("CREATE TABLE items (value INTEGER)").await.unwrap();
        pdo.begin_transaction().await.unwrap();
        pdo.exec("INSERT INTO items VALUES (1)").await.unwrap();
        pdo.inject(Fault::EndSession);

        let lost = pdo.roll_back().await.unwrap_err();

        assert_eq!(lost.message(), "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away");
        assert!(!pdo.in_transaction(), "The lost connection ended the transaction");
        pdo.exec("INSERT INTO items VALUES (2)").await.unwrap();
        assert_eq!(values(&file).await, vec!["2"]);
    }
}

/// `Tests\Unit\PDOStatementTest`.
#[cfg(feature = "sqlite")]
mod pdo_statement {
    use super::*;

    #[utopia_async::test]
    async fn execute_reconnects_reprepares_and_replays_when_not_in_transaction() {
        let mut pdo = memory().await;
        pdo.exec("CREATE TABLE marker (a)").await.unwrap();
        let mut statement = pdo.prepare("SELECT :id AS id").unwrap();
        statement.bind_value(":id", "abc", ParamType::Str).unwrap();
        pdo.inject(Fault::Next(timeout_reached()));

        assert!(statement.execute(&mut pdo).await.unwrap());

        assert_eq!(dump_rows(&statement.fetch_all_assoc()), "[[id=>\"abc\"]]");
        assert!(!has_table(&mut pdo, "marker").await, "the statement ran on a new connection");
    }

    #[utopia_async::test]
    async fn execute_rethrows_and_does_not_reconnect_inside_transaction() {
        let mut pdo = memory().await;
        pdo.exec("CREATE TABLE marker (a)").await.unwrap();
        pdo.begin_transaction().await.unwrap();
        let mut statement = pdo.prepare("SELECT 1").unwrap();
        pdo.inject(Fault::Next(timeout_reached()));

        let error = statement.execute(&mut pdo).await.unwrap_err();

        assert_eq!(error.message(), "Max connect timeout reached");
        assert!(pdo.in_transaction());
        assert!(has_table(&mut pdo, "marker").await, "no reconnect");
    }

    #[utopia_async::test]
    async fn execute_rethrows_non_connection_errors() {
        let mut pdo = memory().await;
        pdo.exec("CREATE TABLE marker (a)").await.unwrap();
        let mut statement = pdo.prepare("SELECT 1").unwrap();
        pdo.inject(Fault::Next(PdoError::new("SQLSTATE[42000]: Syntax error").into_error()));

        let error = statement.execute(&mut pdo).await.unwrap_err();

        assert!(error.message().contains("Syntax error"));
        assert!(has_table(&mut pdo, "marker").await, "no reconnect");
    }

    /// `testForwardsCallsAndPropertiesToUnderlyingStatement`.
    #[utopia_async::test]
    async fn reports_the_row_count() {
        let mut pdo = memory().await;
        pdo.exec("CREATE TABLE t (a); INSERT INTO t VALUES (1), (2), (3), (4), (5), (6), (7)").await.unwrap();
        let mut statement = pdo.prepare("UPDATE t SET a = a + 1").unwrap();
        statement.execute(&mut pdo).await.unwrap();
        assert_eq!(statement.row_count(), 7);
    }

    /// `testIsIterableAndDelegatesIterationToTheStatement`.
    #[utopia_async::test]
    async fn iterates_rows() {
        let mut pdo = memory().await;
        let mut statement = pdo.query("SELECT 1 AS id UNION SELECT 2").await.unwrap();
        let mut ids = Vec::new();
        while let Some(row) = statement.fetch().unwrap() {
            ids.push(dump(&row));
        }
        assert_eq!(ids, vec!["[id=>\"1\"]", "[id=>\"2\"]"]);
    }

    /// `testDoesNotReconnectForNonExecuteMethods`: results are read when the
    /// statement executes, so fetching never reaches the connection: a lost
    /// connection does not affect it and nothing reconnects.
    #[utopia_async::test]
    async fn does_not_reconnect_for_non_execute_methods() {
        let mut pdo = memory().await;
        pdo.exec("CREATE TABLE marker (a)").await.unwrap();
        let mut statement = pdo.query("SELECT 1 AS a").await.unwrap();
        pdo.inject(Fault::Next(PdoError::new("server has gone away").into_error()));
        assert_eq!(statement.fetch_column(0).unwrap(), Some(s("1")));
        assert_eq!(statement.fetch_column(0).unwrap(), None);
        assert!(
            !has_table(&mut pdo, "marker").await,
            "the failure is still pending: fetching never reached the connection"
        );
    }

    /// `testBindParamReplaysCurrentValueAfterReconnect` /
    /// `testReplaysMixedBindingsInOriginalCallOrder`: values are bound by
    /// value; the binding applied last is the one replayed.
    #[utopia_async::test]
    async fn replays_the_last_binding_after_reconnect() {
        let mut pdo = memory().await;
        let mut statement = pdo.prepare("SELECT :id AS id").unwrap();
        statement.bind_value(":id", "old", ParamType::Str).unwrap();
        statement.bind_value("id", "new", ParamType::Str).unwrap();
        pdo.inject(Fault::Next(PdoError::new("server has gone away").into_error()));

        assert!(statement.execute(&mut pdo).await.unwrap());

        assert_eq!(statement.fetch_column(0).unwrap(), Some(s("new")));
    }

    #[utopia_async::test]
    async fn execute_retried_after_a_lost_connection_runs_on_the_configured_session() {
        let mut pdo = memory().await;
        pdo.configure("marker", "CREATE TEMP TABLE marker AS SELECT 7 AS value").await.unwrap();
        let mut statement = pdo.prepare("SELECT value FROM temp.marker").unwrap();
        pdo.inject(Fault::Next(gone_away()));

        assert!(statement.execute(&mut pdo).await.unwrap());
        assert_eq!(statement.fetch_column(0).unwrap(), Some(s("7")));
    }

    #[utopia_async::test]
    async fn a_statement_is_refused_after_a_lost_transaction() {
        let mut pdo = memory().await;
        let mut statement = pdo.prepare("SELECT 1").unwrap();
        pdo.begin_transaction().await.unwrap();
        pdo.inject(Fault::EndSession);
        assert!(pdo.exec("SELECT 1").await.is_err());
        assert_refused(statement.execute(&mut pdo).await);
        assert!(pdo.roll_back().await.unwrap());
        assert!(statement.execute(&mut pdo).await.unwrap());
    }
}

/// `Tests\Unit\Adapter\PDOConfigureTest` (with `ERRMODE_EXCEPTION`, the
/// engine's error is returned instead of `Failed to configure session`).
#[cfg(feature = "sqlite")]
mod pdo_configure {
    use super::*;

    #[utopia_async::test]
    async fn configure_rejects_a_statement_the_engine_refuses() {
        let mut pdo = memory().await;

        let error = pdo.configure("broken", "NOT SQL").await.unwrap_err();
        assert_eq!(error.message(), "SQLSTATE[HY000]: General error: 1 near \"NOT\": syntax error");

        pdo.reconnect().await.unwrap();

        assert_eq!(rows(&mut pdo, "SELECT 1 AS value").await, "[[value=>\"1\"]]");
    }

    #[utopia_async::test]
    async fn configure_keeps_the_earlier_statement_when_a_later_one_is_refused() {
        let mut pdo = memory().await;
        pdo.configure("marker", "CREATE TEMP TABLE marker AS SELECT 7 AS value").await.unwrap();

        assert!(pdo.configure("marker", "NOT SQL").await.is_err());

        pdo.reconnect().await.unwrap();

        assert_eq!(rows(&mut pdo, "SELECT value FROM temp.marker").await, "[[value=>\"7\"]]");
    }
}

/// `Tests\Unit\Adapter\PDOStatementFetchModeTest`.
#[cfg(feature = "sqlite")]
mod pdo_statement_fetch_mode {
    use super::*;

    const QUERY: &str = "SELECT 7 AS value, 8 AS other";

    async fn lost(options: Options) -> (Pdo, Statement) {
        let mut pdo = Pdo::connect_with("sqlite::memory:", None, None, options).await.unwrap();
        let statement = pdo.prepare(QUERY).unwrap();
        pdo.inject(Fault::Next(gone_away()));
        (pdo, statement)
    }

    #[utopia_async::test]
    async fn fetch_mode_is_kept_across_a_reconnect() {
        let (mut pdo, mut statement) = lost(Options::default()).await;
        statement.set_fetch_mode(FetchMode::Num);

        assert!(statement.execute(&mut pdo).await.unwrap());
        assert_eq!(dump(&statement.fetch().unwrap().unwrap()), "[0=>\"7\",1=>\"8\"]");
    }

    #[utopia_async::test]
    async fn fetch_mode_arguments_are_kept_across_a_reconnect() {
        let (mut pdo, mut statement) = lost(Options::default()).await;
        statement.set_fetch_mode(FetchMode::Column(1));

        assert!(statement.execute(&mut pdo).await.unwrap());
        assert_eq!(dump_array(&statement.fetch_all().unwrap()), "[0=>\"8\"]");
    }

    #[utopia_async::test]
    async fn without_a_fetch_mode_the_reconnected_statement_uses_the_default() {
        let options = Options { fetch_mode: FetchMode::Both, ..Options::default() };
        let (mut pdo, mut statement) = lost(options).await;

        assert!(statement.execute(&mut pdo).await.unwrap());
        assert_eq!(dump(&statement.fetch().unwrap().unwrap()), "[value=>\"7\",0=>\"7\",other=>\"8\",1=>\"8\"]");
    }
}

/// The servers of `tests/compat/database/services.sh`.
mod servers {
    use super::*;

    pub const POSTGRES: &str = "pgsql:host=127.0.0.1;port=16432;dbname=root";
    pub const MARIADB: &str = "mysql:host=127.0.0.1;port=16306;charset=utf8mb4";
    pub const MYSQL: &str = "mysql:host=127.0.0.1;port=16307;charset=utf8mb4";

    /// A connection to `dsn`, or `None` (with a note) when its server is
    /// not reachable.
    pub async fn connect(dsn: &str) -> Option<Pdo> {
        match Pdo::connect(dsn, Some("root"), Some("password")).await {
            Ok(pdo) => Some(pdo),
            Err(e) => {
                eprintln!(
                    "skipped: {dsn} is not reachable ({}); run tests/compat/database/services.sh up",
                    e.message()
                );
                None
            }
        }
    }

    /// Every engine reachable: SQLite, then the servers that are up.
    pub async fn all() -> Vec<(&'static str, Pdo)> {
        let mut out = Vec::new();
        #[cfg(feature = "sqlite")]
        out.push(("sqlite", memory().await));
        #[cfg(feature = "postgres")]
        if let Some(pdo) = connect(POSTGRES).await {
            out.push(("pgsql", pdo));
        }
        #[cfg(feature = "mariadb")]
        {
            if let Some(pdo) = connect(MARIADB).await {
                out.push(("mariadb", pdo));
            }
            if let Some(pdo) = connect(MYSQL).await {
                out.push(("mysql", pdo));
            }
        }
        out
    }
}

/// PDO itself, against every engine: the expected values are PHP's.
mod emulation {
    use super::servers::all;
    use super::*;

    /// A statement, its bindings, and its rows or error.
    type BindCase<'a> = (&'a str, &'a [(&'a str, Value, ParamType)], Result<&'a str, &'a str>);

    async fn run(pdo: &mut Pdo, sql: &str, binds: &[(&str, Value, ParamType)]) -> utopia_database::Result<String> {
        let mut statement = pdo.prepare(sql)?;
        for (param, value, ty) in binds {
            match param.parse::<usize>() {
                Ok(n) => statement.bind_value(n, value.clone(), *ty)?,
                Err(_) => statement.bind_value(*param, value.clone(), *ty)?,
            }
        }
        statement.execute(pdo).await?;
        Ok(dump_rows(&statement.fetch_all_assoc()))
    }

    #[utopia_async::test]
    async fn binds_positional_and_named_parameters() {
        for (name, mut pdo) in all().await {
            let quoted = "it's \"q\" \\ \n";
            let got = run(
                &mut pdo,
                "SELECT ? AS a, ? AS b, ? AS c, ? AS d",
                &[
                    ("1", s(quoted), ParamType::Str),
                    ("2", Value::Bool(true), ParamType::Bool),
                    ("3", s("12abc"), ParamType::Int),
                    ("4", Value::Null, ParamType::Str),
                ],
            )
            .await
            .unwrap();
            let b = if name == "pgsql" { "t" } else { "1" };
            assert_eq!(got, format!("[[a=>{quoted:?},b=>\"{b}\",c=>\"12\",d=>null]]"), "{name}");

            let mut statement = pdo.prepare("SELECT :a AS a, :b AS b, :a AS c").unwrap();
            statement.bind_value(":a", "A", ParamType::Str).unwrap();
            statement.bind_value("b", false, ParamType::Bool).unwrap();
            statement.execute(&mut pdo).await.unwrap();
            let b = if name == "pgsql" { "f" } else { "0" };
            assert_eq!(dump_rows(&statement.fetch_all_num()), format!("[[0=>\"A\",1=>\"{b}\",2=>\"A\"]]"), "{name}");

            let got =
                run(&mut pdo, "SELECT :a AS a", &[(":a", s("A"), ParamType::Str), (":a", s("B"), ParamType::Str)])
                    .await
                    .unwrap();
            assert_eq!(got, "[[a=>\"B\"]]", "{name}: rebinding replaces");

            let got = run(
                &mut pdo,
                "SELECT ? AS a, ? AS b",
                &[("1", Value::Float(3.9), ParamType::Int), ("2", s("5"), ParamType::Null)],
            )
            .await
            .unwrap();
            assert_eq!(got, "[[a=>\"3\",b=>null]]", "{name}");

            let got = run(
                &mut pdo,
                "SELECT ? AS a, ? AS b",
                &[("1", Value::Float(1.0), ParamType::Str), ("2", Value::Float(0.1 + 0.2), ParamType::Str)],
            )
            .await
            .unwrap();
            assert_eq!(got, "[[a=>\"1\",b=>\"0.3\"]]", "{name}");

            let got = run(&mut pdo, "SELECT ? AS a", &[("1", Value::Str(Str::from(&b"a\0b"[..])), ParamType::Str)])
                .await
                .unwrap();
            let expected = if name == "pgsql" { "[[a=>\"a\"]]" } else { "[[a=>\"a\\0b\"]]" };
            assert_eq!(got, expected, "{name}: NUL bytes");

            let got = run(&mut pdo, "SELECT '??' AS a, ? AS b", &[("1", s("x"), ParamType::Str)]).await.unwrap();
            assert_eq!(got, "[[a=>\"??\",b=>\"x\"]]", "{name}");
        }
    }

    #[utopia_async::test]
    async fn reports_binding_errors() {
        let count =
            "SQLSTATE[HY093]: Invalid parameter number: number of bound variables does not match number of tokens";
        let undefined = "SQLSTATE[HY093]: Invalid parameter number: parameter was not defined";
        let mixed = "SQLSTATE[HY093]: Invalid parameter number: mixed named and positional parameters";
        let range = "SQLSTATE[HY000]: General error: 25 column index out of range";
        for (name, mut pdo) in all().await {
            let emulated = name != "sqlite";
            let cases: [BindCase<'_>; 7] = [
                (
                    "SELECT :a AS a, :b AS b",
                    &[(":a", s("A"), ParamType::Str)],
                    if emulated { Err(count) } else { Ok("[[a=>\"A\",b=>null]]") },
                ),
                (
                    "SELECT :a AS a",
                    &[(":a", s("A"), ParamType::Str), (":zz", s("Z"), ParamType::Str)],
                    Err(if emulated { count } else { range }),
                ),
                (
                    "SELECT ? AS a",
                    &[("1", s("A"), ParamType::Str), ("2", s("Z"), ParamType::Str)],
                    Err(if emulated { count } else { range }),
                ),
                (
                    "SELECT ? AS a, ? AS b",
                    &[("2", s("A"), ParamType::Str)],
                    if emulated { Err(count) } else { Ok("[[a=>null,b=>\"A\"]]") },
                ),
                ("SELECT 1 AS a", &[(":zz", s("x"), ParamType::Str)], Err(if emulated { count } else { range })),
                (
                    "SELECT :a AS a",
                    &[("1", s("x"), ParamType::Str)],
                    if emulated { Err(undefined) } else { Ok("[[a=>\"x\"]]") },
                ),
                (
                    "SELECT ? AS a, :b AS b",
                    &[("1", s("x"), ParamType::Str), (":b", s("y"), ParamType::Str)],
                    if emulated { Err(mixed) } else { Ok("[[a=>\"x\",b=>\"y\"]]") },
                ),
            ];
            for (sql, binds, expected) in cases {
                let got = run(&mut pdo, sql, binds).await;
                match (got, expected) {
                    (Ok(rows), Ok(want)) => assert_eq!(rows, want, "{name}: {sql}"),
                    (Err(e), Err(want)) => {
                        assert_eq!(e.message(), want, "{name}: {sql}");
                        assert_eq!(e.kind(), Kind::Pdo);
                    }
                    (got, want) => panic!("{name}: {sql}: got {got:?}, want {want:?}"),
                }
            }
            if emulated {
                let error = run(&mut pdo, "SELECT 1 AS a WHERE 1 = 1 OR ? = :b", &[]).await.unwrap_err();
                assert_eq!(error.message(), mixed, "{name}: mixed without bindings");
                assert_eq!(error.state(), Some("HY093"));
                assert_eq!(error.driver_code(), Some(0));
            }
            let error = pdo.prepare("SELECT ?").unwrap().bind_value(0, "x", ParamType::Str).unwrap_err();
            assert_eq!(error.kind(), Kind::ValueError);
            assert_eq!(
                error.message(),
                "PDOStatement::bindValue(): Argument #1 ($param) must be greater than or equal to 1"
            );
        }
    }

    #[utopia_async::test]
    async fn fetches_as_php_does() {
        for (name, mut pdo) in all().await {
            let dup = rows(&mut pdo, "SELECT 1 AS a, 2 AS b, 3 AS a").await;
            assert_eq!(dup, "[[a=>\"3\",b=>\"2\"]]", "{name}: a later duplicate overwrites in place");
            let mut statement = pdo.query("SELECT 1 AS a, 2 AS b, 3 AS a").await.unwrap();
            assert_eq!(dump_rows(&statement.fetch_all_num()), "[[0=>\"1\",1=>\"2\",2=>\"3\"]]", "{name}");
            let numeric = if name.starts_with('m') { "SELECT 1 AS `5`" } else { "SELECT 1 AS \"5\"" };
            assert_eq!(rows(&mut pdo, numeric).await, "[[5=>\"1\"]]", "{name}");

            let mut statement = pdo.query("SELECT 1 AS a, 2 AS b UNION SELECT 3, 4").await.unwrap();
            assert_eq!(statement.row_count(), if name == "sqlite" { 0 } else { 2 }, "{name}");
            assert_eq!(statement.fetch_column(1).unwrap(), Some(s("2")));
            assert_eq!(statement.fetch_column(0).unwrap(), Some(s("3")));
            assert_eq!(statement.fetch_column(0).unwrap(), None);

            let mut statement = pdo.query("SELECT 1 AS a").await.unwrap();
            let error = statement.fetch_column(3).unwrap_err();
            assert_eq!((error.kind(), error.message()), (Kind::ValueError, "Invalid column index"));

            let mut statement = pdo.query("SELECT 1 AS a, 2 AS b").await.unwrap();
            statement.set_fetch_mode(FetchMode::Both);
            assert_eq!(dump(&statement.fetch().unwrap().unwrap()), "[a=>\"1\",0=>\"1\",b=>\"2\",1=>\"2\"]");

            let mut statement = pdo.query("SELECT 1 AS a UNION SELECT 2").await.unwrap();
            assert!(statement.fetch_assoc().is_some());
            assert!(statement.close_cursor());
            assert!(statement.fetch_assoc().is_none(), "{name}: closeCursor frees the rows");

            let mut statement = pdo.prepare("SELECT 1 AS a").unwrap();
            assert!(statement.fetch().unwrap().is_none());
            assert_eq!(statement.row_count(), 0);

            let mut statement = pdo.prepare("SELECT ? AS a").unwrap();
            statement.bind_value(1, "x", ParamType::Str).unwrap();
            statement.execute(&mut pdo).await.unwrap();
            statement.bind_value(1, "y", ParamType::Str).unwrap();
            statement.execute(&mut pdo).await.unwrap();
            assert_eq!(dump_rows(&statement.fetch_all_assoc()), "[[a=>\"y\"]]", "{name}: executing again");
        }
    }

    #[utopia_async::test]
    async fn prints_engine_types_as_php_does() {
        for (name, mut pdo) in all().await {
            let (sql, expected) = match name {
                "pgsql" => (
                    "SELECT 1 AS i, 1.5::float8 AS f, 0.1::float4 AS f4, 2.50::numeric AS n, true AS b, NULL AS z, 'x' AS s, \
                     9223372036854775807::int8 AS big, 1e100::float8 AS e, 'NaN'::float8 AS nan, '-Infinity'::float8 AS inf, \
                     1.0::float8 AS one, 0.30000000000000004::float8 AS third, 123456789012345678::float8 AS g, 5::oid AS o, \
                     '{1,2}'::int[] AS arr, 'x'::char(3) AS ch, 7::int2 AS small, '\\x00ff'::bytea AS bytes",
                    "[[i=>\"1\",f=>\"1.5\",f4=>\"0.1\",n=>\"2.50\",b=>\"1\",z=>null,s=>\"x\",big=>\"9223372036854775807\",\
                     e=>\"1.0E+100\",nan=>\"NAN\",inf=>\"-INF\",one=>\"1\",third=>\"0.3\",g=>\"1.2345678901235E+17\",o=>\"5\",\
                     arr=>\"{1,2}\",ch=>\"x  \",small=>\"7\",bytes=>\"\\0\u{fffd}\"]]",
                ),
                "sqlite" => (
                    "SELECT 9223372036854775807 AS a, 1e100 AS b, 1.0 AS e, 0.1 AS f, X'00FF' AS h, 0.30000000000000004 AS g, \
                     1e15 AS i, 123456789012345.6 AS k, NULL AS z",
                    "[[a=>\"9223372036854775807\",b=>\"1.0E+100\",e=>\"1\",f=>\"0.1\",h=>\"\\0\u{fffd}\",g=>\"0.3\",\
                     i=>\"1.0E+15\",k=>\"1.2345678901235E+14\",z=>null]]",
                ),
                _ => (
                    "SELECT 9223372036854775807 AS a, 1e100 AS b, 1.0 AS e, CAST(0.1 AS DOUBLE) AS f, X'00FF' AS h, \
                     CAST(1.5 AS FLOAT) AS i, NULL AS z",
                    "[[a=>\"9223372036854775807\",b=>\"1e100\",e=>\"1.0\",f=>\"0.1\",h=>\"\\0\u{fffd}\",i=>\"1.5\",z=>null]]",
                ),
            };
            let expected: String = expected.split_whitespace().collect::<Vec<_>>().join("");
            let got: String = rows(&mut pdo, sql).await.split_whitespace().collect::<Vec<_>>().join("");
            assert_eq!(got, expected, "{name}");
        }
    }

    #[utopia_async::test]
    async fn quotes_as_the_driver_does() {
        for (name, pdo) in all().await {
            let quoted = pdo.quote(b"it's \"x\" \\ \n \x1a").unwrap().map(|q| q.as_bytes().to_vec());
            let nul = pdo.quote(b"a\0b");
            match name {
                "pgsql" => {
                    assert_eq!(quoted.unwrap(), b"'it''s \"x\" \\ \n \x1a'".to_vec());
                    assert_eq!(nul.unwrap().unwrap().as_bytes(), b"'a'");
                    assert_eq!(pdo.quote(b"\xff").unwrap(), None);
                }
                "sqlite" => {
                    assert_eq!(quoted.unwrap(), b"'it''s \"x\" \\ \n \x1a'".to_vec());
                    assert_eq!(nul.unwrap_err().message(), "SQLite PDO::quote does not support null bytes");
                }
                _ => {
                    assert_eq!(quoted.unwrap(), b"'it\\'s \\\"x\\\" \\\\ \\n \\Z'".to_vec());
                    assert_eq!(nul.unwrap().unwrap().as_bytes(), b"'a\\0b'");
                }
            }
        }
    }

    #[utopia_async::test]
    async fn reports_errors_as_php_does() {
        for (name, mut pdo) in all().await {
            let error = pdo.query("SELEC 1").await.unwrap_err();
            let (message, state, code) = match name {
                "pgsql" => (
                    "SQLSTATE[42601]: Syntax error: 7 ERROR:  syntax error at or near \"SELEC\"\nLINE 1: SELEC 1\n        ^",
                    "42601",
                    7,
                ),
                "sqlite" => ("SQLSTATE[HY000]: General error: 1 near \"SELEC\": syntax error", "HY000", 1),
                "mariadb" => (
                    "SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the \
                     manual that corresponds to your MariaDB server version for the right syntax to use near 'SELEC 1' at line 1",
                    "42000",
                    1064,
                ),
                _ => (
                    "SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the \
                     manual that corresponds to your MySQL server version for the right syntax to use near 'SELEC 1' at line 1",
                    "42000",
                    1064,
                ),
            };
            assert_eq!(error.message(), message, "{name}");
            assert_eq!(error.kind(), Kind::Pdo);
            assert_eq!(error.state(), Some(state), "{name}");
            assert_eq!(error.driver_code(), Some(code), "{name}");
            assert_eq!(error.code(), Error::string_code(state.as_bytes()), "{name}");

            let mut statement = pdo.prepare("SELECT * FROM nosuch WHERE a = ?");
            let error = match &mut statement {
                Ok(statement) => {
                    statement.bind_value(1, "x", ParamType::Str).unwrap();
                    statement.execute(&mut pdo).await.unwrap_err()
                }
                Err(e) => e.clone(),
            };
            let message = match name {
                "pgsql" => {
                    "SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation \"nosuch\" does not exist\nLINE 1: SELECT * FROM nosuch WHERE a = 'x'\n                      ^"
                }
                "sqlite" => "SQLSTATE[HY000]: General error: 1 no such table: nosuch",
                _ => "SQLSTATE[3D000]: Invalid catalog name: 1046 No database selected",
            };
            assert_eq!(error.message(), message, "{name}");

            for (result, message) in [
                (pdo.commit().await, "There is no active transaction"),
                (pdo.roll_back().await, "There is no active transaction"),
            ] {
                let error = result.unwrap_err();
                assert_eq!((error.kind(), error.message(), error.code(), error.state()), (Kind::Pdo, message, 0, None));
            }
            pdo.begin_transaction().await.unwrap();
            let error = pdo.begin_transaction().await.unwrap_err();
            assert_eq!(error.message(), "There is already an active transaction");
            pdo.roll_back().await.unwrap();

            if name != "sqlite" {
                // A raw BEGIN counts as a transaction, as the engine reports it.
                pdo.exec("BEGIN").await.unwrap();
                assert!(pdo.in_transaction(), "{name}");
                assert!(pdo.commit().await.unwrap());
                assert!(!pdo.in_transaction(), "{name}");
            } else {
                pdo.exec("BEGIN IMMEDIATE").await.unwrap();
                assert!(pdo.in_transaction());
                assert!(pdo.commit().await.unwrap());
                assert!(!pdo.in_transaction());
            }

            let mut statement = pdo.query("SELECT 1 AS a").await.unwrap();
            if name.starts_with('m') {
                assert!(!statement.next_rowset().unwrap());
            } else {
                let error = statement.next_rowset().unwrap_err();
                assert_eq!(
                    error.message(),
                    "SQLSTATE[IM001]: Driver does not support this function: driver does not support multiple rowsets"
                );
                assert_eq!(error.state(), Some("IM001"));
            }
        }
    }

    #[utopia_async::test]
    async fn writes_rows_counts_and_ids() {
        for (name, mut pdo) in all().await {
            let my = name.starts_with('m');
            if my {
                pdo.exec("CREATE DATABASE IF NOT EXISTS rustpdo").await.unwrap();
                pdo.exec("USE rustpdo").await.unwrap();
            }
            let table = format!("t_{}", std::process::id());
            pdo.exec(&format!("DROP TABLE IF EXISTS {table}")).await.unwrap();
            pdo.exec(&match name {
                "pgsql" => format!("CREATE TABLE {table} (id SERIAL PRIMARY KEY, v TEXT)"),
                "sqlite" => format!("CREATE TABLE {table} (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)"),
                _ => format!("CREATE TABLE {table} (id INT AUTO_INCREMENT PRIMARY KEY, v TEXT)"),
            })
            .await
            .unwrap();
            let mut statement = pdo.prepare(&format!("INSERT INTO {table} (v) VALUES (?), (?)")).unwrap();
            statement.bind_value(1, "a", ParamType::Str).unwrap();
            statement.bind_value(2, "b", ParamType::Str).unwrap();
            assert!(statement.execute(&mut pdo).await.unwrap());
            assert_eq!(statement.row_count(), 2, "{name}");
            let id = pdo.last_insert_id().await.unwrap();
            assert_eq!(id.as_bytes(), if my { b"1" } else { b"2" }, "{name}");
            assert_eq!(pdo.exec(&format!("UPDATE {table} SET v = 'z'")).await.unwrap(), 2, "{name}");

            let mut statement = pdo.query(&format!("SELECT * FROM {table} WHERE 1 = 0")).await.unwrap();
            assert_eq!(statement.row_count(), if name == "sqlite" { 2 } else { 0 }, "{name}");
            assert!(statement.fetch_all_assoc().is_empty());

            let mut statement = pdo.query(&format!("SELECT id, v FROM {table} ORDER BY id")).await.unwrap();
            assert_eq!(dump_array(&statement.fetch_all_key_pair().unwrap()), "[1=>\"z\",2=>\"z\"]", "{name}");
            let mut statement = pdo.query(&format!("SELECT id, v FROM {table} ORDER BY id")).await.unwrap();
            assert_eq!(statement.fetch_all_column(1).unwrap(), vec![s("z"), s("z")], "{name}");

            let error = pdo.exec(&format!("INSERT INTO {table} (id, v) VALUES (1, 'x')")).await.unwrap_err();
            let message = match name {
                "pgsql" => format!(
                    "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"{table}_pkey\"\nDETAIL:  Key (id)=(1) already exists."
                ),
                "sqlite" => {
                    format!("SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: {table}.id")
                }
                "mariadb" => {
                    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1' for key 'PRIMARY'"
                        .to_owned()
                }
                _ => format!(
                    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1' for key '{table}.PRIMARY'"
                ),
            };
            assert_eq!(error.message(), message, "{name}");

            if my {
                pdo.exec(&format!("INSERT INTO {table} (v) VALUES ('a')")).await.unwrap();
                assert_eq!(pdo.last_insert_id().await.unwrap().as_bytes(), b"3");
                pdo.query("SELECT 1").await.unwrap();
                assert_eq!(pdo.last_insert_id().await.unwrap().as_bytes(), b"0", "a SELECT generates no id");
            }
            if name == "pgsql" {
                let mut fresh = servers::connect(servers::POSTGRES).await.unwrap();
                let error = fresh.last_insert_id().await.unwrap_err();
                assert_eq!(
                    error.message(),
                    "SQLSTATE[55000]: Object not in prerequisite state: 7 ERROR:  lastval is not yet defined in this session"
                );
            }
            if name == "sqlite" {
                // The changes of the last write: what rowCount() gives a
                // statement without rows.
                assert_eq!(pdo.exec(&format!("UPDATE {table} SET v = 'y' WHERE id = 1")).await.unwrap(), 1);
            }
            pdo.exec(&format!("DROP TABLE {table}")).await.unwrap();
        }
    }

    #[utopia_async::test]
    async fn postgres_specifics() {
        let Some(mut pdo) = servers::connect(servers::POSTGRES).await else { return };
        // `??` is an escaped `?` when values are bound, and left as it is
        // otherwise.
        let got = run(&mut pdo, "SELECT '{\"a\":1}'::jsonb ?? 'a' AS a, ? AS b", &[("1", s("x"), ParamType::Str)])
            .await
            .unwrap();
        assert_eq!(got, "[[a=>\"1\",b=>\"x\"]]");
        let error = pdo.query("SELECT '{\"a\":1}'::jsonb ?? 'a' AS a").await.unwrap_err();
        assert_eq!(
            error.message(),
            "SQLSTATE[42883]: Undefined function: 7 ERROR:  operator does not exist: jsonb ?? unknown\nLINE 1: SELECT '{\"a\":1}'::jsonb ?? 'a' AS a\n                                ^\nHINT:  No operator matches the given name and argument types. You might need to add explicit type casts."
        );
        let got = run(&mut pdo, "SELECT :a::int AS a", &[(":a", s("5"), ParamType::Str)]).await.unwrap();
        assert_eq!(got, "[[a=>\"5\"]]");
        // A value that is not UTF-8 makes execute() return false.
        let mut statement = pdo.prepare("SELECT ? AS a").unwrap();
        statement.bind_value(1, Value::Str(Str::from(&b"\xff\xfe"[..])), ParamType::Str).unwrap();
        assert!(!statement.execute(&mut pdo).await.unwrap());
        assert!(statement.fetch().unwrap().is_none());
        let error = pdo.query("SELECT '\u{fffd}' AS a, E'\\xff' AS b").await.unwrap_err();
        assert_eq!(
            error.message(),
            "SQLSTATE[22021]: Character not in repertoire: 7 ERROR:  invalid byte sequence for encoding \"UTF8\": 0xff"
        );
        // A failed transaction commits as a roll back.
        pdo.begin_transaction().await.unwrap();
        assert!(pdo.exec("SELEC").await.is_err());
        assert!(pdo.in_transaction());
        assert!(pdo.commit().await.unwrap());
        assert!(!pdo.in_transaction());
        assert_eq!(pdo.hostname().unwrap(), "127.0.0.1");
        assert_eq!(pdo.driver(), Driver::Postgres);
        assert_eq!(pdo.driver().name(), "pgsql");
    }

    #[utopia_async::test]
    async fn mysql_rowsets() {
        for dsn in [servers::MARIADB, servers::MYSQL] {
            let Some(mut pdo) = servers::connect(dsn).await else { continue };
            let engine = if dsn == servers::MARIADB { "MariaDB" } else { "MySQL" };
            pdo.exec("CREATE DATABASE IF NOT EXISTS rustpdo").await.unwrap();
            pdo.exec("USE rustpdo").await.unwrap();
            let table = format!("r_{}", std::process::id());
            pdo.exec(&format!("DROP TABLE IF EXISTS {table}")).await.unwrap();
            pdo.exec(&format!("CREATE TABLE {table} (id INT AUTO_INCREMENT PRIMARY KEY, v TEXT)")).await.unwrap();

            let mut statement = pdo.prepare("SELECT 1 AS a; SELECT 2 AS b").unwrap();
            statement.execute(&mut pdo).await.unwrap();
            assert_eq!(dump_rows(&statement.fetch_all_assoc()), "[[a=>\"1\"]]");
            let pending = pdo.query("SELECT 3 AS c").await.unwrap_err();
            assert_eq!(
                pending.message(),
                "SQLSTATE[HY000]: General error: 2014 Cannot execute queries while there are pending result sets. Consider unsetting the previous PDOStatement or calling PDOStatement::closeCursor()"
            );
            assert!(statement.next_rowset().unwrap());
            assert_eq!(rows(&mut pdo, "SELECT 3 AS c").await, "[[c=>\"3\"]]", "the last result does not block");
            assert_eq!(dump_rows(&statement.fetch_all_assoc()), "[[b=>\"2\"]]");
            assert!(!statement.next_rowset().unwrap());

            let mut statement = pdo.prepare("SELECT 1 AS a; SELEC 2; SELECT 3").unwrap();
            assert!(statement.execute(&mut pdo).await.unwrap());
            assert_eq!(dump_rows(&statement.fetch_all_assoc()), "[[a=>\"1\"]]");
            let error = statement.next_rowset().unwrap_err();
            assert_eq!(
                error.message(),
                format!(
                    "SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your {engine} server version for the right syntax to use near 'SELEC 2; SELECT 3' at line 1"
                )
            );
            assert_eq!(rows(&mut pdo, "SELECT 1 AS x").await, "[[x=>\"1\"]]");

            let mut statement = pdo
                .prepare(&format!("INSERT INTO {table} (v) VALUES ('x'); INSERT INTO {table} (v) VALUES ('y')"))
                .unwrap();
            statement.execute(&mut pdo).await.unwrap();
            assert_eq!(pdo.last_insert_id().await.unwrap().as_bytes(), b"1");
            assert_eq!(statement.row_count(), 1);
            assert!(statement.next_rowset().unwrap());
            assert_eq!(pdo.last_insert_id().await.unwrap().as_bytes(), b"2");
            assert!(!statement.next_rowset().unwrap());

            let mut statement = pdo
                .prepare(&format!(
                    "INSERT INTO {table} (v) VALUES ('m'); INSERT INTO {table} (id, v) VALUES (1, 'dup')"
                ))
                .unwrap();
            assert!(statement.execute(&mut pdo).await.unwrap());
            assert_eq!(statement.row_count(), 1);
            assert!(statement.next_rowset().unwrap_err().message().contains("1062 Duplicate entry '1'"));

            let mut statement = pdo.prepare("DO 1; DO 2").unwrap();
            statement.execute(&mut pdo).await.unwrap();
            assert!(pdo.query("SELECT 3 AS c").await.unwrap_err().message().contains("pending result sets"));
            drop(statement);
            assert_eq!(
                rows(&mut pdo, "SELECT 4 AS d").await,
                "[[d=>\"4\"]]",
                "dropping the statement frees its results"
            );

            assert_eq!(
                pdo.exec(&format!("INSERT INTO {table} (v) VALUES ('x'); UPDATE {table} SET v = 'q'")).await.unwrap(),
                1
            );
            assert_eq!(pdo.last_insert_id().await.unwrap().as_bytes(), b"0");
            assert!(
                pdo.exec(&format!("INSERT INTO {table} (v) VALUES ('x'); INSERT INTO {table} (id) VALUES (1)"))
                    .await
                    .unwrap_err()
                    .message()
                    .contains("1062")
            );
            pdo.exec(&format!("DROP TABLE {table}")).await.unwrap();

            // An exec() whose first query returns rows leaves them unread.
            assert_eq!(pdo.exec("SELECT 1").await.unwrap(), 0);
            let unread = "SQLSTATE[HY000]: General error: 2014 Cannot execute queries while other unbuffered queries are active.  Consider using PDOStatement::fetchAll().  Alternatively, if your code is only ever going to run against mysql, you may enable query buffering by setting the Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY attribute.";
            assert_eq!(pdo.exec("DO 1").await.unwrap_err().message(), unread);
            assert_eq!(pdo.begin_transaction().await.unwrap_err().message(), unread);
            assert!(!pdo.in_transaction());
            pdo.reconnect().await.unwrap();
            assert_eq!(pdo.exec("DO 1").await.unwrap(), 0);
            assert_eq!(pdo.hostname().unwrap(), "127.0.0.1");
        }
    }

    #[utopia_async::test]
    async fn connect_errors() {
        let error = Pdo::connect("foo:bar", None, None).await.unwrap_err();
        assert_eq!((error.kind(), error.message(), error.code()), (Kind::Pdo, "could not find driver", 0));
        let error = Pdo::connect("nothing", None, None).await.unwrap_err();
        assert_eq!(error.message(), "PDO::__construct(): Argument #1 ($dsn) must be a valid data source name");
        #[cfg(feature = "sqlite")]
        {
            let error = Pdo::connect("sqlite:/nonexistent/dir/x.db", None, None).await.unwrap_err();
            assert_eq!(error.message(), "SQLSTATE[HY000] [14] unable to open database file");
            assert_eq!((error.code(), error.state(), error.driver_code()), (14, Some("HY000"), Some(14)));
            let mut pdo = memory().await;
            assert_eq!(pdo.hostname().unwrap_err().message(), "No host found in DSN");
            assert_eq!(pdo.driver().name(), "sqlite");
        }
        #[cfg(feature = "postgres")]
        if servers::connect(servers::POSTGRES).await.is_some() {
            let error = Pdo::connect(servers::POSTGRES, Some("root"), Some("nope")).await.unwrap_err();
            assert_eq!(
                error.message(),
                "SQLSTATE[08006] [7] connection to server at \"127.0.0.1\", port 16432 failed: FATAL:  password authentication failed for user \"root\""
            );
            assert_eq!((error.code(), error.state(), error.driver_code()), (7, Some("08006"), Some(7)));
            assert!(utopia_database::connection::is_lost(&error));
            let error = Pdo::connect("pgsql:host=127.0.0.1;port=1;dbname=root", Some("root"), Some("password"))
                .await
                .unwrap_err();
            assert_eq!(
                error.message(),
                "SQLSTATE[08006] [7] connection to server at \"127.0.0.1\", port 1 failed: Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?"
            );
            let error = Pdo::connect("pgsql:host=nosuchhost.invalid;port=5432", Some("root"), None).await.unwrap_err();
            assert_eq!(
                error.message(),
                "SQLSTATE[08006] [7] could not translate host name \"nosuchhost.invalid\" to address: Name does not resolve"
            );
            let error = Pdo::connect("pgsql:host=127.0.0.1;port=16432;dbname=nosuchdb", Some("root"), Some("password"))
                .await
                .unwrap_err();
            assert_eq!(
                error.message(),
                "SQLSTATE[08006] [7] connection to server at \"127.0.0.1\", port 16432 failed: FATAL:  database \"nosuchdb\" does not exist"
            );
            let mut pdo =
                Pdo::connect("pgsql:host=127.0.0.1 port=16432 dbname=root user=root password=password", None, None)
                    .await
                    .unwrap();
            assert_eq!(rows(&mut pdo, "SELECT current_user AS a").await, "[[a=>\"root\"]]");
        }
        #[cfg(feature = "mariadb")]
        if servers::connect(servers::MARIADB).await.is_some() {
            let error = Pdo::connect(servers::MARIADB, Some("root"), Some("nope")).await.unwrap_err();
            assert!(
                error.message().starts_with("SQLSTATE[HY000] [1045] Access denied for user 'root'@'"),
                "{}",
                error.message()
            );
            assert_eq!((error.code(), error.state(), error.driver_code()), (1045, Some("HY000"), Some(1045)));
            let error = Pdo::connect("mysql:host=127.0.0.1;port=1", Some("root"), Some("password")).await.unwrap_err();
            assert_eq!(error.message(), "SQLSTATE[HY000] [2002] Connection refused");
            assert_eq!(error.code(), 2002);
            let error = Pdo::connect("mysql:host=nosuchhost.invalid;port=3306", Some("root"), None).await.unwrap_err();
            assert_eq!(
                error.message(),
                "SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for nosuchhost.invalid failed: Name does not resolve"
            );
            let error = Pdo::connect("mysql:host=127.0.0.1;port=16306;dbname=nosuchdb", Some("root"), Some("password"))
                .await
                .unwrap_err();
            assert_eq!(error.message(), "SQLSTATE[HY000] [1049] Unknown database 'nosuchdb'");
            let mut pdo = Pdo::connect("mysql:host=127.0.0.1;port=16306;dbname=mysql", Some("root"), Some("password"))
                .await
                .unwrap();
            assert_eq!(rows(&mut pdo, "SELECT DATABASE() AS a").await, "[[a=>\"mysql\"]]");
        }
    }
}

/// Lost connections on real servers: the server ends the session.
mod lost {
    use std::time::Duration;

    use super::*;

    const FATAL: &str = "SQLSTATE[HY000]: General error: 7 FATAL:  terminating connection due to administrator command\nserver closed the connection unexpectedly\n\tThis probably means the server terminated abnormally\n\tbefore or while processing the request.";

    async fn terminate(admin: &mut Pdo, pdo: &mut Pdo) {
        let pid = column(pdo, "SELECT pg_backend_pid()").await.unwrap();
        let pid = String::from_utf8_lossy(match &pid {
            Value::Str(s) => s.as_bytes(),
            _ => b"0",
        })
        .into_owned();
        admin.query(&format!("SELECT pg_terminate_backend({pid})")).await.unwrap();
        utopia_async::sleep(Duration::from_millis(200)).await;
    }

    async fn kill(admin: &mut Pdo, pdo: &mut Pdo) {
        let id = column(pdo, "SELECT CONNECTION_ID()").await.unwrap();
        let id = String::from_utf8_lossy(match &id {
            Value::Str(s) => s.as_bytes(),
            _ => b"0",
        })
        .into_owned();
        admin.exec(&format!("KILL {id}")).await.unwrap();
        utopia_async::sleep(Duration::from_millis(200)).await;
    }

    /// PostgreSQL: a failed connection reports a transaction, so the
    /// wrapper does not retry: it reconnects and refuses statements until
    /// the caller rolls back, as PHP does.
    #[utopia_async::test]
    async fn postgres() {
        let Some(mut admin) = servers::connect(servers::POSTGRES).await else { return };
        let mut pdo = servers::connect(servers::POSTGRES).await.unwrap();

        terminate(&mut admin, &mut pdo).await;
        let error = pdo.query("SELECT 1 AS x").await.unwrap_err();
        assert_eq!(error.message(), FATAL);
        assert_eq!((error.state(), error.driver_code()), (Some("HY000"), Some(7)));
        assert!(utopia_database::connection::is_lost(&error));
        assert!(pdo.in_transaction());
        let refusal = pdo.query("SELECT 1 AS x").await.unwrap_err();
        assert_eq!(refusal.message(), LOST_TRANSACTION);
        assert_eq!(refusal.previous().map(Error::message), Some(FATAL));
        assert!(pdo.roll_back().await.unwrap());
        assert_eq!(rows(&mut pdo, "SELECT 1 AS x").await, "[[x=>\"1\"]]");

        terminate(&mut admin, &mut pdo).await;
        let mut statement = pdo.prepare("SELECT ? AS x").unwrap();
        statement.bind_value(1, "y", ParamType::Str).unwrap();
        assert_eq!(statement.execute(&mut pdo).await.unwrap_err().message(), FATAL);
        assert!(pdo.in_transaction());
        let error = pdo.exec("SELECT 1").await.unwrap_err();
        assert_eq!(error.message(), "SQLSTATE[HY000]: General error: 7 no connection to the server");
        assert!(pdo.roll_back().await.unwrap());
        assert_eq!(pdo.exec("SELECT 1").await.unwrap(), 0);
    }

    /// MySQL and MariaDB: outside a transaction the statement runs again on
    /// a new connection; inside one the transaction is lost.
    #[utopia_async::test]
    async fn mysql() {
        for dsn in [servers::MARIADB, servers::MYSQL] {
            let Some(mut admin) = servers::connect(dsn).await else { continue };
            let mut pdo = servers::connect(dsn).await.unwrap();

            kill(&mut admin, &mut pdo).await;
            let mut statement = pdo.prepare("SELECT ? AS x").unwrap();
            statement.bind_value(1, "y", ParamType::Str).unwrap();
            assert!(statement.execute(&mut pdo).await.unwrap(), "{dsn}");
            assert_eq!(dump_rows(&statement.fetch_all_assoc()), "[[x=>\"y\"]]");

            kill(&mut admin, &mut pdo).await;
            assert_eq!(rows(&mut pdo, "SELECT 1 AS x").await, "[[x=>\"1\"]]", "{dsn}: query runs again");

            pdo.begin_transaction().await.unwrap();
            kill(&mut admin, &mut pdo).await;
            let error = pdo.exec("DO 1").await.unwrap_err();
            assert_eq!(error.message(), "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away", "{dsn}");
            assert_eq!((error.state(), error.driver_code()), (Some("HY000"), Some(2006)));
            assert_refused(pdo.exec("DO 1").await);
            assert!(pdo.in_transaction());
            assert!(pdo.roll_back().await.unwrap());
            assert_eq!(pdo.exec("DO 1").await.unwrap(), 0);
        }
    }
}

/// The handle and its futures cross threads, as an adapter shares them
/// behind a `utopia_async::sync::Mutex`.
#[test]
fn handles_and_futures_are_send() {
    fn send<T: Send>(_: &T) {}
    async fn calls(mut pdo: Pdo) {
        let mut statement = pdo.prepare("SELECT 1").unwrap();
        let _ = statement.execute(&mut pdo).await;
        let _ = pdo.query("SELECT 1").await;
        let _ = pdo.exec("SELECT 1").await;
        let _ = pdo.begin_transaction().await;
        let _ = pdo.last_insert_id().await;
        let _ = pdo.configure("a", "SELECT 1").await;
        let _ = pdo.reconnect().await;
    }
    let future = async {
        let pdo = Pdo::connect("sqlite::memory:", None, None).await.unwrap();
        calls(pdo).await;
    };
    send(&future);
}
