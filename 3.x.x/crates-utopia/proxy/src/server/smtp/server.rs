//! SMTP proxy server. Tokio port of `src/Server/SMTP/Swoole.php`.

use std::io;
use std::sync::Arc;

use tokio::io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader};
use tokio::net::{TcpListener, TcpStream};
use tokio::sync::Mutex;
use tokio::time::timeout;
use tracing::{debug, error, info, warn};

use crate::adapter::Adapter;
use crate::protocol::Protocol;
use crate::resolver::Resolver;

use super::config::SmtpConfig;
use super::connection::{Connection, SmtpState};
use utopia_console::Console;

/// Initial banner sent to every client on accept.
const GREETING: &[u8] = b"220 utopia-php.io ESMTP Proxy\r\n";
/// Leading bytes of any valid SMTP success greeting from the backend.
const GREETING_CODE: &[u8] = b"220";
/// SMTP "begin data input" reply code.
const DATA_READY_CODE: &[u8] = b"354";
/// 500-series error sent when the client issues a command we do not recognise.
const ERROR_UNKNOWN_COMMAND: &[u8] = b"500 Unknown command\r\n";
/// 501-series error for malformed EHLO/HELO lines.
const ERROR_SYNTAX: &[u8] = b"501 Syntax error\r\n";
/// 421 - backend unavailable, connection will be closed.
const ERROR_UNAVAILABLE: &[u8] = b"421 Service not available\r\n";
/// Single dot terminator that ends DATA mode.
const DATA_TERMINATOR: &str = ".";
/// Backend-side recv buffer size (matches PHP `RECV_BUFFER = 8192`).
const RECV_BUFFER: usize = 8192;
/// Maximum client line length (matches PHP `PACKAGE_MAX_LENGTH = 10MB`).
const PACKAGE_MAX_LENGTH: usize = 10 * 1024 * 1024;

/// SMTP proxy server. Accepts client connections and brokers an SMTP session
/// against the backend returned by the resolver for the EHLO/HELO domain.
pub struct SmtpServer {
    resolver: Arc<dyn Resolver>,
    config: SmtpConfig,
}

impl std::fmt::Debug for SmtpServer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("SmtpServer")
            .field("config", &self.config)
            .finish_non_exhaustive()
    }
}

impl SmtpServer {
    pub fn new(resolver: Arc<dyn Resolver>, config: SmtpConfig) -> Self {
        Self { resolver, config }
    }

    /// Bind the configured address and accept connections forever.
    pub async fn start(self) -> io::Result<()> {
        let listener = TcpListener::bind((self.config.host.as_str(), self.config.port)).await?;
        let local = listener.local_addr()?;
        let _ = Console::success(&format!(
            "SMTP Proxy Server started at {}:{}",
            self.config.host, self.config.port
        ));
        info!(address = %local, "SMTP proxy listening");

        let adapter = Arc::new(Self::build_adapter(self.resolver.clone(), &self.config).await);
        let config = Arc::new(self.config);

        loop {
            let (stream, peer) = match listener.accept().await {
                Ok(pair) => pair,
                Err(error) => {
                    error!(%error, "SMTP accept failed");
                    continue;
                }
            };

            if let Err(error) = stream.set_nodelay(true) {
                warn!(%error, "failed to set TCP_NODELAY on SMTP client socket");
            }

            let adapter = adapter.clone();
            let config = config.clone();

            tokio::spawn(async move {
                debug!(%peer, "SMTP client connected");
                if let Err(error) = handle_client(stream, adapter, config).await {
                    debug!(%peer, %error, "SMTP client session ended with error");
                }
                debug!(%peer, "SMTP client disconnected");
            });
        }
    }

    async fn build_adapter(resolver: Arc<dyn Resolver>, config: &SmtpConfig) -> Adapter {
        let adapter = Adapter::new(Some(resolver), Protocol::Smtp);
        adapter.set_cache_ttl(config.cache_ttl).await;
        if config.skip_validation {
            adapter.set_skip_validation(true).await;
        }
        adapter
    }
}

