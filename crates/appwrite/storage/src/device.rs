//! The file device (`getDevice` + `deviceForFiles`).

use utopia_storage::{Body, Device, DeviceType, Local, Metadata, S3};

use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, CHUNK_SIZE};

/// A local disk or an S3-compatible store. `Device` is not object-safe.
pub enum Stored {
    Local(Local),
    S3(Box<S3>),
}

impl Stored {
    /// Files of one project: `/storage/uploads/app-{projectId}` unless S3 is configured.
    pub fn for_project(project_id: &str) -> Result<Self> {
        let root = format!("/storage/uploads/app-{project_id}");
        for_root(&root)
    }

    /// A local device with an empty root, used to read preview logos.
    pub fn local_root() -> Self {
        Stored::Local(Local::new(""))
    }

    pub fn kind(&self) -> DeviceType {
        match self {
            Stored::Local(device) => device.kind(),
            Stored::S3(device) => device.kind(),
        }
    }

    pub fn root(&self) -> &str {
        match self {
            Stored::Local(device) => device.root(),
            Stored::S3(device) => device.root(),
        }
    }

    pub fn is_local(&self) -> bool {
        self.kind() == DeviceType::Local
    }

    /// `{root}/{bucketId}/{fileId}.{ext}` (`str_ireplace` of the device root).
    pub fn file_path(&self, bucket_id: &str, file_id: &str, name: &str) -> String {
        let ext = crate::base::extension(name);
        let filename = format!("{file_id}.{ext}");
        let path = match self {
            Stored::Local(device) => device.path(&filename),
            Stored::S3(device) => device.path(&filename),
        };
        let root = self.root().trim_end_matches('/');
        let replacement = format!("{root}/{bucket_id}");
        let lower_path = path.to_ascii_lowercase();
        let lower_root = root.to_ascii_lowercase();
        match lower_path.find(&lower_root) {
            Some(at) => {
                let end = at + lower_root.len();
                format!("{}{replacement}{}", &path[..at], &path[end..])
            }
            None => path,
        }
    }

    pub async fn exists(&self, path: &str) -> Result<bool> {
        match self {
            Stored::Local(device) => device.exists(path).await.map_err(base::device_error),
            Stored::S3(device) => device.exists(path).await.map_err(base::device_error),
        }
    }

    pub async fn file_size(&self, path: &str) -> Result<u64> {
        match self {
            Stored::Local(device) => device.file_size(path).await.map_err(base::device_error),
            Stored::S3(device) => device.file_size(path).await.map_err(base::device_error),
        }
    }

    pub async fn file_mime_type(&self, path: &str) -> Result<String> {
        match self {
            Stored::Local(device) => device.file_mime_type(path).await.map_err(base::device_error),
            Stored::S3(device) => device.file_mime_type(path).await.map_err(base::device_error),
        }
    }

    pub async fn file_hash(&self, path: &str) -> Result<String> {
        match self {
            Stored::Local(device) => device.file_hash(path).await.map_err(base::device_error),
            Stored::S3(device) => device.file_hash(path).await.map_err(base::device_error),
        }
    }

    pub async fn read(&self, path: &str, offset: u64, length: Option<u64>) -> Result<Body> {
        match self {
            Stored::Local(device) => device.read(path, offset, length, None).await.map_err(base::device_error),
            Stored::S3(device) => device.read(path, offset, length, None).await.map_err(base::device_error),
        }
    }

    pub async fn write(&self, path: &str, data: Vec<u8>, content_type: &str) -> Result<()> {
        match self {
            Stored::Local(device) => {
                device.write(path, Body::from(data), content_type).await.map(|_| ()).map_err(base::device_error)
            }
            Stored::S3(device) => {
                device.write(path, Body::from(data), content_type).await.map(|_| ()).map_err(base::device_error)
            }
        }
    }

    pub async fn delete(&self, path: &str) -> Result<bool> {
        match self {
            Stored::Local(device) => device.delete(path, false).await.map_err(base::device_error),
            Stored::S3(device) => device.delete(path, false).await.map_err(base::device_error),
        }
    }

    pub async fn abort(&self, path: &str, upload_id: &str) -> Result<bool> {
        match self {
            Stored::Local(device) => device.abort(path, upload_id).await.map_err(base::device_error),
            Stored::S3(device) => device.abort(path, upload_id).await.map_err(base::device_error),
        }
    }

