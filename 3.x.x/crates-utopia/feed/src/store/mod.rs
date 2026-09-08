use serde_json::{json, Map, Value};
use utopia_cloudevents::{CloudEvent, ExtensionValue};

use crate::{Extensions, FeedError, Readable, TIP};

mod cache;
mod memory;
mod none;
#[cfg(feature = "redis")]
mod pool;
#[cfg(feature = "redis")]
mod redis;

pub use cache::Cache;
pub use memory::Memory;
pub use none::None;
#[cfg(feature = "redis")]
pub use pool::Pool;
#[cfg(feature = "redis")]
pub use redis::{Redis, RedisConn};

/// PHP `Store::MAX_SIZE`.
pub const DEFAULT_MAX_SIZE: usize = 100_000;
/// PHP `Store::POLL_INTERVAL`.
pub const DEFAULT_POLL_INTERVAL: i64 = 500;

pub fn validate_store(name: &str, max_size: usize, poll_interval: i64) -> Result<(), FeedError> {
    if name.is_empty() {
        return Err(FeedError::invalid("Feed name is required"));
    }
    if max_size < 1 {
        return Err(FeedError::invalid(
            "Feed retention must be at least 1 event",
        ));
    }
    if poll_interval < 1 {
        return Err(FeedError::invalid(
            "Feed poll interval must be at least 1 millisecond",
        ));
    }
    Ok(())
}

/// PHP `Store::poll()`: resolve `$` once, then loop `read()` until events or deadline.
pub fn store_poll<S: Readable + ?Sized>(
    store: &S,
    last_event_id: Option<&str>,
    limit: i64,
    timeout: i64,
    poll_interval_ms: u64,
) -> Result<Vec<CloudEvent>, FeedError> {
    let resolved = if last_event_id == Some(TIP) {
        store.tip()?
    } else {
        last_event_id.map(str::to_owned)
    };
    poll_loop(
        |last, lim| store.read(last, lim),
        resolved.as_deref(),
        limit,
        timeout,
        poll_interval_ms,
    )
}

pub fn poll_loop<F>(
    mut read: F,
    last_event_id: Option<&str>,
    limit: i64,
    timeout: i64,
    poll_interval_ms: u64,
) -> Result<Vec<CloudEvent>, FeedError>
where
    F: FnMut(Option<&str>, i64) -> Result<Vec<CloudEvent>, FeedError>,
{
    let deadline =
        std::time::Instant::now() + std::time::Duration::from_millis(timeout.max(0) as u64);
    loop {
        let events = read(last_event_id, limit)?;
        let remaining = deadline.saturating_duration_since(std::time::Instant::now());
        if !events.is_empty() || remaining.is_zero() || timeout <= 0 {
            return Ok(events);
        }
        let sleep = std::time::Duration::from_millis(poll_interval_ms).min(remaining);
        std::thread::sleep(sleep);
    }
}

/// PHP `Store::encode()` - flattened string fields.
pub fn encode(event: &CloudEvent) -> Result<Map<String, Value>, FeedError> {
    let mut map = Map::new();
    map.insert("type".into(), json!(event.r#type.clone()));
    map.insert("source".into(), json!(event.source.clone()));
    map.insert(
        "subject".into(),
        json!(event.subject.clone().unwrap_or_default()),
    );
    map.insert(
        "datacontenttype".into(),
        json!(event.datacontenttype.clone().unwrap_or_default()),
    );
    map.insert(
        "dataschema".into(),
        json!(event.dataschema.clone().unwrap_or_default()),
    );
    map.insert("time".into(), json!(event.time.clone().unwrap_or_default()));
    map.insert("data".into(), json!(json_string(&event.data, "data")?));
    map.insert(
        "extensions".into(),
        json!(json_string(
            &extensions_json(&event.extensions),
            "extensions"
        )?),
    );
    Ok(map)
}

pub fn encode_fields(event: &CloudEvent) -> Result<Vec<(String, String)>, FeedError> {
    let map = encode(event)?;
    Ok(map
        .into_iter()
        .map(|(k, v)| (k, v.as_str().unwrap_or("").to_owned()))
        .collect())
}

/// PHP `Store::decode()`.
pub fn decode(id: &str, fields: &Map<String, Value>) -> Result<CloudEvent, FeedError> {
    let extensions_raw = json_decode_field(fields, "extensions");
    let mut event = Map::new();
    event.insert("specversion".into(), json!("1.0"));
    event.insert("id".into(), json!(id));
    event.insert("type".into(), json!(field(fields, "type")));
    event.insert("source".into(), json!(field(fields, "source")));
    event.insert("data".into(), json_decode_field(fields, "data"));
    for optional in ["subject", "datacontenttype", "dataschema", "time"] {
        let value = field(fields, optional);
        if !value.is_empty() {
            event.insert(optional.into(), json!(value));
        }
    }
    for (k, v) in Extensions::filter_value(&extensions_raw) {
        event.insert(k, v);
    }
    CloudEvent::from_array(&event).map_err(|e| {
        FeedError::invalid(format!(
            "Feed entry {id} could not be read as an event: {e}"
        ))
    })
}

pub fn decode_pairs(id: &str, fields: &[(String, String)]) -> Result<CloudEvent, FeedError> {
    let mut map = Map::new();
    for (k, v) in fields {
        map.insert(k.clone(), json!(v));
    }
    decode(id, &map)
}

pub fn unix_millis() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_millis() as i64)
        .unwrap_or(0)
}

fn field(fields: &Map<String, Value>, key: &str) -> String {
    match fields.get(key) {
        Some(Value::String(s)) => s.clone(),
        Some(Value::Number(n)) => n.to_string(),
        Some(Value::Bool(true)) => "1".into(),
        _ => String::new(),
    }
}

fn json_string(value: &Value, attribute: &str) -> Result<String, FeedError> {
    serde_json::to_string(value).map_err(|e| {
        FeedError::invalid(format!(
            "Feed event {attribute} must be JSON encodable: {e}"
        ))
    })
}

fn json_decode_field(fields: &Map<String, Value>, key: &str) -> Value {
    let raw = field(fields, key);
    serde_json::from_str(&raw).unwrap_or(Value::Null)
}

fn extensions_json(ext: &std::collections::BTreeMap<String, ExtensionValue>) -> Value {
    if ext.is_empty() {
        return json!([]);
    }
    let mut map = Map::new();
    for (k, v) in ext {
        map.insert(
            k.clone(),
            match v {
                ExtensionValue::Bool(b) => json!(b),
                ExtensionValue::Int(i) => json!(i),
                ExtensionValue::String(s) => json!(s),
            },
        );
    }
    Value::Object(map)
}
