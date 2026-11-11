//! An object store on a JetStream stream (`OBJ_<bucket>`): objects split
//! into chunks, with a meta record per name.

use base64::Engine as _;
use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use futures_util::future::BoxFuture;
use indexmap::IndexMap;
use rand::Rng;
use serde_json::Value;
use sha2::{Digest, Sha256};

use crate::connection::Connection;
use crate::error::{CallbackError, Error, Result};
use crate::fields::{self, Object};
use crate::headers::Headers;
use crate::jetstream::{
    AckPolicy, ConsumerConfig, DeliverPolicy, DiscardPolicy, JetStream, PublishOptions, RetentionPolicy, StorageType,
    StreamConfig, StreamInfo,
};
use crate::message::Message;
use crate::subscription::Subscription;

const CHUNK_SIZE: usize = 128 * 1024;

/// Where a link points: an object of this bucket, or a whole bucket.
///
/// PHP: `Utopia\NATS\ObjectStore\ObjectLink`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct ObjectLink {
    /// The target bucket.
    pub bucket: String,
    /// The target object (`None` for a bucket link).
    pub name: Option<String>,
}

impl ObjectLink {
    /// The meta record's `link` object.
    ///
    /// PHP: `ObjectLink::toArray()`.
    pub fn to_value(&self) -> Value {
        Object::new()
            .set("bucket", self.bucket.as_str())
            .opt("name", self.name.as_deref().filter(|n| !n.is_empty()))
            .build()
    }

    /// Reads a `link` object.
    ///
    /// PHP: `ObjectLink::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        ObjectLink {
            bucket: fields::get(data, "bucket").map(fields::php_string).unwrap_or_default(),
            name: fields::get(data, "name").map(fields::php_string),
        }
    }
}

/// An object's meta record.
///
/// PHP: `Utopia\NATS\ObjectStore\ObjectMeta`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct ObjectMeta {
    /// The object's name.
    pub name: String,
    /// Its bucket.
    pub bucket: String,
    /// The id its chunks are stored under.
    pub nuid: String,
    /// Its size in bytes.
    pub size: i64,
    /// How many chunks.
    pub chunks: i64,
    /// `SHA-256=<base64url digest>`.
    pub digest: String,
    /// A description.
    pub description: Option<String>,
    /// When it was written (`mtime`).
    pub modified: Option<String>,
    /// Whether this is a deletion marker.
    pub deleted: bool,
    /// Application metadata.
    pub metadata: Option<IndexMap<String, String>>,
    /// Where it points, for a link.
    pub link: Option<ObjectLink>,
}

impl ObjectMeta {
    /// The meta record as stored.
    ///
    /// PHP: `ObjectMeta::toArray()`.
    pub fn to_value(&self) -> Value {
        let mut o = Object::new();
        o.set("name", self.name.as_str())
            .set("bucket", self.bucket.as_str())
            .set("nuid", self.nuid.as_str())
            .set("size", self.size)
            .set("chunks", self.chunks)
            .set("digest", self.digest.as_str())
            .opt("description", self.description.as_deref())
            .opt("mtime", self.modified.as_deref());
        if self.deleted {
            o.set("deleted", true);
        }
        o.opt("metadata", self.metadata.as_ref().filter(|m| !m.is_empty()).map(fields::map_value))
            .opt("options", self.link.as_ref().map(|link| Object::new().set("link", link.to_value()).build()));
        o.build()
    }

