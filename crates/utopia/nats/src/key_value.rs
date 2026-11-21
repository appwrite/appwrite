//! A key-value store on a JetStream stream (`KV_<bucket>`).

use bytes::Bytes;
use serde_json::Value;

use crate::connection::Connection;
use crate::error::{CallbackError, Error, Result};
use crate::fields::{self, Object};
use crate::headers::Headers;
use crate::jetstream::{
    AckPolicy, ConsumerConfig, DeliverPolicy, DiscardPolicy, JetStream, JetStreamMessage, MsgMetadata, PublishOptions,
    RetentionPolicy, StorageType, StreamConfig, StreamInfo,
};
use crate::message::Message;
use crate::subscription::Subscription;

/// What an entry records.
///
/// PHP: `Utopia\NATS\KeyValue\KeyValueOperation`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Default)]
pub enum KeyValueOperation {
    /// `PUT`: a value.
    #[default]
    Put,
    /// `DEL`: a delete marker.
    Delete,
    /// `PURGE`: a purge marker.
    Purge,
}

impl KeyValueOperation {
    /// `PUT`, `DEL` or `PURGE`.
    ///
    /// PHP: the enum case's `$value`.
    pub fn as_str(self) -> &'static str {
        match self {
            KeyValueOperation::Put => "PUT",
            KeyValueOperation::Delete => "DEL",
            KeyValueOperation::Purge => "PURGE",
        }
    }

    fn from_headers(headers: Option<&Headers>) -> Self {
        match headers.and_then(|h| h.get("KV-Operation")) {
            Some("DEL") => KeyValueOperation::Delete,
            Some("PURGE") => KeyValueOperation::Purge,
            _ => KeyValueOperation::Put,
        }
    }
}

/// A bucket's configuration.
///
/// PHP: `Utopia\NATS\KeyValue\KeyValueConfig`.
#[derive(Debug, Clone, PartialEq)]
pub struct KeyValueConfig {
    /// The bucket name.
    pub bucket: String,
    /// A description.
    pub description: Option<String>,
    /// The largest value (`-1`: unlimited).
    pub max_value_size: i64,
    /// Revisions kept per key.
    pub history: i64,
    /// How long values live, in seconds.
    pub ttl: Option<f64>,
    /// The bucket's size limit (`-1`: unlimited).
    pub max_bytes: i64,
    /// Where values are stored.
    pub storage: StorageType,
    /// Replicas.
    pub replicas: i64,
}

impl KeyValueConfig {
    /// The defaults for `bucket`: one revision per key, file storage.
    ///
    /// PHP: `new KeyValueConfig($bucket)`.
    pub fn new(bucket: impl Into<String>) -> Self {
        KeyValueConfig {
            bucket: bucket.into(),
            description: None,
            max_value_size: -1,
            history: 1,
            ttl: None,
            max_bytes: -1,
            storage: StorageType::File,
            replicas: 1,
        }
    }

    /// The backing stream's configuration.
    ///
    /// PHP: `KeyValueConfig::toStreamConfig()`.
    pub fn to_stream_config(&self) -> StreamConfig {
        StreamConfig {
            subjects: vec![format!("$KV.{}.>", self.bucket)],
            description: self.description.clone(),
            retention: RetentionPolicy::Limits,
            max_bytes: self.max_bytes,
            max_msgs_per_subject: self.history,
            max_msg_size: (self.max_value_size > 0).then_some(self.max_value_size),
            max_age: self.ttl,
            storage: self.storage,
            replicas: self.replicas,
            discard: DiscardPolicy::New,
            allow_direct: true,
            allow_rollup: true,
            ..StreamConfig::new(format!("KV_{}", self.bucket))
        }
    }
}

/// One revision of a key.
///
/// PHP: `Utopia\NATS\KeyValue\KeyValueEntry`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct KeyValueEntry {
    /// The bucket.
    pub bucket: String,
    /// The key.
    pub key: String,
    /// The value (empty for markers).
    pub value: Bytes,
    /// The revision (stream sequence).
    pub revision: i64,
    /// When it was stored.
    pub created: Option<String>,
    /// What it records.
    pub operation: KeyValueOperation,
}

