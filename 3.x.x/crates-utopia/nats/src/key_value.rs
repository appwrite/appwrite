//! Key-Value store over `JetStream` (PHP `Utopia\NATS\KeyValue`).

use crate::connection::Connection;
use crate::error::{KeyValueException, NatsError};
use crate::headers::Headers;
use crate::jetstream::{
    AckPolicy, ConsumerConfig, DeliverPolicy, DiscardPolicy, JetStream, JetStreamMessage,
    MsgMetadata, RetentionPolicy, StorageType, StreamConfig, StreamInfo,
};
use crate::message::Message;
use crate::subscription::{MessageCallback, Subscription};
use base64::Engine;
use serde_json::{json, Value};
use std::sync::atomic::{AtomicBool, AtomicI64, Ordering};
use std::sync::Arc;
use std::time::{SystemTime, UNIX_EPOCH};

#[derive(Clone, Debug)]
pub struct KeyValue {
    conn: Connection,
    js: JetStream,
    bucket: String,
}

#[derive(Clone, Debug)]
pub struct KeyValueConfig {
    pub bucket: String,
    pub description: Option<String>,
    pub max_value_size: i64,
    pub history: i64,
    pub ttl: Option<f64>,
    pub max_bytes: i64,
    pub storage: StorageType,
    pub replicas: i64,
}

impl Default for KeyValueConfig {
    fn default() -> Self {
        Self {
            bucket: String::new(),
            description: None,
            max_value_size: -1,
            history: 1,
            ttl: None,
            max_bytes: -1,
            storage: StorageType::File,
            replicas: 1,
        }
    }
}

impl KeyValueConfig {
    pub fn new(bucket: impl Into<String>) -> Self {
        Self {
            bucket: bucket.into(),
            ..Self::default()
        }
    }

    pub fn to_stream_config(&self) -> StreamConfig {
        let mut sc = StreamConfig::new(format!("KV_{}", self.bucket));
        sc.subjects = vec![format!("$KV.{}.>", self.bucket)];
        sc.description.clone_from(&self.description);
        sc.retention = RetentionPolicy::Limits;
        sc.max_bytes = self.max_bytes;
        sc.max_msgs_per_subject = self.history;
        sc.max_msg_size = if self.max_value_size > 0 {
            Some(self.max_value_size)
        } else {
            None
        };
        sc.max_age = self.ttl;
        sc.storage = self.storage;
        sc.replicas = self.replicas;
        sc.discard = DiscardPolicy::New;
        sc.allow_direct = true;
        sc.allow_rollup = true;
        sc
    }
}

#[derive(Clone, Debug)]
pub struct KeyValueEntry {
    pub bucket: String,
    pub key: String,
    pub value: Vec<u8>,
    pub revision: i64,
    pub created: Option<String>,
    pub operation: KeyValueOperation,
}

#[derive(Clone, Debug)]
pub struct KeyValueStatus {
    pub bucket: String,
    pub values: i64,
    pub bytes: i64,
    pub history: i64,
    pub ttl: Option<f64>,
    pub stream_info: StreamInfo,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum KeyValueOperation {
    Put,
    Delete,
    Purge,
}

#[derive(Clone, Debug, Default)]
pub struct KeyValueWatchOptions {
    pub include_history: bool,
    pub updates_only: bool,
    pub ignore_deletes: bool,
    pub meta_only: bool,
}

impl KeyValue {
    pub fn new(conn: Connection, js: JetStream, bucket: impl Into<String>) -> Self {
        Self {
            conn,
            js,
            bucket: bucket.into(),
        }
    }

    pub fn get_bucket(&self) -> &str {
        &self.bucket
    }

    pub fn bucket(&self) -> &str {
        &self.bucket
    }

    pub fn stream_name(&self) -> String {
        format!("KV_{}", self.bucket)
    }

    pub fn get(&self, key: &str) -> Result<KeyValueEntry, NatsError> {
        self.validate_key(key)?;
        let subject = format!("$KV.{}.{}", self.bucket, key);
        let payload = json!({"last_by_subj": subject});
        let body = serde_json::to_vec(&payload).unwrap_or_default();
        let msg = self
            .conn
            .request(
                &format!("$JS.API.DIRECT.GET.KV_{}", self.bucket),
                &body,
                None,
                None,
            )
            .map_err(|_| KeyValueException(format!("Key not found: {key}")))?;
        if let Some(headers) = &msg.headers {
            let op = headers.get("KV-Operation");
            if op == Some("DEL") || op == Some("PURGE") {
                return Err(KeyValueException(format!("Key not found: {key}")).into());
            }
        }
        let mut revision = 0i64;
        let mut created = None;
        if let Some(headers) = &msg.headers {
            if let Some(seq) = headers.get("Nats-Sequence") {
                revision = seq.parse().unwrap_or(0);
            }
            created = headers.get("Nats-Time-Stamp").map(str::to_owned);
        }
        Ok(KeyValueEntry {
            bucket: self.bucket.clone(),
            key: key.to_owned(),
            value: msg.data,
            revision,
            created,
            operation: KeyValueOperation::Put,
        })
    }

