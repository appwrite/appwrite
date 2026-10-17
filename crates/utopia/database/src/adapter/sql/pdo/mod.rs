//! Statements as PHP's PDO runs them, over one connection.
//!
//! The SQL adapters of PHP run every statement through `\PDO` with the
//! attributes `ATTR_EMULATE_PREPARES`, `ATTR_STRINGIFY_FETCHES`,
//! `ATTR_ERRMODE => ERRMODE_EXCEPTION` and `ATTR_DEFAULT_FETCH_MODE =>
//! FETCH_ASSOC`, wrapped in `Utopia\Database\PDO` (lost-connection recovery,
//! session replay) and `Utopia\Database\PDOStatement` (re-prepare and retry
//! after a lost connection). [`Pdo`] and [`Statement`] are that pair over one
//! connection of PostgreSQL (`pgsql`, feature `postgres`), MySQL/MariaDB
//! (`mysql`, feature `mariadb`) or SQLite (`sqlite`, feature `sqlite`), with
//! PHP's semantics:
//!
//! - **Placeholders.** PostgreSQL and MySQL statements are rendered client
//!   side as PDO's emulated prepares render them: positional `?` and named
//!   `:name` placeholders are found by the driver's own scanner (quotes,
//!   identifiers, comments, dollar quotes, `::` casts and `??` are not
//!   placeholders), and each bound value becomes a literal as the driver
//!   quotes it ([`ParamType`]). SQLite prepares natively and binds values
//!   when the statement executes, as `pdo_sqlite` does.
//! - **Values.** Every fetched value is a string or `null`, as
//!   `ATTR_STRINGIFY_FETCHES` makes them: PostgreSQL booleans are `"1"`/`"0"`,
//!   floats of PostgreSQL and SQLite are printed as PHP prints them when they
//!   are fetched, at the `precision` ini (14, or the round-trip digits while
//!   [`Statement::fetch_exactly`] runs), integers and text are the bytes the
//!   engine sent.
//! - **Errors** are [`Kind::Pdo`](crate::Kind::Pdo) errors carrying PHP's
//!   exact `PDOException` message, the SQLSTATE ([`crate::Error::state`],
//!   `errorInfo[0]`), the driver's code ([`crate::Error::driver_code`],
//!   `errorInfo[1]`) and `getCode()` ([`crate::Error::code`]), so an
//!   adapter's `processException` maps them and [`crate::connection::is_lost`]
//!   recognises a lost connection.
//! - **Transactions** follow PDO: [`Pdo::in_transaction`] asks the engine
//!   (so a raw `BEGIN` counts), [`Pdo::commit`] without one fails with
//!   `There is no active transaction`.
//! - **Recovery** follows `Utopia\Database\PDO`: a call that loses the
//!   connection outside a transaction reconnects (replaying the
//!   [`Pdo::configure`]d session) and runs again; inside one it reconnects
//!   and refuses every statement until the caller rolls back
//!   (`The transaction was lost with the connection: roll it back before
//!   running another statement`). [`Statement::execute`] re-runs a statement
//!   after a lost connection outside a transaction.
//!
//! Results are read in full when a statement executes (PDO's buffered
//! queries), so a [`Statement`] holds its rows without borrowing the
//! [`Pdo`]: an adapter keeping the handle behind a lock locks it for
//! [`Statement::execute`] only, and fetches afterwards.
//!
//! # Examples
//!
//! PostgreSQL:
//!
//! ```no_run
//! # async fn demo() -> utopia_database::Result<()> {
//! use utopia_database::adapter::sql::pdo::{ParamType, Pdo};
//!
//! let mut pdo = Pdo::connect("pgsql:host=localhost;port=5432;dbname=app", Some("root"), Some("secret")).await?;
//! let mut statement = pdo.prepare("SELECT ? AS id, ? AS active")?;
//! statement.bind_value(1, "abc", ParamType::Str)?;
//! statement.bind_value(2, true, ParamType::Bool)?;
//! statement.execute(&mut pdo).await?;
//! let rows = statement.fetch_all_assoc(); // [["id" => "abc", "active" => "t"]]
//! # let _ = rows;
//! # Ok(()) }
//! ```
//!
//! MySQL or MariaDB, with a statement of several queries:
//!
//! ```no_run
//! # async fn demo() -> utopia_database::Result<()> {
//! use utopia_database::adapter::sql::pdo::{ParamType, Pdo};
//!
//! let mut pdo = Pdo::connect("mysql:host=localhost;port=3306;charset=utf8mb4", Some("root"), Some("secret")).await?;
//! pdo.begin_transaction().await?;
//! let mut statement = pdo.prepare("INSERT INTO app.t (v) VALUES (:v); UPDATE app.t SET v = :v")?;
//! statement.bind_value(":v", "x", ParamType::Str)?;
//! statement.execute(&mut pdo).await?;
//! while statement.next_rowset()? {} // a failing later query surfaces here
//! pdo.commit().await?;
//! # Ok(()) }
//! ```
//!
//! SQLite, in memory, with PHP's `REGEXP`:
//!
//! ```no_run
//! # async fn demo() -> utopia_database::Result<()> {
//! use utopia_database::adapter::sql::pdo::{ParamType, Pdo};
//!
//! let mut pdo = Pdo::connect("sqlite::memory:", None, None).await?;
//! pdo.register_regexp();
//! let mut statement = pdo.prepare("SELECT :v REGEXP '^a' AS m")?;
//! statement.bind_value(":v", "abc", ParamType::Str)?;
//! statement.execute(&mut pdo).await?;
//! assert_eq!(statement.fetch_column(0)?, Some("1".into()));
//! # Ok(()) }
//! ```
//!
//! PHP: `Utopia\Database\PDO`, `Utopia\Database\PDOStatement`, `\PDO` and
//! `\PDOStatement` with the `pdo_pgsql`, `pdo_mysql` (mysqlnd) and
//! `pdo_sqlite` drivers.

mod dsn;
#[cfg(any(feature = "postgres", feature = "mariadb"))]
mod emulate;
#[cfg(feature = "mariadb")]
mod mysql;
#[cfg(feature = "postgres")]
mod postgres;
#[cfg(feature = "sqlite")]
mod sqlite;
mod sqlstate;

use std::sync::Arc;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::Duration;

use crate::error::{Error, Kind, Result};
use crate::value::{Array, ArrayKey, Str, Value};

/// The database engine a [`Pdo`] talks to.
///
/// PHP: `PDO::ATTR_DRIVER_NAME`.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
pub enum Driver {
    /// PostgreSQL (`pgsql`).
    Postgres,
    /// MySQL or MariaDB (`mysql`).
    Mysql,
    /// SQLite (`sqlite`).
    Sqlite,
}

