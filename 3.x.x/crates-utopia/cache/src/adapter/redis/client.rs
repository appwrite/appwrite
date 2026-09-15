use super::types::{ConnectionError, ConnectionException, ParseOutcome, RedisError, RespValue};
use crate::error::CacheError;

/// PHP `Utopia\Cache\Adapter\Redis\Client` - RESP2 encode/parse (no Swoole).
#[derive(Debug)]
pub struct Client {
    buffer: String,
}

impl Client {
    /// PHP `Client::INCOMPLETE`.
    pub const INCOMPLETE: &'static str = "\0__INCOMPLETE__\0";

    #[must_use]
    pub fn new() -> Self {
        Self {
            buffer: String::new(),
        }
    }

    #[must_use]
    pub fn take_buffer(&mut self) -> String {
        std::mem::take(&mut self.buffer)
    }

    /// PHP `Client::unwrap($value)`.
    pub fn unwrap_value(value: RespValue) -> Result<RespValue, CacheError> {
        match value {
            RespValue::RedisError(msg) => Err(CacheError::Redis(msg)),
            RespValue::ConnectionError(msg) => Err(CacheError::Connection(msg)),
            RespValue::Array(items) => {
                let mut out = Vec::with_capacity(items.len());
                for item in items {
                    out.push(Self::unwrap_value(item)?);
                }
                Ok(RespValue::Array(out))
            }
            other => Ok(other),
        }
    }

    /// Unwrap a [`RedisError`] / [`ConnectionError`] wrapper (PHP objects).
    pub fn unwrap_error(error: RedisError) -> Result<RespValue, CacheError> {
        Err(CacheError::Redis(error.message))
    }

    pub fn unwrap_connection(error: ConnectionError) -> Result<RespValue, CacheError> {
        Err(CacheError::Connection(error.exception.message))
    }

    /// PHP `Client::encode(array $args)`.
    #[must_use]
    pub fn encode(args: &[impl AsRef<[u8]>]) -> Vec<u8> {
        let mut out = Vec::new();
        out.extend_from_slice(format!("*{}\r\n", args.len()).as_bytes());
        for arg in args {
            let bytes = arg.as_ref();
            out.extend_from_slice(format!("${}\r\n", bytes.len()).as_bytes());
            out.extend_from_slice(bytes);
            out.extend_from_slice(b"\r\n");
        }
        out
    }

    /// Encode string arguments (PHP `(string) $arg`).
    #[must_use]
    pub fn encode_strings<S: AsRef<str>>(args: &[S]) -> String {
        String::from_utf8_lossy(&Self::encode(
            &args
                .iter()
                .map(|s| s.as_ref().as_bytes())
                .collect::<Vec<_>>(),
        ))
        .into_owned()
    }

    /// PHP `Client::parse($buffer, &$offset)`.
    pub fn parse(buffer: &str, offset: &mut usize) -> Result<ParseOutcome, CacheError> {
        Self::parse_bytes(buffer.as_bytes(), offset)
    }

    pub fn parse_bytes(buffer: &[u8], offset: &mut usize) -> Result<ParseOutcome, CacheError> {
        if *offset >= buffer.len() {
            return Ok(ParseOutcome::Incomplete);
        }
        let start = *offset;
        let type_byte = buffer[*offset];
        if !matches!(type_byte, b'+' | b'-' | b':' | b'$' | b'*') {
            return Err(CacheError::UnknownRespType(type_byte as char));
        }
        let Some(line_end) = find_crlf(buffer, *offset + 1) else {
            return Ok(ParseOutcome::Incomplete);
        };
        let line = &buffer[*offset + 1..line_end];
        let line_str = String::from_utf8_lossy(line).into_owned();
        *offset = line_end + 2;

        match type_byte {
            b'+' => Ok(ParseOutcome::Value(RespValue::Simple(line_str))),
            b'-' => Ok(ParseOutcome::Value(RespValue::RedisError(line_str))),
            b':' => Ok(ParseOutcome::Value(RespValue::Integer(parse_int(
                &line_str,
            )))),
            b'$' => {
                let len = parse_int(&line_str);
                if len < 0 {
                    return Ok(ParseOutcome::Value(RespValue::Nil));
                }
                let len = len as usize;
                if buffer.len() < *offset + len + 2 {
                    *offset = start;
                    return Ok(ParseOutcome::Incomplete);
                }
                let value = String::from_utf8_lossy(&buffer[*offset..*offset + len]).into_owned();
                *offset += len + 2;
                Ok(ParseOutcome::Value(RespValue::Bulk(value)))
            }
            b'*' => {
                let count = parse_int(&line_str);
                if count < 0 {
                    return Ok(ParseOutcome::Value(RespValue::Nil));
                }
                let mut items = Vec::with_capacity(count as usize);
                for _ in 0..count {
                    match Self::parse_bytes(buffer, offset)? {
                        ParseOutcome::Incomplete => {
                            *offset = start;
                            return Ok(ParseOutcome::Incomplete);
                        }
                        ParseOutcome::Value(item) => items.push(item),
                    }
                }
                Ok(ParseOutcome::Value(RespValue::Array(items)))
            }
            _ => Err(CacheError::UnknownRespType(type_byte as char)),
        }
    }
}

impl Default for Client {
    fn default() -> Self {
        Self::new()
    }
}

fn find_crlf(buffer: &[u8], from: usize) -> Option<usize> {
    let mut i = from;
    while i + 1 < buffer.len() {
        if buffer[i] == b'\r' && buffer[i + 1] == b'\n' {
            return Some(i);
        }
        i += 1;
    }
    None
}

fn parse_int(s: &str) -> i64 {
    s.parse().unwrap_or(0)
}

impl From<ConnectionException> for CacheError {
    fn from(value: ConnectionException) -> Self {
        Self::Connection(value.message)
    }
}