    pub fn put(&self, key: &str, value: &[u8]) -> Result<i64, NatsError> {
        self.validate_key(key)?;
        let subject = format!("$KV.{}.{}", self.bucket, key);
        let ack = self
            .js
            .publish(&subject, value, None, None, None, None, None, None, None, 0)?;
        Ok(ack.sequence)
    }

    pub fn create(&self, key: &str, value: &[u8]) -> Result<i64, NatsError> {
        self.validate_key(key)?;
        let subject = format!("$KV.{}.{}", self.bucket, key);
        let mut headers = Headers::new();
        headers.set("Nats-Expected-Last-Subject-Sequence", "0");
        self.js
            .publish(
                &subject,
                value,
                Some(headers),
                None,
                None,
                None,
                None,
                None,
                None,
                0,
            )
            .map(|ack| ack.sequence)
            .map_err(|_| KeyValueException(format!("Key already exists: {key}")).into())
    }

    pub fn update(&self, key: &str, value: &[u8], revision: i64) -> Result<i64, NatsError> {
        self.validate_key(key)?;
        let subject = format!("$KV.{}.{}", self.bucket, key);
        self.js
            .publish(
                &subject,
                value,
                None,
                None,
                None,
                None,
                Some(revision),
                None,
                None,
                0,
            )
            .map(|ack| ack.sequence)
            .map_err(|_| KeyValueException(format!("Wrong last revision for key: {key}")).into())
    }

    pub fn delete(&self, key: &str) -> Result<(), NatsError> {
        self.validate_key(key)?;
        let subject = format!("$KV.{}.{}", self.bucket, key);
        let mut headers = Headers::new();
        headers.set("KV-Operation", "DEL");
        self.js.publish(
            &subject,
            b"",
            Some(headers),
            None,
            None,
            None,
            None,
            None,
            None,
            0,
        )?;
        Ok(())
    }

    pub fn purge(&self, key: &str) -> Result<(), NatsError> {
        self.validate_key(key)?;
        let subject = format!("$KV.{}.{}", self.bucket, key);
        let mut headers = Headers::new();
        headers.set("KV-Operation", "PURGE");
        headers.set("Nats-Rollup", "sub");
        self.js.publish(
            &subject,
            b"",
            Some(headers),
            None,
            None,
            None,
            None,
            None,
            None,
            0,
        )?;
        Ok(())
    }

    pub fn keys(&self) -> Result<Vec<String>, NatsError> {
        let stream_name = format!("KV_{}", self.bucket);
        let subject = format!("$KV.{}.>", self.bucket);
        let payload = json!({"subjects_filter": subject});
        let body = serde_json::to_vec(&payload).unwrap_or_default();
        let msg = match self.conn.request(
            &format!("$JS.API.STREAM.INFO.{stream_name}"),
            &body,
            None,
            None,
        ) {
            Ok(m) => m,
            Err(_) => return Ok(Vec::new()),
        };
        let data: Value = serde_json::from_slice(&msg.data).unwrap_or(json!({}));
        if JetStream::check_error(&data).is_err() {
            return Ok(Vec::new());
        }
        let prefix = format!("$KV.{}.", self.bucket);
        let mut keys = Vec::new();
        if let Some(subjects) = data
            .get("state")
            .and_then(|s| s.get("subjects"))
            .and_then(Value::as_object)
        {
            for subj in subjects.keys() {
                if let Some(rest) = subj.strip_prefix(&prefix) {
                    keys.push(rest.to_owned());
                }
            }
        }
        Ok(keys)
    }

    pub fn status(&self) -> Result<KeyValueStatus, NatsError> {
        let info = self.js.get_stream_info(&format!("KV_{}", self.bucket))?;
        Ok(KeyValueStatus {
            bucket: self.bucket.clone(),
            values: info.state.messages,
            bytes: info.state.bytes,
            history: info.config.max_msgs_per_subject,
            ttl: info.config.max_age,
            stream_info: info,
        })
    }

    pub fn get_revision(&self, key: &str, seq: i64) -> Result<KeyValueEntry, NatsError> {
        self.validate_key(key)?;
        self.fetch_stored(&json!({"seq": seq}), key)
    }