impl Driver {
    /// The driver's name as PDO reports it (`getAttribute(PDO::ATTR_DRIVER_NAME)`):
    /// `pgsql`, `mysql` or `sqlite`.
    pub fn name(self) -> &'static str {
        match self {
            Driver::Postgres => "pgsql",
            Driver::Mysql => "mysql",
            Driver::Sqlite => "sqlite",
        }
    }
}

/// The type a value is bound with (`PDO::PARAM_*`).
///
/// With emulated prepares (PostgreSQL, MySQL) `null` is `NULL` whatever the
/// type; otherwise [`ParamType::Null`] is `NULL`, [`ParamType::Int`] the
/// value as an integer literal (`zval_get_long`), [`ParamType::Bool`] `'t'`
/// or `'f'` on PostgreSQL and `1` or `0` on MySQL, and [`ParamType::Str`]
/// the value's PHP string, quoted as the driver quotes strings. SQLite binds
/// `Int` and `Bool` as integers, `Str` as text.
///
/// PHP: `PDO::PARAM_NULL`, `PARAM_INT`, `PARAM_STR`, `PARAM_BOOL`.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum ParamType {
    /// `PDO::PARAM_NULL`.
    Null,
    /// `PDO::PARAM_INT`.
    Int,
    /// `PDO::PARAM_STR` (the default).
    #[default]
    Str,
    /// `PDO::PARAM_BOOL`.
    Bool,
}

/// What a value is bound to: a 1-based position (`?`) or a name (`:name`,
/// the leading `:` optional).
///
/// PHP: `PDOStatement::bindValue(int|string $param, ...)`.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Param {
    /// The `n`th `?` placeholder, from 1.
    Position(usize),
    /// A named placeholder, stored with its leading `:`.
    Name(String),
}

impl From<usize> for Param {
    fn from(n: usize) -> Self {
        Param::Position(n)
    }
}

impl From<&str> for Param {
    fn from(name: &str) -> Self {
        Param::Name(if name.starts_with(':') { name.to_owned() } else { format!(":{name}") })
    }
}

impl From<String> for Param {
    fn from(name: String) -> Self {
        Param::from(name.as_str())
    }
}

impl From<&String> for Param {
    fn from(name: &String) -> Self {
        Param::from(name.as_str())
    }
}

/// How rows are returned (`PDO::FETCH_*`).
///
/// PHP: `PDO::FETCH_ASSOC`, `FETCH_NUM`, `FETCH_BOTH`, `FETCH_COLUMN`,
/// `FETCH_KEY_PAIR`.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum FetchMode {
    /// Column name => value; a later column with the same name overwrites
    /// the value of the first, in the first one's position.
    Assoc,
    /// Column index => value.
    Num,
    /// Both, name first then index, column by column.
    Both,
    /// The value of one column (0-based).
    Column(usize),
    /// First column => second column (`fetchAll` only, two columns exactly).
    KeyPair,
}

/// How column names are cased in fetched rows.
///
/// PHP: `PDO::ATTR_CASE` (`CASE_NATURAL`, `CASE_UPPER`, `CASE_LOWER`).
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum Case {
    /// As the engine names them.
    #[default]
    Natural,
    /// Upper case (ASCII).
    Upper,
    /// Lower case (ASCII).
    Lower,
}

/// An attribute set on a [`Pdo`] after connecting, kept across reconnects.
///
/// PHP: `Utopia\Database\PDO::setAttribute()` with `PDO::ATTR_CASE` or
/// `PDO::ATTR_DEFAULT_FETCH_MODE`.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Attribute {
    /// `PDO::ATTR_CASE`.
    Case(Case),
    /// `PDO::ATTR_DEFAULT_FETCH_MODE`: the mode of [`Statement::fetch`] and
    /// [`Statement::fetch_all`] when the statement sets none.
    DefaultFetchMode(FetchMode),
}

/// Connection options: what the PHP adapters pass as PDO attributes.
///
/// PHP: the `$config` of `new PDO($dsn, $username, $password, $config)`.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Options {
    /// `PDO::ATTR_TIMEOUT`: the connect timeout (PostgreSQL, MySQL) or the
    /// busy timeout (SQLite). 3 seconds by default, as the adapters set it.
    pub timeout: Duration,
    /// `PDO::ATTR_DEFAULT_FETCH_MODE`: [`FetchMode::Assoc`] by default, as
    /// the adapters set it.
    pub fetch_mode: FetchMode,
    /// `PDO::ATTR_STRINGIFY_FETCHES`: every fetched value a string, as the
    /// adapters set it (`true` by default). SQLite without it fetches
    /// integers and floats as numbers, as `pdo_sqlite` does on PHP 8.1+.
    pub stringify_fetches: bool,
    /// Whether the handle has `createFunction()` (a `Pdo\Sqlite`, as the
    /// adapters open it; `true` by default). A plain `PDO` has not, and
    /// the SQLite adapter then registers no `REGEXP` (`Adapter/SQLite.php`
    /// `registerUserFunctions()`, line 297).
    pub user_functions: bool,
}

impl Default for Options {
    fn default() -> Self {
        Options {
            timeout: Duration::from_secs(3),
            fetch_mode: FetchMode::Assoc,
            stringify_fetches: true,
            user_functions: true,
        }
    }
}

/// A `PDOException`: PHP's message, `getCode()` and `errorInfo`.
///
/// Convert it with [`PdoError::into_error`] (or `?`): the result is a
/// [`Kind::Pdo`] error with [`Error::with_string_code`] of the SQLSTATE and
/// [`Error::with_driver_code`] of the driver's code.
///
/// PHP: `PDOException`.
#[derive(Clone, Debug, PartialEq)]
pub struct PdoError {
    /// `errorInfo[0]`: the SQLSTATE (`None` for an exception PDO throws
    /// without one, such as `There is no active transaction`).
    pub state: Option<String>,
    /// `errorInfo[1]`: the driver's error code.
    pub driver_code: Option<i64>,
    /// `errorInfo[2]`: the driver's own message.
    pub info: Option<String>,
    /// `getCode()` when it is an integer (connection errors carry the
    /// driver's code, PDO's own exceptions `0`); `None` when it is the
    /// SQLSTATE.
    pub code: Option<i64>,
    /// `getMessage()`.
    pub message: String,
}

impl PdoError {
    /// An exception PDO or the wrapper throws with only a message: code `0`
    /// and no `errorInfo` (`There is no active transaction`).
    pub fn new(message: impl Into<String>) -> Self {
        PdoError { state: None, driver_code: None, info: None, code: Some(0), message: message.into() }
    }

