//! PHP `Utopia\CircuitBreaker\Adapter\SwooleTable` equivalent.

use std::collections::HashMap;

use hex::encode;
use parking_lot::Mutex;
use sha1::{Digest, Sha1};

use super::{Adapter, CacheValue};
use crate::error::CircuitBreakerError;

const VALUE_COLUMN: &str = "value";
const NUMBER_COLUMN: &str = "number";
const TYPE_COLUMN: &str = "type";
const TYPE_STRING: i32 = 1;
const TYPE_INT: i32 = 2;
const MAX_TABLE_KEY_LENGTH: usize = 63;

#[derive(Clone, Debug)]
struct Row {
    value: String,
    number: i32,
    type_col: i32,
}

/// Process-local table matching Swoole table column semantics.
#[derive(Debug)]
pub struct Table {
    prefix: String,
    rows: Mutex<HashMap<String, Row>>,
}

impl Default for Table {
    fn default() -> Self {
        Self::new()
    }
}

impl Table {
    #[must_use]
    pub fn new() -> Self {
        Self::with_prefix("breaker:")
    }

    #[must_use]
    pub fn with_prefix(prefix: impl Into<String>) -> Self {
        Self {
            prefix: prefix.into(),
            rows: Mutex::new(HashMap::new()),
        }
    }

    fn key(&self, key: &str) -> String {
        let table_key = format!("{}{key}", self.prefix);
        if table_key.len() <= MAX_TABLE_KEY_LENGTH {
            table_key
        } else {
            let mut hasher = Sha1::new();
            hasher.update(table_key.as_bytes());
            format!(
                "{}{}",
                &self.prefix.chars().take(20).collect::<String>(),
                encode(hasher.finalize())
            )
        }
    }
}

impl Adapter for Table {
    fn get(&self, key: &str) -> Result<Option<CacheValue>, CircuitBreakerError> {
        let rows = self.rows.lock();
        let Some(row) = rows.get(&self.key(key)) else {
            return Ok(None);
        };
        match row.type_col {
            TYPE_STRING => Ok(Some(CacheValue::String(row.value.clone()))),
            TYPE_INT => Ok(Some(CacheValue::Int(row.number))),
            _ => Err(CircuitBreakerError::Adapter(format!(
                "Unexpected Swoole table value type for cache key \"{key}\"."
            ))),
        }
    }

    fn set(&self, key: &str, value: CacheValue) -> Result<(), CircuitBreakerError> {
        let row = match value {
            CacheValue::Int(number) => Row {
                value: String::new(),
                number,
                type_col: TYPE_INT,
            },
            CacheValue::String(text) => Row {
                value: text,
                number: 0,
                type_col: TYPE_STRING,
            },
        };
        self.rows.lock().insert(self.key(key), row);
        Ok(())
    }

    fn increment(&self, key: &str, by: i32) -> Result<i32, CircuitBreakerError> {
        let table_key = self.key(key);
        let mut rows = self.rows.lock();
        if let Some(row) = rows.get(&table_key) {
            if row.type_col == TYPE_STRING {
                return Err(CircuitBreakerError::Adapter(format!(
                    "Cannot increment non-numeric Swoole table key \"{key}\"."
                )));
            }
        }
        let entry = rows.entry(table_key).or_insert(Row {
            value: String::new(),
            number: 0,
            type_col: TYPE_INT,
        });
        entry.number += by;
        entry.type_col = TYPE_INT;
        Ok(entry.number)
    }

    fn delete(&self, key: &str) -> Result<(), CircuitBreakerError> {
        self.rows.lock().remove(&self.key(key));
        Ok(())
    }
}

#[allow(dead_code)]
fn _columns() {
    let _ = (VALUE_COLUMN, NUMBER_COLUMN, TYPE_COLUMN);
}