/// A bucket's size and settings.
///
/// PHP: `Utopia\NATS\KeyValue\KeyValueStatus`.
#[derive(Debug, Clone, PartialEq)]
pub struct KeyValueStatus {
    /// The bucket.
    pub bucket: String,
    /// Stored entries.
    pub values: i64,
    /// Stored bytes.
    pub bytes: i64,
    /// Revisions kept per key.
    pub history: i64,
    /// Value lifetime in seconds.
    pub ttl: Option<f64>,
    /// The backing stream's info.
    pub stream_info: StreamInfo,
}

/// What a watch delivers.
///
/// PHP: `Utopia\NATS\KeyValue\KeyValueWatchOptions`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct KeyValueWatchOptions {
    /// Every stored revision first, then live updates.
    pub include_history: bool,
    /// Only updates from now on (what a watch without options does).
    pub updates_only: bool,
    /// Skip delete and purge markers.
    pub ignore_deletes: bool,
    /// Headers only, without values.
    pub meta_only: bool,
}

/// A callback receiving watched entries.
pub type EntryCallback = Box<dyn FnMut(KeyValueEntry) -> Result<(), CallbackError> + Send>;
/// Called once the initial set of a watch was delivered.
pub type InitCallback = Box<dyn FnMut() -> Result<(), CallbackError> + Send>;

/// A key-value bucket.
///
/// ```
/// # utopia_async::run(async {
/// use utopia_nats::key_value::KeyValueConfig;
/// # let options = utopia_nats::ConnectionOptions { connect_timeout: std::time::Duration::from_millis(200),
/// #     ..utopia_nats::ConnectionOptions::new([std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into())]) };
/// # let Ok(mut connection) = utopia_nats::Connection::connect(options).await else { return Ok(()) };
/// let js = connection.jetstream(None, None);
/// let kv = js.create_key_value(&mut connection, &KeyValueConfig { history: 5, ..KeyValueConfig::new("doc_config") }).await?;
/// let revision = kv.put(&mut connection, "app.name", "My Service").await?;
/// let entry = kv.get(&mut connection, "app.name").await?;
/// assert_eq!((entry.value.as_ref(), entry.revision), (&b"My Service"[..], revision));
/// js.delete_key_value(&mut connection, "doc_config").await?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\NATS\Connection;
/// use Utopia\NATS\ConnectionOptions;
/// use Utopia\NATS\KeyValue\KeyValueConfig;
/// use Utopia\NATS\Exception\ConnectionException;
///
/// try {
///     $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
/// } catch (ConnectionException) {
///     return;
/// }
/// $js = $connection->jetStream();
/// $kv = $js->createKeyValue(new KeyValueConfig(bucket: 'doc_config', history: 5));
/// $revision = $kv->put('app.name', 'My Service');
/// $kv->get('app.name')->value; // 'My Service'
/// $js->deleteKeyValue('doc_config');
/// ```
///
/// PHP: `Utopia\NATS\KeyValue\KeyValue`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct KeyValue {
    js: JetStream,
    bucket: String,
}

impl KeyValue {
    /// The bucket `bucket` through `js`.
    ///
    /// PHP: `new KeyValue($connection, $js, $bucket)`.
    pub fn new(js: JetStream, bucket: impl Into<String>) -> Self {
        KeyValue { js, bucket: bucket.into() }
    }

    fn subject(&self, key: &str) -> String {
        format!("$KV.{}.{key}", self.bucket)
    }

