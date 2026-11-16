//! [`Storage`]: entries as objects on a `utopia-storage` device.

use std::sync::Arc;

use php_std::types::{Str, Value};
use utopia_storage::{Body, Device};

use crate::adapter::{Adapter, coerce, empty};
use crate::{Error, Result};

const CONTENT_TYPE: &str = "application/octet-stream";

/// Stores each entry as one object on a storage device, under the device's
/// root, holding exactly the cached bytes. Age is the object's last modified
/// time.
///
/// A load is one conditional read (on S3 a GET answering 200 with the
/// bytes, 304 without a body if expired, or 404 if missing), and a device
/// failure reads as a miss.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, Storage};
/// use utopia_storage::Local;
///
/// # utopia_async::Runtime::new()?.block_on(async {
/// let cache = Storage::new(std::sync::Arc::new(Local::new("/tmp/utopia-cache-storage")))?;
/// assert!(cache.save("key", &Value::from("value"), "", 0).await?);
/// assert_eq!(cache.load("key", 60, "").await?, Some(Value::from("value")));
/// assert!(cache.purge("key", "").await?);
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Storage;
/// use Utopia\Storage\Device\Local;
///
/// $cache = new Storage(new Local('/tmp/utopia-cache-storage'));
/// var_dump($cache->save('key', 'value')); // string(5) "value"
/// var_dump($cache->load('key', 60));      // string(5) "value"
/// var_dump($cache->purge('key'));         // bool(true)
/// ```
///
/// PHP: `Utopia\Cache\Adapter\Storage`.
#[derive(Clone)]
pub struct Storage {
    device: Arc<dyn Device>,
}

impl std::fmt::Debug for Storage {
    /// Writes the adapter's name and the device's root.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Storage").field("root", &self.device.root()).finish()
    }
}

impl Storage {
    /// An adapter over a built device, so the caller decides its client and
    /// connection reuse. Fails with [`Error::InvalidArgument`] when the
    /// device has no root, which a flush would delete wholesale.
    ///
    /// PHP: `new Storage($device)`.
    pub fn new(device: Arc<dyn Device>) -> Result<Self> {
        let root = utopia_storage::absolute_path(device.root());
        if php_std::string::trim(root.as_bytes(), b"/").is_empty() {
            return Err(Error::InvalidArgument("The cache device needs a root".into()));
        }
        Ok(Storage { device })
    }

    /// The device's path for `key`: the key as one encoded path segment, so
    /// it cannot leave the root.
    ///
    /// PHP: `getPath($key)`.
    pub fn path(&self, key: &str) -> String {
        let name = php_std::encoding::rawurlencode(key.as_bytes());
        // These three survive encoding but do not name an object.
        // rawurlencode() never emits a bare '%' or '%2E'.
        let name = match name.as_str() {
            "" => "%",
            "." => "%2E",
            ".." => "%2E%2E",
            name => name,
        };
        self.device.path(name)
    }
}

/// A device failure as the error PHP lets through.
fn failed(e: utopia_storage::Error) -> Error {
    Error::Storage { class: e.php_class(), message: e.to_string() }
}

/// Whether PHP catches the failure (`StorageException | RuntimeException`)
/// rather than letting it through.
fn caught(e: &utopia_storage::Error) -> bool {
    !matches!(e, utopia_storage::Error::InvalidArgument(_) | utopia_storage::Error::BadMethodCall(_))
}

impl Adapter for Storage {
    /// One conditional read since `ttl` seconds ago; a device failure is a
    /// miss.
    async fn load(&self, key: &str, ttl: i64, _hash: &str) -> Result<Option<Value>> {
        let since = chrono::DateTime::from_timestamp(php_std::datetime::time() - ttl, 0).map(|d| d.fixed_offset());
        let read = match self.device.read(&self.path(key), 0, None, None, since).await {
            Ok(body) => body.bytes().await.map_err(utopia_storage::Error::Body),
            Err(e) => Err(e),
        };
        match read {
            Ok(bytes) => Ok(Some(Value::Str(Str::from(bytes.to_vec())))),
            Err(e) if caught(&e) => Ok(Option::None),
            Err(e) => Err(failed(e)),
        }
    }

    /// One write, resetting the entry's age. An array is stored as its
    /// values joined, and one holding a non-scalar is refused.
    async fn save(&self, key: &str, data: &Value, _hash: &str, _ttl: i64) -> Result<bool> {
        let data = coerce(data, "Utopia\\Cache\\Adapter\\Storage::save(): Argument #2 ($data)")?;
        if empty(&data) {
            return Ok(false);
        }
        let mut body = Vec::new();
        let values: Vec<&Value> = match &*data {
            Value::Array(items) => items.values().collect(),
            other => vec![other],
        };
        for value in values {
            if !matches!(value, Value::Str(_) | Value::Int(_) | Value::Float(_) | Value::Bool(_)) {
                return Ok(false);
            }
            let text = value.to_php_string().map_err(|e| Error::Engine(e.message))?;
            body.extend_from_slice(text.as_bytes());
        }
        match self.device.write(&self.path(key), Body::from(body), CONTENT_TYPE).await {
            Ok(_) => Ok(true),
            Err(e) if caught(&e) => Ok(false),
            Err(e) => Err(failed(e)),
        }
    }

    /// Rewrites the object, as objects have no writable modified time,
    /// conditional on its ETag so an entry purged or replaced meanwhile is
    /// not brought back (`false`).
    async fn touch(&self, key: &str, _hash: &str) -> Result<bool> {
        let path = self.path(key);
        let result = async {
            let Some(etag) = self.device.file_info(&path).await?.etag else { return Ok(false) };
            let body = self.device.read(&path, 0, None, Some(&etag), None).await?;
            self.device.replace(&path, body, &etag, CONTENT_TYPE).await?;
            Ok::<_, utopia_storage::Error>(true)
        }
        .await;
        match result {
            Ok(touched) => Ok(touched),
            Err(e) if caught(&e) => Ok(false),
            Err(e) => Err(failed(e)),
        }
    }

    /// An entry has no fields to list.
    async fn list(&self, _key: &str) -> Result<Vec<String>> {
        Ok(Vec::new())
    }

    /// One delete. S3 does not say whether the object existed, so purging a
    /// missing key is `true` there. A failure is an error, since the entry
    /// may still be cached.
    async fn purge(&self, key: &str, _hash: &str) -> Result<bool> {
        self.device.delete(&self.path(key), false).await.map_err(failed)
    }

    /// Deletes everything under the root. A failure is an error.
    async fn flush(&self) -> Result<bool> {
        let root = self.device.root().to_owned();
        if self.device.delete(&root, true).await.map_err(failed)? {
            return Ok(true);
        }
        Ok(!self.device.exists(&root).await.map_err(failed)?)
    }

    /// Whether the device answers a check of the root.
    async fn ping(&self) -> Result<bool> {
        match self.device.exists(self.device.root()).await {
            Ok(_) => Ok(true),
            Err(e @ (utopia_storage::Error::InvalidArgument(_) | utopia_storage::Error::BadMethodCall(_))) => {
                Err(failed(e))
            }
            Err(_) => Ok(false),
        }
    }

    /// Always 0: the size is only known by listing every object.
    async fn size(&self) -> Result<i64> {
        Ok(0)
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "storage"
    }
}