    /// An error PDO raises itself (`pdo_raise_impl_error`):
    /// `SQLSTATE[HY093]: Invalid parameter number: ...`, `errorInfo`
    /// `[state, 0]`.
    pub fn implementation(state: &str, supplement: &str) -> Self {
        PdoError {
            state: Some(state.to_owned()),
            driver_code: Some(0),
            info: None,
            code: None,
            message: format!("SQLSTATE[{state}]: {}: {supplement}", sqlstate::describe(state)),
        }
    }

    /// An error a driver reports for a statement:
    /// `SQLSTATE[state]: description: code info`.
    pub fn driver(state: &str, code: i64, info: &str) -> Self {
        PdoError {
            state: Some(state.to_owned()),
            driver_code: Some(code),
            info: Some(info.to_owned()),
            code: None,
            message: format!("SQLSTATE[{state}]: {}: {code} {info}", sqlstate::describe(state)),
        }
    }

    /// An error a driver reports when connecting (`pdo_throw_exception`
    /// from the driver's factory): `SQLSTATE[state] [code] info`, with
    /// `getCode()` the driver's code.
    pub fn connect(state: &str, code: i64, info: &str) -> Self {
        PdoError {
            state: Some(state.to_owned()),
            driver_code: Some(code),
            info: Some(info.to_owned()),
            code: Some(code),
            message: format!("SQLSTATE[{state}] [{code}] {info}"),
        }
    }

    /// The error as the crate's error: [`Kind::Pdo`], the message, the
    /// SQLSTATE as its string code, the driver's code, and `getCode()`.
    pub fn into_error(self) -> Error {
        let mut error = Error::new(Kind::Pdo, self.message);
        if let Some(state) = &self.state {
            error = error.with_string_code(state.as_bytes());
        }
        if let Some(code) = self.code {
            error = error.with_code(code);
        }
        if let Some(code) = self.driver_code {
            error = error.with_driver_code(code);
        }
        error
    }
}

impl From<PdoError> for Error {
    fn from(e: PdoError) -> Self {
        e.into_error()
    }
}

/// A failure to inject into a [`Pdo`], to exercise the recovery paths
/// without a server that drops connections.
///
/// PHP: what `Tests\Unit\PDOTest` and `PDOStatementTest` do with mocks of
/// `\PDO` and `\PDOStatement`.
#[derive(Clone, Debug)]
pub enum Fault {
    /// The next statement the handle sends (an `exec`, `query`, statement
    /// execution, `beginTransaction`, `commit` or `rollBack`) fails with this
    /// error without reaching the engine.
    Next(Error),
    /// The server ended the session: until [`Pdo::reconnect`], every
    /// statement fails with `SQLSTATE[HY000]: General error: 2006 MySQL
    /// server has gone away` and the connection reports a transaction.
    EndSession,
}

/// The message of the refusal of a statement after a lost transaction.
///
/// PHP: `Utopia\Database\PDO::guard()`.
pub const LOST_TRANSACTION: &str =
    "The transaction was lost with the connection: roll it back before running another statement";

/// A bound value.
#[derive(Clone, Debug)]
pub(crate) struct Binding {
    pub(crate) param: Param,
    pub(crate) value: Value,
    pub(crate) ty: ParamType,
}

/// One result of a statement: its columns, rows and row count.
#[derive(Clone, Debug, Default)]
pub(crate) struct Rowset {
    /// Column names as the engine sent them.
    pub(crate) columns: Vec<Str>,
    /// Rows: one value per column (a string or `null`, or a float PDO
    /// prints when it is fetched).
    pub(crate) rows: Vec<Vec<Value>>,
    /// `rowCount()`.
    pub(crate) count: i64,
    /// MySQL: the insert id the result reported (`mysql_insert_id()`).
    pub(crate) insert_id: u64,
    /// Whether a float of a row is printed when it is fetched (PDO's
    /// stringify of PostgreSQL's and SQLite's doubles); otherwise a float
    /// stays one (SQLite without `ATTR_STRINGIFY_FETCHES`).
    pub(crate) print_floats: bool,
}

/// What a statement produced: one rowset per query of its text (MySQL),
/// otherwise one; and the error of a later query, met when advancing to it.
#[derive(Clone, Debug, Default)]
pub(crate) struct Results {
    pub(crate) sets: Vec<Rowset>,
    pub(crate) failure: Option<Error>,
}

/// State a connection shares with the statements that ran on it.
#[derive(Debug, Default)]
pub(crate) struct Link {
    /// MySQL: `mysql_insert_id()` of the result the connection last read.
    insert_id: AtomicU64,
}

/// One connection of a driver.
enum Conn {
    #[cfg(feature = "postgres")]
    Postgres(postgres::Conn),
    #[cfg(feature = "mariadb")]
    Mysql(mysql::Conn),
    #[cfg(feature = "sqlite")]
    Sqlite(sqlite::Conn),
    /// A session the server ended ([`Fault::EndSession`]).
    Ended,
}

/// The error of a statement on an ended session.
fn gone() -> Error {
    PdoError::driver("HY000", 2006, "MySQL server has gone away").into_error()
}

impl Conn {
    async fn open(
        target: &dsn::Target,
        username: Option<&str>,
        password: Option<&str>,
        options: &Options,
    ) -> Result<Conn> {
        let _ = (username, password, options);
        match target {
            #[cfg(feature = "postgres")]
            dsn::Target::Postgres(params) => {
                Ok(Conn::Postgres(postgres::Conn::connect(params, username, password, options.timeout).await?))
            }
            #[cfg(feature = "mariadb")]
            dsn::Target::Mysql(params) => {
                Ok(Conn::Mysql(mysql::Conn::connect(params, username, password, options.timeout).await?))
            }
            #[cfg(feature = "sqlite")]
            dsn::Target::Sqlite(path) => {
                Ok(Conn::Sqlite(sqlite::Conn::open(path, options.timeout, options.stringify_fetches)?))
            }
        }
    }

    fn in_transaction(&self) -> bool {
        match self {
            #[cfg(feature = "postgres")]
            Conn::Postgres(c) => c.in_transaction(),
            #[cfg(feature = "mariadb")]
            Conn::Mysql(c) => c.in_transaction(),
            #[cfg(feature = "sqlite")]
            Conn::Sqlite(c) => c.in_transaction(),
            Conn::Ended => true,
        }
    }

    async fn exec(&mut self, sql: &str) -> Result<(i64, u64)> {
        match self {
            #[cfg(feature = "postgres")]
            Conn::Postgres(c) => c.exec(sql).await.map(|n| (n, 0)),
            #[cfg(feature = "mariadb")]
            Conn::Mysql(c) => c.exec(sql).await,
            #[cfg(feature = "sqlite")]
            Conn::Sqlite(c) => c.exec(sql).map(|n| (n, 0)),
            Conn::Ended => Err(gone()),
        }
    }