    /// Reads a meta record.
    ///
    /// PHP: `ObjectMeta::fromArray()`.
    pub fn from_value(data: &Value) -> Self {
        let metadata = match fields::get(data, "metadata") {
            Some(Value::Object(m)) => Some(m.iter().map(|(k, v)| (k.clone(), fields::php_string(v))).collect()),
            Some(Value::Array(a)) => {
                Some(a.iter().enumerate().map(|(i, v)| (i.to_string(), fields::php_string(v))).collect())
            }
            _ => None,
        };
        let link = fields::get(data, "options")
            .and_then(|o| fields::get(o, "link"))
            .filter(|l| l.is_object() || l.is_array())
            .map(ObjectLink::from_value);
        ObjectMeta {
            name: fields::get(data, "name").map(fields::php_string).unwrap_or_default(),
            bucket: fields::get(data, "bucket").map(fields::php_string).unwrap_or_default(),
            nuid: fields::get(data, "nuid").map(fields::php_string).unwrap_or_default(),
            size: fields::get(data, "size").map_or(0, fields::php_int),
            chunks: fields::get(data, "chunks").map_or(0, fields::php_int),
            digest: fields::get(data, "digest").map(fields::php_string).unwrap_or_default(),
            description: fields::get(data, "description").map(fields::php_string),
            modified: fields::get(data, "mtime").map(fields::php_string),
            deleted: fields::get(data, "deleted").is_some_and(fields::php_bool),
            metadata,
            link,
        }
    }
}

/// A bucket's configuration.
///
/// PHP: `Utopia\NATS\ObjectStore\ObjectStoreConfig`.
#[derive(Debug, Clone, PartialEq)]
pub struct ObjectStoreConfig {
    /// The bucket name.
    pub bucket: String,
    /// A description.
    pub description: Option<String>,
    /// The bucket's size limit (`-1`: unlimited).
    pub max_bytes: i64,
    /// How long objects live, in seconds.
    pub ttl: Option<f64>,
    /// Where objects are stored.
    pub storage: StorageType,
    /// Replicas.
    pub replicas: i64,
}

impl ObjectStoreConfig {
    /// The defaults for `bucket`.
    ///
    /// PHP: `new ObjectStoreConfig($bucket)`.
    pub fn new(bucket: impl Into<String>) -> Self {
        ObjectStoreConfig {
            bucket: bucket.into(),
            description: None,
            max_bytes: -1,
            ttl: None,
            storage: StorageType::File,
            replicas: 1,
        }
    }

    /// The backing stream's configuration.
    ///
    /// PHP: `ObjectStoreConfig::toStreamConfig()`.
    pub fn to_stream_config(&self) -> StreamConfig {
        StreamConfig {
            subjects: vec![format!("$O.{}.C.>", self.bucket), format!("$O.{}.M.>", self.bucket)],
            description: self.description.clone(),
            retention: RetentionPolicy::Limits,
            max_bytes: self.max_bytes,
            max_age: self.ttl,
            storage: self.storage,
            replicas: self.replicas,
            discard: DiscardPolicy::New,
            allow_direct: true,
            allow_rollup: true,
            ..StreamConfig::new(format!("OBJ_{}", self.bucket))
        }
    }
}

/// A callback receiving watched meta records.
pub type MetaCallback = Box<dyn FnMut(ObjectMeta) -> Result<(), CallbackError> + Send>;

/// An object store bucket.
///
/// PHP: `Utopia\NATS\ObjectStore\ObjectStore`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ObjectStore {
    js: JetStream,
    bucket: String,
}

impl ObjectStore {
    /// The bucket `bucket` through `js`.
    ///
    /// PHP: `new ObjectStore($connection, $js, $bucket)`.
    pub fn new(js: JetStream, bucket: impl Into<String>) -> Self {
        ObjectStore { js, bucket: bucket.into() }
    }

    /// Creates (or updates) the backing stream.
    ///
    /// PHP: `ObjectStore::createOrUpdate()`.
    pub async fn create_or_update(conn: &mut Connection, js: &JetStream, config: &ObjectStoreConfig) -> Result<Self> {
        js.create_or_update_stream(conn, &config.to_stream_config()).await?;
        Ok(ObjectStore::new(js.clone(), &config.bucket))
    }

    /// Stores an object (replacing the previous version, whose chunks are
    /// purged).
    ///
    /// PHP: `ObjectStore::put()`.
    pub async fn put(&self, conn: &mut Connection, name: &str, data: &[u8]) -> Result<ObjectMeta> {
        let (previous, seq) = self.read_meta_with_seq(conn, name).await?;
        self.write_version(conn, name, data, previous.as_ref(), seq).await
    }

