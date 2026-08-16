//! RFC 6455 handshake and frame encode/decode.

use base64::engine::general_purpose::STANDARD;
use base64::Engine;
use rand::Rng;
use sha1::{Digest, Sha1};
use std::fmt::Write;

pub const OPCODE_TEXT: u8 = 0x1;
pub const OPCODE_CLOSE: u8 = 0x8;
pub const OPCODE_PING: u8 = 0x9;
pub const OPCODE_PONG: u8 = 0xA;

const MAGIC: &[u8] = b"258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

/// Generate a `Sec-WebSocket-Key`.
#[must_use]
pub fn generate_key() -> String {
    let bytes: [u8; 16] = rand::thread_rng().gen();
    STANDARD.encode(bytes)
}

/// `Sec-WebSocket-Accept` for a client key.
#[must_use]
pub fn accept_key(key: &str) -> String {
    let mut hasher = Sha1::new();
    hasher.update(key.as_bytes());
    hasher.update(MAGIC);
    STANDARD.encode(hasher.finalize())
}

/// Encode a WebSocket frame. Client frames must be masked.
#[must_use]
pub fn encode_frame(opcode: u8, payload: &[u8], mask: bool) -> Vec<u8> {
    let mut frame = Vec::with_capacity(14 + payload.len());
    frame.push(0x80 | (opcode & 0x0F));
    let len = payload.len();
    if len < 126 {
        frame.push(if mask { 0x80 } else { 0 } | u8::try_from(len).unwrap_or(0));
    } else if len <= 65535 {
        frame.push(if mask { 0x80 } else { 0 } | 126);
        frame.extend_from_slice(&u16::try_from(len).unwrap_or(0).to_be_bytes());
    } else {
        frame.push(if mask { 0x80 } else { 0 } | 127);
        frame.extend_from_slice(&(len as u64).to_be_bytes());
    }
    if mask {
        let key: [u8; 4] = rand::thread_rng().gen();
        frame.extend_from_slice(&key);
        frame.extend(payload.iter().enumerate().map(|(i, b)| b ^ key[i % 4]));
    } else {
        frame.extend_from_slice(payload);
    }
    frame
}

/// A decoded WebSocket frame.
#[derive(Debug, Clone)]
pub struct Frame {
    pub opcode: u8,
    pub payload: Vec<u8>,
}

/// Decode one frame from a buffer. Returns `(frame, bytes_consumed)` or `None` if incomplete.
pub fn decode_frame(buffer: &[u8], max_len: usize) -> Result<Option<(Frame, usize)>, String> {
    if buffer.len() < 2 {
        return Ok(None);
    }
    let opcode = buffer[0] & 0x0F;
    let masked = buffer[1] & 0x80 != 0;
    let mut len = usize::from(buffer[1] & 0x7F);
    let mut offset = 2usize;
    if len == 126 {
        if buffer.len() < 4 {
            return Ok(None);
        }
        len = usize::from(u16::from_be_bytes([buffer[2], buffer[3]]));
        offset = 4;
    } else if len == 127 {
        if buffer.len() < 10 {
            return Ok(None);
        }
        let n = u64::from_be_bytes(buffer[2..10].try_into().unwrap_or([0; 8]));
        len = n as usize;
        offset = 10;
    }
    if len > max_len {
        return Err(format!("frame exceeds max length ({len} > {max_len})"));
    }
    if masked {
        offset += 4;
    }
    if buffer.len() < offset + len {
        return Ok(None);
    }
    let mut payload = buffer[offset..offset + len].to_vec();
    if masked {
        let key = &buffer[offset - 4..offset];
        for (i, byte) in payload.iter_mut().enumerate() {
            *byte ^= key[i % 4];
        }
    }
    Ok(Some((Frame { opcode, payload }, offset + len)))
}

/// Build a client HTTP upgrade request.
#[must_use]
pub fn client_upgrade_request(
    host: &str,
    port: u16,
    path: &str,
    key: &str,
    headers: &[(String, String)],
) -> String {
    let host_header = if port == 80 || port == 443 {
        host.to_string()
    } else {
        format!("{host}:{port}")
    };
    let mut req = format!(
        "GET {path} HTTP/1.1\r\nHost: {host_header}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n"
    );
    for (name, value) in headers {
        let _ = write!(req, "{name}: {value}\r\n");
    }
    req.push_str("\r\n");
    req
}

/// Validate a server upgrade response.
pub fn validate_accept(response: &str, key: &str) -> Result<(), String> {
    let expected = accept_key(key);
    let mut status_ok = false;
    let mut accept_ok = false;
    for (i, line) in response.split("\r\n").enumerate() {
        if i == 0 {
            status_ok = line.contains("101");
            continue;
        }
        if let Some((name, value)) = line.split_once(':') {
            if name.eq_ignore_ascii_case("sec-websocket-accept") && value.trim() == expected {
                accept_ok = true;
            }
        }
    }
    if status_ok && accept_ok {
        Ok(())
    } else {
        Err("invalid handshake response".to_string())
    }
}

/// Parsed HTTP request: method, path, headers, and byte offset past `\r\n\r\n`.
pub type ParsedHttpRequest = (String, String, Vec<(String, String)>, usize);

/// Parse an HTTP request from bytes (headers only). Returns `(method, path, headers, header_end)`.
#[must_use]
pub fn parse_http_request(data: &[u8]) -> Option<ParsedHttpRequest> {
    let text = std::str::from_utf8(data).ok()?;
    let header_end = text.find("\r\n\r\n")? + 4;
    let headers_block = &text[..header_end - 4];
    let mut lines = headers_block.split("\r\n");
    let request_line = lines.next()?;
    let mut parts = request_line.split_whitespace();
    let method = parts.next()?.to_string();
    let path = parts.next()?.to_string();
    let mut headers = Vec::new();
    for line in lines {
        if let Some((name, value)) = line.split_once(':') {
            headers.push((name.trim().to_string(), value.trim().to_string()));
        }
    }
    Some((method, path, headers, header_end))
}

/// Build a 101 Switching Protocols response.
#[must_use]
pub fn server_upgrade_response(key: &str) -> String {
    format!(
        "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {}\r\n\r\n",
        accept_key(key)
    )
}

/// Header lookup (case-insensitive).
#[must_use]
pub fn header_value<'a>(headers: &'a [(String, String)], name: &str) -> Option<&'a str> {
    headers
        .iter()
        .find(|(k, _)| k.eq_ignore_ascii_case(name))
        .map(|(_, v)| v.as_str())
}