    /// The latest value of `key`.
    ///
    /// A missing key reads as an empty entry at revision 0 (the server's
    /// 404 status reply is not an error to the request).
    ///
    /// # Errors
    ///
    /// [`Error::KeyValue`] for an invalid key, a deleted or purged key, or
    /// any failure of the request.
    ///
    /// PHP: `KeyValue::get()`.
    pub async fn get(&self, conn: &mut Connection, key: &str) -> Result<KeyValueEntry> {
        validate_key(key)?;
        let message = match self.direct_get(conn, key).await {
            Ok(message) => message,
            Err(_) => return Err(Error::KeyValue(format!("Key not found: {key}"))),
        };
        if let Some(headers) = &message.headers
            && matches!(headers.get("KV-Operation"), Some("DEL" | "PURGE"))
        {
            return Err(Error::KeyValue(format!("Key not found: {key}")));
        }
        let headers = message.headers.as_ref();
        Ok(KeyValueEntry {
            bucket: self.bucket.clone(),
            key: key.to_owned(),
            value: message.data.clone(),
            revision: headers
                .and_then(|h| h.get("Nats-Sequence"))
                .map_or(0, |s| php_std::format::str_to_int(s.as_bytes())),
            created: headers.and_then(|h| h.get("Nats-Time-Stamp")).map(str::to_owned),
            operation: KeyValueOperation::Put,
        })
    }

    async fn direct_get(&self, conn: &mut Connection, key: &str) -> Result<Message> {
        let body = crate::json::encode(&Object::new().set("last_by_subj", self.subject(key)).build())?;
        conn.request(&format!("$JS.API.DIRECT.GET.KV_{}", self.bucket), body, None, None).await
    }

    /// Stores a value, returning its revision.
    ///
    /// PHP: `KeyValue::put()`.
    pub async fn put(&self, conn: &mut Connection, key: &str, value: impl AsRef<[u8]>) -> Result<i64> {
        validate_key(key)?;
        let ack = self.js.publish(conn, &self.subject(key), value.as_ref(), PublishOptions::default(), 0).await?;
        Ok(ack.sequence)
    }

    /// Stores a value only if the key has none.
    ///
    /// # Errors
    ///
    /// [`Error::KeyValue`] (`Key already exists`) when the server refused
    /// it for that reason; any other failure as it is.
    ///
    /// PHP: `KeyValue::create()`.
    pub async fn create(&self, conn: &mut Connection, key: &str, value: impl AsRef<[u8]>) -> Result<i64> {
        validate_key(key)?;
        let mut headers = Headers::new();
        headers.set("Nats-Expected-Last-Subject-Sequence", "0");
        let options = PublishOptions { headers: Some(headers), ..Default::default() };
        match self.js.publish(conn, &self.subject(key), value.as_ref(), options, 0).await {
            Ok(ack) => Ok(ack.sequence),
            Err(error) if error.api_err_code() == Some(JetStream::ERR_WRONG_LAST_SEQUENCE) => {
                Err(Error::KeyValue(format!("Key already exists: {key}")))
            }
            Err(error) => Err(error),
        }
    }

    /// Stores a value only if the key is at `revision`.
    ///
    /// # Errors
    ///
    /// [`Error::KeyValue`] (`Wrong last revision`) when the server refused
    /// it for that reason; any other failure as it is.
    ///
    /// PHP: `KeyValue::update()`.
    pub async fn update(
        &self,
        conn: &mut Connection,
        key: &str,
        value: impl AsRef<[u8]>,
        revision: i64,
    ) -> Result<i64> {
        validate_key(key)?;
        let options = PublishOptions { expected_last_subject_seq: Some(revision), ..Default::default() };
        match self.js.publish(conn, &self.subject(key), value.as_ref(), options, 0).await {
            Ok(ack) => Ok(ack.sequence),
            Err(error) if error.api_err_code() == Some(JetStream::ERR_WRONG_LAST_SEQUENCE) => {
                Err(Error::KeyValue(format!("Wrong last revision for key: {key}")))
            }
            Err(error) => Err(error),
        }
    }

