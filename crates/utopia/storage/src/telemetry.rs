//! [`Telemetry`]: a device decorator measuring every operation.

use std::any::Any;
use std::future::Future;
use std::sync::Arc;
use std::time::Instant;

use async_trait::async_trait;
use utopia_telemetry::{Histogram, Telemetry as Backend, Value};

use crate::device::{self, Device, DeviceType, FileInfo, FileList, Metadata, UploadList};
use crate::{Body, Error, Result};

/// The bucket boundaries of the `storage.operation` histogram, in seconds.
const BOUNDARIES: &[f64] = &[0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0];

/// A device that records how long each operation of another one takes, in
/// the `storage.operation` histogram (seconds), with the attributes
/// `storage` (the device type) and `operation` (`device:<method>`, the PHP
/// method name).
///
/// ```
/// use std::sync::Arc;
/// use utopia_storage::{Device, Local, Telemetry};
/// use utopia_telemetry::{Kind, MemoryTelemetry};
///
/// # tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap().block_on(async {
/// let telemetry = Arc::new(MemoryTelemetry::new());
/// let device = Telemetry::new(&telemetry, Arc::new(Local::new("/tmp")));
/// device.exists("/tmp/missing.txt").await?;
/// assert_eq!(telemetry.names(Kind::Histogram), ["storage.operation"]);
/// # Ok::<(), utopia_storage::Error>(())
/// # }).unwrap();
/// ```
///
/// ```php
/// use Utopia\Storage\Device\Local;
/// use Utopia\Storage\Device\Telemetry;
/// use Utopia\Telemetry\Adapter\Test;
///
/// $telemetry = new Test();
/// $device = new Telemetry($telemetry, new Local('/tmp'));
/// $device->exists('/tmp/missing.txt');
/// echo implode(',', array_keys($telemetry->histograms)), "\n"; // storage.operation
/// ```
///
/// PHP: `Utopia\Storage\Device\Telemetry`.
#[derive(Clone)]
pub struct Telemetry {
    device: Arc<dyn Device>,
    histogram: Histogram,
}

impl std::fmt::Debug for Telemetry {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Telemetry").field("kind", &self.device.kind()).finish_non_exhaustive()
    }
}

impl Telemetry {
    /// `device`, measured into `telemetry`.
    ///
    /// PHP: `new Telemetry($telemetry, $device)`.
    pub fn new(telemetry: &dyn Backend, device: Arc<dyn Device>) -> Self {
        let histogram = telemetry.histogram("storage.operation", Some("s"), None, Some(BOUNDARIES));
        Telemetry { device, histogram }
    }

    /// The decorated device, for the methods of a concrete device (such as
    /// [`Local`](crate::Local)'s partition metrics).
    ///
    /// PHP: `getDevice()`.
    pub fn device(&self) -> &Arc<dyn Device> {
        &self.device
    }

    /// The multipart uploads in progress on the decorated device, which must
    /// be an [`S3`](crate::S3) device; any other is an
    /// [`Error::BadMethodCall`].
    ///
    /// PHP: `listUploads()`.
    pub async fn list_uploads(&self, prefix: &str, max: i64, cursor: Option<&str>) -> Result<UploadList> {
        #[cfg(feature = "s3")]
        if let Some(s3) = self.device.as_any().downcast_ref::<crate::S3>() {
            return self.measure("listUploads", s3.list_uploads(prefix, max, cursor)).await;
        }
        let _ = (prefix, max, cursor);
        Err(Error::BadMethodCall(format!("{} does not list multipart uploads", php_class(self.device.kind()))))
    }

    async fn measure<T>(&self, method: &str, operation: impl Future<Output = T>) -> T {
        let start = Instant::now();
        let result = operation.await;
        self.record(method, start);
        result
    }

    fn record(&self, method: &str, start: Instant) {
        let operation = format!("device:{method}");
        self.histogram.record(
            start.elapsed().as_secs_f64(),
            &[("storage", Value::from(self.device.kind().as_str())), ("operation", Value::from(operation.as_str()))],
        );
    }
}

