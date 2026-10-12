//! The PostgreSQL frontend/backend protocol, as libpq's `PQexec` speaks it.
//!
//! [`Wire`] is one server connection: it authenticates (trust, cleartext,
//! MD5 or SCRAM-SHA-256), then runs statements with the simple query
//! protocol, keeping the transaction status every `ReadyForQuery` reports.
//! Statements are bytes, sent as-is up to their first NUL (a C string, as
//! libpq sends them), and results keep the bytes the server sent, with the
//! type of each column, so the adapter can read rows exactly as PDO's pgsql
//! driver does.
//!
//! PHP: the libpq connection under `PDO` (`PQconnectdb`, `PQexec`,
//! `PQtransactionStatus`, `PQresultErrorField`).

use std::io;
use std::time::Duration;

use bytes::{Bytes, BytesMut};
use fallible_iterator::FallibleIterator;
use postgres_protocol::authentication::md5_hash;
use postgres_protocol::authentication::sasl::{ChannelBinding, SCRAM_SHA_256, ScramSha256};
use postgres_protocol::message::backend::Message;
use postgres_protocol::message::frontend;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpStream;

/// Where and as whom to connect.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Config {
    /// Host name or address.
    pub host: String,
    /// TCP port.
    pub port: u16,
    /// User name.
    pub user: String,
    /// Password (empty for none).
    pub password: String,
    /// Database name.
    pub dbname: String,
}

impl Config {
    /// Reads `postgres://user:password@host:port/dbname` (also
    /// `postgresql://`); the port defaults to 5432 and the database to the
    /// user's name. User and password may be percent-encoded.
    pub fn parse(url: &str) -> Option<Config> {
        let rest = url.strip_prefix("postgres://").or_else(|| url.strip_prefix("postgresql://"))?;
        let rest = rest.split(['?', '#']).next().unwrap_or("");
        let (auth, location) = match rest.rfind('@') {
            Some(at) => (&rest[..at], &rest[at + 1..]),
            None => ("", rest),
        };
        let (user, password) = match auth.find(':') {
            Some(i) => (&auth[..i], &auth[i + 1..]),
            None => (auth, ""),
        };
        let (hostport, dbname) = match location.find('/') {
            Some(i) => (&location[..i], &location[i + 1..]),
            None => (location, ""),
        };
        let (host, port) = match hostport.rfind(':') {
            Some(i) if !hostport[i + 1..].is_empty() => (&hostport[..i], hostport[i + 1..].parse().ok()?),
            _ => (hostport, 5432),
        };
        let user = percent_decode(user);
        let dbname = if dbname.is_empty() { user.clone() } else { percent_decode(dbname) };
        Some(Config {
            host: host.trim_start_matches('[').trim_end_matches(']').to_owned(),
            port,
            user,
            password: percent_decode(password),
            dbname,
        })
    }
}

