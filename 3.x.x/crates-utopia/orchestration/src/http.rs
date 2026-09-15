//! Blocking HTTP/1.1 client for the Docker engine (unix socket or TCP).

use crate::error::OrchestrationError;
use std::fmt::Write as FmtWrite;
use std::io::{Read, Write};
use std::net::TcpStream;
use std::os::unix::net::UnixStream;
use std::time::Duration;

#[derive(Debug, Clone)]
pub enum Endpoint {
    Unix {
        socket: String,
        host: String,
    },
    Tcp {
        host: String,
        port: u16,
        scheme_host: String,
    },
}

impl Endpoint {
    #[must_use]
    pub fn unix() -> Self {
        Self::Unix {
            socket: "/var/run/docker.sock".to_string(),
            host: "utopia-php".to_string(),
        }
    }

    pub fn from_base_url(url: &str) -> Result<Self, OrchestrationError> {
        let stripped = url
            .trim_end_matches('/')
            .strip_prefix("http://")
            .or_else(|| url.trim_end_matches('/').strip_prefix("https://"))
            .ok_or_else(|| {
                OrchestrationError::Orchestration(format!("unsupported docker endpoint: {url}"))
            })?;
        let (hostport, _) = stripped.split_once('/').unwrap_or((stripped, ""));
        let (host, port) = if let Some((h, p)) = hostport.rsplit_once(':') {
            (
                h.to_string(),
                p.parse::<u16>().map_err(|_| {
                    OrchestrationError::Orchestration("invalid docker endpoint port".into())
                })?,
            )
        } else {
            (hostport.to_string(), 80)
        };
        Ok(Self::Tcp {
            scheme_host: hostport.to_string(),
            host,
            port,
        })
    }
}

#[derive(Debug, Clone)]
pub struct HttpResponse {
    pub code: u16,
    pub body: Vec<u8>,
}

pub fn call(
    endpoint: &Endpoint,
    method: &str,
    path: &str,
    body: Option<&[u8]>,
    extra_headers: &[(&str, &str)],
    timeout_secs: i64,
) -> Result<HttpResponse, OrchestrationError> {
    let mut stream = connect(endpoint, timeout_secs)?;
    let host = match endpoint {
        Endpoint::Unix { host, .. } => host.as_str(),
        Endpoint::Tcp { scheme_host, .. } => scheme_host.as_str(),
    };
    let mut request = format!("{method} {path} HTTP/1.1\r\nHost: {host}\r\n");
    for (name, value) in extra_headers {
        let _ = write!(request, "{name}: {value}\r\n");
    }
    if let Some(body) = body {
        if !extra_headers
            .iter()
            .any(|(n, _)| n.eq_ignore_ascii_case("content-length"))
        {
            let _ = write!(request, "Content-Length: {}\r\n", body.len());
        }
    } else if method == "POST" || method == "DELETE" {
        request.push_str("Content-Length: 0\r\n");
    }
    request.push_str("Connection: close\r\n\r\n");
    stream
        .write_all(request.as_bytes())
        .map_err(|e| OrchestrationError::Orchestration(format!("Curl Error: {e}")))?;
    if let Some(body) = body {
        stream
            .write_all(body)
            .map_err(|e| OrchestrationError::Orchestration(format!("Curl Error: {e}")))?;
    }
    read_response(&mut stream, timeout_secs)
}

fn connect(endpoint: &Endpoint, timeout_secs: i64) -> Result<Box<dyn Stream>, OrchestrationError> {
    match endpoint {
        Endpoint::Unix { socket, .. } => {
            let stream = UnixStream::connect(socket)
                .map_err(|e| OrchestrationError::Orchestration(format!("Curl Error: {e}")))?;
            apply_timeout(&stream, timeout_secs)?;
            Ok(Box::new(stream))
        }
        Endpoint::Tcp { host, port, .. } => {
            let stream = TcpStream::connect((host.as_str(), *port))
                .map_err(|e| OrchestrationError::Orchestration(format!("Curl Error: {e}")))?;
            apply_timeout(&stream, timeout_secs)?;
            Ok(Box::new(stream))
        }
    }
}

fn apply_timeout<S: SetTimeout>(stream: &S, timeout_secs: i64) -> Result<(), OrchestrationError> {
    if timeout_secs > 0 {
        let duration = Duration::from_secs(timeout_secs as u64);
        stream
            .set_rw_timeout(Some(duration))
            .map_err(|e| OrchestrationError::Orchestration(format!("Curl Error: {e}")))?;
    }
    Ok(())
}

trait SetTimeout {
    fn set_rw_timeout(&self, timeout: Option<Duration>) -> std::io::Result<()>;
}

