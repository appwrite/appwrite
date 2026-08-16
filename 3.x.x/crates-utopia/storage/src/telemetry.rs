use std::collections::HashMap;
use std::io::{Read, Write};
use std::path::{Path, PathBuf};
use std::sync::{Arc, OnceLock};
use std::time::Instant;

use utopia_telemetry::{Adapter, Histogram};

use crate::device::{Device, ParallelUploadOptions, ReadSeek, UploadMetadata};
use crate::device_type::DeviceType;
use crate::error::StorageError;
use crate::file_info::FileList;

/// Device decorator that records a `storage.operation` histogram around calls.
pub struct TelemetryDevice<'a, D> {
    device: D,
    adapter: &'a dyn Adapter,
    histogram: OnceLock<Arc<dyn Histogram>>,
}

impl<D: std::fmt::Debug> std::fmt::Debug for TelemetryDevice<'_, D> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("TelemetryDevice")
            .field("device", &self.device)
            .finish_non_exhaustive()
    }
}

impl<'a, D> TelemetryDevice<'a, D> {
    pub fn new(adapter: &'a dyn Adapter, device: D) -> Self {
        Self {
            device,
            adapter,
            histogram: OnceLock::new(),
        }
    }

    pub const fn get_device(&self) -> &D {
        &self.device
    }

    fn histogram(&self) -> &Arc<dyn Histogram> {
        self.histogram.get_or_init(|| {
            self.adapter.create_histogram(
                "storage.operation",
                Some("s"),
                None,
                histogram_advisory(),
            )
        })
    }
}

fn histogram_advisory() -> HashMap<String, String> {
    let mut advisory = HashMap::new();
    advisory.insert(
        "ExplicitBucketBoundaries".to_string(),
        "0.005,0.01,0.025,0.05,0.075,0.1,0.25,0.5,0.75,1,2.5,5,7.5,10".to_string(),
    );
    advisory
}

impl<D: Device> TelemetryDevice<'_, D> {
    fn measure<T>(&self, method: &str, operation: impl FnOnce() -> T) -> T {
        let start = Instant::now();
        let result = operation();
        let mut attrs = HashMap::new();
        attrs.insert(
            "storage".to_string(),
            self.device.get_type().as_str().to_string(),
        );
        attrs.insert("operation".to_string(), format!("device:{method}"));
        self.histogram()
            .record(start.elapsed().as_secs_f64(), &attrs);
        result
    }
}

impl<D: Device> Device for TelemetryDevice<'_, D> {
    fn get_type(&self) -> DeviceType {
        self.device.get_type()
    }

    fn get_root(&self) -> &Path {
        self.device.get_root()
    }

    fn get_path(&self, filename: &str) -> PathBuf {
        self.measure("getPath", || self.device.get_path(filename))
    }

    fn prepare(
        &self,
        path: &Path,
        content_type: &str,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<(), StorageError> {
        self.measure("prepare", || {
            self.device.prepare(path, content_type, chunks, metadata)
        })
    }

    fn upload_chunk(
        &self,
        data: &[u8],
        path: &Path,
        chunk: u32,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<u32, StorageError> {
        self.measure("uploadChunk", || {
            self.device
                .upload_chunk(data, path, chunk, chunks, metadata)
        })
    }

    fn finalize(
        &self,
        path: &Path,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<bool, StorageError> {
        self.measure("finalize", || self.device.finalize(path, chunks, metadata))
    }

    fn abort(&self, path: &Path, upload_id: &str) -> Result<bool, StorageError> {
        self.measure("abort", || self.device.abort(path, upload_id))
    }

    fn read(&self, path: &Path, offset: u64, length: Option<u64>) -> Result<Vec<u8>, StorageError> {
        self.measure("read", || self.device.read(path, offset, length))
    }

    fn read_into(
        &self,
        path: &Path,
        writer: &mut dyn Write,
        offset: u64,
        length: Option<u64>,
    ) -> Result<u64, StorageError> {
        self.measure("readInto", || {
            self.device.read_into(path, writer, offset, length)
        })
    }

    fn write(&self, path: &Path, data: &[u8], content_type: &str) -> Result<(), StorageError> {
        self.measure("write", || self.device.write(path, data, content_type))
    }

    fn write_from(
        &self,
        path: &Path,
        reader: &mut dyn ReadSeek,
        content_type: &str,
    ) -> Result<(), StorageError> {
        self.measure("writeFrom", || {
            self.device.write_from(path, reader, content_type)
        })
    }

    fn upload_from(
        &self,
        reader: &mut dyn Read,
        path: &Path,
        content_type: &str,
        chunk: u32,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<u32, StorageError> {
        self.measure("uploadFrom", || {
            self.device
                .upload_from(reader, path, content_type, chunk, chunks, metadata)
        })
    }

    fn upload_parallel(
        &self,
        source: &mut dyn ReadSeek,
        path: &Path,
        content_type: &str,
        options: ParallelUploadOptions,
    ) -> Result<(), StorageError> {
        self.measure("uploadParallel", || {
            self.device
                .upload_parallel(source, path, content_type, options)
        })
    }

    fn copy(
        &self,
        source: &Path,
        target: &Path,
        to: Option<&dyn Device>,
        chunk_size: usize,
    ) -> Result<(), StorageError>
    where
        Self: Sized,
    {
        self.measure("copy", || self.device.copy(source, target, to, chunk_size))
    }

    fn r#move(&self, source: &Path, target: &Path) -> Result<bool, StorageError> {
        self.measure("move", || self.device.r#move(source, target))
    }

    fn delete(&self, path: &Path, recursive: bool) -> Result<bool, StorageError> {
        self.measure("delete", || self.device.delete(path, recursive))
    }

    fn delete_path(&self, path: &str) -> Result<bool, StorageError> {
        self.measure("deletePath", || self.device.delete_path(path))
    }

    fn exists(&self, path: &Path) -> bool {
        self.measure("exists", || self.device.exists(path))
    }

    fn list_files(
        &self,
        prefix: &Path,
        max: usize,
        cursor: Option<&str>,
    ) -> Result<FileList, StorageError> {
        self.measure("listFiles", || self.device.list_files(prefix, max, cursor))
    }

    fn get_file_size(&self, path: &Path) -> Result<u64, StorageError> {
        self.measure("getFileSize", || self.device.get_file_size(path))
    }

    fn get_file_mime_type(&self, path: &Path) -> Result<String, StorageError> {
        self.measure("getFileMimeType", || self.device.get_file_mime_type(path))
    }

    fn get_file_hash(&self, path: &Path) -> Result<String, StorageError> {
        self.measure("getFileHash", || self.device.get_file_hash(path))
    }

    fn create_directory(&self, path: &Path) -> Result<bool, StorageError> {
        self.measure("createDirectory", || self.device.create_directory(path))
    }
}
