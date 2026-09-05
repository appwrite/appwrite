use serde_json::{json, Map, Value};

use crate::adapter::Json;
use crate::value::CacheValue;

/// PHP `Utopia\Cache\Adapter\Redis\Envelope`.
///
/// Stored payload is `{"time": <unix>, "data": <value>}`.
#[derive(Debug, Clone, Copy)]
pub struct Envelope;

impl Envelope {
    /// PHP `Envelope::encode($data, $time)`.
    pub fn encode(data: &CacheValue, time: i64) -> Result<String, serde_json::Error> {
        let mut map = Map::new();
        map.insert("time".into(), json!(time));
        map.insert("data".into(), data.clone().into_json());
        serde_json::to_string(&Value::Object(map))
    }

    /// PHP `Envelope::decode($value, $ttl, $now)`. Miss on malformed / stale.
    #[must_use]
    pub fn decode(value: &str, ttl: i64, now: i64) -> Option<CacheValue> {
        let cache = Json::decode(value)?;
        let obj = cache.as_object()?;
        let time = match obj.get("time") {
            Some(Value::Number(n)) if n.is_i64() || n.is_u64() => n
                .as_i64()
                .or_else(|| n.as_u64().and_then(|u| i64::try_from(u).ok()))?,
            _ => return None,
        };
        let data = match obj.get("data") {
            None | Some(Value::Null) => return None,
            Some(v) => CacheValue::from_json(v.clone()),
        };
        if time + ttl > now {
            Some(data)
        } else {
            None
        }
    }

    /// PHP `Envelope::touch($value, $newTime)`.
    #[must_use]
    pub fn touch(value: &str, new_time: i64) -> Option<String> {
        let mut cache = Json::decode_strict(value).ok()?;
        let obj = cache.as_object_mut()?;
        if !obj.contains_key("data") {
            return None;
        }
        obj.insert("time".into(), json!(new_time));
        serde_json::to_string(&cache).ok()
    }
}
