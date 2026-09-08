//! Object store over `JetStream` (PHP `Utopia\NATS\ObjectStore`).

use crate::connection::Connection;
use crate::error::{NatsError, ObjectStoreException};
use crate::headers::Headers;
use crate::jetstream::{
    AckPolicy, ConsumerConfig, DeliverPolicy, DiscardPolicy, JetStream, RetentionPolicy,
    StorageType, StreamConfig, StreamInfo,
};
use crate::subscription::{MessageCallback, Subscription};
use base64::Engine;
use rand::RngCore;
use serde_json::{json, Map, Value};
use sha2::{Digest, Sha256};
use std::sync::Arc;

const CHUNK_SIZE: usize = 128 * 1024;

#[derive(Clone, Debug)]
pub struct ObjectStore {
    conn: Connection,
    js: JetStream,
    bucket: String,
}

#[derive(Clone, Debug)]
pub struct ObjectStoreConfig {
    pub bucket: String,
    pub description: Option<String>,
    pub max_bytes: i64,
    pub ttl: Option<f64>,
    pub storage: StorageType,
    pub replicas: i64,
}

impl Default for ObjectStoreConfig {
    fn default() -> Self {
        Self {
            bucket: String::new(),
            description: None,
            max_bytes: -1,
            ttl: None,
            storage: StorageType::File,
            replicas: 1,
        }
    }
}

impl ObjectStoreConfig {
    pub fn new(bucket: impl Into<String>) -> Self {
        Self {
            bucket: bucket.into(),
            ..Self::default()
        }
    }

    pub fn to_stream_config(&self) -> StreamConfig {
        let mut sc = StreamConfig::new(format!("OBJ_{}", self.bucket));
        sc.subjects = vec![
            format!("$O.{}.C.>", self.bucket),
            format!("$O.{}.M.>", self.bucket),
        ];
        sc.description.clone_from(&self.description);
        sc.retention = RetentionPolicy::Limits;
        sc.max_bytes = self.max_bytes;
        sc.max_age = self.ttl;
        sc.storage = self.storage;
        sc.replicas = self.replicas;
        sc.discard = DiscardPolicy::New;
        sc.allow_direct = true;
        sc.allow_rollup = true;
        sc
    }
}

#[derive(Clone, Debug, Default)]
pub struct ObjectMeta {
    pub name: String,
    pub bucket: String,
    pub nuid: String,
    pub size: i64,
    pub chunks: i64,
    pub digest: String,
    pub description: Option<String>,
    pub modified: Option<String>,
    pub deleted: bool,
    pub metadata: Option<Map<String, Value>>,
    pub link: Option<ObjectLink>,
}

impl ObjectMeta {
    pub fn to_array(&self) -> Map<String, Value> {
        let mut data = Map::new();
        data.insert("name".into(), json!(self.name));
        data.insert("bucket".into(), json!(self.bucket));
        data.insert("nuid".into(), json!(self.nuid));
        data.insert("size".into(), json!(self.size));
        data.insert("chunks".into(), json!(self.chunks));
        data.insert("digest".into(), json!(self.digest));
        if let Some(v) = &self.description {
            data.insert("description".into(), json!(v));
        }
        if let Some(v) = &self.modified {
            data.insert("mtime".into(), json!(v));
        }
        if self.deleted {
            data.insert("deleted".into(), json!(true));
        }
        if let Some(meta) = &self.metadata {
            if !meta.is_empty() {
                data.insert("metadata".into(), Value::Object(meta.clone()));
            }
        }
        if let Some(link) = &self.link {
            data.insert(
                "options".into(),
                json!({"link": Value::Object(link.to_array())}),
            );
        }
        data
    }