    /// Writes a delete marker.
    ///
    /// PHP: `KeyValue::delete()`.
    pub async fn delete(&self, conn: &mut Connection, key: &str) -> Result<()> {
        validate_key(key)?;
        let mut headers = Headers::new();
        headers.set("KV-Operation", "DEL");
        let options = PublishOptions { headers: Some(headers), ..Default::default() };
        self.js.publish(conn, &self.subject(key), b"", options, 0).await.map(drop)
    }

    /// Writes a purge marker, rolling up the key's history.
    ///
    /// PHP: `KeyValue::purge()`.
    pub async fn purge(&self, conn: &mut Connection, key: &str) -> Result<()> {
        validate_key(key)?;
        let mut headers = Headers::new();
        headers.set("KV-Operation", "PURGE").set("Nats-Rollup", "sub");
        let options = PublishOptions { headers: Some(headers), ..Default::default() };
        self.js.publish(conn, &self.subject(key), b"", options, 0).await.map(drop)
    }

    /// The keys with stored entries (markers included); empty on any
    /// failure.
    ///
    /// PHP: `KeyValue::keys()`.
    pub async fn keys(&self, conn: &mut Connection) -> Result<Vec<String>> {
        let request = Object::new().set("subjects_filter", format!("$KV.{}.>", self.bucket)).build();
        let found: Result<Vec<String>> = async {
            let body = crate::json::encode(&request)?;
            let message = conn.request(&format!("$JS.API.STREAM.INFO.KV_{}", self.bucket), body, None, None).await?;
            let data = crate::json::decode(&message.data)?;
            JetStream::check_error(&data)?;
            let prefix = format!("$KV.{}.", self.bucket);
            let subjects = fields::get(&data, "state").and_then(|s| fields::get(s, "subjects"));
            Ok(match subjects {
                Some(Value::Object(subjects)) => {
                    subjects.keys().filter_map(|s| s.strip_prefix(prefix.as_str())).map(str::to_owned).collect()
                }
                _ => Vec::new(),
            })
        }
        .await;
        Ok(found.unwrap_or_default())
    }

    /// The bucket's size and settings.
    ///
    /// PHP: `KeyValue::status()`.
    pub async fn status(&self, conn: &mut Connection) -> Result<KeyValueStatus> {
        let info = self.js.get_stream_info(conn, &format!("KV_{}", self.bucket)).await?;
        Ok(KeyValueStatus {
            bucket: self.bucket.clone(),
            values: info.state.messages,
            bytes: info.state.bytes,
            history: info.config.max_msgs_per_subject,
            ttl: info.config.max_age,
            stream_info: info,
        })
    }

    /// The revision `seq` of `key`.
    ///
    /// # Errors
    ///
    /// [`Error::KeyValue`] (`Revision not found`) when the request fails or
    /// the sequence belongs to another key; [`Error::JetStream`] when the
    /// stream has no such sequence.
    ///
    /// PHP: `KeyValue::getRevision()`.
    pub async fn get_revision(&self, conn: &mut Connection, key: &str, seq: i64) -> Result<KeyValueEntry> {
        validate_key(key)?;
        self.fetch_stored(conn, Object::new().set("seq", seq).build(), key).await
    }

    /// Every stored revision of `key`, oldest first.
    ///
    /// PHP: `KeyValue::history()`.
    pub async fn history(&self, conn: &mut Connection, key: &str) -> Result<Vec<KeyValueEntry>> {
        validate_key(key)?;
        let stream = format!("KV_{}", self.bucket);
        let config = ConsumerConfig {
            deliver_policy: DeliverPolicy::All,
            ack_policy: AckPolicy::Explicit,
            filter_subject: Some(self.subject(key)),
            inactive_threshold: Some(30.0),
            ..Default::default()
        };
        let consumer = self.js.create_consumer(conn, &stream, &config).await?;
        let entries: Result<Vec<KeyValueEntry>> = async {
            let mut entries = Vec::new();
            for message in consumer.fetch(conn, 1024, Some(std::time::Duration::from_secs(1)), false, None).await? {
                entries.push(self.entry_from_delivered(key, &message)?);
                message.ack(conn).await?;
            }
            Ok(entries)
        }
        .await;
        let _ = self.js.delete_consumer(conn, &stream, consumer.name()).await;
        entries
    }

