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

fn for_root(root: &str) -> Result<Stored> {
    let configured = utopia_system::env("_APP_STORAGE_DEVICE").unwrap_or_else(|| "local".to_owned());
    let key = utopia_system::env("_APP_STORAGE_S3_ACCESS_KEY").unwrap_or_default();
    let secret = utopia_system::env("_APP_STORAGE_S3_SECRET").unwrap_or_default();
    let region = utopia_system::env("_APP_STORAGE_S3_REGION").unwrap_or_default();
    let bucket = utopia_system::env("_APP_STORAGE_S3_BUCKET").unwrap_or_default();
    let endpoint = utopia_system::env("_APP_STORAGE_S3_ENDPOINT").unwrap_or_default();
    let s3 =
        matches!(configured.as_str(), "s3" | "awss3") && !key.is_empty() && !secret.is_empty() && !bucket.is_empty();
    if !s3 {
        return Ok(Stored::Local(Local::new(root)));
    }
    let bucket_name = bucket.trim_matches('/').split('/').next().unwrap_or("").to_owned();
    let device = if endpoint.is_empty() {
        S3::aws(root, key, secret, bucket_name, region)
    } else {
        let bucket_root = if bucket.is_empty() {
            root.trim_start_matches('/').to_owned()
        } else {
            format!("{}/{}", bucket.trim_end_matches('/'), root.trim_start_matches('/'))
        };
        S3::new(bucket_root, key, secret, &endpoint, region)
            .and_then(|device| if bucket_name.is_empty() { Ok(device) } else { device.with_bucket(bucket_name) })
    };
    device
        .map(|device| Stored::S3(Box::new(device)))
        .map_err(|e| Error::with_message(ErrorType::StorageDeviceNotFound, e.to_string()))
}

/// How many chunks a content-range total takes, and which chunk `start` is.
pub fn chunk_index(start: i64, total: i64) -> (i64, i64) {
    let chunks =
        if total <= 0 { 0 } else { total.div_euclid(CHUNK_SIZE) + i64::from(total.rem_euclid(CHUNK_SIZE) != 0) };
    let chunk = start.div_euclid(CHUNK_SIZE) + 1;
    (chunk, chunks)
}