    async fn execute(&mut self, sql: &str, bindings: &[Binding]) -> Result<Option<Results>> {
        let _ = (sql, bindings);
        match self {
            #[cfg(feature = "postgres")]
            Conn::Postgres(c) => c.execute(sql, bindings).await,
            #[cfg(feature = "mariadb")]
            Conn::Mysql(c) => c.execute(sql, bindings).await,
            #[cfg(feature = "sqlite")]
            Conn::Sqlite(c) => c.execute(sql, bindings),
            Conn::Ended => Err(gone()),
        }
    }

    async fn control(&mut self, op: Control) -> Result<()> {
        let _ = op;
        match self {
            #[cfg(feature = "postgres")]
            Conn::Postgres(c) => c.control(op).await,
            #[cfg(feature = "mariadb")]
            Conn::Mysql(c) => c.control(op).await,
            #[cfg(feature = "sqlite")]
            Conn::Sqlite(c) => c.control(op),
            Conn::Ended => Err(gone()),
        }
    }

    async fn close(self) {
        match self {
            #[cfg(feature = "postgres")]
            Conn::Postgres(c) => c.close().await,
            #[cfg(feature = "mariadb")]
            Conn::Mysql(c) => c.close().await,
            #[cfg(feature = "sqlite")]
            Conn::Sqlite(_) => {}
            Conn::Ended => {}
        }
    }
}

/// A transaction control statement.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Control {
    Begin,
    Commit,
    RollBack,
}

impl Control {
    /// The statement each driver's hook sends.
    #[allow(dead_code)]
    fn sql(self) -> &'static str {
        match self {
            Control::Begin => "BEGIN",
            Control::Commit => "COMMIT",
            Control::RollBack => "ROLLBACK",
        }
    }

    /// The method name `Utopia\Database\PDO::__call` guards with.
    fn method(self) -> &'static str {
        match self {
            Control::Begin => "beginTransaction",
            Control::Commit => "commit",
            Control::RollBack => "rollBack",
        }
    }
}

/// A call `Utopia\Database\PDO::__call` forwards.
enum Call<'a> {
    Exec(&'a str),
    Query(&'a str),
    Control(Control),
    LastInsertId,
}

/// What a forwarded call returned.
enum Done {
    Count(i64),
    Results(Option<Results>),
    Unit,
    Id(Str),
}

/// A PDO handle: one connection, with `Utopia\Database\PDO`'s recovery.
///
/// See the [module docs](self).
///
/// PHP: `Utopia\Database\PDO` over `\PDO`.
pub struct Pdo {
    dsn: String,
    target: dsn::Target,
    username: Option<String>,
    password: Option<String>,
    options: Options,
    conn: Conn,
    link: Arc<Link>,
    hostname: Option<String>,
    lost: Option<Error>,
    session: Vec<(String, String)>,
    case: Case,
    fetch_mode: FetchMode,
    #[cfg_attr(not(feature = "sqlite"), allow(dead_code))]
    regexp: bool,
    fault: Option<Error>,
}

impl std::fmt::Debug for Pdo {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pdo").field("dsn", &self.dsn).field("driver", &self.driver()).finish_non_exhaustive()
    }
}

impl Pdo {
    /// Connects with a PHP DSN, as the adapters do: `pgsql:host=..;port=..;dbname=..`,
    /// `mysql:host=..;port=..;charset=utf8mb4[;dbname=..]`,
    /// `sqlite:/path/to/file` or `sqlite::memory:`, with the default
    /// [`Options`].
    ///
    /// Fails with PHP's `PDOException`: `could not find driver` for another
    /// driver (or one whose feature is off), `SQLSTATE[08006] [7] ...` when
    /// PostgreSQL refuses, `SQLSTATE[HY000] [2002] ...` / `[1045] ...` when
    /// MySQL does, `SQLSTATE[HY000] [14] unable to open database file` when
    /// SQLite cannot open the file.
    ///
    /// PHP: `new Utopia\Database\PDO($dsn, $username, $password, $attributes)`.
    pub async fn connect(dsn: &str, username: Option<&str>, password: Option<&str>) -> Result<Pdo> {
        Self::connect_with(dsn, username, password, Options::default()).await
    }

    /// [`Pdo::connect`] with options.
    ///
    /// PHP: `new Utopia\Database\PDO($dsn, $username, $password, $config)`.
    pub async fn connect_with(
        dsn: &str,
        username: Option<&str>,
        password: Option<&str>,
        options: Options,
    ) -> Result<Pdo> {
        let target = dsn::parse(dsn)?;
        let conn = Conn::open(&target, username, password, &options).await?;
        Ok(Pdo {
            dsn: dsn.to_owned(),
            target,
            username: username.map(str::to_owned),
            password: password.map(str::to_owned),
            options,
            conn,
            link: Arc::new(Link::default()),
            hostname: None,
            lost: None,
            session: Vec::new(),
            case: Case::Natural,
            fetch_mode: options.fetch_mode,
            regexp: false,
            fault: None,
        })
    }

    /// The engine.
    ///
    /// PHP: `getAttribute(PDO::ATTR_DRIVER_NAME)` ([`Driver::name`]).
    pub fn driver(&self) -> Driver {
        self.target.driver()
    }

    /// The DSN the handle connects with.
    pub fn dsn(&self) -> &str {
        &self.dsn
    }

    /// The host of the DSN.
    ///
    /// Fails with an [`Kind::Exception`] `No host found in DSN` when the DSN
    /// names none (always for SQLite).
    ///
    /// PHP: `Utopia\Database\PDO::getHostname()`.
    pub fn hostname(&mut self) -> Result<String> {
        if let Some(host) = &self.hostname {
            return Ok(host.clone());
        }
        let host = dsn::hostname(&self.dsn)?;
        self.hostname = Some(host.clone());
        Ok(host)
    }

    /// Prepares a statement (`$pdo->prepare($sql)`).
    ///
    /// Refused after a lost transaction until the caller rolls back (a
    /// `ROLLBACK` statement is that roll back). SQLite compiles the statement
    /// here, so its syntax errors surface here, as with PHP's native
    /// prepares; PostgreSQL and MySQL only check it when it executes.
    ///
    /// PHP: `Utopia\Database\PDO::prepare()` / `prepareNative()`.
    pub fn prepare(&mut self, sql: &str) -> Result<Statement> {
        self.prepare_native(sql)?;
        Ok(Statement::new(self, sql))
    }

    fn prepare_native(&mut self, sql: &str) -> Result<()> {
        self.guard(sql)?;
        if sql.is_empty() {
            return Err(Error::new(Kind::ValueError, "PDO::prepare(): Argument #1 ($query) must not be empty"));
        }
        match &self.conn {
            #[cfg(feature = "sqlite")]
            Conn::Sqlite(c) => c.prepare(sql),
            _ => Ok(()),
        }
    }

