//! Running statements: comments, transforms, bindings, timeouts, the
//! profiler.
//!
//! Every statement an adapter runs goes through [`Sql::prepare_statement`]
//! (the metadata comments, then the transforms of its event) and
//! [`Sql::execute`] (the engine's timeout for the event, the profiler).
//! A builder's statement is prepared and bound by [`Sql::execute_result`]
//! as PHP's `executeResult()` binds it; [`Sql::run_select`] runs a select
//! and reads its rows.
//!
//! PHP: `SQL::prepareStatement()`, `comments()`, `commentText()`,
//! `transformQuery()`, `executeResult()`, `execute()` (and
//! `Postgres::execute()`, `MariaDB::execute()`, `SQLite::execute()`),
//! `executeAndProfile()`, `describeStatement()`, `executeStatement()`
//! (and `MariaDB`'s), `executeSelect()`, `runSelect()`, `getPdoType()`,
//! `bind()`, `formatFloat()`, `setTimeout()`, `clearTimeout()`.

use std::time::Instant;

use php_std::json::Flags;
use utopia_query::builder::Statement;

use super::pdo::{ParamType, Pdo};
use super::{Engine, Sql};
use crate::adapter::Adapter;
use crate::capability::Capability;
use crate::error::{Error, Result};
use crate::event::Event;
use crate::hook::SqlBuilder;
use crate::value::{Array, Value};

/// `formatFloat()`: a decimal that reads back as exactly this double, in as
/// few digits as PHP writes it when they suffice, else 16 or 17 significant
/// digits (`%H` ignores the locale) (8.3.4).
pub(crate) fn format_float(value: f64) -> String {
    let formatted = php_std::number::to_string(value);
    if php_std::format::str_to_float(formatted.as_bytes()) == value {
        return formatted;
    }
    let digits = |precision: &[u8]| {
        php_std::format::sprintf(precision, &[php_std::format::Arg::Float(value)])
            .map(|out| String::from_utf8_lossy(&out).into_owned())
            .unwrap_or_default()
    };
    let formatted = digits(b"%.16H");
    if php_std::format::str_to_float(formatted.as_bytes()) == value { formatted } else { digits(b"%.17H") }
}

/// The connection an adapter runs on.
pub type Connection = utopia_async::sync::Mutex<Pdo>;

/// The setting MariaDB's and MySQL's session timeout is configured under
/// (`MariaDB::TIMEOUT_SETTING`).
const TIMEOUT_SETTING: &str = "timeout";

/// A statement prepared on the adapter's connection, with what the
/// profiler and the timeouts need to know about it.
///
/// PHP: the `PDOStatement` `prepareStatement()` returns, and the
/// adapter's `$statementEvents`, `$statementBindings` and
/// `$statementCollections` entries for it.
#[derive(Debug)]
pub struct Prepared {
    /// The driver's statement.
    pub statement: super::pdo::Statement,
    /// The event it runs for.
    pub event: Option<Event>,
    /// The values bound, for the profiler.
    pub bindings: Array,
    /// The collection it reads or writes, for the profiler.
    pub collection: String,
}

impl Prepared {
    /// `bindValue()`.
    pub fn bind_value(&mut self, param: impl Into<super::pdo::Param>, value: Value, ty: ParamType) -> Result<()> {
        self.statement.bind_value(param, value, ty)
    }

    /// The SQL as prepared.
    pub fn query(&self) -> &str {
        self.statement.query_string()
    }
}

/// `commentText()`: a metadata key or value as a comment shows it.
fn comment_text(value: &Value) -> String {
    let text: Vec<u8> = match value {
        Value::Null => Vec::new(),
        Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_) => {
            value.to_php_string().map(|s| s.as_bytes().to_vec()).unwrap_or_default()
        }
        _ => {
            let flags = Flags::UNESCAPED_SLASHES
                | Flags::UNESCAPED_UNICODE
                | Flags::INVALID_UTF8_SUBSTITUTE
                | Flags::PARTIAL_OUTPUT_ON_ERROR;
            match php_std::json::encode(value, flags, php_std::json::DEFAULT_DEPTH) {
                Ok(json) if !json.is_empty() => json.into_bytes(),
                _ => value.type_name().as_bytes().to_vec(),
            }
        }
    };
    let text = if text.iter().any(|b| !(0x20..=0x7e).contains(b)) {
        let scrubbed = php_std::mb::mb_scrub(&text).into_owned();
        match php_std::pcre::preg_replace(
            &php_std::pcre::StrOrArray::Str(br"/[\p{Cc}\p{Zl}\p{Zp}]/u"),
            &php_std::pcre::StrOrArray::Str(b" "),
            &php_std::pcre::StrOrArray::Str(&scrubbed),
            -1,
        ) {
            Ok(preg) => match preg.value.result {
                php_std::pcre::Value::Str(s) => s,
                _ => Vec::new(),
            },
            Err(_) => Vec::new(),
        }
    } else {
        text
    };
    let text = String::from_utf8_lossy(&text).into_owned();
    text.replace("/*", "/ *").replace("*/", "* /")
}