/// Handle a single client session end-to-end.
async fn handle_client(
    stream: TcpStream,
    adapter: Arc<Adapter>,
    config: Arc<SmtpConfig>,
) -> io::Result<()> {
    let (read_half, mut write_half) = stream.into_split();
    let mut reader = BufReader::with_capacity(RECV_BUFFER, read_half);

    write_half.write_all(GREETING).await?;
    write_half.flush().await?;

    let mut connection = Connection::new();
    let mut line = Vec::with_capacity(RECV_BUFFER);

    loop {
        line.clear();
        let read = match read_line(&mut reader, &mut line).await {
            Ok(0) => return Ok(()),
            Ok(n) => n,
            Err(error) => return Err(error),
        };

        if read > PACKAGE_MAX_LENGTH {
            let _ = write_half.write_all(ERROR_UNAVAILABLE).await;
            return Ok(());
        }

        if connection.is_data() {
            if let Err(error) = forward_data(&mut write_half, &line, &mut connection, &config).await
            {
                warn!(%error, "SMTP data forwarding failed");
                let _ = write_half.write_all(ERROR_UNAVAILABLE).await;
                return Ok(());
            }
            continue;
        }

        let command = command_token(&line);
        match command.as_str() {
            "EHLO" | "HELO" => {
                if let Err(error) =
                    handle_helo(&mut write_half, &line, &mut connection, &adapter, &config).await
                {
                    warn!(%error, "SMTP HELO handling failed");
                    let _ = write_half.write_all(ERROR_UNAVAILABLE).await;
                    return Ok(());
                }
            }
            "MAIL" | "RCPT" | "DATA" | "RSET" | "NOOP" | "QUIT" => {
                if let Err(error) =
                    forward_command(&mut write_half, &line, &mut connection, &config).await
                {
                    warn!(%error, "SMTP command forwarding failed");
                    let _ = write_half.write_all(ERROR_UNAVAILABLE).await;
                    return Ok(());
                }
                if command == "QUIT" {
                    return Ok(());
                }
            }
            _ => {
                write_half.write_all(ERROR_UNKNOWN_COMMAND).await?;
                write_half.flush().await?;
            }
        }
    }
}

/// Read a CRLF-terminated line (or until EOF) into `buffer`. Returns bytes read.
async fn read_line<R>(reader: &mut R, buffer: &mut Vec<u8>) -> io::Result<usize>
where
    R: AsyncBufReadExt + Unpin,
{
    reader.read_until(b'\n', buffer).await
}

/// Return the uppercased first 4-character command token from a raw line, e.g. "MAIL".
fn command_token(line: &[u8]) -> String {
    let trimmed = trim_ascii(line);
    let take = trimmed.len().min(4);
    std::str::from_utf8(&trimmed[..take])
        .unwrap_or("")
        .to_ascii_uppercase()
}

/// Trim leading/trailing ASCII whitespace (including CR/LF) from a byte slice.
fn trim_ascii(bytes: &[u8]) -> &[u8] {
    let mut start = 0;
    let mut end = bytes.len();
    while start < end && bytes[start].is_ascii_whitespace() {
        start += 1;
    }
    while end > start && bytes[end - 1].is_ascii_whitespace() {
        end -= 1;
    }
    &bytes[start..end]
}

/// Extract the domain argument from an EHLO/HELO line (`EHLO example.com\r\n` → `example.com`).
fn parse_helo_domain(line: &[u8]) -> Option<&str> {
    let trimmed = trim_ascii(line);
    let as_str = std::str::from_utf8(trimmed).ok()?;
    let mut parts = as_str.splitn(2, char::is_whitespace);
    let _verb = parts.next()?;
    let rest = parts.next()?.trim();
    let domain = rest.split_whitespace().next()?;
    if domain.is_empty() {
        None
    } else {
        Some(domain)
    }
}

/// Handle EHLO/HELO: extract domain, route via adapter, open backend connection,
/// then forward the HELO command to the backend and relay its response.
async fn handle_helo<W>(
    writer: &mut W,
    line: &[u8],
    connection: &mut Connection,
    adapter: &Arc<Adapter>,
    config: &SmtpConfig,
) -> io::Result<()>
where
    W: AsyncWriteExt + Unpin,
{
    let Some(domain) = parse_helo_domain(line) else {
        writer.write_all(ERROR_SYNTAX).await?;
        writer.flush().await?;
        return Ok(());
    };
    connection.domain = Some(domain.to_string());

    let routed = adapter
        .route(domain.as_bytes())
        .await
        .map_err(|error| io::Error::other(format!("resolver error: {error}")))?;

    let backend = connect_to_backend(routed.endpoint(), config).await?;
    connection.backend = Some(backend);

    forward_command(writer, line, connection, config).await
}

/// Open a TCP connection to the backend, read its 220 greeting, wrap in `Arc<Mutex>`.
async fn connect_to_backend(
    endpoint: &str,
    config: &SmtpConfig,
) -> io::Result<Arc<Mutex<TcpStream>>> {
    let (host, port) = Adapter::parse_endpoint(endpoint, SmtpConfig::DEFAULT_PORT);

    let stream = timeout(config.connect_timeout, TcpStream::connect((host, port)))
        .await
        .map_err(|_| io::Error::new(io::ErrorKind::TimedOut, "backend connect timeout"))??;

    let _ = stream.set_nodelay(true);

    let mut stream = stream;
    let mut buffer = vec![0u8; RECV_BUFFER];
    let read = timeout(config.timeout, stream.read(&mut buffer))
        .await
        .map_err(|_| io::Error::new(io::ErrorKind::TimedOut, "backend greeting timeout"))??;

    if read == 0 {
        return Err(io::Error::new(
            io::ErrorKind::UnexpectedEof,
            "backend closed before greeting",
        ));
    }

    let greeting = trim_ascii(&buffer[..read]);
    if !greeting.starts_with(GREETING_CODE) {
        return Err(io::Error::other(format!(
            "backend SMTP greeting failed: {}",
            String::from_utf8_lossy(greeting)
        )));
    }

    Ok(Arc::new(Mutex::new(stream)))
}