    /// Runs a statement and returns it with its results (`$pdo->query($sql)`).
    ///
    /// PHP: `Utopia\Database\PDO::__call('query', [$sql])`.
    pub async fn query(&mut self, sql: &str) -> Result<Statement> {
        if sql.is_empty() {
            self.guard(sql)?;
            return Err(Error::new(Kind::ValueError, "PDO::query(): Argument #1 ($query) must not be empty"));
        }
        let mut statement = Statement::new(self, sql);
        match self.call(Call::Query(sql)).await? {
            Done::Results(results) => statement.store(self, results),
            _ => unreachable!("a query returns results"),
        }
        Ok(statement)
    }

    /// Runs `sql` (every query of it) and returns the affected row count
    /// (`$pdo->exec($sql)`).
    ///
    /// PHP: `Utopia\Database\PDO::__call('exec', [$sql])`.
    pub async fn exec(&mut self, sql: &str) -> Result<i64> {
        if sql.is_empty() {
            self.guard(sql)?;
            return Err(Error::new(Kind::ValueError, "PDO::exec(): Argument #1 ($statement) must not be empty"));
        }
        match self.call(Call::Exec(sql)).await? {
            Done::Count(n) => Ok(n),
            _ => unreachable!("exec returns a count"),
        }
    }

    /// Starts a transaction (`beginTransaction()`); fails with `There is
    /// already an active transaction` when the engine reports one.
    ///
    /// PHP: `Utopia\Database\PDO::__call('beginTransaction')`.
    pub async fn begin_transaction(&mut self) -> Result<bool> {
        self.call(Call::Control(Control::Begin)).await.map(|_| true)
    }

    /// Commits (`commit()`); fails with `There is no active transaction`
    /// when the engine reports none.
    ///
    /// PHP: `Utopia\Database\PDO::__call('commit')`.
    pub async fn commit(&mut self) -> Result<bool> {
        self.call(Call::Control(Control::Commit)).await.map(|_| true)
    }

    /// Rolls back (`rollBack()`); fails with `There is no active
    /// transaction` when the engine reports none. After a lost transaction
    /// it only ends the refusal and returns `true`.
    ///
    /// PHP: `Utopia\Database\PDO::__call('rollBack')`.
    pub async fn roll_back(&mut self) -> Result<bool> {
        self.call(Call::Control(Control::RollBack)).await.map(|_| true)
    }

    /// Whether a transaction is open: one the engine reports (a raw `BEGIN`
    /// counts; a PostgreSQL connection that failed reports one), or one lost
    /// with the connection and not rolled back yet.
    ///
    /// PHP: `Utopia\Database\PDO::inTransaction()`.
    pub fn in_transaction(&self) -> bool {
        self.lost.is_some() || self.conn.in_transaction()
    }

    /// The id the last insert generated, as a string
    /// (`lastInsertId()`): PostgreSQL's `LASTVAL()` (failing when the session
    /// has none), MySQL's `mysql_insert_id()` (`"0"` after a statement that
    /// generated none), SQLite's last row id.
    ///
    /// PHP: `Utopia\Database\PDO::__call('lastInsertId')`.
    pub async fn last_insert_id(&mut self) -> Result<Str> {
        match self.call(Call::LastInsertId).await? {
            Done::Id(id) => Ok(id),
            _ => unreachable!("lastInsertId returns an id"),
        }
    }

    /// `$string` as a quoted literal (`quote()`): `None` where PHP returns
    /// `false` (PostgreSQL, for a string that is not valid UTF-8 up to its
    /// first NUL byte).
    ///
    /// PostgreSQL doubles `'` and cuts at a NUL byte; MySQL escapes with
    /// backslashes for utf8mb4; SQLite doubles `'` and fails on a NUL byte
    /// (`SQLite PDO::quote does not support null bytes`).
    ///
    /// PHP: `PDO::quote($string)`.
    pub fn quote(&self, string: &[u8]) -> Result<Option<Str>> {
        let _ = string;
        match self.driver() {
            #[cfg(feature = "postgres")]
            Driver::Postgres => Ok(postgres::quote(string).map(Str::from)),
            #[cfg(feature = "mariadb")]
            Driver::Mysql => Ok(Some(Str::from(mysql::quote(string)))),
            #[cfg(feature = "sqlite")]
            Driver::Sqlite => sqlite::quote(string).map(|q| Some(Str::from(q))),
            #[allow(unreachable_patterns)]
            _ => Err(PdoError::new("could not find driver").into_error()),
        }
    }

    /// Runs a statement that sets session state and replays it on every
    /// connection [`Pdo::reconnect`] opens; a later statement for the same
    /// `setting` replaces the earlier one (in its place). A statement that
    /// fails is not kept.
    ///
    /// PHP: `Utopia\Database\PDO::configure()`.
    pub async fn configure(&mut self, setting: &str, statement: &str) -> Result<()> {
        self.exec(statement).await?;
        match self.session.iter_mut().find(|(s, _)| s == setting) {
            Some(slot) => slot.1 = statement.to_owned(),
            None => self.session.push((setting.to_owned(), statement.to_owned())),
        }
        Ok(())
    }

    /// Sets an attribute, kept on every connection a reconnect opens.
    ///
    /// PHP: `Utopia\Database\PDO::setAttribute()`.
    pub fn set_attribute(&mut self, attribute: Attribute) -> bool {
        match attribute {
            Attribute::Case(case) => self.case = case,
            Attribute::DefaultFetchMode(mode) => self.fetch_mode = mode,
        }
        true
    }

    /// `getAttribute(PDO::ATTR_CASE)`.
    pub fn case(&self) -> Case {
        self.case
    }

    /// `getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)`.
    pub fn default_fetch_mode(&self) -> FetchMode {
        self.fetch_mode
    }

    /// Opens a new connection, replays the configured session on it (and,
    /// on SQLite, the `REGEXP` function), then replaces the current one and
    /// ends a lost transaction's refusal. When a replayed statement fails,
    /// the current connection stays and the error is returned.
    ///
    /// PHP: `Utopia\Database\PDO::reconnect()` (and `SQLite::reconnect()`'s
    /// `registerUserFunctions()`).
    pub async fn reconnect(&mut self) -> Result<()> {
        let username = self.username.clone();
        let password = self.password.clone();
        let mut conn = Conn::open(&self.target, username.as_deref(), password.as_deref(), &self.options).await?;
        for (_, statement) in &self.session {
            if let Err(e) = conn.exec(statement).await {
                conn.close().await;
                return Err(e);
            }
        }
        #[cfg(feature = "sqlite")]
        if self.regexp
            && let Conn::Sqlite(c) = &conn
        {
            c.register_regexp();
        }
        let old = std::mem::replace(&mut self.conn, conn);
        old.close().await;
        self.link = Arc::new(Link::default());
        self.lost = None;
        Ok(())
    }