impl SetTimeout for UnixStream {
    fn set_rw_timeout(&self, timeout: Option<Duration>) -> std::io::Result<()> {
        self.set_read_timeout(timeout)?;
        self.set_write_timeout(timeout)
    }
}

impl SetTimeout for TcpStream {
    fn set_rw_timeout(&self, timeout: Option<Duration>) -> std::io::Result<()> {
        self.set_read_timeout(timeout)?;
        self.set_write_timeout(timeout)
    }
}

trait Stream: Read + Write {}
impl Stream for UnixStream {}
impl Stream for TcpStream {}

fn read_response(
    stream: &mut dyn Read,
    timeout_secs: i64,
) -> Result<HttpResponse, OrchestrationError> {
    let mut buf = Vec::new();
    let mut tmp = [0u8; 4096];
    loop {
        match stream.read(&mut tmp) {
            Ok(0) => break,
            Ok(n) => buf.extend_from_slice(&tmp[..n]),
            Err(e) if e.kind() == std::io::ErrorKind::TimedOut => {
                if timeout_secs > 0 {
                    return Err(OrchestrationError::Timeout(format!("Curl Error: {e}")));
                }
                break;
            }
            Err(e) => {
                return Err(OrchestrationError::Orchestration(format!(
                    "Curl Error: {e}"
                )));
            }
        }
        if buf.windows(4).any(|w| w == b"\r\n\r\n") {
            break;
        }
    }
    let header_end = buf
        .windows(4)
        .position(|w| w == b"\r\n\r\n")
        .ok_or_else(|| OrchestrationError::Orchestration("Curl Error: empty response".into()))?
        + 4;
    let headers = String::from_utf8_lossy(&buf[..header_end]);
    let mut lines = headers.split("\r\n");
    let status = lines.next().unwrap_or("");
    let code = status
        .split_whitespace()
        .nth(1)
        .and_then(|c| c.parse().ok())
        .unwrap_or(0);
    let mut content_length: Option<usize> = None;
    let mut chunked = false;
    for line in lines {
        if let Some((name, value)) = line.split_once(':') {
            if name.eq_ignore_ascii_case("content-length") {
                content_length = value.trim().parse().ok();
            }
            if name.eq_ignore_ascii_case("transfer-encoding")
                && value.to_ascii_lowercase().contains("chunked")
            {
                chunked = true;
            }
        }
    }
    let mut body = buf[header_end..].to_vec();
    if let Some(len) = content_length {
        while body.len() < len {
            let n = stream.read(&mut tmp).unwrap_or(0);
            if n == 0 {
                break;
            }
            body.extend_from_slice(&tmp[..n]);
        }
        body.truncate(len);
    } else {
        loop {
            match stream.read(&mut tmp) {
                Ok(n) if n > 0 => body.extend_from_slice(&tmp[..n]),
                _ => break,
            }
        }
        if chunked {
            body = decode_chunked(&body).unwrap_or(body);
        }
    }
    Ok(HttpResponse { code, body })
}

fn decode_chunked(input: &[u8]) -> Option<Vec<u8>> {
    let mut out = Vec::new();
    let mut i = 0;
    while i < input.len() {
        let rest = std::str::from_utf8(&input[i..]).ok()?;
        let line_end = rest.find("\r\n")?;
        let size = usize::from_str_radix(rest[..line_end].trim(), 16).ok()?;
        i += line_end + 2;
        if size == 0 {
            break;
        }
        if i + size > input.len() {
            return None;
        }
        out.extend_from_slice(&input[i..i + size]);
        i += size;
        if i + 2 <= input.len() && &input[i..i + 2] == b"\r\n" {
            i += 2;
        }
    }
    Some(out)
}

/// PHP Docker multiplexed attach stream parser.
pub fn parse_docker_stream(input: &[u8]) -> (String, String) {
    let mut stdout = String::new();
    let mut stderr = String::new();
    let mut i = 0;
    while i + 8 <= input.len() {
        let stream_type = input[i];
        let size =
            u32::from_be_bytes([input[i + 4], input[i + 5], input[i + 6], input[i + 7]]) as usize;
        i += 8;
        if i + size > input.len() {
            let chunk = String::from_utf8_lossy(&input[i..]);
            if stream_type == 1 {
                stdout.push_str(&chunk);
            } else {
                stderr.push_str(&chunk);
            }
            break;
        }
        let chunk = String::from_utf8_lossy(&input[i..i + size]);
        if stream_type == 1 {
            stdout.push_str(&chunk);
        } else {
            stderr.push_str(&chunk);
        }
        i += size;
    }
    (stdout, stderr)
}