    /// Watches a key (or a wildcard pattern): delivers entries to
    /// `callback` as the connection reads them. Without options only new
    /// updates arrive. `on_init_done` runs once the initial set was
    /// delivered (at once when there is none).
    ///
    /// PHP: `KeyValue::watch()`.
    pub async fn watch(
        &self,
        conn: &mut Connection,
        key_pattern: &str,
        mut callback: EntryCallback,
        options: Option<KeyValueWatchOptions>,
        on_init_done: Option<InitCallback>,
    ) -> Result<Subscription> {
        let options = options.unwrap_or(KeyValueWatchOptions { updates_only: true, ..Default::default() });
        let stream = format!("KV_{}", self.bucket);
        let deliver = conn.new_inbox();
        let deliver_policy = if options.include_history {
            DeliverPolicy::All
        } else if options.updates_only {
            DeliverPolicy::New
        } else {
            DeliverPolicy::LastPerSubject
        };
        let mut config = Object::new();
        config
            .set("deliver_subject", deliver.as_str())
            .set("deliver_policy", deliver_policy.as_str())
            .set("ack_policy", "none")
            .set("filter_subject", format!("$KV.{}.{key_pattern}", self.bucket))
            .set("inactive_threshold", 30_000_000_000i64);
        if options.meta_only {
            config.set("headers_only", true);
        }
        let payload = Object::new().set("stream_name", stream.as_str()).set("config", config.build()).build();
        let response = conn
            .request(&format!("$JS.API.CONSUMER.CREATE.{stream}"), crate::json::encode(&payload)?, None, None)
            .await?;
        let data = crate::json::decode(&response.data)?;
        JetStream::check_error(&data)?;
        let num_pending = fields::get(&data, "num_pending").map_or(0, fields::php_int);

        let signaled_now = num_pending == 0;
        let mut init = on_init_done;
        let mut pending_init = if signaled_now { None } else { init.take() };
        let mut delivered = 0i64;
        let mut signaled = signaled_now;
        let bucket = self.bucket.clone();
        let handler = Box::new(move |message: Message| -> Result<(), CallbackError> {
            if !message.status().is_empty() {
                return Ok(());
            }
            delivered += 1;
            let entry = entry_from_message(&bucket, message)?;
            let marker = matches!(entry.operation, KeyValueOperation::Delete | KeyValueOperation::Purge);
            if !options.ignore_deletes || !marker {
                callback(entry)?;
            }
            if !signaled && delivered >= num_pending {
                signaled = true;
                if let Some(init) = pending_init.as_mut() {
                    init()?;
                }
            }
            Ok(())
        });
        let subscription = conn.subscribe(&deliver, Some(handler), None).await?;
        if signaled_now && let Some(mut init) = init {
            init().map_err(|e| Error::Callback(e.into()))?;
        }
        Ok(subscription)
    }

