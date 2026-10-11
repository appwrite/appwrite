//! The message envelope.

use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};

use php_std::{Array, Str, Value};

use crate::error::{Error, Result};

/// One delivery of a message: the envelope a publisher wrote (pid, queue,
/// timestamp, payload, attempts) plus what the broker knows about this
/// delivery (its sequence, its receipt, the handler's terminal verdict).
///
/// The envelope's field order is the wire format: `pid`, `queue`,
/// `timestamp`, `payload`, then `attempts` on a requeued message.
///
/// ```
/// use php_std::{Array, Value};
/// use utopia_queue::Message;
///
/// let mut payload = Array::new();
/// payload.set("to".into(), Value::from("ada@example.com"));
/// let message = Message::new("pid-1", "emails", 1_700_000_000, payload);
/// assert_eq!(message.pid().as_bytes(), b"pid-1");
/// assert_eq!(message.attempts(), 0);
/// assert_eq!(message.payload().get_str("to"), Some(&Value::from("ada@example.com")));
/// ```
///
/// ```php
/// use Utopia\Queue\Message;
///
/// $message = new Message(['pid' => 'pid-1', 'queue' => 'emails', 'timestamp' => 1700000000, 'payload' => ['to' => 'ada@example.com']]);
/// echo $message->getPid(), ' ', $message->getAttempts(), ' ', $message->getPayload()['to'], "\n"; // pid-1 0 ada@example.com
/// ```
#[derive(Debug, Clone)]
pub struct Message {
    pid: Str,
    queue: Str,
    timestamp: i64,
    payload: Array,
    attempts: i64,
    sequence: Option<i64>,
    receipt: Option<String>,
    // Shared by clones of one delivery, so a handler holding a copy can mark it.
    terminal: Arc<AtomicBool>,
}

impl PartialEq for Message {
    fn eq(&self, o: &Self) -> bool {
        self.pid == o.pid
            && self.queue == o.queue
            && self.timestamp == o.timestamp
            && self.payload == o.payload
            && self.attempts == o.attempts
            && self.sequence == o.sequence
            && self.receipt == o.receipt
            && self.is_terminal() == o.is_terminal()
    }
}

impl Message {
    /// A message with no attempts, no sequence and no receipt.
    pub fn new(pid: impl Into<Str>, queue: impl Into<Str>, timestamp: i64, payload: Array) -> Self {
        Self {
            pid: pid.into(),
            queue: queue.into(),
            timestamp,
            payload,
            attempts: 0,
            sequence: None,
            receipt: None,
            terminal: Arc::default(),
        }
    }

    /// `new Message($envelope)`: reads a decoded envelope, coercing its fields
    /// the way PHP assigns typed properties (an integer pid becomes a string,
    /// a numeric string timestamp an integer). A missing `payload` is an empty
    /// array and missing `attempts` zero. Fails with [`Error::Type`] where PHP
    /// raises a `TypeError`: a missing or non-scalar `pid`, `queue` or
    /// `timestamp`, a payload that is not an array.
    pub fn from_envelope(envelope: &Array) -> Result<Self> {
        let pid = string_field(envelope, "pid")?;
        let queue = string_field(envelope, "queue")?;
        let timestamp = int_field(envelope.get_str("timestamp"), "timestamp")?;
        let payload = match envelope.get_str("payload") {
            None | Some(Value::Null) => Array::new(),
            Some(Value::Array(a)) => a.clone(),
            Some(other) => return Err(type_error("payload", "array", other)),
        };
        let attempts = match envelope.get_str("attempts") {
            None | Some(Value::Null) => 0,
            Some(v) => int_field(Some(v), "attempts")?,
        };
        let sequence = match envelope.get_str("sequence") {
            None | Some(Value::Null) => None,
            Some(v) => Some(int_field(Some(v), "sequence")?),
        };
        Ok(Self { pid, queue, timestamp, payload, attempts, sequence, receipt: None, terminal: Arc::default() })
    }

    /// The message's identity, stable across every redelivery.
    pub fn pid(&self) -> &Str {
        &self.pid
    }

    /// The name of the queue the message was published to.
    pub fn queue(&self) -> &Str {
        &self.queue
    }

    /// When the message was published, in seconds since the epoch, by the publisher's clock.
    pub fn timestamp(&self) -> i64 {
        self.timestamp
    }

    /// What the publisher sent.
    pub fn payload(&self) -> &Array {
        &self.payload
    }

    /// Times this message has been requeued after a failed or stranded run.
    pub fn attempts(&self) -> i64 {
        self.attempts
    }

    /// The broker's own position for this message where it has one (the
    /// JetStream stream sequence): it tells one delivery of a message from
    /// another, where the pid identifies the logical message.
    pub fn sequence(&self) -> Option<i64> {
        self.sequence
    }

    /// The broker's proof of ownership of this delivery (Redis: the claim token).
    pub fn receipt(&self) -> Option<&str> {
        self.receipt.as_deref()
    }

