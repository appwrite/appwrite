use serde_json::Value;

use crate::{Batch, FeedError, Id, Readable, MAX_BATCH, MAX_TIMEOUT, TIP};

/// PHP `Utopia\Feed\Server`.
#[derive(Clone)]
pub struct Server<S> {
    store: S,
}

impl<S: Readable> Server<S> {
    pub fn new(store: S) -> Self {
        Self { store }
    }

    #[must_use]
    pub fn get_name(&self) -> &str {
        self.store.get_name()
    }

    pub fn tip(&self) -> Result<Option<String>, FeedError> {
        self.store.tip()
    }

    pub fn read(&self, last_event_id: Option<&str>, limit: i64) -> Result<Batch, FeedError> {
        let limit = limit.clamp(1, MAX_BATCH);
        Ok(Batch::new(self.store.read(last_event_id, limit)?, limit))
    }

    pub fn poll(
        &self,
        last_event_id: Option<&str>,
        limit: i64,
        timeout: i64,
    ) -> Result<Batch, FeedError> {
        let limit = limit.clamp(1, MAX_BATCH);
        let timeout = timeout.clamp(0, MAX_TIMEOUT);
        Ok(Batch::new(
            self.store.poll(last_event_id, limit, timeout)?,
            limit,
        ))
    }

    /// PHP `serve(array $query)`. Query values are JSON (strings from HTTP, or mixed in tests).
    pub fn serve(&self, query: &serde_json::Map<String, Value>) -> Result<Batch, FeedError> {
        let last_event_id = match query.get("lastEventId") {
            None | Some(Value::Null) => None,
            Some(Value::String(s)) if s.is_empty() => None,
            Some(Value::String(s)) => {
                if s != TIP && !Id::is_valid(s) {
                    return Err(FeedError::invalid(format!("Invalid lastEventId: {s}")));
                }
                Some(s.as_str())
            }
            Some(other) => {
                return Err(FeedError::invalid(format!(
                    "Invalid lastEventId: expected a string, got {}",
                    php_debug_type(other)
                )));
            }
        };
        let limit = numeric(query.get("limit")).unwrap_or(MAX_BATCH);
        let timeout = numeric(query.get("timeout")).unwrap_or(0);
        self.poll(last_event_id, limit, timeout)
    }
}

/// PHP `is_numeric` then `(int)`.
fn numeric(value: Option<&Value>) -> Option<i64> {
    match value {
        Some(Value::Number(n)) => n.as_i64().or_else(|| n.as_f64().map(|f| f as i64)),
        Some(Value::String(s)) => {
            let trimmed = s.trim();
            if php_is_numeric(trimmed) {
                trimmed.parse::<f64>().ok().map(|n| n as i64)
            } else {
                None
            }
        }
        _ => None,
    }
}

fn php_is_numeric(s: &str) -> bool {
    if s.is_empty() {
        return false;
    }
    s.parse::<f64>().is_ok()
}

fn php_debug_type(value: &Value) -> &'static str {
    match value {
        Value::Array(_) | Value::Object(_) => "array",
        Value::Bool(_) => "bool",
        Value::Number(n) if n.is_f64() && !n.is_i64() => "float",
        Value::Number(_) => "int",
        Value::String(_) => "string",
        Value::Null => "null",
    }
}

impl<S: Readable> std::fmt::Debug for Server<S> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Server")
            .field("name", &self.store.get_name())
            .finish_non_exhaustive()
    }
}
