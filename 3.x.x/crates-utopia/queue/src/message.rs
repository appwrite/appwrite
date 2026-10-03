use serde_json::{json, Map, Value};

/// Envelope stored on a broker (`pid`, `queue`, `timestamp`, `payload`, `attempts`).
///
/// PHP `Utopia\Queue\Message`.
#[derive(Debug, Clone, PartialEq)]
pub struct Message {
    pid: String,
    queue: String,
    timestamp: i64,
    payload: Option<Value>,
    attempts: i64,
}

impl Default for Message {
    fn default() -> Self {
        Self::new()
    }
}

impl Message {
    pub fn new() -> Self {
        Self {
            pid: String::new(),
            queue: String::new(),
            timestamp: 0,
            payload: None,
            attempts: 0,
        }
    }

    /// Build from a JSON object (PHP constructor `array $array`).
    pub fn from_value(value: &Value) -> Self {
        let Some(obj) = value.as_object() else {
            return Self::new();
        };
        if obj.is_empty() {
            return Self::new();
        }
        Self {
            pid: obj
                .get("pid")
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_owned(),
            queue: obj
                .get("queue")
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_owned(),
            timestamp: json_i64(obj.get("timestamp")).unwrap_or(0),
            payload: obj.get("payload").cloned().or_else(|| Some(json!({}))),
            attempts: json_i64(obj.get("attempts")).unwrap_or(0),
        }
    }

    pub fn set_pid(&mut self, pid: impl Into<String>) -> &mut Self {
        self.pid = pid.into();
        self
    }

    pub fn set_queue(&mut self, queue: impl Into<String>) -> &mut Self {
        self.queue = queue.into();
        self
    }

    pub fn set_timestamp(&mut self, timestamp: i64) -> &mut Self {
        self.timestamp = timestamp;
        self
    }

    pub fn set_payload(&mut self, payload: Value) -> &mut Self {
        self.payload = Some(payload);
        self
    }

    pub fn get_pid(&self) -> &str {
        &self.pid
    }

    pub fn get_queue(&self) -> &str {
        &self.queue
    }

    pub fn get_timestamp(&self) -> i64 {
        self.timestamp
    }

    pub fn get_payload(&self) -> Value {
        self.payload.clone().unwrap_or(Value::Null)
    }

    pub fn get_payload_ref(&self) -> Option<&Value> {
        self.payload.as_ref()
    }

    pub fn get_attempts(&self) -> i64 {
        self.attempts
    }

    pub fn set_attempts(&mut self, attempts: i64) -> &mut Self {
        self.attempts = attempts;
        self
    }

    /// PHP `asArray()` keys: `pid` / `queue` / `timestamp` / `payload` / `attempts`.
    pub fn as_array(&self) -> Value {
        json!({
            "pid": self.pid,
            "queue": self.queue,
            "timestamp": self.timestamp,
            "payload": self.payload.clone().unwrap_or(Value::Null),
            "attempts": self.attempts,
        })
    }

    pub fn as_object(&self) -> Map<String, Value> {
        self.as_array().as_object().cloned().unwrap_or_default()
    }
}

fn json_i64(value: Option<&Value>) -> Option<i64> {
    match value? {
        Value::Number(n) => n.as_i64().or_else(|| n.as_f64().map(|f| f as i64)),
        Value::String(s) => s.parse().ok(),
        _ => None,
    }
}