    pub async fn prepare(&self, path: &str, content_type: &str, chunks: i64, metadata: &mut Metadata) -> Result<()> {
        match self {
            Stored::Local(device) => {
                device.prepare(path, content_type, chunks, metadata).await.map_err(base::device_error)
            }
            Stored::S3(device) => {
                device.prepare(path, content_type, chunks, metadata).await.map_err(base::device_error)
            }
        }
    }

    pub async fn upload(
        &self,
        data: Vec<u8>,
        path: &str,
        content_type: &str,
        chunk: i64,
        chunks: i64,
        metadata: &mut Metadata,
    ) -> Result<i64> {
        let data = Body::from(data);
        match self {
            Stored::Local(device) => {
                device.upload(data, path, content_type, chunk, chunks, metadata).await.map_err(base::device_error)
            }
            Stored::S3(device) => {
                device.upload(data, path, content_type, chunk, chunks, metadata).await.map_err(base::device_error)
            }
        }
    }

    pub async fn finalize(&self, path: &str, chunks: i64, metadata: &mut Metadata) -> Result<bool> {
        match self {
            Stored::Local(device) => device.finalize(path, chunks, metadata).await.map_err(base::device_error),
            Stored::S3(device) => device.finalize(path, chunks, metadata).await.map_err(base::device_error),
        }
    }
}

/// The device under `root`, built as `getDevice()` in `app/init/resources.php`
/// builds it: `_APP_STORAGE_DEVICE` with the generic S3 settings or the
/// provider's own (`_APP_STORAGE_{DO_SPACES,BACKBLAZE,LINODE,WASABI}_*`),
/// else the `_APP_CONNECTIONS_STORAGE` DSN, else the local disk.
fn for_root(root: &str) -> Result<Stored> {
    let configured = Settings::from_env(|key| utopia_system::env(key).unwrap_or_default()).resolve();
    build(root, configured, utopia_system::env("_APP_STORAGE_S3_ENDPOINT").unwrap_or_default(), {
        match utopia_system::env("_APP_STORAGE_S3_PATH_STYLE").as_deref() {
            Some("enabled") => Some(true),
            Some("disabled") => Some(false),
            // Unset lets the device infer it from the endpoint and bucket.
            _ => None,
        }
    })
}

/// The device settings `getDevice()` resolves before it builds a device.
#[derive(Debug, Clone, PartialEq, Eq)]
struct Resolved {
    kind: DeviceType,
    key: String,
    secret: String,
    bucket: String,
    region: String,
}

/// The environment `getDevice()` reads.
struct Settings {
    configured: DeviceType,
    s3: [String; 4],
    connection: String,
    provider: Box<dyn Fn(&str) -> String>,
}

impl Settings {
    fn from_env(env: impl Fn(&str) -> String + 'static) -> Self {
        let configured = parse_kind(&env("_APP_STORAGE_DEVICE")).unwrap_or(DeviceType::Local);
        Settings {
            configured,
            s3: [
                env("_APP_STORAGE_S3_ACCESS_KEY"),
                env("_APP_STORAGE_S3_SECRET"),
                env("_APP_STORAGE_S3_BUCKET"),
                env("_APP_STORAGE_S3_REGION"),
            ],
            connection: env("_APP_CONNECTIONS_STORAGE"),
            provider: Box::new(env),
        }
    }

