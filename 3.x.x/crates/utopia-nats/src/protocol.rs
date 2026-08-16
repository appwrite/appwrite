//! NATS protocol parser and writer (PHP `Utopia\NATS\Protocol`).

use crate::error::{NatsError, ProtocolException};
use crate::transport::Transport;
use serde_json::Value;
use std::sync::Arc;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ServerOp {
    Info,
    Msg,
    HMsg,
    Ping,
    Pong,
    Ok,
    Err,
}

impl ServerOp {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Info => "INFO",
            Self::Msg => "MSG",
            Self::HMsg => "HMSG",
            Self::Ping => "PING",
            Self::Pong => "PONG",
            Self::Ok => "+OK",
            Self::Err => "-ERR",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Command {
    Connect,
    Pub,
    HPub,
    Sub,
    Unsub,
    Ping,
    Pong,
}

#[derive(Debug, Clone, PartialEq)]
pub struct MsgData {
    pub subject: String,
    pub sid: String,
    pub reply_to: Option<String>,
    pub payload: Vec<u8>,
    pub headers: Option<Vec<u8>>,
}

#[derive(Debug, Clone, PartialEq)]
pub enum ServerEvent {
    Info(Value),
    Msg(MsgData),
    HMsg(MsgData),
    Ping,
    Pong,
    Ok,
    Err(String),
}

impl ServerEvent {
    pub fn op(&self) -> ServerOp {
        match self {
            Self::Info(_) => ServerOp::Info,
            Self::Msg(_) => ServerOp::Msg,
            Self::HMsg(_) => ServerOp::HMsg,
            Self::Ping => ServerOp::Ping,
            Self::Pong => ServerOp::Pong,
            Self::Ok => ServerOp::Ok,
            Self::Err(_) => ServerOp::Err,
        }
    }
}

#[derive(Debug, Clone, Default)]
pub struct Writer;

impl Writer {
    pub fn connect(&self, options: &Value) -> String {
        format!(
            "CONNECT {}\r\n",
            serde_json::to_string(options).expect("CONNECT json")
        )
    }

    pub fn pub_cmd(&self, subject: &str, payload: &[u8], reply_to: Option<&str>) -> Vec<u8> {
        let size = payload.len();
        let mut out = if let Some(reply) = reply_to {
            format!("PUB {subject} {reply} {size}\r\n").into_bytes()
        } else {
            format!("PUB {subject} {size}\r\n").into_bytes()
        };
        out.extend_from_slice(payload);
        out.extend_from_slice(b"\r\n");
        out
    }

    pub fn hpub(
        &self,
        subject: &str,
        headers: &[u8],
        payload: &[u8],
        reply_to: Option<&str>,
    ) -> Vec<u8> {
        let header_size = headers.len();
        let total_size = header_size + payload.len();
        let mut out = if let Some(reply) = reply_to {
            format!("HPUB {subject} {reply} {header_size} {total_size}\r\n").into_bytes()
        } else {
            format!("HPUB {subject} {header_size} {total_size}\r\n").into_bytes()
        };
        out.extend_from_slice(headers);
        out.extend_from_slice(payload);
        out.extend_from_slice(b"\r\n");
        out
    }

    pub fn sub(&self, subject: &str, sid: &str, queue: Option<&str>) -> String {
        if let Some(queue) = queue {
            format!("SUB {subject} {queue} {sid}\r\n")
        } else {
            format!("SUB {subject} {sid}\r\n")
        }
    }

    pub fn unsub(&self, sid: &str, max_messages: Option<i64>) -> String {
        if let Some(max) = max_messages {
            format!("UNSUB {sid} {max}\r\n")
        } else {
            format!("UNSUB {sid}\r\n")
        }
    }

    pub fn ping(&self) -> &'static str {
        "PING\r\n"
    }

    pub fn pong(&self) -> &'static str {
        "PONG\r\n"
    }
}

#[derive(Debug)]
pub struct Parser {
    transport: Arc<dyn Transport>,
    buffer: Vec<u8>,
}

impl Parser {
    pub fn new(transport: Arc<dyn Transport>) -> Self {
        Self {
            transport,
            buffer: Vec::new(),
        }
    }