    /// Removes delete and purge markers: purges each key whose latest entry
    /// is one (keeping markers younger than `threshold` seconds). Returns
    /// how many keys were purged.
    ///
    /// PHP: `KeyValue::purgeDeletes()`.
    pub async fn purge_deletes(&self, conn: &mut Connection, threshold: Option<f64>) -> Result<i64> {
        let now = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0.0, |d| d.as_secs_f64());
        let mut purged = 0;
        for key in self.keys(conn).await? {
            let Ok(message) = self.direct_get(conn, &key).await else {
                continue;
            };
            let Some(headers) = &message.headers else {
                continue;
            };
            if !matches!(headers.get("KV-Operation"), Some("DEL" | "PURGE")) {
                continue;
            }
            let mut keep = 0;
            if let Some(threshold) = threshold {
                let created = headers.get("Nats-Time-Stamp").and_then(|ts| {
                    php_std::datetime::strtotime(ts.as_bytes(), now as i64, &php_std::datetime::TzInfo::utc())
                });
                if let Some(created) = created
                    && now - (created as f64) < threshold
                {
                    keep = 1;
                }
            }
            let body = crate::json::encode(&Object::new().set("filter", self.subject(&key)).set("keep", keep).build())?;
            let response = conn.request(&format!("$JS.API.STREAM.PURGE.KV_{}", self.bucket), body, None, None).await?;
            let result = crate::json::decode(&response.data)?;
            JetStream::check_error(&result)?;
            if keep == 0 {
                purged += 1;
            }
        }
        Ok(purged)
    }

    /// The bucket name.
    ///
    /// PHP: `KeyValue::getBucket()`.
    pub fn bucket(&self) -> &str {
        &self.bucket
    }

    fn entry_from_delivered(&self, key: &str, message: &JetStreamMessage) -> Result<KeyValueEntry> {
        let meta = message.metadata()?;
        Ok(KeyValueEntry {
            bucket: self.bucket.clone(),
            key: key.to_owned(),
            value: message.message.data.clone(),
            revision: meta.stream_sequence,
            created: Some(meta.timestamp),
            operation: KeyValueOperation::from_headers(message.headers()),
        })
    }

    async fn fetch_stored(&self, conn: &mut Connection, request: Value, key: &str) -> Result<KeyValueEntry> {
        let body = crate::json::encode(&request)?;
        let response = match conn.request(&format!("$JS.API.STREAM.MSG.GET.KV_{}", self.bucket), body, None, None).await
        {
            Ok(response) => response,
            Err(_) => return Err(Error::KeyValue(format!("Revision not found for key: {key}"))),
        };
        let data = crate::json::decode(&response.data)?;
        JetStream::check_error(&data)?;
        let stored = match fields::get(&data, "message") {
            Some(stored @ (Value::Object(_) | Value::Array(_))) => stored,
            _ => return Err(Error::KeyValue(format!("Revision not found for key: {key}"))),
        };
        if let Some(subject) = fields::get(stored, "subject")
            && *subject != Value::String(self.subject(key))
        {
            return Err(Error::KeyValue(format!("Revision not found for key: {key}")));
        }
        let decode =
            |v: &Value| php_std::encoding::base64_decode(fields::php_string(v).as_bytes(), true).unwrap_or_default();
        let headers = match fields::get(stored, "hdrs") {
            Some(Value::String(encoded)) => Some(Headers::from_wire(&decode(&Value::String(encoded.clone())))?),
            _ => None,
        };
        Ok(KeyValueEntry {
            bucket: self.bucket.clone(),
            key: key.to_owned(),
            value: fields::get(stored, "data").map(decode).unwrap_or_default().into(),
            revision: fields::get(stored, "seq").map_or(0, fields::php_int),
            created: fields::opt_string(stored, "time"),
            operation: KeyValueOperation::from_headers(headers.as_ref()),
        })
    }
}

fn entry_from_message(bucket: &str, message: Message) -> Result<KeyValueEntry> {
    let prefix = format!("$KV.{bucket}.");
    let key = message.subject.strip_prefix(prefix.as_str()).unwrap_or(&message.subject).to_owned();
    let (revision, created) = match &message.reply_to {
        Some(reply) => {
            let meta = MsgMetadata::from_reply_subject(reply)?;
            (meta.stream_sequence, Some(meta.timestamp))
        }
        None => (0, None),
    };
    Ok(KeyValueEntry {
        bucket: bucket.to_owned(),
        operation: KeyValueOperation::from_headers(message.headers.as_ref()),
        key,
        value: message.data,
        revision,
        created,
    })
}

fn validate_key(key: &str) -> Result<()> {
    if key.is_empty() || key.contains(' ') || key.contains('>') || key.contains('*') {
        return Err(Error::KeyValue(format!("Invalid key: {key}")));
    }
    Ok(())
}