/// Forward a command line to the backend, read its response, relay to the client.
/// If the command is exactly `DATA` and the backend replies `354`, flip to `Data` mode.
async fn forward_command<W>(
    writer: &mut W,
    line: &[u8],
    connection: &mut Connection,
    config: &SmtpConfig,
) -> io::Result<()>
where
    W: AsyncWriteExt + Unpin,
{
    let backend = connection
        .backend
        .as_ref()
        .ok_or_else(|| io::Error::other("no backend connection"))?
        .clone();

    let is_data_command = is_data_only_line(line);

    let mut guard = backend.lock().await;

    timeout(config.timeout, guard.write_all(line))
        .await
        .map_err(|_| io::Error::new(io::ErrorKind::TimedOut, "backend write timeout"))??;
    timeout(config.timeout, guard.flush())
        .await
        .map_err(|_| io::Error::new(io::ErrorKind::TimedOut, "backend flush timeout"))??;

    let mut buffer = vec![0u8; RECV_BUFFER];
    let read = timeout(config.timeout, guard.read(&mut buffer))
        .await
        .map_err(|_| io::Error::new(io::ErrorKind::TimedOut, "backend read timeout"))??;

    drop(guard);

    if read == 0 {
        return Err(io::Error::new(
            io::ErrorKind::UnexpectedEof,
            "backend closed unexpectedly",
        ));
    }

    let response = &buffer[..read];
    writer.write_all(response).await?;
    writer.flush().await?;

    if is_data_command && response.starts_with(DATA_READY_CODE) {
        connection.state = SmtpState::Data;
    }

    Ok(())
}

/// Forward a DATA-mode line to the backend verbatim. On the dot terminator,
/// read the backend's final response and flip back to `Command` mode.
async fn forward_data<W>(
    writer: &mut W,
    line: &[u8],
    connection: &mut Connection,
    config: &SmtpConfig,
) -> io::Result<()>
where
    W: AsyncWriteExt + Unpin,
{
    let backend = connection
        .backend
        .as_ref()
        .ok_or_else(|| io::Error::other("no backend connection"))?
        .clone();

    let mut guard = backend.lock().await;

    timeout(config.timeout, guard.write_all(line))
        .await
        .map_err(|_| io::Error::new(io::ErrorKind::TimedOut, "backend write timeout"))??;
    timeout(config.timeout, guard.flush())
        .await
        .map_err(|_| io::Error::new(io::ErrorKind::TimedOut, "backend flush timeout"))??;

    let trimmed = trim_ascii(line);
    if trimmed == DATA_TERMINATOR.as_bytes() {
        let mut buffer = vec![0u8; RECV_BUFFER];
        let read = timeout(config.timeout, guard.read(&mut buffer))
            .await
            .map_err(|_| io::Error::new(io::ErrorKind::TimedOut, "backend read timeout"))??;
        drop(guard);

        connection.state = SmtpState::Command;

        if read > 0 {
            writer.write_all(&buffer[..read]).await?;
            writer.flush().await?;
        }
    }

    Ok(())
}

/// True if `line` trimmed of whitespace is exactly `DATA` (case-insensitive).
fn is_data_only_line(line: &[u8]) -> bool {
    let trimmed = trim_ascii(line);
    trimmed.len() == 4 && trimmed.eq_ignore_ascii_case(b"DATA")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn command_token_uppercases_and_trims() {
        assert_eq!(command_token(b"ehlo test.com\r\n"), "EHLO");
        assert_eq!(command_token(b"  mail FROM:<a@b>\r\n"), "MAIL");
        assert_eq!(command_token(b"quit\r\n"), "QUIT");
        assert_eq!(command_token(b"\r\n"), "");
    }

    #[test]
    fn parse_helo_domain_extracts_argument() {
        assert_eq!(
            parse_helo_domain(b"EHLO example.com\r\n"),
            Some("example.com")
        );
        assert_eq!(parse_helo_domain(b"HELO  foo.bar\r\n"), Some("foo.bar"));
        assert_eq!(parse_helo_domain(b"EHLO\r\n"), None);
        assert_eq!(parse_helo_domain(b"EHLO \r\n"), None);
    }

    #[test]
    fn is_data_only_line_matches_exact() {
        assert!(is_data_only_line(b"DATA\r\n"));
        assert!(is_data_only_line(b"data\n"));
        assert!(is_data_only_line(b"  DATA  "));
        assert!(!is_data_only_line(b"DATA payload\r\n"));
        assert!(!is_data_only_line(b"MAIL\r\n"));
    }
}