/// The PHP class of a device of this type, as `$device::class` names it.
fn php_class(kind: DeviceType) -> &'static str {
    match kind {
        DeviceType::Local => "Utopia\\Storage\\Device\\Local",
        DeviceType::S3 => "Utopia\\Storage\\Device\\S3",
        DeviceType::AwsS3 => "Utopia\\Storage\\Device\\AWS",
        DeviceType::DoSpaces => "Utopia\\Storage\\Device\\DOSpaces",
        DeviceType::Wasabi => "Utopia\\Storage\\Device\\Wasabi",
        DeviceType::Backblaze => "Utopia\\Storage\\Device\\Backblaze",
        DeviceType::Linode => "Utopia\\Storage\\Device\\Linode",
    }
}

#[async_trait]
impl Device for Telemetry {
    fn kind(&self) -> DeviceType {
        self.device.kind()
    }

    fn root(&self) -> &str {
        self.device.root()
    }

    fn path(&self, filename: &str) -> String {
        let start = Instant::now();
        let path = self.device.path(filename);
        self.record("getPath", start);
        path
    }

    async fn prepare(&self, path: &str, content_type: &str, chunks: i64, metadata: &mut Metadata) -> Result<()> {
        self.measure("prepare", self.device.prepare(path, content_type, chunks, metadata)).await
    }

    async fn upload_chunk(
        &self,
        data: Body,
        path: &str,
        chunk: i64,
        chunks: i64,
        metadata: &mut Metadata,
    ) -> Result<i64> {
        self.measure("uploadChunk", self.device.upload_chunk(data, path, chunk, chunks, metadata)).await
    }

    async fn finalize(&self, path: &str, chunks: i64, metadata: &mut Metadata) -> Result<bool> {
        self.measure("finalize", self.device.finalize(path, chunks, metadata)).await
    }

    async fn abort(&self, path: &str, upload_id: &str) -> Result<bool> {
        self.measure("abort", self.device.abort(path, upload_id)).await
    }

    async fn read(&self, path: &str, offset: u64, length: Option<u64>, etag: Option<&str>) -> Result<Body> {
        self.measure("read", self.device.read(path, offset, length, etag)).await
    }

    async fn write(&self, path: &str, data: Body, content_type: &str) -> Result<String> {
        self.measure("write", self.device.write(path, data, content_type)).await
    }

    async fn create(&self, path: &str, data: Body, content_type: &str) -> Result<String> {
        self.measure("create", self.device.create(path, data, content_type)).await
    }

    async fn replace(&self, path: &str, data: Body, etag: &str, content_type: &str) -> Result<String> {
        self.measure("replace", self.device.replace(path, data, etag, content_type)).await
    }

    async fn copy(&self, source: &str, target: &str, to: Option<&dyn Device>, chunk_size: i64) -> Result<bool> {
        self.measure("copy", self.device.copy(source, target, to, chunk_size)).await
    }

    async fn move_file(&self, source: &str, target: &str) -> Result<bool> {
        device::move_file(self, source, target).await
    }

    async fn delete(&self, path: &str, recursive: bool) -> Result<bool> {
        self.measure("delete", self.device.delete(path, recursive)).await
    }

    async fn delete_path(&self, path: &str) -> Result<bool> {
        self.measure("deletePath", self.device.delete_path(path)).await
    }

    async fn exists(&self, path: &str) -> Result<bool> {
        self.measure("exists", self.device.exists(path)).await
    }

    async fn list_files(&self, prefix: &str, max: i64, cursor: Option<&str>) -> Result<FileList> {
        self.measure("listFiles", self.device.list_files(prefix, max, cursor)).await
    }

    async fn file_info(&self, path: &str) -> Result<FileInfo> {
        self.measure("getFileInfo", self.device.file_info(path)).await
    }

    async fn file_size(&self, path: &str) -> Result<u64> {
        self.measure("getFileSize", self.device.file_size(path)).await
    }

    async fn file_mime_type(&self, path: &str) -> Result<String> {
        self.measure("getFileMimeType", self.device.file_mime_type(path)).await
    }

    async fn file_hash(&self, path: &str) -> Result<String> {
        self.measure("getFileHash", self.device.file_hash(path)).await
    }

    fn as_any(&self) -> &dyn Any {
        self
    }
}