    /// SQLite: registers the `REGEXP` function SQLite's `REGEXP` operator
    /// calls, matching like PHP's `preg_match` with the pattern between
    /// `\x01` delimiters and the `u` modifier (a `null` operand, a pattern
    /// longer than 512 bytes or one that fails never matches), and registers
    /// it again on every reconnect. Returns whether it was registered
    /// (`false` on other engines).
    ///
    /// PHP: `Utopia\Database\Adapter\SQLite::registerUserFunctions()`.
    pub fn register_regexp(&mut self) -> bool {
        if !self.options.user_functions {
            return false;
        }
        match &self.conn {
            #[cfg(feature = "sqlite")]
            Conn::Sqlite(c) => {
                self.regexp = c.register_regexp();
                self.regexp
            }
            _ => false,
        }
    }

    /// Injects a failure (see [`Fault`]).
    pub fn inject(&mut self, fault: Fault) {
        match fault {
            Fault::Next(error) => self.fault = Some(error),
            Fault::EndSession => {
                let old = std::mem::replace(&mut self.conn, Conn::Ended);
                drop(old);
            }
        }
    }

    /// Refuses a statement while a lost transaction is not rolled back; a
    /// bare `ROLLBACK` ends the refusal and passes.
    ///
    /// PHP: `Utopia\Database\PDO::guard()`.
    fn guard(&mut self, statement: &str) -> Result<()> {
        let Some(lost) = &self.lost else { return Ok(()) };
        if statement.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B']).eq_ignore_ascii_case("ROLLBACK") {
            self.lost = None;
            return Ok(());
        }
        Err(PdoError::new(LOST_TRANSACTION).into_error().with_previous(lost.clone()))
    }

    /// `Utopia\Database\PDO::__call()`: the guard, the call, and on a lost
    /// connection a reconnect, then the call again outside a transaction or
    /// the lost transaction recorded inside one.
    async fn call(&mut self, call: Call<'_>) -> Result<Done> {
        if let Call::Control(Control::RollBack) = call
            && self.lost.is_some()
        {
            self.lost = None;
            return Ok(Done::Unit);
        }
        match call {
            Call::Exec(sql) | Call::Query(sql) => self.guard(sql)?,
            Call::Control(op @ (Control::Begin | Control::Commit)) => self.guard(op.method())?,
            _ => {}
        }
        match self.send(&call).await {
            Ok(done) => Ok(done),
            Err(e) => {
                if crate::connection::is_lost(&e) {
                    tracing::warn!("[Database] {}", e.message());
                    tracing::warn!("[Database] Lost connection detected. Reconnecting...");
                    let in_transaction = self.conn.in_transaction();
                    self.reconnect().await?;
                    if !in_transaction {
                        return self.send(&call).await;
                    }
                    if !matches!(call, Call::Control(Control::RollBack)) {
                        self.lost = Some(e.clone());
                    }
                }
                Err(e)
            }
        }
    }

    /// Sends a forwarded call to the connection (`$this->pdo->{$method}()`).
    async fn send(&mut self, call: &Call<'_>) -> Result<Done> {
        match call {
            Call::Exec(sql) => {
                self.before_statement()?;
                let (count, insert_id) = self.conn.exec(sql).await?;
                self.link.insert_id.store(insert_id, Ordering::Relaxed);
                Ok(Done::Count(count))
            }
            Call::Query(sql) => {
                self.before_statement()?;
                self.conn.execute(sql, &[]).await.map(Done::Results)
            }
            Call::Control(op) => {
                let active = self.conn.in_transaction();
                match op {
                    Control::Begin if active => {
                        return Err(PdoError::new("There is already an active transaction").into_error());
                    }
                    Control::Commit | Control::RollBack if !active => {
                        return Err(PdoError::new("There is no active transaction").into_error());
                    }
                    _ => {}
                }
                self.before_statement()?;
                self.conn.control(*op).await.map(|()| Done::Unit)
            }
            Call::LastInsertId => match &mut self.conn {
                #[cfg(feature = "postgres")]
                Conn::Postgres(c) => c.last_insert_id().await.map(Done::Id),
                #[cfg(feature = "mariadb")]
                Conn::Mysql(_) => Ok(Done::Id(Str::from(self.link.insert_id.load(Ordering::Relaxed).to_string()))),
                #[cfg(feature = "sqlite")]
                Conn::Sqlite(c) => Ok(Done::Id(c.last_insert_id())),
                Conn::Ended => Err(gone()),
            },
        }
    }

    /// Checks made before a statement reaches the connection: an injected
    /// failure, and MySQL's refusal while a statement holds unread results
    /// (`2014 Cannot execute queries while there are pending result sets`).
    fn before_statement(&mut self) -> Result<()> {
        if let Some(e) = self.fault.take() {
            return Err(e);
        }
        #[cfg(feature = "mariadb")]
        if let Conn::Mysql(c) = &self.conn {
            c.check_sync(Arc::strong_count(&self.link) > 1)?;
        }
        Ok(())
    }

    /// Runs a statement's text with its bindings.
    async fn run(&mut self, sql: &str, bindings: &[Binding]) -> Result<Option<Results>> {
        self.before_statement()?;
        self.conn.execute(sql, bindings).await
    }
}

/// A prepared statement: its SQL, its bound values and, once executed, its
/// results.
///
/// See the [module docs](self).
///
/// PHP: `Utopia\Database\PDOStatement` over `\PDOStatement`.
#[derive(Debug)]
pub struct Statement {
    sql: String,
    driver: Driver,
    bindings: Vec<Binding>,
    fetch_mode: FetchMode,
    case: Case,
    results: Option<Results>,
    set: usize,
    row: usize,
    /// Held while results after the current one are unread (MySQL), which
    /// refuses other statements on the connection, as mysqlnd does.
    pending: Option<Arc<Link>>,
    /// The `precision` ini a fetched float is printed at (14; -1 while
    /// [`Statement::fetch_exactly`] runs).
    precision: i32,
}

impl Statement {
    fn new(pdo: &Pdo, sql: &str) -> Statement {
        Statement {
            sql: sql.to_owned(),
            driver: pdo.driver(),
            bindings: Vec::new(),
            fetch_mode: pdo.fetch_mode,
            case: pdo.case,
            results: None,
            set: 0,
            row: 0,
            pending: None,
            precision: 14,
        }
    }