    fn resolve(self) -> Resolved {
        let [s3_key, s3_secret, s3_bucket, s3_region] = self.s3;
        let has_s3 = !s3_key.is_empty() && !s3_secret.is_empty() && !s3_bucket.is_empty();
        let generic = matches!(self.configured, DeviceType::S3 | DeviceType::AwsS3);
        let local = || Resolved {
            kind: DeviceType::Local,
            key: String::new(),
            secret: String::new(),
            bucket: String::new(),
            region: String::new(),
        };

        // The generic S3 configuration takes precedence; the legacy DSN is a fallback.
        if (!generic || !has_s3) && !self.connection.is_empty() {
            return match utopia_dsn::Dsn::parse(&self.connection) {
                Ok(dsn) => {
                    let text = |bytes: &[u8]| String::from_utf8_lossy(bytes).into_owned();
                    Resolved {
                        kind: parse_kind(dsn.scheme()).unwrap_or(DeviceType::Local),
                        key: text(dsn.user().unwrap_or_default()),
                        secret: text(dsn.password().unwrap_or_default()),
                        bucket: text(dsn.path()),
                        region: text(dsn.param("region").ok().flatten().unwrap_or_default()),
                    }
                }
                Err(_) => local(),
            };
        }

        let prefix = match self.configured {
            DeviceType::Local => return local(),
            DeviceType::S3 | DeviceType::AwsS3 => None,
            DeviceType::DoSpaces => Some("DO_SPACES"),
            DeviceType::Backblaze => Some("BACKBLAZE"),
            DeviceType::Linode => Some("LINODE"),
            DeviceType::Wasabi => Some("WASABI"),
        };
        match prefix {
            Some(prefix) if !has_s3 => {
                let env = &self.provider;
                Resolved {
                    kind: self.configured,
                    key: env(&format!("_APP_STORAGE_{prefix}_ACCESS_KEY")),
                    secret: env(&format!("_APP_STORAGE_{prefix}_SECRET")),
                    bucket: env(&format!("_APP_STORAGE_{prefix}_BUCKET")),
                    region: env(&format!("_APP_STORAGE_{prefix}_REGION")),
                }
            }
            _ => {
                Resolved { kind: self.configured, key: s3_key, secret: s3_secret, bucket: s3_bucket, region: s3_region }
            }
        }
    }
}

fn build(root: &str, resolved: Resolved, endpoint: String, path_style: Option<bool>) -> Result<Stored> {
    let Resolved { kind, key, secret, bucket, region } = resolved;
    let device = match kind {
        DeviceType::Local => return Ok(Stored::Local(Local::new(root))),
        DeviceType::S3 | DeviceType::AwsS3 if !endpoint.is_empty() => {
            let prefix = if bucket.is_empty() { String::new() } else { format!("{bucket}/") };
            let bucket_root = format!("{prefix}{}", root.trim_start_matches('/'));
            // A configured bucket may carry a key prefix (`bucket/prefix`); the bucket is its first segment.
            let bucket_name = bucket.trim_matches('/').split('/').next().unwrap_or("").to_owned();
            S3::new(bucket_root, key, secret, &endpoint, region)
                .and_then(|device| if bucket_name.is_empty() { Ok(device) } else { device.with_bucket(bucket_name) })
                .and_then(|device| device.with_path_style(path_style))
        }
        DeviceType::S3 | DeviceType::AwsS3 => S3::aws(root, key, secret, bucket, region),
        DeviceType::DoSpaces => S3::do_spaces(root, key, secret, bucket, region),
        DeviceType::Backblaze => S3::backblaze(root, key, secret, bucket, region),
        DeviceType::Linode => S3::linode(root, key, secret, bucket, region),
        DeviceType::Wasabi => S3::wasabi(root, key, secret, bucket, region),
    };
    device
        .map(|device| Stored::S3(Box::new(device)))
        .map_err(|e| Error::with_message(ErrorType::StorageDeviceNotFound, e.to_string()))
}

/// `DeviceType::tryFrom(strtolower($value))`.
fn parse_kind(value: &str) -> Option<DeviceType> {
    let value = value.to_ascii_lowercase();
    [
        DeviceType::Local,
        DeviceType::S3,
        DeviceType::AwsS3,
        DeviceType::DoSpaces,
        DeviceType::Wasabi,
        DeviceType::Backblaze,
        DeviceType::Linode,
    ]
    .into_iter()
    .find(|kind| kind.as_str() == value)
}

/// How many chunks a content-range total takes, and which chunk `start` is.
pub fn chunk_index(start: i64, total: i64) -> (i64, i64) {
    let chunks =
        if total <= 0 { 0 } else { total.div_euclid(CHUNK_SIZE) + i64::from(total.rem_euclid(CHUNK_SIZE) != 0) };
    let chunk = start.div_euclid(CHUNK_SIZE) + 1;
    (chunk, chunks)
}

#[cfg(test)]
mod tests {
    use std::collections::HashMap;

    use super::*;

    fn resolve(vars: &[(&str, &str)]) -> Resolved {
        let vars: HashMap<String, String> = vars.iter().map(|(k, v)| ((*k).to_owned(), (*v).to_owned())).collect();
        Settings::from_env(move |key| vars.get(key).cloned().unwrap_or_default()).resolve()
    }

    fn resolved(kind: DeviceType, key: &str, secret: &str, bucket: &str, region: &str) -> Resolved {
        Resolved {
            kind,
            key: key.to_owned(),
            secret: secret.to_owned(),
            bucket: bucket.to_owned(),
            region: region.to_owned(),
        }
    }