    pub fn history(&self, key: &str) -> Result<Vec<KeyValueEntry>, NatsError> {
        self.validate_key(key)?;
        let stream = format!("KV_{}", self.bucket);
        let subject = format!("$KV.{}.{}", self.bucket, key);
        let config = ConsumerConfig {
            deliver_policy: DeliverPolicy::All,
            ack_policy: AckPolicy::Explicit,
            filter_subject: Some(subject),
            inactive_threshold: Some(30.0),
            ..ConsumerConfig::default()
        };
        let consumer = self.js.create_consumer(&stream, &config)?;
        let result = (|| {
            let mut entries = Vec::new();
            for msg in consumer.fetch(1024, Some(1.0), false, None)? {
                entries.push(self.entry_from_delivered(key, &msg));
                let _ = msg.ack();
            }
            Ok(entries)
        })();
        let _ = self.js.delete_consumer(&stream, consumer.get_name());
        result
    }

    pub fn watch(
        &self,
        key_pattern: &str,
        callback: impl Fn(KeyValueEntry) + Send + Sync + 'static,
        options: Option<KeyValueWatchOptions>,
        on_init_done: Option<Arc<dyn Fn() + Send + Sync>>,
    ) -> Result<Subscription, NatsError> {
        let options = options.unwrap_or(KeyValueWatchOptions {
            updates_only: true,
            ..KeyValueWatchOptions::default()
        });
        let stream = format!("KV_{}", self.bucket);
        let filter = format!("$KV.{}.{key_pattern}", self.bucket);
        let deliver_subject = self.conn.new_inbox();
        let deliver_policy = if options.include_history {
            "all"
        } else if options.updates_only {
            "new"
        } else {
            "last_per_subject"
        };
        let mut config = json!({
            "deliver_subject": deliver_subject,
            "deliver_policy": deliver_policy,
            "ack_policy": "none",
            "filter_subject": filter,
            "inactive_threshold": 30_000_000_000i64,
        });
        if options.meta_only {
            config["headers_only"] = json!(true);
        }
        let payload = json!({
            "stream_name": stream,
            "config": config,
        });
        let body = serde_json::to_vec(&payload).unwrap_or_default();
        let response = self.conn.request(
            &format!("$JS.API.CONSUMER.CREATE.{stream}"),
            &body,
            None,
            None,
        )?;
        let data: Value = serde_json::from_slice(&response.data).unwrap_or(json!({}));
        JetStream::check_error(&data)?;
        let num_pending = data.get("num_pending").and_then(Value::as_i64).unwrap_or(0);
        let delivered = Arc::new(AtomicI64::new(0));
        let init_signaled = Arc::new(AtomicBool::new(false));
        let kv = self.clone();
        let delivered_cb = Arc::clone(&delivered);
        let init_cb = Arc::clone(&init_signaled);
        let on_done = on_init_done.clone();
        let handler: MessageCallback = Arc::new(move |msg| {
            if msg
                .headers
                .as_ref()
                .is_some_and(|h| !h.get_status().is_empty())
            {
                return;
            }
            let n = delivered_cb.fetch_add(1, Ordering::Relaxed) + 1;
            let entry = kv.entry_from_message(&msg);
            let is_marker = matches!(
                entry.operation,
                KeyValueOperation::Delete | KeyValueOperation::Purge
            );
            if !options.ignore_deletes || !is_marker {
                callback(entry);
            }
            if !init_cb.load(Ordering::Relaxed) && n >= num_pending {
                init_cb.store(true, Ordering::Relaxed);
                if let Some(cb) = &on_done {
                    cb();
                }
            }
        });
        let sub = self.conn.subscribe(&deliver_subject, Some(handler), None)?;
        if num_pending == 0 && !init_signaled.load(Ordering::Relaxed) {
            init_signaled.store(true, Ordering::Relaxed);
            if let Some(cb) = on_init_done {
                cb();
            }
        }
        Ok(sub)
    }

    pub fn purge_deletes(&self, threshold: Option<f64>) -> Result<i64, NatsError> {
        let mut purged = 0i64;
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs_f64();
        for key in self.keys()? {
            let subject = format!("$KV.{}.{}", self.bucket, key);
            let payload = json!({"last_by_subj": subject});
            let body = serde_json::to_vec(&payload).unwrap_or_default();
            let msg = match self.conn.request(
                &format!("$JS.API.DIRECT.GET.KV_{}", self.bucket),
                &body,
                None,
                None,
            ) {
                Ok(m) => m,
                Err(_) => continue,
            };
            let Some(headers) = &msg.headers else {
                continue;
            };
            let op = headers.get("KV-Operation");
            if op != Some("DEL") && op != Some("PURGE") {
                continue;
            }
            let mut keep = 0i64;
            if let Some(th) = threshold {
                if let Some(ts) = headers.get("Nats-Time-Stamp") {
                    if let Some(created) = parse_unix(ts) {
                        if (now - created) < th {
                            keep = 1;
                        }
                    }
                }
            }
            let purge_payload = json!({"filter": subject, "keep": keep});
            let purge_body = serde_json::to_vec(&purge_payload).unwrap_or_default();
            let response = self.conn.request(
                &format!("$JS.API.STREAM.PURGE.KV_{}", self.bucket),
                &purge_body,
                None,
                None,
            )?;
            let result: Value = serde_json::from_slice(&response.data).unwrap_or(json!({}));
            JetStream::check_error(&result)?;
            if keep == 0 {
                purged += 1;
            }
        }
        Ok(purged)
    }

