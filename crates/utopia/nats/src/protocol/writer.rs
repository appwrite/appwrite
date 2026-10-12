//! Encodes client operations.

use serde_json::Value;

use crate::error::Result;

/// Encodes the operations a client sends, byte for byte as the server
/// expects them.
///
/// ```
/// use utopia_nats::protocol::Writer;
///
/// assert_eq!(Writer::publish("orders.new", b"hi", Some("_INBOX.1")), b"PUB orders.new _INBOX.1 2\r\nhi\r\n");
/// assert_eq!(Writer::sub("orders.>", "7", Some("workers")), b"SUB orders.> workers 7\r\n");
/// ```
///
/// ```php
/// use Utopia\NATS\Protocol\Writer;
///
/// (new Writer())->pub('orders.new', 'hi', '_INBOX.1'); // "PUB orders.new _INBOX.1 2\r\nhi\r\n"
/// (new Writer())->sub('orders.>', '7', 'workers');     // "SUB orders.> workers 7\r\n"
/// ```
///
/// PHP: `Utopia\NATS\Protocol\Writer` (instance methods without state;
/// here associated functions).
pub struct Writer;

impl Writer {
    /// `CONNECT {json}\r\n`, the options encoded as PHP's `json_encode`
    /// does (slashes and non-ASCII escaped).
    ///
    /// # Errors
    ///
    /// [`crate::Error::Json`] when the options cannot be encoded.
    ///
    /// PHP: `Writer::connect()`.
    pub fn connect(options: &Value) -> Result<Vec<u8>> {
        let json = crate::json::encode(options)?;
        let mut out = Vec::with_capacity(json.len() + 10);
        out.extend_from_slice(b"CONNECT ");
        out.extend_from_slice(json.as_bytes());
        out.extend_from_slice(b"\r\n");
        Ok(out)
    }

    /// `PUB subject [reply] size\r\npayload\r\n`.
    ///
    /// PHP: `Writer::pub()`.
    pub fn publish(subject: &str, payload: &[u8], reply_to: Option<&str>) -> Vec<u8> {
        let mut out = Vec::with_capacity(subject.len() + payload.len() + reply_to.map_or(0, str::len) + 20);
        Self::publish_into(&mut out, subject, payload, reply_to);
        out
    }

    pub(crate) fn publish_into(out: &mut Vec<u8>, subject: &str, payload: &[u8], reply_to: Option<&str>) {
        out.extend_from_slice(b"PUB ");
        out.extend_from_slice(subject.as_bytes());
        out.push(b' ');
        if let Some(reply) = reply_to {
            out.extend_from_slice(reply.as_bytes());
            out.push(b' ');
        }
        push_int(out, payload.len());
        out.extend_from_slice(b"\r\n");
        out.extend_from_slice(payload);
        out.extend_from_slice(b"\r\n");
    }

    /// `HPUB subject [reply] header_size total_size\r\nheaders payload\r\n`.
    ///
    /// PHP: `Writer::hpub()`.
    pub fn hpub(subject: &str, headers: &[u8], payload: &[u8], reply_to: Option<&str>) -> Vec<u8> {
        let mut out = Vec::with_capacity(subject.len() + headers.len() + payload.len() + 40);
        Self::hpub_into(&mut out, subject, headers, payload, reply_to);
        out
    }

    pub(crate) fn hpub_into(out: &mut Vec<u8>, subject: &str, headers: &[u8], payload: &[u8], reply_to: Option<&str>) {
        out.extend_from_slice(b"HPUB ");
        out.extend_from_slice(subject.as_bytes());
        out.push(b' ');
        if let Some(reply) = reply_to {
            out.extend_from_slice(reply.as_bytes());
            out.push(b' ');
        }
        push_int(out, headers.len());
        out.push(b' ');
        push_int(out, headers.len() + payload.len());
        out.extend_from_slice(b"\r\n");
        out.extend_from_slice(headers);
        out.extend_from_slice(payload);
        out.extend_from_slice(b"\r\n");
    }

    /// `SUB subject [queue] sid\r\n`.
    ///
    /// PHP: `Writer::sub()`.
    pub fn sub(subject: &str, sid: &str, queue: Option<&str>) -> Vec<u8> {
        let mut out = Vec::with_capacity(subject.len() + sid.len() + queue.map_or(0, str::len) + 8);
        out.extend_from_slice(b"SUB ");
        out.extend_from_slice(subject.as_bytes());
        out.push(b' ');
        if let Some(queue) = queue {
            out.extend_from_slice(queue.as_bytes());
            out.push(b' ');
        }
        out.extend_from_slice(sid.as_bytes());
        out.extend_from_slice(b"\r\n");
        out
    }

    /// `UNSUB sid [max]\r\n`.
    ///
    /// PHP: `Writer::unsub()`.
    pub fn unsub(sid: &str, max_messages: Option<i64>) -> Vec<u8> {
        let mut out = Vec::with_capacity(sid.len() + 30);
        out.extend_from_slice(b"UNSUB ");
        out.extend_from_slice(sid.as_bytes());
        if let Some(max) = max_messages {
            out.push(b' ');
            out.extend_from_slice(max.to_string().as_bytes());
        }
        out.extend_from_slice(b"\r\n");
        out
    }

    /// `PING\r\n`.
    ///
    /// PHP: `Writer::ping()`.
    pub fn ping() -> &'static [u8] {
        b"PING\r\n"
    }

    /// `PONG\r\n`.
    ///
    /// PHP: `Writer::pong()`.
    pub fn pong() -> &'static [u8] {
        b"PONG\r\n"
    }
}

fn push_int(out: &mut Vec<u8>, n: usize) {
    let mut buf = [0u8; 20];
    let mut pos = buf.len();
    let mut n = n;
    loop {
        pos -= 1;
        buf[pos] = b'0' + (n % 10) as u8;
        n /= 10;
        if n == 0 {
            break;
        }
    }
    out.extend_from_slice(&buf[pos..]);
}