    pub fn from_array(data: &Value) -> Self {
        let metadata = data.get("metadata").and_then(Value::as_object).cloned();
        let link = data
            .get("options")
            .and_then(|o| o.get("link"))
            .map(ObjectLink::from_array);
        Self {
            name: data
                .get("name")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            bucket: data
                .get("bucket")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            nuid: data
                .get("nuid")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            size: data.get("size").and_then(Value::as_i64).unwrap_or(0),
            chunks: data.get("chunks").and_then(Value::as_i64).unwrap_or(0),
            digest: data
                .get("digest")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            description: data
                .get("description")
                .and_then(Value::as_str)
                .map(str::to_owned),
            modified: data.get("mtime").and_then(Value::as_str).map(str::to_owned),
            deleted: data
                .get("deleted")
                .and_then(Value::as_bool)
                .unwrap_or(false),
            metadata,
            link,
        }
    }
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ObjectLink {
    pub bucket: String,
    pub name: Option<String>,
}

impl ObjectLink {
    pub fn new(bucket: impl Into<String>, name: Option<String>) -> Self {
        Self {
            bucket: bucket.into(),
            name,
        }
    }

    pub fn to_array(&self) -> Map<String, Value> {
        let mut data = Map::new();
        data.insert("bucket".into(), json!(self.bucket));
        if let Some(n) = &self.name {
            if !n.is_empty() {
                data.insert("name".into(), json!(n));
            }
        }
        data
    }

    pub fn from_array(data: &Value) -> Self {
        Self {
            bucket: data
                .get("bucket")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned(),
            name: data.get("name").and_then(Value::as_str).map(str::to_owned),
        }
    }
}

impl ObjectStore {
    pub fn new(conn: Connection, js: JetStream, bucket: impl Into<String>) -> Self {
        Self {
            conn,
            js,
            bucket: bucket.into(),
        }
    }

    pub fn create_or_update(
        conn: Connection,
        js: &JetStream,
        config: &ObjectStoreConfig,
    ) -> Result<Self, NatsError> {
        js.create_or_update_stream(&config.to_stream_config())?;
        Ok(Self::new(conn, js.clone(), &config.bucket))
    }

    pub fn get_bucket(&self) -> &str {
        &self.bucket
    }

    pub fn bucket(&self) -> &str {
        &self.bucket
    }

    pub fn put(&self, name: &str, data: &[u8]) -> Result<ObjectMeta, NatsError> {
        let (previous, previous_seq) = self.read_meta_with_seq(name);
        self.write_version(name, data, previous.as_ref(), previous_seq)
    }

    fn write_version(
        &self,
        name: &str,
        data: &[u8],
        previous: Option<&ObjectMeta>,
        expected_seq: i64,
    ) -> Result<ObjectMeta, NatsError> {
        let nuid = random_nuid();
        let chunk_subject = format!("$O.{}.C.{nuid}", self.bucket);
        let mut chunks = 0i64;
        for chunk in data.chunks(CHUNK_SIZE) {
            self.js.publish(
                &chunk_subject,
                chunk,
                None,
                None,
                None,
                None,
                None,
                None,
                None,
                0,
            )?;
            chunks += 1;
        }
        let meta = ObjectMeta {
            name: name.to_owned(),
            bucket: self.bucket.clone(),
            nuid: nuid.clone(),
            size: data.len() as i64,
            chunks,
            digest: digest(data),
            modified: Some(gmt_stamp()),
            ..ObjectMeta::default()
        };
        let mut headers = Headers::new();
        headers.set("Nats-Rollup", "sub");
        if self
            .js
            .publish(
                &self.meta_subject(name),
                &serde_json::to_vec(&Value::Object(meta.to_array())).unwrap_or_default(),
                Some(headers),
                None,
                None,
                None,
                Some(expected_seq),
                None,
                None,
                0,
            )
            .is_err()
        {
            self.purge_chunks(&nuid);
            return Err(ObjectStoreException(format!(
                "conflicting concurrent write for object: {name}"
            ))
            .into());
        }
        if let Some(prev) = previous {
            if !prev.nuid.is_empty() && prev.nuid != nuid {
                self.purge_chunks(&prev.nuid);
            }
        }
        Ok(meta)
    }