    /// Runs `fetch` at the precision that round-trips every double: PDO
    /// prints a float it fetches (from PostgreSQL and SQLite) at the
    /// `precision` ini, 14 significant digits by default.
    ///
    /// PHP: `SQL::fetchExactly()` (utopia-php/database 8.3.4), which sets
    /// `precision` to `-1` around the fetch.
    pub fn fetch_exactly<T>(&mut self, fetch: impl FnOnce(&mut Self) -> T) -> T {
        let precision = std::mem::replace(&mut self.precision, -1);
        let fetched = fetch(self);
        self.precision = precision;
        fetched
    }

    /// The SQL as prepared.
    ///
    /// PHP: `Utopia\Database\PDOStatement::getQueryString()` (`$queryString`).
    pub fn query_string(&self) -> &str {
        &self.sql
    }

    /// Binds `value` to a 1-based position or a `:name` with a type; binding
    /// the same parameter again replaces its value.
    ///
    /// Fails with PHP's `ValueError` for position 0.
    ///
    /// PHP: `PDOStatement::bindValue($param, $value, $type)`.
    pub fn bind_value(&mut self, param: impl Into<Param>, value: impl Into<Value>, ty: ParamType) -> Result<()> {
        let param = param.into();
        if param == Param::Position(0) {
            return Err(Error::new(
                Kind::ValueError,
                "PDOStatement::bindValue(): Argument #1 ($param) must be greater than or equal to 1",
            ));
        }
        let mut value = value.into();
        let mut ty = ty;
        if self.driver == Driver::Postgres && ty == ParamType::Bool {
            // pdo_pgsql's parameter hook: booleans become 't' / 'f'.
            value = Value::Str(Str::from(if value.truthy() { "t" } else { "f" }));
            ty = ParamType::Str;
        }
        match self.bindings.iter_mut().find(|b| b.param == param) {
            Some(slot) => {
                slot.value = value;
                slot.ty = ty;
            }
            None => self.bindings.push(Binding { param, value, ty }),
        }
        Ok(())
    }

    /// Executes the statement, replacing earlier results. Returns `false`
    /// where PHP's `execute()` does without an exception (a PostgreSQL value
    /// that cannot be quoted, a SQLite statement with no SQL).
    ///
    /// A lost connection outside a transaction is recovered: the handle
    /// reconnects, the statement is prepared again with its bindings, and it
    /// runs again. After a lost transaction the statement is refused until
    /// the caller rolls back.
    ///
    /// PHP: `Utopia\Database\PDOStatement::__call('execute')`.
    pub async fn execute(&mut self, pdo: &mut Pdo) -> Result<bool> {
        self.release();
        pdo.guard(&self.sql)?;
        let results = match pdo.run(&self.sql, &self.bindings).await {
            Ok(results) => results,
            Err(e) => {
                if !crate::connection::is_lost(&e) || pdo.in_transaction() {
                    return Err(e);
                }
                tracing::warn!("[Database] {}", e.message());
                tracing::warn!("[Database] Lost connection detected. Re-preparing statement...");
                pdo.reconnect().await?;
                pdo.prepare_native(&self.sql)?;
                pdo.run(&self.sql, &self.bindings).await?
            }
        };
        let ok = results.is_some();
        self.store(pdo, results);
        Ok(ok)
    }

    fn store(&mut self, pdo: &Pdo, results: Option<Results>) {
        self.release();
        self.set = 0;
        self.row = 0;
        if let Some(results) = &results {
            if let Some(first) = results.sets.first() {
                pdo.link.insert_id.store(first.insert_id, Ordering::Relaxed);
            }
            if results.sets.len() > 1 || results.failure.is_some() {
                self.pending = Some(Arc::clone(&pdo.link));
            }
        }
        self.results = results;
    }

    fn release(&mut self) {
        self.pending = None;
    }

    /// Sets the mode of [`Statement::fetch`] and [`Statement::fetch_all`].
    ///
    /// PHP: `Utopia\Database\PDOStatement::setFetchMode()`.
    pub fn set_fetch_mode(&mut self, mode: FetchMode) {
        self.fetch_mode = mode;
    }

    fn current(&self) -> Option<&Rowset> {
        self.results.as_ref().and_then(|r| r.sets.get(self.set))
    }

    fn next_row(&mut self) -> Option<(Vec<Str>, Vec<Value>)> {
        let precision = self.precision;
        let set = self.current()?;
        let mut row = set.rows.get(self.row)?.clone();
        if set.print_floats {
            for value in &mut row {
                if let Value::Float(f) = value {
                    *value = Value::Str(Str::from(php_std::number::gcvt(*f, precision, 'E')));
                }
            }
        }
        let names = set.columns.clone();
        self.row += 1;
        Some((names, row))
    }

    fn name(&self, column: &Str) -> ArrayKey {
        let name = match self.case {
            Case::Natural => column.clone(),
            Case::Upper => Str::from(column.as_bytes().to_ascii_uppercase()),
            Case::Lower => Str::from(column.as_bytes().to_ascii_lowercase()),
        };
        ArrayKey::normalize(name)
    }

    fn shape(&self, mode: FetchMode, names: &[Str], row: Vec<Value>) -> Result<Value> {
        Ok(match mode {
            FetchMode::Assoc => {
                let mut a = Array::with_capacity(row.len());
                for (name, value) in names.iter().zip(row) {
                    a.set(self.name(name), value);
                }
                Value::Array(a)
            }
            FetchMode::Num => Value::Array(Array::from_list(row)),
            FetchMode::Both => {
                let mut a = Array::with_capacity(row.len() * 2);
                for (i, (name, value)) in names.iter().zip(row).enumerate() {
                    a.set(self.name(name), value.clone());
                    a.set(ArrayKey::Int(i as i64), value);
                }
                Value::Array(a)
            }
            FetchMode::Column(n) => row.into_iter().nth(n).ok_or_else(invalid_column)?,
            FetchMode::KeyPair => {
                if row.len() != 2 {
                    return Err(key_pair_error());
                }
                let mut values = row.into_iter();
                let key = values.next().unwrap_or(Value::Null);
                let value = values.next().unwrap_or(Value::Null);
                let mut a = Array::with_capacity(1);
                a.set(key_of(&key), value);
                Value::Array(a)
            }
        })
    }

    /// The next row in the statement's fetch mode (the handle's default
    /// unless [`Statement::set_fetch_mode`] set one), `None` when there is
    /// none left.
    ///
    /// PHP: `PDOStatement::fetch()`.
    pub fn fetch(&mut self) -> Result<Option<Value>> {
        let mode = self.fetch_mode;
        match self.next_row() {
            Some((names, row)) => self.shape(mode, &names, row).map(Some),
            None => Ok(None),
        }
    }