fn percent_decode(s: &str) -> String {
    let b = s.as_bytes();
    let mut out = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        if b[i] == b'%'
            && i + 2 < b.len()
            && let (Some(h), Some(l)) = (hex(b[i + 1]), hex(b[i + 2]))
        {
            out.push(h * 16 + l);
            i += 3;
            continue;
        }
        out.push(b[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

fn hex(c: u8) -> Option<u8> {
    match c {
        b'0'..=b'9' => Some(c - b'0'),
        b'a'..=b'f' => Some(c - b'a' + 10),
        b'A'..=b'F' => Some(c - b'A' + 10),
        _ => None,
    }
}

/// `PQtransactionStatus()`.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum TxStatus {
    /// `PQTRANS_IDLE`: not in a transaction block.
    Idle,
    /// `PQTRANS_INTRANS`: in a transaction block.
    InTransaction,
    /// `PQTRANS_INERROR`: in a failed transaction block.
    Failed,
}

/// A column of a result: its name and type OID.
#[derive(Clone, Debug)]
pub struct Column {
    /// The column name, as the server sent it.
    pub name: Bytes,
    /// The type OID.
    pub oid: u32,
}

/// What `PQresultStatus()` reports for a result.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Status {
    /// `PGRES_EMPTY_QUERY` (0).
    Empty,
    /// `PGRES_COMMAND_OK` (1).
    Command,
    /// `PGRES_TUPLES_OK` (2).
    Tuples,
    /// `PGRES_FATAL_ERROR` (7).
    Fatal,
}

impl Status {
    /// The `ExecStatusType` value.
    pub fn code(self) -> i64 {
        match self {
            Status::Empty => 0,
            Status::Command => 1,
            Status::Tuples => 2,
            Status::Fatal => 7,
        }
    }
}

/// One result (`PGresult`): the last of a query string, as `PQexec` keeps.
#[derive(Clone, Debug)]
pub struct Outcome {
    /// The result status.
    pub status: Status,
    /// Columns (tuple results).
    pub columns: Vec<Column>,
    /// Rows: one value per column, `None` for SQL `NULL`.
    pub rows: Vec<Vec<Option<Bytes>>>,
    /// The command tag (`INSERT 0 1`, `SELECT 2`, ...).
    pub tag: String,
    /// The error's fields (code byte, value), for a failed statement.
    pub error: Vec<(u8, Bytes)>,
    /// The message libpq builds for an error without fields (a lost
    /// connection), with its trailing newline.
    pub client_message: String,
}

impl Outcome {
    fn new(status: Status) -> Self {
        Self {
            status,
            columns: Vec::new(),
            rows: Vec::new(),
            tag: String::new(),
            error: Vec::new(),
            client_message: String::new(),
        }
    }

    /// An error result libpq makes itself (no server fields).
    pub fn client_error(message: impl Into<String>) -> Self {
        Self { client_message: message.into(), ..Self::new(Status::Fatal) }
    }

    /// The error field `code` (`PQresultErrorField`).
    pub fn field(&self, code: u8) -> Option<&[u8]> {
        self.error.iter().find(|(c, _)| *c == code).map(|(_, v)| v.as_ref())
    }

    /// `PQcmdTuples()`: the row count of the command tag, `0` when it has none.
    pub fn cmd_tuples(&self) -> i64 {
        let mut words = self.tag.split(' ');
        let count = match words.next() {
            Some("INSERT") => words.nth(1),
            Some("SELECT" | "UPDATE" | "DELETE" | "MOVE" | "FETCH" | "COPY" | "MERGE") => words.next(),
            _ => None,
        };
        count.and_then(|c| c.parse().ok()).unwrap_or(0)
    }
}

/// A server connection.
pub struct Wire {
    stream: TcpStream,
    buf: BytesMut,
    out: BytesMut,
    status: TxStatus,
    pid: i32,
    broken: bool,
}

fn protocol_error(message: &str) -> io::Error {
    io::Error::new(io::ErrorKind::InvalidData, message.to_owned())
}

impl Wire {
    /// Connects and authenticates (`connect_timeout` 3 seconds, as PDO's
    /// `ATTR_TIMEOUT`).
    pub async fn connect(config: &Config) -> io::Result<Wire> {
        let address = (config.host.as_str(), config.port);
        let stream = tokio::time::timeout(Duration::from_secs(3), TcpStream::connect(address))
            .await
            .map_err(|_| io::Error::new(io::ErrorKind::TimedOut, "timeout expired"))??;
        stream.set_nodelay(true)?;
        let mut wire = Wire {
            stream,
            buf: BytesMut::with_capacity(8192),
            out: BytesMut::with_capacity(1024),
            status: TxStatus::Idle,
            pid: 0,
            broken: false,
        };
        wire.startup(config).await?;
        Ok(wire)
    }

    async fn startup(&mut self, config: &Config) -> io::Result<()> {
        frontend::startup_message(
            [("user", config.user.as_str()), ("database", config.dbname.as_str())],
            &mut self.out,
        )?;
        self.flush().await?;
        let mut scram: Option<ScramSha256> = None;
        loop {
            match self.read().await? {
                Message::AuthenticationOk => {}
                Message::AuthenticationCleartextPassword => {
                    frontend::password_message(config.password.as_bytes(), &mut self.out)?;
                    self.flush().await?;
                }
                Message::AuthenticationMd5Password(body) => {
                    let hash = md5_hash(config.user.as_bytes(), config.password.as_bytes(), body.salt());
                    frontend::password_message(hash.as_bytes(), &mut self.out)?;
                    self.flush().await?;
                }
                Message::AuthenticationSasl(body) => {
                    let mut mechanisms = body.mechanisms();
                    let mut found = false;
                    while let Some(m) = mechanisms.next()? {
                        found |= m == SCRAM_SHA_256;
                    }
                    if !found {
                        return Err(protocol_error(
                            "none of the server's SASL authentication mechanisms are supported",
                        ));
                    }
                    let s = ScramSha256::new(config.password.as_bytes(), ChannelBinding::unsupported());
                    frontend::sasl_initial_response(SCRAM_SHA_256, s.message(), &mut self.out)?;
                    self.flush().await?;
                    scram = Some(s);
                }
                Message::AuthenticationSaslContinue(body) => {
                    let s = scram.as_mut().ok_or_else(|| protocol_error("unexpected SASL message"))?;
                    s.update(body.data())?;
                    frontend::sasl_response(s.message(), &mut self.out)?;
                    self.flush().await?;
                }
                Message::AuthenticationSaslFinal(body) => {
                    let s = scram.as_mut().ok_or_else(|| protocol_error("unexpected SASL message"))?;
                    s.finish(body.data())?;
                }
                Message::BackendKeyData(body) => self.pid = body.process_id(),
                Message::ParameterStatus(_) | Message::NoticeResponse(_) => {}
                Message::ReadyForQuery(body) => {
                    self.status = status_of(body.status());
                    return Ok(());
                }
                Message::ErrorResponse(body) => {
                    let mut fields = body.fields();
                    let mut message = String::new();
                    while let Some(f) = fields.next()? {
                        if f.type_() == b'M' {
                            message = String::from_utf8_lossy(f.value_bytes()).into_owned();
                        }
                    }
                    return Err(io::Error::new(io::ErrorKind::PermissionDenied, message));
                }
                _ => return Err(protocol_error("unexpected message during startup")),
            }
        }
    }

    async fn flush(&mut self) -> io::Result<()> {
        let out = self.out.split();
        if let Err(e) = self.stream.write_all(&out).await {
            self.broken = true;
            return Err(e);
        }
        Ok(())
    }

    async fn read(&mut self) -> io::Result<Message> {
        loop {
            if let Some(message) = Message::parse(&mut self.buf)? {
                return Ok(message);
            }
            let n = self.stream.read_buf(&mut self.buf).await;
            match n {
                Ok(0) => {
                    self.broken = true;
                    return Err(io::Error::new(
                        io::ErrorKind::UnexpectedEof,
                        "server closed the connection unexpectedly",
                    ));
                }
                Ok(_) => {}
                Err(e) => {
                    self.broken = true;
                    return Err(e);
                }
            }
        }
    }

    /// The backend's process id (`pg_backend_pid()`).
    pub fn pid(&self) -> i32 {
        self.pid
    }

    /// `PQtransactionStatus()` as of the last `ReadyForQuery`.
    pub fn status(&self) -> TxStatus {
        self.status
    }

    /// Whether the connection failed (`PQstatus() == CONNECTION_BAD`).
    pub fn is_broken(&self) -> bool {
        self.broken
    }

    /// `PQexec($query)`: runs a query string with the simple query protocol
    /// and returns its last result. The query is cut at its first NUL byte,
    /// as libpq sends C strings.
    pub async fn exec(&mut self, query: &[u8]) -> Outcome {
        self.exec_many(&[query]).await.pop().unwrap_or_else(|| Outcome::new(Status::Empty))
    }

    /// `PQexec` of each query string in turn, sent in one write (the
    /// server runs them one after the other, as separate `PQexec` calls
    /// would): one result per query.
    pub async fn exec_many(&mut self, queries: &[&[u8]]) -> Vec<Outcome> {
        let lost = || {
            Outcome::client_error(
                "server closed the connection unexpectedly\n\tThis probably means the server terminated abnormally\n\tbefore or while processing the request.\n",
            )
        };
        if self.broken {
            return queries.iter().map(|_| Outcome::client_error("no connection to the server\n")).collect();
        }
        for query in queries {
            let query = match query.iter().position(|b| *b == 0) {
                Some(i) => &query[..i],
                None => query,
            };
            // 'Q', length, the query, NUL.
            self.out.extend_from_slice(b"Q");
            self.out.extend_from_slice(&((query.len() + 5) as i32).to_be_bytes());
            self.out.extend_from_slice(query);
            self.out.extend_from_slice(&[0]);
        }
        if self.flush().await.is_err() {
            self.broken = true;
            return queries.iter().map(|_| lost()).collect();
        }
        let mut out = Vec::with_capacity(queries.len());
        for _ in queries {
            match self.receive().await {
                Ok(outcome) => out.push(outcome),
                Err(_) => {
                    self.broken = true;
                    out.push(lost());
                }
            }
        }
        out
    }

    /// Reads one query string's messages up to `ReadyForQuery`, keeping
    /// its last result.
    async fn receive(&mut self) -> io::Result<Outcome> {
        if self.broken {
            return Err(io::Error::new(io::ErrorKind::NotConnected, "no connection to the server"));
        }
        let mut last: Option<Outcome> = None;
        let mut current: Option<Outcome> = None;
        loop {
            match self.read().await? {
                Message::RowDescription(body) => {
                    let mut out = Outcome::new(Status::Tuples);
                    let mut fields = body.fields();
                    while let Some(f) = fields.next()? {
                        out.columns
                            .push(Column { name: Bytes::copy_from_slice(f.name().as_bytes()), oid: f.type_oid() });
                    }
                    current = Some(out);
                }
                Message::DataRow(body) => {
                    let buffer = body.buffer_bytes().clone();
                    let mut ranges = body.ranges();
                    let mut row = Vec::new();
                    while let Some(range) = ranges.next()? {
                        row.push(range.map(|r| buffer.slice(r)));
                    }
                    if let Some(c) = current.as_mut() {
                        c.rows.push(row);
                    }
                }
                Message::CommandComplete(body) => {
                    let mut out = current.take().unwrap_or_else(|| Outcome::new(Status::Command));
                    out.tag = body.tag().unwrap_or("").to_owned();
                    last = Some(out);
                }
                Message::EmptyQueryResponse => {
                    current = None;
                    last = Some(Outcome::new(Status::Empty));
                }
                Message::ErrorResponse(body) => {
                    let mut out = Outcome::new(Status::Fatal);
                    let mut fields = body.fields();
                    while let Some(f) = fields.next()? {
                        out.error.push((f.type_(), Bytes::copy_from_slice(f.value_bytes())));
                    }
                    current = None;
                    last = Some(out);
                }
                Message::ReadyForQuery(body) => {
                    self.status = status_of(body.status());
                    return Ok(last.unwrap_or_else(|| Outcome::new(Status::Empty)));
                }
                Message::CopyInResponse(_) => {
                    frontend::copy_fail("COPY from stdin is not supported", &mut self.out)?;
                    self.flush().await?;
                }
                _ => {}
            }
        }
    }

    /// Sends `Terminate` and closes the socket.
    pub async fn close(mut self) {
        frontend::terminate(&mut self.out);
        let _ = self.flush().await;
        let _ = self.stream.shutdown().await;
    }
}

fn status_of(b: u8) -> TxStatus {
    match b {
        b'T' => TxStatus::InTransaction,
        b'E' => TxStatus::Failed,
        _ => TxStatus::Idle,
    }
}