    pub fn get(&self, name: &str) -> Result<Vec<u8>, NatsError> {
        let meta = self.read_meta(name);
        if meta.as_ref().map_or(true, |m| m.deleted) {
            return Err(crate::error::NatsException(format!("Object not found: {name}")).into());
        }
        let meta = meta.unwrap();
        if let Some(link) = &meta.link {
            let Some(target_name) = link.name.as_deref().filter(|name| !name.is_empty()) else {
                return Err(
                    ObjectStoreException(format!("cannot get a bucket link: {name}")).into(),
                );
            };
            return self.get(target_name);
        }
        let mut data = Vec::new();
        if meta.chunks > 0 {
            let stream = format!("OBJ_{}", self.bucket);
            let subject = format!("$O.{}.C.{}", self.bucket, meta.nuid);
            let config = ConsumerConfig {
                deliver_policy: DeliverPolicy::All,
                ack_policy: AckPolicy::Explicit,
                filter_subject: Some(subject),
                inactive_threshold: Some(30.0),
                ..ConsumerConfig::default()
            };
            let consumer = self.js.create_consumer(&stream, &config)?;
            let fetched = consumer.fetch(meta.chunks, Some(10.0), false, None);
            let _ = self.js.delete_consumer(&stream, consumer.get_name());
            for msg in fetched? {
                data.extend_from_slice(msg.get_data());
                let _ = msg.ack();
            }
        }
        if data.len() as i64 != meta.size || digest(&data) != meta.digest {
            return Err(crate::error::NatsException(format!(
                "Object integrity check failed: {name}"
            ))
            .into());
        }
        Ok(data)
    }

    pub fn get_meta(&self, name: &str) -> Result<ObjectMeta, NatsError> {
        match self.read_meta(name) {
            Some(m) if !m.deleted => Ok(m),
            _ => Err(crate::error::NatsException(format!("Object not found: {name}")).into()),
        }
    }

    pub fn delete(&self, name: &str) -> Result<(), NatsError> {
        let (meta, expected_seq) = self.read_meta_with_seq(name);
        let Some(meta) = meta.filter(|m| !m.deleted) else {
            return Ok(());
        };
        self.delete_version(&meta, expected_seq)
    }

    fn delete_version(&self, meta: &ObjectMeta, expected_seq: i64) -> Result<(), NatsError> {
        let tombstone = ObjectMeta {
            name: meta.name.clone(),
            bucket: meta.bucket.clone(),
            nuid: String::new(),
            size: 0,
            chunks: 0,
            digest: String::new(),
            description: meta.description.clone(),
            modified: Some(gmt_stamp()),
            deleted: true,
            ..ObjectMeta::default()
        };
        self.publish_meta(&tombstone, expected_seq)?;
        if !meta.nuid.is_empty() {
            self.purge_chunks(&meta.nuid);
        }
        Ok(())
    }

    pub fn list(&self) -> Result<Vec<ObjectMeta>, NatsError> {
        let stream = format!("OBJ_{}", self.bucket);
        let subject = format!("$O.{}.M.>", self.bucket);
        let config = ConsumerConfig {
            deliver_policy: DeliverPolicy::LastPerSubject,
            ack_policy: AckPolicy::Explicit,
            filter_subject: Some(subject),
            inactive_threshold: Some(30.0),
            ..ConsumerConfig::default()
        };
        let consumer = match self.js.create_consumer(&stream, &config) {
            Ok(c) => c,
            Err(_) => return Ok(Vec::new()),
        };
        let result = (|| {
            let mut objects = Vec::new();
            for msg in consumer.fetch(1024, Some(1.0), false, None)? {
                let _ = msg.ack();
                if let Ok(decoded) = serde_json::from_slice::<Value>(msg.get_data()) {
                    let meta = ObjectMeta::from_array(&decoded);
                    if !meta.deleted {
                        objects.push(meta);
                    }
                }
            }
            Ok(objects)
        })();
        let _ = self.js.delete_consumer(&stream, consumer.get_name());
        result
    }