impl Sql {
    /// `comments()`: one `/* key: value */` line per metadata entry, cached
    /// while every value is scalar or null.
    fn comments(&self) -> String {
        let metadata = self.metadata();
        if let Some((cached, comments)) = &self.engine_state().comments
            && *cached == metadata
        {
            return comments.clone();
        }
        let mut comments = String::new();
        let mut scalar = true;
        for (key, value) in &metadata {
            comments.push_str("/* ");
            comments.push_str(&comment_text(&Value::Str(key.as_str().into())));
            comments.push_str(": ");
            comments.push_str(&comment_text(value));
            comments.push_str(" */\n");
            scalar = scalar
                && matches!(value, Value::Null | Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_));
        }
        if scalar {
            self.engine_state().comments = Some((metadata, comments.clone()));
        }
        comments
    }

    /// `prepareStatement()`: the SQL with the metadata comments ahead of
    /// it and, for an event, through the transforms, prepared on the
    /// connection.
    pub(crate) async fn prepare_statement(&self, sql: &str, event: Option<Event>) -> Result<Prepared> {
        let mut sql = self.comments() + sql;
        if let Some(event) = event {
            sql = self.state().transform(event, sql)?;
        }
        let statement = self.connection.lock().await.prepare(&sql)?;
        Ok(Prepared { statement, event, bindings: Array::new(), collection: String::new() })
    }

    /// `getPdoType()`: the parameter type a value binds as.
    pub(crate) fn pdo_type(&self, value: &Value) -> Result<ParamType> {
        Ok(match value {
            Value::Str(_) | Value::Float(_) => ParamType::Str,
            Value::Bool(_) if self.engine == Engine::Postgres => ParamType::Bool,
            Value::Int(_) | Value::Bool(_) => ParamType::Int,
            Value::Null => ParamType::Null,
            other => return Err(Error::database(format!("Unknown PDO Type for {}", php_type(other)))),
        })
    }

    /// `bind()`: a value with the parameter type it binds as, a double as
    /// its exact decimal ([`format_float`]): PDO would write it at the
    /// `precision` ini (8.3.4).
    pub(crate) fn bindable(&self, value: Value) -> Result<(Value, ParamType)> {
        match value {
            Value::Float(f) => Ok((Value::Str(format_float(f).into()), ParamType::Str)),
            other => {
                let ty = self.pdo_type(&other)?;
                Ok((other, ty))
            }
        }
    }

    /// `executeResult()`: a builder's statement prepared, with its
    /// positional bindings bound by [`Sql::bindable`] (booleans as integers
    /// on engines storing them so).
    pub(crate) async fn execute_result(
        &self,
        result: &Statement,
        event: Option<Event>,
        collection: &str,
    ) -> Result<Prepared> {
        let mut prepared = self.prepare_statement(&result.query, event).await?;
        let bindings: Vec<Value> = result.bindings.iter().map(crate::value::from_query).collect();
        if self.profiling() {
            prepared.bindings = bindings.iter().cloned().collect();
            prepared.collection = collection.to_owned();
        }
        let integer_booleans = self.supports(Capability::IntegerBooleans);
        for (i, value) in bindings.into_iter().enumerate() {
            let value = match value {
                Value::Bool(b) if integer_booleans => Value::Int(i64::from(b)),
                other => other,
            };
            let (value, ty) = self.bindable(value)?;
            prepared.bind_value(i + 1, value, ty)?;
        }
        Ok(prepared)
    }

    /// Whether a profiler is recording.
    fn profiling(&self) -> bool {
        self.profiler().is_some_and(|p| p.is_enabled())
    }

    /// `describeStatement()`: keeps the values bound and the collection for
    /// the profiler, while one is recording.
    pub(crate) fn describe_statement(&self, prepared: &mut Prepared, bindings: Array, collection: &str) {
        if self.profiling() {
            prepared.bindings = bindings;
            prepared.collection = collection.to_owned();
        }
    }

    /// `execute()`: runs a prepared statement under the timeout of its
    /// event, as each engine applies it, and hands it to the profiler.
    /// Fails with the driver's error (a [`crate::Kind::Pdo`]), which the
    /// caller maps with [`Sql::process_exception`].
    pub(crate) async fn execute(&self, prepared: &mut Prepared) -> Result<bool> {
        match self.engine {
            Engine::Sqlite => self.execute_and_profile(prepared).await,
            Engine::Postgres => self.execute_postgres(prepared).await,
            Engine::MariaDb | Engine::MySql => self.execute_mariadb(prepared).await,
        }
    }

    /// `executeAndProfile()`.
    pub(crate) async fn execute_and_profile(&self, prepared: &mut Prepared) -> Result<bool> {
        let profiler = self.profiler().filter(|p| p.is_enabled());
        let start = Instant::now();
        let result = {
            let mut pdo = self.connection.lock().await;
            prepared.statement.execute(&mut pdo).await
        };
        if let Some(profiler) = profiler {
            let result = result?;
            profiler.log(
                prepared.statement.query_string(),
                prepared.bindings.clone(),
                start.elapsed().as_secs_f64() * 1000.0,
                &prepared.collection,
                prepared.event.map_or("", Event::as_str),
            );
            return Ok(result);
        }
        result
    }

    /// The timeout of a statement's event (`getTimeout($event)`).
    fn statement_timeout(&self, event: Option<Event>) -> i64 {
        self.timeouts.get(event.unwrap_or(Event::All))
    }

    /// `Postgres::execute()`: `SET LOCAL` in a transaction, else
    /// `SET statement_timeout` around the statement.
    async fn execute_postgres(&self, prepared: &mut Prepared) -> Result<bool> {
        let timeout = self.statement_timeout(prepared.event);
        if self.in_transaction() {
            self.apply_local_timeout(timeout).await?;
            return self.execute_and_profile(prepared).await;
        }
        self.engine_state().local_timeout = Some(0);
        if timeout == 0 {
            return self.execute_and_profile(prepared).await;
        }
        self.connection.lock().await.exec(&format!("SET statement_timeout = '{timeout}ms'")).await?;
        let result = self.execute_and_profile(prepared).await;
        let reset = self.connection.lock().await.exec("RESET statement_timeout").await;
        match (result, reset) {
            (Ok(r), Ok(_)) => Ok(r),
            (Ok(_), Err(e)) => Err(e),
            (Err(e), _) => Err(e),
        }
    }

    /// `Postgres::applyLocalTimeout()`.
    async fn apply_local_timeout(&self, milliseconds: i64) -> Result<()> {
        if self.engine_state().local_timeout == Some(milliseconds) {
            return Ok(());
        }
        let statement = if milliseconds == 0 {
            "SET LOCAL statement_timeout = DEFAULT".to_owned()
        } else {
            format!("SET LOCAL statement_timeout = '{milliseconds}ms'")
        };
        self.connection.lock().await.exec(&statement).await?;
        self.engine_state().local_timeout = Some(milliseconds);
        Ok(())
    }

    /// `MariaDB::execute()`: the event's session timeout around the
    /// statement, the baseline restored after.
    async fn execute_mariadb(&self, prepared: &mut Prepared) -> Result<bool> {
        let baseline = self.timeouts.get(Event::All);
        let timeout = match prepared.event {
            None => baseline,
            Some(event) => self.timeouts.get(event),
        };
        self.apply_timeout(timeout).await?;
        let result = self.execute_and_profile(prepared).await;
        if timeout != baseline {
            let restored = self.apply_timeout(baseline).await;
            if let (Ok(_), Err(e)) = (&result, restored) {
                return Err(e);
            }
        }
        result
    }

    /// `MariaDB::applyTimeout()`: the session timeout, configured on the
    /// connection so a reconnect replays it.
    pub(crate) async fn apply_timeout(&self, milliseconds: i64) -> Result<()> {
        let applied = self.engine_state().applied_timeout;
        if (milliseconds == 0 && applied == 0) || milliseconds == applied {
            return Ok(());
        }
        let statement = match self.engine {
            Engine::MySql => format!("SET SESSION MAX_EXECUTION_TIME = {milliseconds}"),
            _ => {
                let seconds =
                    php_std::format::sprintf(b"%.6F", &[php_std::format::Arg::Float(milliseconds as f64 / 1000.0)])
                        .map(|s| String::from_utf8_lossy(&s).into_owned())
                        .unwrap_or_default();
                format!("SET max_statement_time = {seconds}")
            }
        };
        self.connection.lock().await.configure(TIMEOUT_SETTING, &statement).await?;
        self.engine_state().applied_timeout = milliseconds;
        Ok(())
    }

    /// `executeStatement()`: prepares and runs SQL for an event; on MariaDB
    /// and MySQL every result of a multi-statement text is reached, so a
    /// later statement's failure fails the call.
    pub(crate) async fn execute_statement(&self, sql: &str, event: Event) -> Result<bool> {
        let mut prepared = self.prepare_statement(sql, Some(event)).await?;
        let executed = self.execute(&mut prepared).await?;
        if self.engine.is_mariadb_family() {
            while prepared.statement.next_rowset()? {}
        }
        Ok(executed)
    }

    /// `executeSelect()`: builds the select, mapping the builder's
    /// validation errors to query errors, and runs it.
    pub(crate) async fn execute_select(
        &self,
        builder: &mut SqlBuilder,
        event: Event,
        collection: &str,
    ) -> Result<Vec<Array>> {
        let result = builder.build().map_err(crate::query::query_error)?;
        self.run_select(&result, event, collection).await
    }

    /// `runSelect()`: the rows a select reads (`FETCH_ASSOC`), its driver
    /// errors mapped by `processSelectException()`.
    pub(crate) async fn run_select(&self, result: &Statement, event: Event, collection: &str) -> Result<Vec<Array>> {
        let run = async {
            let mut prepared = self.execute_result(result, Some(event), collection).await?;
            self.execute(&mut prepared).await?;
            let rows = prepared.statement.fetch_exactly(|s| s.fetch_all_assoc());
            prepared.statement.close_cursor();
            Ok::<_, Error>(rows)
        };
        run.await.map_err(|e| self.process_select_exception(e, &result.query))
    }

    /// `setTimeout()`: fails for a timeout that is not positive. MariaDB
    /// and MySQL apply a global timeout to the session before the next
    /// statement runs.
    pub(crate) fn set_timeout(&self, milliseconds: i64, event: Event) -> Result<()> {
        if milliseconds <= 0 {
            return Err(Error::database("Timeout must be greater than 0"));
        }
        self.timeouts.set(milliseconds, event);
        Ok(())
    }

    /// `clearTimeout()`.
    pub(crate) fn clear_timeout(&self, event: Event) {
        self.timeouts.clear(event);
    }
}

/// `gettype()`.
fn php_type(value: &Value) -> &'static str {
    match value {
        Value::Null => "NULL",
        Value::Bool(_) => "boolean",
        Value::Int(_) => "integer",
        Value::Float(_) => "double",
        Value::Str(_) => "string",
        Value::Array(_) => "array",
        _ => "object",
    }
}

#[cfg(test)]
mod tests {
    use super::format_float;

    /// PHP 8.3.6 Adapter/SQL.php:212-222 (`formatFloat()`) and
    /// FloatBindingTest::floats(): the digits PHP writes when they read back
    /// as the double, else 16 or 17 significant ones.
    #[test]
    fn a_double_binds_as_a_decimal_that_round_trips() {
        for (value, bound) in [
            (0.1 + 0.2, "0.30000000000000004"),
            (std::f64::consts::PI, "3.141592653589793"),
            (123456789.12345679, "123456789.12345679"),
            (5e-324, "4.9406564584125E-324"),
            (1e-300, "1.0E-300"),
            (-2.5e-20, "-2.5E-20"),
            (1.7976931348623157e308, "1.7976931348623157E+308"),
            (0.1, "0.1"),
        ] {
            assert_eq!(format_float(value), bound);
        }
    }
}
