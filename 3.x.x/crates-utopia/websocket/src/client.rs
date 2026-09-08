use crate::error::WebsocketError;
use crate::protocol::{
    client_upgrade_request, decode_frame, encode_frame, generate_key, validate_accept,
    OPCODE_CLOSE, OPCODE_PING, OPCODE_PONG, OPCODE_TEXT,
};
use std::collections::HashMap;
use std::io::{Read, Write};
use std::net::{TcpStream, ToSocketAddrs};
use std::time::Duration;

/// PHP `Utopia\WebSocket\Client`.
pub struct Client {
    host: String,
    port: u16,
    path: String,
    headers: Vec<(String, String)>,
    timeout: Duration,
    connected: bool,
    stream: Option<TcpStream>,
    read_buf: Vec<u8>,
    on_message: Option<Box<dyn Fn(String) + Send>>,
    on_close: Option<Box<dyn Fn() + Send>>,
    on_error: Option<Box<dyn Fn(WebsocketError) + Send>>,
    on_open: Option<Box<dyn Fn() + Send>>,
    on_ping: Option<Box<dyn Fn(Vec<u8>) + Send>>,
    on_pong: Option<Box<dyn Fn(Vec<u8>) + Send>>,
}

impl std::fmt::Debug for Client {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Client")
            .field("host", &self.host)
            .field("port", &self.port)
            .field("path", &self.path)
            .field("connected", &self.connected)
            .finish_non_exhaustive()
    }
}

impl Client {
    /// PHP `__construct(string $url, array $options = [])`.
    pub fn new(
        url: impl AsRef<str>,
        headers: HashMap<String, String>,
        timeout_secs: f64,
    ) -> Result<Self, WebsocketError> {
        let parsed = parse_ws_url(url.as_ref())?;
        Ok(Self {
            host: parsed.0,
            port: parsed.1,
            path: parsed.2,
            headers: headers.into_iter().collect(),
            timeout: Duration::from_secs_f64(timeout_secs.max(0.0)),
            connected: false,
            stream: None,
            read_buf: Vec::new(),
            on_message: None,
            on_close: None,
            on_error: None,
            on_open: None,
            on_ping: None,
            on_pong: None,
        })
    }

    /// Construct with PHP defaults (`timeout` 30, no headers).
    pub fn from_url(url: impl AsRef<str>) -> Result<Self, WebsocketError> {
        Self::new(url, HashMap::new(), 30.0)
    }

    /// PHP `connect()`.
    pub fn connect(&mut self) -> Result<(), WebsocketError> {
        let addr = (self.host.as_str(), self.port)
            .to_socket_addrs()
            .map_err(|e| WebsocketError::ConnectFailed {
                code: e.raw_os_error().unwrap_or(0),
                message: e.to_string(),
            })?
            .next()
            .ok_or_else(|| WebsocketError::ConnectFailed {
                code: 0,
                message: "could not resolve host".to_string(),
            })?;
        let mut stream = TcpStream::connect_timeout(&addr, self.timeout).map_err(|e| {
            let err = WebsocketError::ConnectFailed {
                code: e.raw_os_error().unwrap_or(0),
                message: e.to_string(),
            };
            self.emit_error(&err);
            err
        })?;
        stream.set_read_timeout(Some(self.timeout))?;
        stream.set_write_timeout(Some(self.timeout))?;

        let key = generate_key();
        let req = client_upgrade_request(&self.host, self.port, &self.path, &key, &self.headers);
        stream
            .write_all(req.as_bytes())
            .map_err(|e| WebsocketError::ConnectFailed {
                code: e.raw_os_error().unwrap_or(0),
                message: e.to_string(),
            })?;

        let mut buf = Vec::new();
        let mut tmp = [0u8; 1024];
        loop {
            let n = stream
                .read(&mut tmp)
                .map_err(|e| WebsocketError::ConnectFailed {
                    code: e.raw_os_error().unwrap_or(0),
                    message: e.to_string(),
                })?;
            if n == 0 {
                break;
            }
            buf.extend_from_slice(&tmp[..n]);
            if buf.windows(4).any(|w| w == b"\r\n\r\n") {
                break;
            }
        }
        let text = String::from_utf8_lossy(&buf);
        if let Some(idx) = text.find("\r\n\r\n") {
            validate_accept(&text[..=idx + 3], &key)
                .map_err(|message| WebsocketError::ConnectFailed { code: 0, message })?;
            self.read_buf = buf[idx + 4..].to_vec();
        } else {
            return Err(WebsocketError::ConnectFailed {
                code: 0,
                message: "incomplete handshake".to_string(),
            });
        }

        self.stream = Some(stream);
        self.connected = true;
        if let Some(cb) = &self.on_open {
            cb();
        }
        Ok(())
    }