    /// The remaining rows in the statement's fetch mode: a list of rows,
    /// of values ([`FetchMode::Column`]), or a map ([`FetchMode::KeyPair`],
    /// a later key overwriting an earlier one).
    ///
    /// PHP: `PDOStatement::fetchAll()`.
    pub fn fetch_all(&mut self) -> Result<Array> {
        let mode = self.fetch_mode;
        if mode == FetchMode::KeyPair {
            return self.fetch_all_key_pair();
        }
        if let FetchMode::Column(n) = mode {
            return self.fetch_all_column(n).map(Array::from_list);
        }
        let mut out = Vec::new();
        while let Some((names, row)) = self.next_row() {
            out.push(self.shape(mode, &names, row)?);
        }
        Ok(Array::from_list(out))
    }

    /// The next row as column name => value, `None` when there is none left.
    ///
    /// PHP: `PDOStatement::fetch(PDO::FETCH_ASSOC)`.
    pub fn fetch_assoc(&mut self) -> Option<Array> {
        let (names, row) = self.next_row()?;
        match self.shape(FetchMode::Assoc, &names, row) {
            Ok(Value::Array(a)) => Some(a),
            _ => None,
        }
    }

    /// The next row as a list of values, `None` when there is none left.
    ///
    /// PHP: `PDOStatement::fetch(PDO::FETCH_NUM)`.
    pub fn fetch_num(&mut self) -> Option<Array> {
        let (_, row) = self.next_row()?;
        Some(Array::from_list(row))
    }

    /// The value of column `column` (0-based) of the next row, `None` when
    /// there is no row left; PHP's `ValueError` `Invalid column index` when
    /// the row has no such column.
    ///
    /// PHP: `PDOStatement::fetchColumn($column)`.
    pub fn fetch_column(&mut self, column: usize) -> Result<Option<Value>> {
        match self.next_row() {
            Some((_, row)) => row.into_iter().nth(column).map(Some).ok_or_else(invalid_column),
            None => Ok(None),
        }
    }

    /// The remaining rows as column name => value.
    ///
    /// PHP: `PDOStatement::fetchAll(PDO::FETCH_ASSOC)`.
    pub fn fetch_all_assoc(&mut self) -> Vec<Array> {
        let mut out = Vec::new();
        while let Some(row) = self.fetch_assoc() {
            out.push(row);
        }
        out
    }

    /// The remaining rows as lists of values.
    ///
    /// PHP: `PDOStatement::fetchAll(PDO::FETCH_NUM)`.
    pub fn fetch_all_num(&mut self) -> Vec<Array> {
        let mut out = Vec::new();
        while let Some(row) = self.fetch_num() {
            out.push(row);
        }
        out
    }

    /// The value of column `column` (0-based) of every remaining row.
    ///
    /// PHP: `PDOStatement::fetchAll(PDO::FETCH_COLUMN, $column)`.
    pub fn fetch_all_column(&mut self, column: usize) -> Result<Vec<Value>> {
        let mut out = Vec::new();
        while let Some(value) = self.fetch_column(column)? {
            out.push(value);
        }
        Ok(out)
    }

    /// The remaining rows as first column => second column; fails unless
    /// the result has exactly two columns.
    ///
    /// PHP: `PDOStatement::fetchAll(PDO::FETCH_KEY_PAIR)`.
    pub fn fetch_all_key_pair(&mut self) -> Result<Array> {
        let mut out = Array::new();
        if self.current().is_some_and(|s| s.columns.len() != 2) {
            return Err(key_pair_error());
        }
        while let Some((_, row)) = self.next_row() {
            let mut values = row.into_iter();
            let key = values.next().unwrap_or(Value::Null);
            let value = values.next().unwrap_or(Value::Null);
            out.set(key_of(&key), value);
        }
        Ok(out)
    }

    /// The rows the statement affected, or a `SELECT` returned (PostgreSQL,
    /// MySQL; SQLite: the changes of the last write when the result has no
    /// row, otherwise 0); 0 before it executed.
    ///
    /// PHP: `PDOStatement::rowCount()`.
    pub fn row_count(&self) -> i64 {
        self.current().map_or(0, |s| s.count)
    }

    /// The number of columns of the current result.
    ///
    /// PHP: `PDOStatement::columnCount()`.
    pub fn column_count(&self) -> usize {
        self.current().map_or(0, |s| s.columns.len())
    }

    /// Moves to the result of the next query of a MySQL statement holding
    /// several: `true` when there is one, `false` when none is left, and the
    /// error of the next query when it failed. PostgreSQL and SQLite fail
    /// with `SQLSTATE[IM001]: Driver does not support this function: driver
    /// does not support multiple rowsets`.
    ///
    /// PHP: `PDOStatement::nextRowset()`.
    pub fn next_rowset(&mut self) -> Result<bool> {
        if self.driver != Driver::Mysql {
            return Err(PdoError::implementation("IM001", "driver does not support multiple rowsets").into_error());
        }
        let Some(results) = &mut self.results else { return Ok(false) };
        if self.set + 1 < results.sets.len() {
            self.set += 1;
            self.row = 0;
            let insert_id = results.sets[self.set].insert_id;
            let more = self.set + 1 < results.sets.len() || results.failure.is_some();
            if let Some(link) = &self.pending {
                link.insert_id.store(insert_id, Ordering::Relaxed);
            }
            if !more {
                self.release();
            }
            return Ok(true);
        }
        let failure = results.failure.take();
        results.sets.clear();
        self.set = 0;
        self.row = 0;
        self.release();
        match failure {
            Some(e) => Err(e),
            None => Ok(false),
        }
    }

    /// Frees the results: the rows left and the results of further queries
    /// (their errors discarded).
    ///
    /// PHP: `PDOStatement::closeCursor()`.
    pub fn close_cursor(&mut self) -> bool {
        if let Some(results) = &mut self.results {
            let current = results.sets.get(self.set).cloned();
            results.sets.clear();
            results.failure = None;
            if let Some(mut set) = current {
                set.rows.clear();
                results.sets.push(set);
            }
            self.set = 0;
            self.row = 0;
        }
        self.release();
        true
    }
}

fn invalid_column() -> Error {
    Error::new(Kind::ValueError, "Invalid column index")
}

fn key_pair_error() -> Error {
    PdoError::implementation(
        "HY000",
        "PDO::FETCH_KEY_PAIR fetch mode requires the result set to contain exactly 2 columns",
    )
    .into_error()
}

/// The array key a fetched value becomes.
fn key_of(value: &Value) -> ArrayKey {
    match value {
        Value::Str(s) => ArrayKey::normalize(s.clone()),
        Value::Int(i) => ArrayKey::Int(*i),
        _ => ArrayKey::Str(Str::from("")),
    }
}
