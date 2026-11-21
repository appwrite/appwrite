//! The PostgreSQL frontend/backend protocol, as libpq's `PQexec` speaks it.
//!
//! [`Wire`] is one server connection: it authenticates (trust, cleartext,
//! MD5 or SCRAM-SHA-256), then runs statements with the simple query
//! protocol, keeping the transaction status every `ReadyForQuery` reports.
//! Statements are bytes, sent as-is up to their first NUL (a C string, as
//! libpq sends them), and results keep the bytes the server sent, with the
//! type of each column, so rows read exactly as PDO's pgsql driver reads
//! them.
//!
//! PHP: the libpq connection under `PDO` (`PQconnectdb`, `PQexec`,
//! `PQtransactionStatus`, `PQresultErrorField`).

use std::io;
use std::net::SocketAddr;
use std::time::Duration;

use bytes::{Bytes, BytesMut};
use fallible_iterator::FallibleIterator;
use postgres_protocol::authentication::md5_hash;
use postgres_protocol::authentication::sasl::{ChannelBinding, SCRAM_SHA_256, ScramSha256};
use postgres_protocol::message::backend::Message;
use postgres_protocol::message::frontend;
use utopia_async::io::{AsyncReadExt, AsyncWriteExt};
use utopia_async::net::TcpStream;

/// Where and as whom to connect.
#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct Config {
    /// Host name or address.
    pub(crate) host: String,
    /// TCP port.
    pub(crate) port: u16,
    /// User name.
    pub(crate) user: String,
    /// Password (empty for none).
    pub(crate) password: String,
    /// Database name.
    pub(crate) dbname: String,
}

/// `PQtransactionStatus()`.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum TxStatus {
    /// `PQTRANS_IDLE`: not in a transaction block.
    Idle,
    /// `PQTRANS_INTRANS`: in a transaction block.
    InTransaction,
    /// `PQTRANS_INERROR`: in a failed transaction block.
    Failed,
    /// `PQTRANS_UNKNOWN`: the connection failed.
    Unknown,
}

/// A column of a result: its name and type OID.
#[derive(Clone, Debug)]
pub(crate) struct Column {
    /// The column name, as the server sent it.
    pub(crate) name: Bytes,
    /// The type OID.
    pub(crate) oid: u32,
}