    /// PHP `listen()`.
    pub fn listen(&mut self) {
        while self.connected {
            match self.read_frame() {
                Ok(Some((opcode, payload))) => self.handle_frame(opcode, payload),
                Ok(None) => {}
                Err(error) => {
                    self.emit_error(&error);
                    self.handle_close();
                    break;
                }
            }
        }
    }

    /// PHP `send(string $data)`.
    pub fn send(&mut self, data: &str) -> Result<(), WebsocketError> {
        if !self.connected {
            return Err(WebsocketError::NotConnected);
        }
        let frame = encode_frame(OPCODE_TEXT, data.as_bytes(), true);
        match self.stream.as_mut() {
            Some(stream) => stream.write_all(&frame).map_err(|e| {
                let err = WebsocketError::SendFailed {
                    code: e.raw_os_error().unwrap_or(0),
                    message: e.to_string(),
                };
                self.emit_error(&err);
                err
            }),
            None => Err(WebsocketError::NotConnected),
        }
    }

    /// PHP `receive(): ?string`.
    pub fn receive(&mut self) -> Result<Option<String>, WebsocketError> {
        if !self.connected {
            return Err(WebsocketError::NotConnected);
        }
        match self.read_frame() {
            Ok(Some((opcode, payload))) => {
                self.handle_frame(opcode, payload.clone());
                if opcode == OPCODE_TEXT {
                    Ok(String::from_utf8(payload).ok())
                } else {
                    Ok(None)
                }
            }
            Ok(None) => Ok(None),
            Err(error) => Err(error),
        }
    }

    /// PHP `close()`.
    pub fn close(&mut self) {
        self.handle_close();
    }

    /// PHP `isConnected()`.
    #[must_use]
    pub fn is_connected(&self) -> bool {
        self.connected
    }

    /// PHP `onMessage`.
    pub fn on_message(&mut self, callback: impl Fn(String) + Send + 'static) -> &mut Self {
        self.on_message = Some(Box::new(callback));
        self
    }

    /// PHP `onClose`.
    pub fn on_close(&mut self, callback: impl Fn() + Send + 'static) -> &mut Self {
        self.on_close = Some(Box::new(callback));
        self
    }

    /// PHP `onError`.
    pub fn on_error(&mut self, callback: impl Fn(WebsocketError) + Send + 'static) -> &mut Self {
        self.on_error = Some(Box::new(callback));
        self
    }

    /// PHP `onOpen`.
    pub fn on_open(&mut self, callback: impl Fn() + Send + 'static) -> &mut Self {
        self.on_open = Some(Box::new(callback));
        self
    }

    /// PHP `onPing`.
    pub fn on_ping(&mut self, callback: impl Fn(Vec<u8>) + Send + 'static) -> &mut Self {
        self.on_ping = Some(Box::new(callback));
        self
    }

    /// PHP `onPong`.
    pub fn on_pong(&mut self, callback: impl Fn(Vec<u8>) + Send + 'static) -> &mut Self {
        self.on_pong = Some(Box::new(callback));
        self
    }