    #[test]
    fn defaults_to_local() {
        assert_eq!(resolve(&[]), resolved(DeviceType::Local, "", "", "", ""));
        assert_eq!(resolve(&[("_APP_STORAGE_DEVICE", "unknown")]), resolved(DeviceType::Local, "", "", "", ""));
    }

    #[test]
    fn generic_s3_settings() {
        let vars = [
            ("_APP_STORAGE_DEVICE", "S3"),
            ("_APP_STORAGE_S3_ACCESS_KEY", "k"),
            ("_APP_STORAGE_S3_SECRET", "s"),
            ("_APP_STORAGE_S3_BUCKET", "b"),
            ("_APP_STORAGE_S3_REGION", "r"),
            ("_APP_CONNECTIONS_STORAGE", "wasabi://dk:ds@wasabi/db?region=dr"),
        ];
        assert_eq!(resolve(&vars), resolved(DeviceType::S3, "k", "s", "b", "r"));
    }

    #[test]
    fn provider_settings() {
        let vars = [
            ("_APP_STORAGE_DEVICE", "dospaces"),
            ("_APP_STORAGE_DO_SPACES_ACCESS_KEY", "k"),
            ("_APP_STORAGE_DO_SPACES_SECRET", "s"),
            ("_APP_STORAGE_DO_SPACES_BUCKET", "b"),
            ("_APP_STORAGE_DO_SPACES_REGION", "nyc3"),
        ];
        assert_eq!(resolve(&vars), resolved(DeviceType::DoSpaces, "k", "s", "b", "nyc3"));
        for (name, prefix, kind) in [
            ("backblaze", "BACKBLAZE", DeviceType::Backblaze),
            ("linode", "LINODE", DeviceType::Linode),
            ("wasabi", "WASABI", DeviceType::Wasabi),
        ] {
            let key = format!("_APP_STORAGE_{prefix}_ACCESS_KEY");
            let bucket = format!("_APP_STORAGE_{prefix}_BUCKET");
            let vars = [("_APP_STORAGE_DEVICE", name), (key.as_str(), "k"), (bucket.as_str(), "b")];
            assert_eq!(resolve(&vars), resolved(kind, "k", "", "b", ""));
        }
    }

    #[test]
    fn provider_prefers_generic_s3_settings() {
        let vars = [
            ("_APP_STORAGE_DEVICE", "wasabi"),
            ("_APP_STORAGE_S3_ACCESS_KEY", "k"),
            ("_APP_STORAGE_S3_SECRET", "s"),
            ("_APP_STORAGE_S3_BUCKET", "b"),
            ("_APP_STORAGE_WASABI_ACCESS_KEY", "wk"),
        ];
        assert_eq!(resolve(&vars), resolved(DeviceType::Wasabi, "k", "s", "b", ""));
    }

    #[test]
    fn connection_dsn() {
        let vars = [
            ("_APP_STORAGE_DEVICE", "s3"),
            ("_APP_CONNECTIONS_STORAGE", "backblaze://key:secret@backblaze/bucket?region=us-west-004"),
        ];
        assert_eq!(resolve(&vars), resolved(DeviceType::Backblaze, "key", "secret", "bucket", "us-west-004"));
        let vars = [("_APP_CONNECTIONS_STORAGE", "local://localhost")];
        assert_eq!(resolve(&vars), resolved(DeviceType::Local, "", "", "", ""));
        let vars = [("_APP_CONNECTIONS_STORAGE", "not a dsn")];
        assert_eq!(resolve(&vars).kind, DeviceType::Local);
    }

    #[test]
    fn builds_devices() {
        let linode = resolved(DeviceType::Linode, "k", "s", "b", "eu-central-1");
        let device = build("/storage/uploads/app-p", linode, String::new(), None).unwrap();
        assert_eq!(device.kind(), DeviceType::Linode);
        let minio = resolved(DeviceType::S3, "k", "s", "bucket/prefix", "us-east-1");
        let device = build("/storage/uploads/app-p", minio, "http://minio:9000".to_owned(), Some(true)).unwrap();
        assert_eq!(device.kind(), DeviceType::S3);
        assert_eq!(device.root(), "bucket/prefix/storage/uploads/app-p");
        let local = resolved(DeviceType::Local, "", "", "", "");
        assert!(build("/storage/uploads/app-p", local, String::new(), None).unwrap().is_local());
    }
}
