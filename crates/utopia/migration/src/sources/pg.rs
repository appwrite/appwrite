//! PostgreSQL as PHP's PDO pgsql driver reads it (native prepares, native
//! types): integers and booleans as PHP integers and booleans, floats as
//! floats, everything else as the text the server sends.

use php_std::{Array, Value};
use tokio_postgres::types::Type;
use tokio_postgres::{Client, NoTls, SimpleQueryMessage};

use crate::error::{Error, Result};

/// A parameter of a query.
#[derive(Debug, Clone)]
pub enum Param {
    /// An integer (`PDO::PARAM_INT`).
    Int(i64),
    /// A string.
    Str(String),
}

/// A connection.
///
/// PHP: `\PDO` with the pgsql driver.
pub struct Pg {
    client: Client,
    error_code: std::sync::Mutex<Option<String>>,
}

impl std::fmt::Debug for Pg {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pg").finish_non_exhaustive()
    }
}

/// A `PDOException` of a server error.
fn pdo_error(e: &tokio_postgres::Error) -> Error {
    match e.as_db_error() {
        Some(db) => {
            let sqlstate = db.code().code().to_owned();
            let message = utopia_database::adapter::sql::pdo::PdoError::driver(
                &sqlstate,
                7,
                &format!("ERROR:  {}", db.message()),
            )
            .message;
            Error::Pdo { message, sqlstate }
        }
        None => Error::Pdo { message: format!("SQLSTATE[08006] [7] {e}"), sqlstate: "08006".to_owned() },
    }
}

fn literal(p: &Param) -> String {
    match p {
        Param::Int(i) => i.to_string(),
        Param::Str(s) => format!("'{}'", s.replace('\'', "''")),
    }
}

/// `sql` with `$n` placeholders replaced by `params` as literals.
fn inline(sql: &str, params: &[Param]) -> String {
    let mut out = sql.to_owned();
    for (i, p) in params.iter().enumerate().rev() {
        out = out.replace(&format!("${}", i + 1), &literal(p));
    }
    out
}

/// A fetched value of a column of type `ty`, as PDO returns it.
fn fetch(ty: &Type, text: Option<&str>) -> Value {
    let Some(text) = text else { return Value::Null };
    match *ty {
        Type::BOOL => Value::Bool(text == "t"),
        Type::INT2 | Type::INT4 | Type::INT8 | Type::OID => text.parse().map_or_else(|_| Value::from(text), Value::Int),
        Type::FLOAT4 | Type::FLOAT8 => text.parse().map_or_else(|_| Value::from(text), Value::Float),
        _ => Value::from(text),
    }
}

impl Pg {
    /// Connects to `host:port`, database `database`, as `user`.
    ///
    /// PHP: `new \PDO('pgsql:host=...;port=...;dbname=...', $user, $password)`.
    pub async fn connect(host: &str, port: &str, database: &str, user: &str, password: &str) -> Result<Pg> {
        let mut config = tokio_postgres::Config::new();
        config.host(host).dbname(database).user(user).password(password);
        if let Ok(p) = port.parse::<u16>() {
            config.port(p);
        }
        let (client, connection) = config.connect(NoTls).await.map_err(|e| Error::Pdo {
            message: format!(
                "SQLSTATE[08006] [7] connection to server at \"{host}\", port {port} failed: {}",
                libpq_reason(&e)
            ),
            sqlstate: "08006".to_owned(),
        })?;
        utopia_async::spawn(async move {
            let _ = connection.await;
        });
        Ok(Pg { client, error_code: std::sync::Mutex::new(None) })
    }

    /// The rows of `sql` (with `$n` placeholders for `params`), as
    /// associative arrays (`fetchAll(PDO::FETCH_ASSOC)`).
    pub async fn rows(&self, sql: &str, params: &[Param]) -> Result<Vec<Array>> {
        let result = self.run(sql, params).await;
        let code = match &result {
            Ok(_) => "00000".to_owned(),
            Err(Error::Pdo { sqlstate, .. }) => sqlstate.clone(),
            Err(_) => "HY000".to_owned(),
        };
        *self.error_code.lock().unwrap_or_else(|e| e.into_inner()) = Some(code);
        result
    }

    /// The SQLSTATE of the last statement (`PDO::errorCode()`: `None`
    /// before the first).
    pub fn error_code(&self) -> Option<String> {
        self.error_code.lock().unwrap_or_else(|e| e.into_inner()).clone()
    }

    async fn run(&self, sql: &str, params: &[Param]) -> Result<Vec<Array>> {
        let statement = self.client.prepare(sql).await.map_err(|e| pdo_error(&e))?;
        let types: Vec<Type> = statement.columns().iter().map(|c| c.type_().clone()).collect();
        let messages = self.client.simple_query(&inline(sql, params)).await.map_err(|e| pdo_error(&e))?;
        let mut out = Vec::new();
        for message in messages {
            if let SimpleQueryMessage::Row(row) = message {
                let mut a = Array::new();
                for (i, column) in row.columns().iter().enumerate() {
                    let ty = types.get(i).cloned().unwrap_or(Type::TEXT);
                    a.set(column.name().into(), fetch(&ty, row.get(i)));
                }
                out.push(a);
            }
        }
        Ok(out)
    }

    /// The first column of the first row (`fetchColumn()`; `false` as
    /// `null`).
    pub async fn column(&self, sql: &str, params: &[Param]) -> Result<Value> {
        let rows = self.rows(sql, params).await?;
        Ok(rows.first().and_then(|r| r.values().next().cloned()).unwrap_or(Value::Null))
    }
}

/// Why a connection failed, as libpq words it.
fn libpq_reason(e: &tokio_postgres::Error) -> String {
    let mut source: Option<&(dyn std::error::Error + 'static)> = std::error::Error::source(e);
    while let Some(s) = source {
        if let Some(io) = s.downcast_ref::<std::io::Error>() {
            if io.kind() == std::io::ErrorKind::ConnectionRefused {
                return "Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?"
                    .to_owned();
            }
            return io.to_string();
        }
        source = s.source();
    }
    e.to_string()
}