    /// Writes a version of an object whose meta record must still be at
    /// sequence `expected_seq` (`0`: must not exist).
    ///
    /// # Errors
    ///
    /// [`Error::ObjectStore`] (`conflicting concurrent write`) when the
    /// record moved on; the chunks this write stored are purged first.
    ///
    /// PHP: `ObjectStore::writeVersion()` (private).
    pub async fn write_version(
        &self,
        conn: &mut Connection,
        name: &str,
        data: &[u8],
        previous: Option<&ObjectMeta>,
        expected_seq: i64,
    ) -> Result<ObjectMeta> {
        let mut raw = [0u8; 12];
        rand::rng().fill_bytes(&mut raw);
        let nuid = hex_upper(&raw);
        let chunk_subject = format!("$O.{}.C.{nuid}", self.bucket);
        let mut chunks = 0;
        for chunk in data.chunks(CHUNK_SIZE) {
            self.js.publish(conn, &chunk_subject, chunk, PublishOptions::default(), 0).await?;
            chunks += 1;
        }
        let meta = ObjectMeta {
            name: name.to_owned(),
            bucket: self.bucket.clone(),
            nuid: nuid.clone(),
            size: data.len() as i64,
            chunks,
            digest: digest(data),
            modified: Some(now()),
            ..Default::default()
        };
        let mut headers = Headers::new();
        headers.set("Nats-Rollup", "sub");
        let options = PublishOptions {
            headers: Some(headers),
            expected_last_subject_seq: Some(expected_seq),
            ..Default::default()
        };
        let body = crate::json::encode(&meta.to_value())?;
        if let Err(error) = self.js.publish(conn, &self.meta_subject(name), body.as_bytes(), options, 0).await {
            if error.api_err_code() != Some(JetStream::ERR_WRONG_LAST_SEQUENCE) {
                return Err(error);
            }
            self.purge_chunks(conn, &nuid).await;
            return Err(Error::ObjectStore(format!("conflicting concurrent write for object: {name}")));
        }
        if let Some(previous) = previous
            && !previous.nuid.is_empty()
            && previous.nuid != nuid
        {
            self.purge_chunks(conn, &previous.nuid).await;
        }
        Ok(meta)
    }