    pub fn next(&mut self, timeout: Option<f64>) -> Result<(ServerOp, ServerEvent), NatsError> {
        let line = self.read_line(timeout)?;
        let line = line.trim_end_matches(['\r', '\n']);
        if line.is_empty() {
            return Err(ProtocolException("Empty protocol line received".into()).into());
        }
        if line == "+OK" {
            return Ok((ServerOp::Ok, ServerEvent::Ok));
        }
        if line == "PING" {
            return Ok((ServerOp::Ping, ServerEvent::Ping));
        }
        if line == "PONG" {
            return Ok((ServerOp::Pong, ServerEvent::Pong));
        }
        if let Some(rest) = line.strip_prefix("-ERR") {
            let message =
                rest.trim_matches(|c: char| c == ' ' || c == '\t' || c == '\'' || c == '"');
            return Ok((ServerOp::Err, ServerEvent::Err(message.to_owned())));
        }
        if let Some(json) = line.strip_prefix("INFO ") {
            let data: Value = serde_json::from_str(json)
                .map_err(|e| ProtocolException(format!("Invalid INFO json: {e}")))?;
            return Ok((ServerOp::Info, ServerEvent::Info(data)));
        }
        if let Some(args) = line.strip_prefix("MSG ") {
            let msg = self.parse_msg(args)?;
            return Ok((ServerOp::Msg, ServerEvent::Msg(msg)));
        }
        if let Some(args) = line.strip_prefix("HMSG ") {
            let msg = self.parse_hmsg(args)?;
            return Ok((ServerOp::HMsg, ServerEvent::HMsg(msg)));
        }
        Err(ProtocolException(format!("Unknown protocol operation: {line}")).into())
    }

    fn parse_msg(&mut self, args: &str) -> Result<MsgData, NatsError> {
        let parts: Vec<&str> = args.split_whitespace().collect();
        if parts.len() < 3 || parts.len() > 4 {
            return Err(ProtocolException(format!("Invalid MSG line: MSG {args}")).into());
        }
        let (subject, sid, reply_to, byte_count) = if parts.len() == 3 {
            (parts[0], parts[1], None, parts[2])
        } else {
            (parts[0], parts[1], Some(parts[2].to_owned()), parts[3])
        };
        let bytes: usize = byte_count.parse().unwrap_or(0);
        let payload = self.read_exactly(bytes)?;
        let _ = self.read_exactly(2)?;
        Ok(MsgData {
            subject: subject.to_owned(),
            sid: sid.to_owned(),
            reply_to,
            payload,
            headers: None,
        })
    }

    fn parse_hmsg(&mut self, args: &str) -> Result<MsgData, NatsError> {
        let parts: Vec<&str> = args.split_whitespace().collect();
        if parts.len() < 4 || parts.len() > 5 {
            return Err(ProtocolException(format!("Invalid HMSG line: HMSG {args}")).into());
        }
        let (subject, sid, reply_to, header_bytes, total_bytes) = if parts.len() == 4 {
            (parts[0], parts[1], None, parts[2], parts[3])
        } else {
            (
                parts[0],
                parts[1],
                Some(parts[2].to_owned()),
                parts[3],
                parts[4],
            )
        };
        let hdr_len: usize = header_bytes.parse().unwrap_or(0);
        let total_len: usize = total_bytes.parse().unwrap_or(0);
        if total_len < hdr_len {
            return Err(ProtocolException(format!(
                "Invalid HMSG byte counts: header={hdr_len}, total={total_len}"
            ))
            .into());
        }
        let payload_len = total_len - hdr_len;
        let header_block = self.read_exactly(hdr_len)?;
        let payload = if payload_len > 0 {
            self.read_exactly(payload_len)?
        } else {
            Vec::new()
        };
        let _ = self.read_exactly(2)?;
        Ok(MsgData {
            subject: subject.to_owned(),
            sid: sid.to_owned(),
            reply_to,
            payload,
            headers: Some(header_block),
        })
    }

    fn read_line(&mut self, timeout: Option<f64>) -> Result<String, NatsError> {
        if let Some(pos) = self.buffer.iter().position(|b| *b == b'\n') {
            let line = self.buffer.drain(..=pos).collect::<Vec<_>>();
            return Ok(String::from_utf8_lossy(&line).into_owned());
        }
        loop {
            let data = self.transport.read(65536, timeout)?;
            self.buffer.extend_from_slice(&data);
            if let Some(pos) = self.buffer.iter().position(|b| *b == b'\n') {
                let line = self.buffer.drain(..=pos).collect::<Vec<_>>();
                return Ok(String::from_utf8_lossy(&line).into_owned());
            }
        }
    }

    fn read_exactly(&mut self, bytes: usize) -> Result<Vec<u8>, NatsError> {
        while self.buffer.len() < bytes {
            let data = self
                .transport
                .read(65536.max(bytes - self.buffer.len()), None)?;
            if data.is_empty() {
                return Err(ProtocolException(
                    "Unexpected end of data while reading payload".into(),
                )
                .into());
            }
            self.buffer.extend_from_slice(&data);
        }
        Ok(self.buffer.drain(..bytes).collect())
    }
}