    pub fn create_bucket(js: &JetStream, config: &KeyValueConfig) -> Result<Self, NatsError> {
        js.create_key_value(config)
    }

    fn entry_from_delivered(&self, key: &str, msg: &JetStreamMessage) -> KeyValueEntry {
        let meta = msg.metadata().ok();
        KeyValueEntry {
            bucket: self.bucket.clone(),
            key: key.to_owned(),
            value: msg.get_data().to_vec(),
            revision: meta.as_ref().map_or(0, |m| m.stream_sequence),
            created: meta.map(|m| m.timestamp),
            operation: self.operation_from_headers(msg.get_headers()),
        }
    }

    fn entry_from_message(&self, msg: &Message) -> KeyValueEntry {
        let prefix = format!("$KV.{}.", self.bucket);
        let key = msg
            .subject
            .strip_prefix(&prefix)
            .unwrap_or(&msg.subject)
            .to_owned();
        let mut revision = 0i64;
        let mut created = None;
        if let Some(reply) = &msg.reply_to {
            if let Ok(meta) = MsgMetadata::from_reply_subject(reply) {
                revision = meta.stream_sequence;
                created = Some(meta.timestamp);
            }
        }
        KeyValueEntry {
            bucket: self.bucket.clone(),
            key,
            value: msg.data.clone(),
            revision,
            created,
            operation: self.operation_from_headers(msg.headers.as_ref()),
        }
    }

    fn fetch_stored(&self, request: &Value, key: &str) -> Result<KeyValueEntry, NatsError> {
        let body = serde_json::to_vec(request).unwrap_or_default();
        let response = self
            .conn
            .request(
                &format!("$JS.API.STREAM.MSG.GET.KV_{}", self.bucket),
                &body,
                None,
                None,
            )
            .map_err(|_| KeyValueException(format!("Revision not found for key: {key}")))?;
        let data: Value = serde_json::from_slice(&response.data).unwrap_or(json!({}));
        JetStream::check_error(&data)?;
        let stored = data
            .get("message")
            .ok_or_else(|| KeyValueException(format!("Revision not found for key: {key}")))?;
        let expected_subject = format!("$KV.{}.{}", self.bucket, key);
        if let Some(subj) = stored.get("subject").and_then(Value::as_str) {
            if subj != expected_subject {
                return Err(KeyValueException(format!("Revision not found for key: {key}")).into());
            }
        }
        let headers = stored.get("hdrs").and_then(Value::as_str).and_then(|s| {
            base64::engine::general_purpose::STANDARD
                .decode(s)
                .ok()
                .and_then(|raw| Headers::from_wire(&String::from_utf8_lossy(&raw)).ok())
        });
        let value = stored
            .get("data")
            .and_then(Value::as_str)
            .and_then(|s| base64::engine::general_purpose::STANDARD.decode(s).ok())
            .unwrap_or_default();
        Ok(KeyValueEntry {
            bucket: self.bucket.clone(),
            key: key.to_owned(),
            value,
            revision: stored.get("seq").and_then(Value::as_i64).unwrap_or(0),
            created: stored
                .get("time")
                .and_then(Value::as_str)
                .map(str::to_owned),
            operation: self.operation_from_headers(headers.as_ref()),
        })
    }

    fn operation_from_headers(&self, headers: Option<&Headers>) -> KeyValueOperation {
        match headers.and_then(|h| h.get("KV-Operation")) {
            Some("DEL") => KeyValueOperation::Delete,
            Some("PURGE") => KeyValueOperation::Purge,
            _ => KeyValueOperation::Put,
        }
    }

    fn validate_key(&self, key: &str) -> Result<(), NatsError> {
        if key.is_empty() || key.contains(' ') || key.contains('>') || key.contains('*') {
            return Err(KeyValueException(format!("Invalid key: {key}")).into());
        }
        Ok(())
    }
}

fn parse_unix(ts: &str) -> Option<f64> {
    time::OffsetDateTime::parse(ts, &time::format_description::well_known::Rfc3339)
        .ok()
        .map(|t| t.unix_timestamp() as f64)
}