    /// An object's bytes, reassembled and verified (a link resolves to its
    /// target).
    ///
    /// # Errors
    ///
    /// [`Error::Runtime`] for a missing object or a failed integrity check,
    /// [`Error::ObjectStore`] for a bucket link.
    ///
    /// PHP: `ObjectStore::get()`.
    pub fn get<'a>(&'a self, conn: &'a mut Connection, name: &'a str) -> BoxFuture<'a, Result<Vec<u8>>> {
        Box::pin(async move {
            let meta = match self.read_meta_with_seq(conn, name).await?.0 {
                Some(meta) if !meta.deleted => meta,
                _ => return Err(Error::Runtime(format!("Object not found: {name}"))),
            };
            if let Some(link) = &meta.link {
                return match link.name.as_deref().filter(|n| !n.is_empty()) {
                    Some(target) => self.get(conn, target).await,
                    None => Err(Error::ObjectStore(format!("cannot get a bucket link: {name}"))),
                };
            }
            let mut data = Vec::with_capacity(meta.size.max(0) as usize);
            if meta.chunks > 0 {
                let stream = format!("OBJ_{}", self.bucket);
                let config = ConsumerConfig {
                    deliver_policy: DeliverPolicy::All,
                    ack_policy: AckPolicy::Explicit,
                    filter_subject: Some(format!("$O.{}.C.{}", self.bucket, meta.nuid)),
                    inactive_threshold: Some(30.0),
                    ..Default::default()
                };
                let consumer = self.js.create_consumer(conn, &stream, &config).await?;
                let fetched: Result<()> = async {
                    for message in
                        consumer.fetch(conn, meta.chunks, Some(std::time::Duration::from_secs(10)), false, None).await?
                    {
                        data.extend_from_slice(message.data());
                        message.ack(conn).await?;
                    }
                    Ok(())
                }
                .await;
                let _ = self.js.delete_consumer(conn, &stream, consumer.name()).await;
                fetched?;
            }
            if data.len() as i64 != meta.size || digest(&data) != meta.digest {
                return Err(Error::Runtime(format!("Object integrity check failed: {name}")));
            }
            Ok(data)
        })
    }

    /// An object's meta record.
    ///
    /// PHP: `ObjectStore::getMeta()`.
    pub async fn get_meta(&self, conn: &mut Connection, name: &str) -> Result<ObjectMeta> {
        match self.read_meta_with_seq(conn, name).await?.0 {
            Some(meta) if !meta.deleted => Ok(meta),
            _ => Err(Error::Runtime(format!("Object not found: {name}"))),
        }
    }

    /// Deletes an object (nothing when it is missing).
    ///
    /// PHP: `ObjectStore::delete()`.
    pub async fn delete(&self, conn: &mut Connection, name: &str) -> Result<()> {
        let (meta, seq) = self.read_meta_with_seq(conn, name).await?;
        match meta {
            Some(meta) if !meta.deleted => self.delete_version(conn, &meta, seq).await,
            _ => Ok(()),
        }
    }

    /// Writes a deletion marker guarded by `expected_seq`, then purges the
    /// object's chunks.
    ///
    /// # Errors
    ///
    /// [`Error::ObjectStore`] when the record moved on (nothing is purged).
    ///
    /// PHP: `ObjectStore::deleteVersion()` (private).
    pub async fn delete_version(&self, conn: &mut Connection, meta: &ObjectMeta, expected_seq: i64) -> Result<()> {
        let tombstone = ObjectMeta {
            name: meta.name.clone(),
            bucket: meta.bucket.clone(),
            description: meta.description.clone(),
            modified: Some(now()),
            deleted: true,
            ..Default::default()
        };
        self.publish_meta(conn, &tombstone, expected_seq).await?;
        if !meta.nuid.is_empty() {
            self.purge_chunks(conn, &meta.nuid).await;
        }
        Ok(())
    }

    /// The objects of the bucket (not the deleted ones).
    ///
    /// PHP: `ObjectStore::list()`.
    pub async fn list(&self, conn: &mut Connection) -> Result<Vec<ObjectMeta>> {
        let stream = format!("OBJ_{}", self.bucket);
        let config = ConsumerConfig {
            deliver_policy: DeliverPolicy::LastPerSubject,
            ack_policy: AckPolicy::Explicit,
            filter_subject: Some(format!("$O.{}.M.>", self.bucket)),
            inactive_threshold: Some(30.0),
            ..Default::default()
        };
        let Ok(consumer) = self.js.create_consumer(conn, &stream, &config).await else {
            return Ok(Vec::new());
        };
        let listed: Result<Vec<ObjectMeta>> = async {
            let mut objects = Vec::new();
            for message in consumer.fetch(conn, 1024, Some(std::time::Duration::from_secs(1)), false, None).await? {
                message.ack(conn).await?;
                let decoded = crate::json::decode(message.data())?;
                if !(decoded.is_object() || decoded.is_array()) {
                    continue;
                }
                let meta = ObjectMeta::from_value(&decoded);
                if !meta.deleted {
                    objects.push(meta);
                }
            }
            Ok(objects)
        }
        .await;
        let _ = self.js.delete_consumer(conn, &stream, consumer.name()).await;
        listed
    }

    /// The backing stream's info.
    ///
    /// PHP: `ObjectStore::status()`.
    pub async fn status(&self, conn: &mut Connection) -> Result<StreamInfo> {
        self.js.get_stream_info(conn, &format!("OBJ_{}", self.bucket)).await
    }

    /// Watches the bucket's meta records (puts and deletes), delivering
    /// them to `callback` as the connection reads them; with
    /// `include_history`, every object's current record first.
    ///
    /// PHP: `ObjectStore::watch()`.
    pub async fn watch(
        &self,
        conn: &mut Connection,
        mut callback: MetaCallback,
        include_history: bool,
    ) -> Result<Subscription> {
        let stream = format!("OBJ_{}", self.bucket);
        let deliver = conn.new_inbox();
        let config = Object::new()
            .set("deliver_subject", deliver.as_str())
            .set("deliver_policy", if include_history { "last_per_subject" } else { "new" })
            .set("ack_policy", "none")
            .set("filter_subject", format!("$O.{}.M.>", self.bucket))
            .set("inactive_threshold", 30_000_000_000i64)
            .build();
        let payload = Object::new().set("stream_name", stream.as_str()).set("config", config).build();
        let response = conn
            .request(&format!("$JS.API.CONSUMER.CREATE.{stream}"), crate::json::encode(&payload)?, None, None)
            .await?;
        let data = crate::json::decode(&response.data)?;
        JetStream::check_error(&data)?;
        let handler = Box::new(move |message: Message| -> Result<(), CallbackError> {
            if !message.status().is_empty() {
                return Ok(());
            }
            let decoded = crate::json::decode(&message.data)?;
            if !(decoded.is_object() || decoded.is_array()) {
                return Ok(());
            }
            callback(ObjectMeta::from_value(&decoded))
        });
        conn.subscribe(&deliver, Some(handler), None).await
    }

    /// Adds `link_name`, pointing to `target` in this bucket.
    ///
    /// PHP: `ObjectStore::addLink()`.
    pub async fn add_link(&self, conn: &mut Connection, link_name: &str, target: &str) -> Result<ObjectMeta> {
        self.write_link(conn, link_name, ObjectLink { bucket: self.bucket.clone(), name: Some(target.to_owned()) })
            .await
    }

    /// Adds `link_name`, pointing to the bucket `target_bucket`.
    ///
    /// PHP: `ObjectStore::addBucketLink()`.
    pub async fn add_bucket_link(
        &self,
        conn: &mut Connection,
        link_name: &str,
        target_bucket: &str,
    ) -> Result<ObjectMeta> {
        self.write_link(conn, link_name, ObjectLink { bucket: target_bucket.to_owned(), name: None }).await
    }

    /// Changes an object's description and metadata, keeping its bytes.
    ///
    /// PHP: `ObjectStore::updateMeta()`.
    pub async fn update_meta(
        &self,
        conn: &mut Connection,
        name: &str,
        description: Option<&str>,
        metadata: Option<IndexMap<String, String>>,
    ) -> Result<ObjectMeta> {
        let (previous, seq) = self.read_meta_with_seq(conn, name).await?;
        let previous = match previous {
            Some(previous) if !previous.deleted => previous,
            _ => return Err(Error::Runtime(format!("Object not found: {name}"))),
        };
        let meta = ObjectMeta {
            description: description.map(str::to_owned).or(previous.description),
            modified: Some(now()),
            metadata: metadata.or(previous.metadata),
            deleted: false,
            ..previous
        };
        self.publish_meta(conn, &meta, seq).await?;
        Ok(meta)
    }

    /// Seals the bucket: its stream becomes read-only.
    ///
    /// PHP: `ObjectStore::seal()`.
    pub async fn seal(&self, conn: &mut Connection) -> Result<()> {
        let stream = format!("OBJ_{}", self.bucket);
        let response = conn.request(&format!("$JS.API.STREAM.INFO.{stream}"), "", None, None).await?;
        let info = crate::json::decode(&response.data)?;
        JetStream::check_error(&info)?;
        let mut config = match fields::get(&info, "config") {
            None => serde_json::Map::new(),
            Some(Value::Object(config)) => config.clone(),
            Some(Value::Array(config)) if config.is_empty() => serde_json::Map::new(),
            Some(_) => {
                return Err(Error::ObjectStore(format!("cannot read stream config for bucket: {}", self.bucket)));
            }
        };
        config.retain(|_, v| {
            !matches!(v, Value::Array(a) if a.is_empty()) && !matches!(v, Value::Object(o) if o.is_empty())
        });
        config.insert("sealed".into(), Value::Bool(true));
        let body = crate::json::encode(&Value::Object(config))?;
        let response = conn.request(&format!("$JS.API.STREAM.UPDATE.{stream}"), body, None, None).await?;
        JetStream::check_error(&crate::json::decode(&response.data)?)
    }

    /// The bucket name.
    ///
    /// PHP: `ObjectStore::getBucket()`.
    pub fn bucket(&self) -> &str {
        &self.bucket
    }

    async fn write_link(&self, conn: &mut Connection, link_name: &str, link: ObjectLink) -> Result<ObjectMeta> {
        let (previous, seq) = self.read_meta_with_seq(conn, link_name).await?;
        let meta = ObjectMeta {
            name: link_name.to_owned(),
            bucket: self.bucket.clone(),
            modified: Some(now()),
            link: Some(link),
            ..Default::default()
        };
        self.publish_meta(conn, &meta, seq).await?;
        if let Some(previous) = previous
            && !previous.nuid.is_empty()
        {
            self.purge_chunks(conn, &previous.nuid).await;
        }
        Ok(meta)
    }

    async fn publish_meta(&self, conn: &mut Connection, meta: &ObjectMeta, expected_seq: i64) -> Result<()> {
        let mut headers = Headers::new();
        headers.set("Nats-Rollup", "sub");
        let options = PublishOptions {
            headers: Some(headers),
            expected_last_subject_seq: Some(expected_seq),
            ..Default::default()
        };
        let body = crate::json::encode(&meta.to_value())?;
        match self.js.publish(conn, &self.meta_subject(&meta.name), body.as_bytes(), options, 0).await {
            Ok(_) => Ok(()),
            Err(error) if error.api_err_code() == Some(JetStream::ERR_WRONG_LAST_SEQUENCE) => {
                Err(Error::ObjectStore(format!("conflicting concurrent write for object: {}", meta.name)))
            }
            Err(error) => Err(error),
        }
    }

    /// The latest meta record of `name` and its stream sequence (`None`
    /// and `0` when there is none).
    ///
    /// PHP: `ObjectStore::readMetaWithSeq()` (private).
    pub async fn read_meta_with_seq(&self, conn: &mut Connection, name: &str) -> Result<(Option<ObjectMeta>, i64)> {
        let body = crate::json::encode(&Object::new().set("last_by_subj", self.meta_subject(name)).build())?;
        let Ok(response) = conn.request(&format!("$JS.API.STREAM.MSG.GET.OBJ_{}", self.bucket), body, None, None).await
        else {
            return Ok((None, 0));
        };
        let data = crate::json::decode(&response.data)?;
        let message = fields::get(&data, "message");
        let encoded = message.and_then(|m| fields::get(m, "data"));
        let (Some(message), Some(encoded)) = (message, encoded) else {
            return Ok((None, 0));
        };
        if fields::get(&data, "error").is_some() {
            return Ok((None, 0));
        }
        let raw = php_std::encoding::base64_decode(fields::php_string(encoded).as_bytes(), true).unwrap_or_default();
        let decoded = crate::json::decode(&raw)?;
        if !(decoded.is_object() || decoded.is_array()) {
            return Ok((None, 0));
        }
        let seq = fields::get(message, "seq").map_or(0, fields::php_int);
        Ok((Some(ObjectMeta::from_value(&decoded)), seq))
    }

    async fn purge_chunks(&self, conn: &mut Connection, nuid: &str) {
        let subject = format!("$O.{}.C.{nuid}", self.bucket);
        let _ = self.js.purge_stream(conn, &format!("OBJ_{}", self.bucket), Some(&subject)).await;
    }

    fn meta_subject(&self, name: &str) -> String {
        format!("$O.{}.M.{}", self.bucket, URL_SAFE_NO_PAD.encode(name))
    }
}

/// `SHA-256=` and the base64url digest, without padding.
fn digest(data: &[u8]) -> String {
    format!("SHA-256={}", URL_SAFE_NO_PAD.encode(Sha256::digest(data)))
}

/// `gmdate('Y-m-d\TH:i:s\Z')`.
fn now() -> String {
    let seconds = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0, |d| d.as_secs() as i64);
    String::from_utf8_lossy(&php_std::datetime::gmdate(b"Y-m-d\\TH:i:s\\Z", seconds)).into_owned()
}

fn hex_upper(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    bytes.iter().flat_map(|b| [HEX[usize::from(b >> 4)] as char, HEX[usize::from(b & 15)] as char]).collect()
}