/// What `PQresultStatus()` reports for a result.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum Status {
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
    pub(crate) fn code(self) -> i64 {
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
pub(crate) struct Outcome {
    /// The result status.
    pub(crate) status: Status,
    /// Columns (tuple results).
    pub(crate) columns: Vec<Column>,
    /// Rows: one value per column, `None` for SQL `NULL`.
    pub(crate) rows: Vec<Vec<Option<Bytes>>>,
    /// The command tag (`INSERT 0 1`, `SELECT 2`, ...).
    pub(crate) tag: String,
    /// The error's fields (code byte, value), for a failed statement.
    pub(crate) error: Vec<(u8, Bytes)>,
    /// The message libpq builds for an error without fields (a lost
    /// connection), with its trailing newline.
    pub(crate) client_message: String,
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
    pub(crate) fn client_error(message: impl Into<String>) -> Self {
        Self { client_message: message.into(), ..Self::new(Status::Fatal) }
    }

    /// The error field `code` (`PQresultErrorField`).
    pub(crate) fn field(&self, code: u8) -> Option<&[u8]> {
        self.error.iter().find(|(c, _)| *c == code).map(|(_, v)| v.as_ref())
    }

    /// `PQcmdTuples()`: the row count of the command tag, `0` when it has none.
    pub(crate) fn cmd_tuples(&self) -> i64 {
        let mut words = self.tag.split(' ');
        let count = match words.next() {
            Some("INSERT") => words.nth(1),
            Some("SELECT" | "UPDATE" | "DELETE" | "MOVE" | "FETCH" | "COPY" | "MERGE") => words.next(),
            _ => None,
        };
        count.and_then(|c| c.parse().ok()).unwrap_or(0)
    }

    /// The server error as libpq prints it without a query position:
    /// `SEVERITY:  message`, then `DETAIL:` and `HINT:` lines.
    fn server_message(&self) -> String {
        let text = |c: u8| self.field(c).map(|v| String::from_utf8_lossy(v).into_owned());
        let mut msg = String::new();
        if let Some(s) = text(b'S') {
            msg.push_str(&s);
            msg.push_str(":  ");
        }
        if let Some(m) = text(b'M') {
            msg.push_str(&m);
        }
        msg.push('\n');
        for (code, label) in [(b'D', "DETAIL"), (b'H', "HINT")] {
            if let Some(v) = text(code) {
                msg.push_str(label);
                msg.push_str(":  ");
                msg.push_str(&v);
                msg.push('\n');
            }
        }
        msg
    }
}

/// libpq's message for a connection the server closed.
const CLOSED: &str = "server closed the connection unexpectedly\n\tThis probably means the server terminated abnormally\n\tbefore or while processing the request.\n";

/// A server connection.
pub(crate) struct Wire {
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

/// Why connecting failed, as libpq words it (`PQerrorMessage`, trimmed).
fn connect_failure(config: &Config, addr: SocketAddr, reason: &str) -> String {
    let ip = addr.ip().to_string();
    if ip == config.host {
        format!("connection to server at \"{}\", port {} failed: {reason}", config.host, config.port)
    } else {
        format!("connection to server at \"{}\" ({ip}), port {} failed: {reason}", config.host, config.port)
    }
}

impl Wire {
    /// Connects and authenticates within `timeout` (`connect_timeout`),
    /// failing with libpq's message.
    pub(crate) async fn connect(config: &Config, timeout: Duration) -> Result<Wire, String> {
        let host = config.host.as_str();
        let addrs: Vec<SocketAddr> = match utopia_async::net::lookup_host((host, config.port)).await {
            Ok(a) => a.collect(),
            Err(_) => Vec::new(),
        };
        let Some(addr) = addrs.first().copied() else {
            return Err(format!("could not translate host name \"{host}\" to address: Name does not resolve"));
        };
        let stream = match utopia_async::timeout(timeout, TcpStream::connect(addr)).await {
            Err(_) => return Err(connect_failure(config, addr, "timeout expired")),
            Ok(Err(e)) if e.kind() == io::ErrorKind::ConnectionRefused => {
                return Err(connect_failure(
                    config,
                    addr,
                    "Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?",
                ));
            }
            Ok(Err(e)) => return Err(connect_failure(config, addr, &e.to_string())),
            Ok(Ok(s)) => s,
        };
        let _ = stream.set_nodelay(true);
        let mut wire = Wire {
            stream,
            buf: BytesMut::with_capacity(8192),
            out: BytesMut::with_capacity(1024),
            status: TxStatus::Idle,
            pid: 0,
            broken: false,
        };
        match utopia_async::timeout(timeout, wire.startup(config)).await {
            Err(_) => Err(connect_failure(config, addr, "timeout expired")),
            Ok(Err(Startup::Server(message))) => Err(connect_failure(config, addr, message.trim_end())),
            Ok(Err(Startup::Io(e))) => Err(connect_failure(config, addr, &e.to_string())),
            Ok(Ok(())) => Ok(wire),
        }
    }

    async fn startup(&mut self, config: &Config) -> Result<(), Startup> {
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
                        return Err(Startup::Io(protocol_error(
                            "none of the server's SASL authentication mechanisms are supported",
                        )));
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
                    let mut out = Outcome::new(Status::Fatal);
                    let mut fields = body.fields();
                    while let Some(f) = fields.next()? {
                        out.error.push((f.type_(), Bytes::copy_from_slice(f.value_bytes())));
                    }
                    return Err(Startup::Server(out.server_message()));
                }
                _ => return Err(Startup::Io(protocol_error("unexpected message during startup"))),
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
                    return Err(io::Error::new(io::ErrorKind::UnexpectedEof, CLOSED));
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
    #[allow(dead_code)]
    pub(crate) fn pid(&self) -> i32 {
        self.pid
    }

    /// `PQtransactionStatus()` as of the last `ReadyForQuery`, unknown once
    /// the connection failed.
    pub(crate) fn status(&self) -> TxStatus {
        if self.broken { TxStatus::Unknown } else { self.status }
    }

    /// `PQexec($query)`: runs a query string with the simple query protocol
    /// and returns its last result. The query is cut at its first NUL byte,
    /// as libpq sends C strings.
    pub(crate) async fn exec(&mut self, query: &[u8]) -> Outcome {
        if self.broken {
            return Outcome::client_error("no connection to the server\n");
        }
        let query = match query.iter().position(|b| *b == 0) {
            Some(i) => &query[..i],
            None => query,
        };
        // 'Q', length, the query, NUL.
        self.out.extend_from_slice(b"Q");
        self.out.extend_from_slice(&((query.len() + 5) as i32).to_be_bytes());
        self.out.extend_from_slice(query);
        self.out.extend_from_slice(&[0]);
        if self.flush().await.is_err() {
            self.broken = true;
            return Outcome::client_error(CLOSED);
        }
        match self.receive().await {
            Ok(outcome) => outcome,
            Err(partial) => {
                self.broken = true;
                // libpq appends its own message to the server's last error
                // (a FATAL the server sent before closing).
                let prefix = partial.filter(|o| !o.error.is_empty()).map(|o| o.server_message()).unwrap_or_default();
                Outcome::client_error(format!("{prefix}{CLOSED}"))
            }
        }
    }

    /// Reads one query string's messages up to `ReadyForQuery`, keeping
    /// its last result; on a failure, the last result read so far.
    async fn receive(&mut self) -> Result<Outcome, Option<Outcome>> {
        let mut last: Option<Outcome> = None;
        let mut current: Option<Outcome> = None;
        loop {
            let message = match self.read().await {
                Ok(m) => m,
                Err(_) => return Err(last),
            };
            match message {
                Message::RowDescription(body) => {
                    let mut out = Outcome::new(Status::Tuples);
                    let mut fields = body.fields();
                    while let Ok(Some(f)) = fields.next() {
                        out.columns
                            .push(Column { name: Bytes::copy_from_slice(f.name().as_bytes()), oid: f.type_oid() });
                    }
                    current = Some(out);
                }
                Message::DataRow(body) => {
                    let buffer = body.buffer_bytes().clone();
                    let mut ranges = body.ranges();
                    let mut row = Vec::new();
                    while let Ok(Some(range)) = ranges.next() {
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
                    while let Ok(Some(f)) = fields.next() {
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
                    frontend::copy_fail("COPY from stdin is not supported", &mut self.out).map_err(|_| None)?;
                    self.flush().await.map_err(|_| None)?;
                }
                _ => {}
            }
        }
    }

    /// Sends `Terminate` and closes the socket.
    pub(crate) async fn close(mut self) {
        frontend::terminate(&mut self.out);
        let _ = self.flush().await;
        let _ = self.stream.shutdown().await;
    }
}

/// Why the startup failed.
enum Startup {
    /// The server refused (its `ErrorResponse`, as libpq prints it).
    Server(String),
    /// The connection failed.
    Io(io::Error),
}

impl From<io::Error> for Startup {
    fn from(e: io::Error) -> Self {
        Startup::Io(e)
    }
}

fn status_of(b: u8) -> TxStatus {
    match b {
        b'T' => TxStatus::InTransaction,
        b'E' => TxStatus::Failed,
        _ => TxStatus::Idle,
    }
}