    fn read_frame(&mut self) -> Result<Option<(u8, Vec<u8>)>, WebsocketError> {
        let max_len = 32 * 1024 * 1024;
        loop {
            match decode_frame(&self.read_buf, max_len) {
                Ok(Some((frame, used))) => {
                    self.read_buf.drain(..used);
                    return Ok(Some((frame.opcode, frame.payload)));
                }
                Ok(None) => {
                    let stream = self.stream.as_mut().ok_or(WebsocketError::NotConnected)?;
                    let mut tmp = [0u8; 4096];
                    match stream.read(&mut tmp) {
                        Ok(0) => {
                            self.handle_close();
                            return Ok(None);
                        }
                        Ok(n) => self.read_buf.extend_from_slice(&tmp[..n]),
                        Err(error)
                            if error.kind() == std::io::ErrorKind::WouldBlock
                                || error.kind() == std::io::ErrorKind::TimedOut =>
                        {
                            return Ok(None);
                        }
                        Err(error) => {
                            return Err(WebsocketError::ReceiveFailed {
                                code: error.raw_os_error().unwrap_or(0),
                                message: error.to_string(),
                            });
                        }
                    }
                }
                Err(message) => {
                    return Err(WebsocketError::ReceiveFailed { code: 0, message });
                }
            }
        }
    }

    fn handle_frame(&mut self, opcode: u8, payload: Vec<u8>) {
        match opcode {
            OPCODE_TEXT => {
                if let (Some(cb), Ok(text)) = (&self.on_message, String::from_utf8(payload)) {
                    cb(text);
                }
            }
            OPCODE_CLOSE => self.handle_close(),
            OPCODE_PING => {
                if let Some(cb) = &self.on_ping {
                    cb(payload.clone());
                }
                if let Some(stream) = self.stream.as_mut() {
                    let _ = stream.write_all(&encode_frame(OPCODE_PONG, &[], true));
                }
            }
            OPCODE_PONG => {
                if let Some(cb) = &self.on_pong {
                    cb(payload);
                }
            }
            _ => {}
        }
    }

    fn handle_close(&mut self) {
        if self.connected {
            self.connected = false;
            if let Some(cb) = &self.on_close {
                cb();
            }
            if let Some(stream) = self.stream.take() {
                let _ = stream.shutdown(std::net::Shutdown::Both);
            }
        }
    }

    fn emit_error(&self, error: &WebsocketError) {
        if let Some(cb) = &self.on_error {
            cb(error.clone());
        }
    }
}

fn parse_ws_url(url: &str) -> Result<(String, u16, String), WebsocketError> {
    let (scheme, rest) = url.split_once("://").ok_or(WebsocketError::MissingHost)?;
    if scheme != "ws" && scheme != "wss" {
        return Err(WebsocketError::InvalidUrl);
    }
    let (authority, path) = match rest.split_once('/') {
        Some((auth, p)) => (auth, format!("/{p}")),
        None => (rest, "/".to_string()),
    };
    if authority.is_empty() {
        return Err(WebsocketError::MissingHost);
    }
    let (host, port) = if let Some(stripped) = authority.strip_prefix('[') {
        let (host, rest) = stripped.split_once(']').ok_or(WebsocketError::InvalidUrl)?;
        let port = if let Some(p) = rest.strip_prefix(':') {
            p.parse().map_err(|_| WebsocketError::InvalidUrl)?
        } else if scheme == "wss" {
            443
        } else {
            80
        };
        (host.to_string(), port)
    } else if let Some((host, port)) = authority.rsplit_once(':') {
        (
            host.to_string(),
            port.parse().map_err(|_| WebsocketError::InvalidUrl)?,
        )
    } else {
        (
            authority.to_string(),
            if scheme == "wss" { 443 } else { 80 },
        )
    };
    if host.is_empty() {
        return Err(WebsocketError::MissingHost);
    }
    Ok((host, port, path))
}