    pub fn status(&self) -> Result<StreamInfo, NatsError> {
        self.js.get_stream_info(&format!("OBJ_{}", self.bucket))
    }

    pub fn watch(
        &self,
        callback: impl Fn(ObjectMeta) + Send + Sync + 'static,
        include_history: bool,
    ) -> Result<Subscription, NatsError> {
        let stream = format!("OBJ_{}", self.bucket);
        let filter = format!("$O.{}.M.>", self.bucket);
        let deliver_subject = self.conn.new_inbox();
        let payload = json!({
            "stream_name": stream,
            "config": {
                "deliver_subject": deliver_subject,
                "deliver_policy": if include_history { "last_per_subject" } else { "new" },
                "ack_policy": "none",
                "filter_subject": filter,
                "inactive_threshold": 30_000_000_000i64,
            }
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
        let handler: MessageCallback = Arc::new(move |msg| {
            if msg
                .headers
                .as_ref()
                .is_some_and(|h| !h.get_status().is_empty())
            {
                return;
            }
            if let Ok(decoded) = serde_json::from_slice::<Value>(&msg.data) {
                callback(ObjectMeta::from_array(&decoded));
            }
        });
        self.conn.subscribe(&deliver_subject, Some(handler), None)
    }

    pub fn add_link(
        &self,
        link_name: &str,
        target_object_name: &str,
    ) -> Result<ObjectMeta, NatsError> {
        self.write_link(
            link_name,
            ObjectLink::new(&self.bucket, Some(target_object_name.to_owned())),
        )
    }

    pub fn add_bucket_link(
        &self,
        link_name: &str,
        target_bucket: &str,
    ) -> Result<ObjectMeta, NatsError> {
        self.write_link(link_name, ObjectLink::new(target_bucket, None))
    }

    pub fn update_meta(
        &self,
        name: &str,
        description: Option<String>,
        metadata: Option<Map<String, Value>>,
    ) -> Result<ObjectMeta, NatsError> {
        let (previous, expected_seq) = self.read_meta_with_seq(name);
        let Some(previous) = previous.filter(|m| !m.deleted) else {
            return Err(crate::error::NatsException(format!("Object not found: {name}")).into());
        };
        let meta = ObjectMeta {
            name: previous.name.clone(),
            bucket: previous.bucket.clone(),
            nuid: previous.nuid.clone(),
            size: previous.size,
            chunks: previous.chunks,
            digest: previous.digest.clone(),
            description: description.or(previous.description),
            modified: Some(gmt_stamp()),
            metadata: metadata.or(previous.metadata),
            link: previous.link,
            deleted: false,
        };
        self.publish_meta(&meta, expected_seq)?;
        Ok(meta)
    }

    pub fn seal(&self) -> Result<(), NatsError> {
        let stream = format!("OBJ_{}", self.bucket);
        let info_response =
            self.conn
                .request(&format!("$JS.API.STREAM.INFO.{stream}"), b"", None, None)?;
        let info: Value = serde_json::from_slice(&info_response.data).unwrap_or(json!({}));
        JetStream::check_error(&info)?;
        let mut config = info
            .get("config")
            .and_then(Value::as_object)
            .cloned()
            .ok_or_else(|| {
                ObjectStoreException(format!(
                    "cannot read stream config for bucket: {}",
                    self.bucket
                ))
            })?;
        config.retain(|_, v| v != &json!([]));
        config.insert("sealed".into(), json!(true));
        let update_response = self.conn.request(
            &format!("$JS.API.STREAM.UPDATE.{stream}"),
            &serde_json::to_vec(&Value::Object(config)).unwrap_or_default(),
            None,
            None,
        )?;
        let updated: Value = serde_json::from_slice(&update_response.data).unwrap_or(json!({}));
        JetStream::check_error(&updated)
    }

    fn write_link(&self, link_name: &str, link: ObjectLink) -> Result<ObjectMeta, NatsError> {
        let (previous, expected_seq) = self.read_meta_with_seq(link_name);
        let meta = ObjectMeta {
            name: link_name.to_owned(),
            bucket: self.bucket.clone(),
            modified: Some(gmt_stamp()),
            link: Some(link),
            ..ObjectMeta::default()
        };
        self.publish_meta(&meta, expected_seq)?;
        if let Some(prev) = previous {
            if !prev.nuid.is_empty() {
                self.purge_chunks(&prev.nuid);
            }
        }
        Ok(meta)
    }

    fn publish_meta(&self, meta: &ObjectMeta, expected_seq: i64) -> Result<(), NatsError> {
        let mut headers = Headers::new();
        headers.set("Nats-Rollup", "sub");
        self.js
            .publish(
                &self.meta_subject(&meta.name),
                &serde_json::to_vec(&Value::Object(meta.to_array())).unwrap_or_default(),
                Some(headers),
                None,
                None,
                None,
                Some(expected_seq),
                None,
                None,
                0,
            )
            .map(|_| ())
            .map_err(|_| {
                ObjectStoreException(format!(
                    "conflicting concurrent write for object: {}",
                    meta.name
                ))
                .into()
            })
    }

    fn read_meta(&self, name: &str) -> Option<ObjectMeta> {
        self.read_meta_with_seq(name).0
    }

    fn read_meta_with_seq(&self, name: &str) -> (Option<ObjectMeta>, i64) {
        let payload = json!({"last_by_subj": self.meta_subject(name)});
        let body = serde_json::to_vec(&payload).unwrap_or_default();
        let Ok(response) = self.conn.request(
            &format!("$JS.API.STREAM.MSG.GET.OBJ_{}", self.bucket),
            &body,
            None,
            None,
        ) else {
            return (None, 0);
        };
        let data: Value = serde_json::from_slice(&response.data).unwrap_or(json!({}));
        if data.get("error").is_some() || data.pointer("/message/data").is_none() {
            return (None, 0);
        }
        let Some(encoded) = data
            .get("message")
            .and_then(|m| m.get("data"))
            .and_then(Value::as_str)
        else {
            return (None, 0);
        };
        let Ok(raw) = base64::engine::general_purpose::STANDARD.decode(encoded) else {
            return (None, 0);
        };
        let Ok(decoded) = serde_json::from_slice::<Value>(&raw) else {
            return (None, 0);
        };
        let seq = data
            .get("message")
            .and_then(|m| m.get("seq"))
            .and_then(Value::as_i64)
            .unwrap_or(0);
        (Some(ObjectMeta::from_array(&decoded)), seq)
    }

    fn purge_chunks(&self, nuid: &str) {
        let _ = self.js.purge_stream(
            &format!("OBJ_{}", self.bucket),
            Some(&format!("$O.{}.C.{nuid}", self.bucket)),
        );
    }

    fn meta_subject(&self, name: &str) -> String {
        let token = base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(name.as_bytes());
        format!("$O.{}.M.{token}", self.bucket)
    }
}

fn digest(data: &[u8]) -> String {
    let hash = Sha256::digest(data);
    format!(
        "SHA-256={}",
        base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(hash)
    )
}

fn random_nuid() -> String {
    let mut buf = [0u8; 12];
    rand::thread_rng().fill_bytes(&mut buf);
    hex_upper(&buf)
}

fn hex_upper(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    let mut out = String::with_capacity(bytes.len() * 2);
    for b in bytes {
        out.push(HEX[(b >> 4) as usize] as char);
        out.push(HEX[(b & 0x0f) as usize] as char);
    }
    out
}

fn gmt_stamp() -> String {
    time::OffsetDateTime::now_utc()
        .format(
            &time::format_description::parse("[year]-[month]-[day]T[hour]:[minute]:[second]Z")
                .unwrap(),
        )
        .unwrap_or_default()
}
