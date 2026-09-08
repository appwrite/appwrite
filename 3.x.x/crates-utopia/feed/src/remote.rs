use bytes::Bytes;
use http::Request;
use serde_json::{Map, Value};
use utopia_client::{Adapter, RelativeUri};
use utopia_cloudevents::CloudEvent;

use crate::{Extensions, FeedError, Readable, MAX_BATCH, MEDIA_TYPE};

const TIMEOUT_MARGIN: f64 = 10.0;

/// PHP `Utopia\Feed\Remote`.
#[derive(Clone)]
pub struct Remote<A: Adapter> {
    client: A,
    name: String,
}

impl<A: Adapter> std::fmt::Debug for Remote<A> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Remote")
            .field("name", &self.name)
            .finish_non_exhaustive()
    }
}

impl<A: Adapter> Remote<A> {
    /// PHP `Readable::MEDIA_TYPE`.
    pub const MEDIA_TYPE: &'static str = MEDIA_TYPE;
    /// PHP `Readable::MAX_BATCH`.
    pub const MAX_BATCH: i64 = MAX_BATCH;

    pub fn new(client: A, name: impl Into<String>) -> Result<Self, FeedError> {
        let name = name.into();
        if name.is_empty() {
            return Err(FeedError::invalid("Feed name is required"));
        }
        Ok(Self { client, name })
    }

    fn fetch(
        &self,
        last_event_id: Option<&str>,
        limit: i64,
        timeout: i64,
    ) -> Result<Vec<CloudEvent>, FeedError> {
        let request = build_request(&self.name, last_event_id, limit, timeout);
        let client = if timeout > 0 {
            self.client
                .with_timeout((timeout as f64 / 1000.0) + TIMEOUT_MARGIN)
                .map_err(|e| {
                    FeedError::transport(format!("Failed to read the {} feed: {e}", self.name))
                })?
        } else {
            self.client.clone()
        };
        let response =
            utopia_client::StreamingClient::send_request(&client, request).map_err(|e| {
                FeedError::transport(format!("Failed to read the {} feed: {e}", self.name))
            })?;
        let status = response.status().as_u16();
        if status >= 400 {
            return Err(FeedError::transport_status(
                format!("Reading the {} feed failed with status {status}", self.name),
                i64::from(status),
            ));
        }
        let raw = String::from_utf8_lossy(response.body()).into_owned();
        let body: Value = serde_json::from_str(&raw).map_err(|e| {
            FeedError::transport(format!(
                "The {} feed returned a body that is not JSON: {e}",
                self.name
            ))
        })?;
        if matches!(&body, Value::Array(a) if a.is_empty()) && !raw.trim_start().starts_with('[') {
            return Err(FeedError::invalid("Expected a feed batch, got an object"));
        }
        decode_batch(&body)
    }
}

impl<A: Adapter> Readable for Remote<A> {
    fn get_name(&self) -> &str {
        &self.name
    }

    fn read(&self, last_event_id: Option<&str>, limit: i64) -> Result<Vec<CloudEvent>, FeedError> {
        self.fetch(last_event_id, limit, 0)
    }

    fn poll(
        &self,
        last_event_id: Option<&str>,
        limit: i64,
        timeout: i64,
    ) -> Result<Vec<CloudEvent>, FeedError> {
        self.fetch(last_event_id, limit, timeout)
    }

    fn tip(&self) -> Result<Option<String>, FeedError> {
        Err(FeedError::unsupported(format!(
            "The {} feed is remote; its producer resolves the tip",
            self.name
        )))
    }
}

fn build_request(
    name: &str,
    last_event_id: Option<&str>,
    limit: i64,
    timeout: i64,
) -> Request<Bytes> {
    let path = rawurlencode(name);
    let query = query_string(last_event_id, limit, timeout);
    let target = if query.is_empty() {
        path
    } else {
        format!("{path}?{query}")
    };
    let mut request = Request::builder()
        .method("GET")
        .uri("/")
        .header("accept", MEDIA_TYPE)
        .body(Bytes::new())
        .expect("feed request");
    request.extensions_mut().insert(RelativeUri(target));
    request
}

fn query_string(last_event_id: Option<&str>, limit: i64, timeout: i64) -> String {
    let mut parts = Vec::new();
    if let Some(id) = last_event_id {
        if !id.is_empty() {
            parts.push(format!("lastEventId={}", rawurlencode(id)));
        }
    }
    if limit > 0 {
        parts.push(format!("limit={limit}"));
    }
    if timeout > 0 {
        parts.push(format!("timeout={timeout}"));
    }
    parts.join("&")
}

fn rawurlencode(s: &str) -> String {
    let mut out = String::new();
    for b in s.bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(char::from(b));
            }
            _ => {
                const HEX: &[u8; 16] = b"0123456789ABCDEF";
                out.push('%');
                out.push(char::from(HEX[usize::from(b >> 4)]));
                out.push(char::from(HEX[usize::from(b & 0x0f)]));
            }
        }
    }
    out
}

fn decode_batch(payload: &Value) -> Result<Vec<CloudEvent>, FeedError> {
    let Value::Array(items) = payload else {
        return Err(FeedError::invalid(format!(
            "Expected a feed batch, got {}",
            debug_type(payload)
        )));
    };
    let mut events = Vec::new();
    for event in items {
        let Value::Object(map) = event else {
            let err = FeedError::invalid("Feed batch contains an entry that is not an event");
            if events.is_empty() {
                return Err(err);
            }
            break;
        };
        match event_from(map) {
            Ok(ev) => events.push(ev),
            Err(err) => {
                if events.is_empty() {
                    return Err(err);
                }
                break;
            }
        }
    }
    Ok(events)
}

fn event_from(raw: &Map<String, Value>) -> Result<CloudEvent, FeedError> {
    for required in ["specversion", "id", "type", "source"] {
        match raw.get(required).and_then(Value::as_str) {
            Some(s) if !s.is_empty() => {}
            _ => {
                let label = if required == "id" {
                    "an id".to_owned()
                } else {
                    format!("a {required}")
                };
                return Err(FeedError::invalid(format!("Feed event is missing {label}")));
            }
        }
    }
    let extensions = Extensions::filter(raw);
    Ok(CloudEvent {
        r#type: raw["type"].as_str().unwrap_or_default().to_owned(),
        source: raw["source"].as_str().unwrap_or_default().to_owned(),
        id: raw["id"].as_str().unwrap_or_default().to_owned(),
        specversion: raw["specversion"].as_str().unwrap_or_default().to_owned(),
        subject: optional(raw, "subject"),
        time: optional(raw, "time"),
        datacontenttype: optional(raw, "datacontenttype"),
        data: raw.get("data").cloned().unwrap_or(Value::Null),
        dataschema: optional(raw, "dataschema"),
        extensions: Extensions::to_extension_map(&extensions),
        data_binary: None,
    })
}

fn optional(raw: &Map<String, Value>, key: &str) -> Option<String> {
    raw.get(key)
        .and_then(Value::as_str)
        .filter(|s| !s.is_empty())
        .map(str::to_owned)
}

fn debug_type(value: &Value) -> &'static str {
    match value {
        Value::Object(_) => "object",
        Value::Array(_) => "array",
        Value::String(_) => "string",
        Value::Number(_) => "int",
        Value::Bool(_) => "bool",
        Value::Null => "null",
    }
}