    /// Whether the handler declared this failure permanent.
    pub fn is_terminal(&self) -> bool {
        self.terminal.load(Ordering::SeqCst)
    }

    /// Sets the pid.
    pub fn set_pid(&mut self, pid: impl Into<Str>) -> &mut Self {
        self.pid = pid.into();
        self
    }

    /// Sets the queue name.
    pub fn set_queue(&mut self, queue: impl Into<Str>) -> &mut Self {
        self.queue = queue.into();
        self
    }

    /// Sets the publication time.
    pub fn set_timestamp(&mut self, timestamp: i64) -> &mut Self {
        self.timestamp = timestamp;
        self
    }

    /// Sets the payload.
    pub fn set_payload(&mut self, payload: Array) -> &mut Self {
        self.payload = payload;
        self
    }

    /// Sets the attempt count.
    pub fn set_attempts(&mut self, attempts: i64) -> &mut Self {
        self.attempts = attempts;
        self
    }

    /// Sets the broker sequence.
    pub fn set_sequence(&mut self, sequence: Option<i64>) -> &mut Self {
        self.sequence = sequence;
        self
    }

    /// Sets the delivery receipt.
    pub fn set_receipt(&mut self, receipt: impl Into<String>) -> &mut Self {
        self.receipt = Some(receipt.into());
        self
    }

    /// Declares that this message must not be delivered again: the payload
    /// fails the same way on every attempt. Read when the message is
    /// rejected; per delivery, not part of the envelope. Returning
    /// [`Error::PermanentFailure`] from a handler sets it too.
    pub fn terminal(&self, terminal: bool) -> &Self {
        self.terminal.store(terminal, Ordering::SeqCst);
        self
    }

    /// `asArray()`: `pid`, `queue`, `timestamp`, `payload`, `attempts`, `sequence`.
    pub fn to_array(&self) -> Array {
        let mut a = Array::with_capacity(6);
        a.set("pid".into(), Value::Str(self.pid.clone()));
        a.set("queue".into(), Value::Str(self.queue.clone()));
        a.set("timestamp".into(), Value::Int(self.timestamp));
        a.set("payload".into(), Value::Array(self.payload.clone()));
        a.set("attempts".into(), Value::Int(self.attempts));
        a.set("sequence".into(), self.sequence.map_or(Value::Null, Value::Int));
        a
    }
}

/// The envelope a publisher writes: `pid`, `queue`, `timestamp`, `payload`,
/// then `attempts` when given (a requeue).
pub(crate) fn envelope(pid: &str, queue: &str, timestamp: i64, payload: Array, attempts: Option<i64>) -> Value {
    let mut a = Array::with_capacity(5);
    a.set("pid".into(), Value::from(pid));
    a.set("queue".into(), Value::from(queue));
    a.set("timestamp".into(), Value::Int(timestamp));
    a.set("payload".into(), Value::Array(payload));
    if let Some(attempts) = attempts {
        a.set("attempts".into(), Value::Int(attempts));
    }
    Value::Array(a)
}

fn type_error(field: &str, expected: &str, value: &Value) -> Error {
    Error::Type(format!(
        "Cannot assign {} to property Utopia\\Queue\\Message::${field} of type {expected}",
        // zend_zval_value_name: booleans by value, everything else by type.
        match value {
            Value::Bool(true) => "true",
            Value::Bool(false) => "false",
            other => other.debug_type(),
        }
    ))
}

fn string_field(envelope: &Array, field: &str) -> Result<Str> {
    match envelope.get_str(field) {
        Some(Value::Str(s)) => Ok(s.clone()),
        Some(v @ (Value::Int(_) | Value::Float(_) | Value::Bool(_))) => {
            v.to_php_string().map_err(|_| type_error(field, "string", v))
        }
        Some(v) => Err(type_error(field, "string", v)),
        None => Err(type_error(field, "string", &Value::Null)),
    }
}

fn int_field(value: Option<&Value>, field: &str) -> Result<i64> {
    let kind = if field == "sequence" { "?int" } else { "int" };
    let value = value.unwrap_or(&Value::Null);
    let from_float = |f: f64| {
        if (-9.223_372_036_854_776e18..9.223_372_036_854_776e18).contains(&f) {
            Ok(f as i64)
        } else {
            Err(type_error(field, kind, value))
        }
    };
    match value {
        Value::Int(i) => Ok(*i),
        Value::Bool(b) => Ok(i64::from(*b)),
        Value::Float(f) => from_float(*f),
        Value::Str(s) => match php_std::value::numeric_str(std::str::from_utf8(s.as_bytes()).unwrap_or("")) {
            Some(php_std::value::Number::Int(i)) => Ok(i),
            Some(php_std::value::Number::Float(f)) => from_float(f),
            None => Err(type_error(field, kind, value)),
        },
        _ => Err(type_error(field, kind, value)),
    }
}
