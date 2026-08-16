use std::collections::HashMap;
use std::io::{Cursor, Read, Seek, SeekFrom, Write};
use std::path::{Path, PathBuf};

use crate::device_type::DeviceType;
use crate::error::{StorageError, UploadError};
use crate::file_info::FileList;

/// Default max chunk size while copying a file from one device to another (20 MiB).
pub const COPY_CHUNK_SIZE: usize = 20_000_000;

/// Pipe buffer for streaming local/S3 hash and copy loops (512 KiB, matches PHP).
pub const PIPE_CHUNK_SIZE: usize = 524_288;

/// Minimum S3 multipart part size (5 MiB), excluding the final part.
pub const MIN_MULTIPART_PART_SIZE: usize = 5 * 1024 * 1024;

/// Default multipart part size for parallel uploads (8 MiB).
pub const DEFAULT_MULTIPART_PART_SIZE: usize = 8 * 1024 * 1024;

/// Default number of concurrent multipart part uploads.
pub const DEFAULT_UPLOAD_CONCURRENCY: usize = 4;

/// Options for [`Device::upload_parallel`].
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct ParallelUploadOptions {
    /// Bytes per part. Non-final parts must be ≥ [`MIN_MULTIPART_PART_SIZE`] for S3.
    pub part_size: usize,
    /// Maximum number of parts uploaded at the same time.
    pub concurrency: usize,
}

impl Default for ParallelUploadOptions {
    fn default() -> Self {
        Self {
            part_size: DEFAULT_MULTIPART_PART_SIZE,
            concurrency: DEFAULT_UPLOAD_CONCURRENCY,
        }
    }
}

impl ParallelUploadOptions {
    pub fn new(part_size: usize, concurrency: usize) -> Self {
        Self {
            part_size,
            concurrency,
        }
    }
}

/// Seekable reader - required for S3 signing (hash then rewind), matching PHP.
pub trait ReadSeek: Read + Seek {}
impl<T: Read + Seek + ?Sized> ReadSeek for T {}

/// Adapter-specific state for chunked uploads.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct UploadMetadata {
    pub parts: HashMap<u32, PartValue>,
    pub chunks: u32,
    pub content_type: Option<String>,
    pub upload_id: Option<String>,
}

/// Per-part state for chunked uploads.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum PartValue {
    Done,
    Etag(String),
}

/// Storage device abstraction.
///
/// Rust port of [`utopia-php/storage` `Device`](https://github.com/utopia-php/storage).
///
/// Large payloads should use [`Self::read_into`], [`Self::write_from`], and
/// [`Self::upload_parallel`] so memory stays bounded by the pipe/part size.
pub trait Device {
    fn get_type(&self) -> DeviceType;

    fn get_root(&self) -> &Path;

    /// Resolve a logical filename against this device's root and normalize the path.
    fn get_path(&self, filename: &str) -> PathBuf;

    /// Initialize adapter-specific upload state without transferring a chunk body.
    fn prepare(
        &self,
        path: &Path,
        content_type: &str,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<(), StorageError>;

    /// Store exactly one chunk without finalizing the full upload.
    ///
    /// Returns the number of chunks received so far.
    fn upload_chunk(
        &self,
        data: &[u8],
        path: &Path,
        chunk: u32,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<u32, StorageError>;

    /// Complete a prepared upload once all chunks are present.
    fn finalize(
        &self,
        path: &Path,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<bool, StorageError>;

    /// Upload one chunk, preparing on first contact and finalizing on the last chunk.
    fn upload(
        &self,
        data: &[u8],
        path: &Path,
        content_type: &str,
        chunk: u32,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<u32, StorageError> {
        self.prepare(path, content_type, chunks, metadata)?;
        let chunks_received = self.upload_chunk(data, path, chunk, chunks, metadata)?;

        if chunks > 1 && chunks == chunks_received && !self.finalize(path, chunks, metadata)? {
            return Err(
                UploadError(format!("failed to finalize upload {}", path.display())).into(),
            );
        }

        Ok(chunks_received)
    }

    /// Abort a chunked upload and remove temporary parts.
    fn abort(&self, path: &Path, upload_id: &str) -> Result<bool, StorageError>;

    /// Read a file or a byte window starting at `offset` into memory.
    ///
    /// Prefer [`Self::read_into`] for large objects so the full payload is never
    /// buffered in a single `Vec`.
    fn read(&self, path: &Path, offset: u64, length: Option<u64>) -> Result<Vec<u8>, StorageError>;

    /// Stream object bytes into `writer`. Memory stays bounded by the pipe buffer.
    ///
    /// Returns the number of bytes written.
    fn read_into(
        &self,
        path: &Path,
        writer: &mut dyn Write,
        offset: u64,
        length: Option<u64>,
    ) -> Result<u64, StorageError> {
        let data = self.read(path, offset, length)?;
        writer.write_all(&data).map_err(|error| {
            StorageError::message(format!("failed to write read buffer: {error}"))
        })?;
        Ok(data.len() as u64)
    }

    /// Write a file from bytes already resident in memory.
    fn write(&self, path: &Path, data: &[u8], content_type: &str) -> Result<(), StorageError>;

    /// Write a file by streaming a seekable reader (hash/rewind friendly for S3).
    ///
    /// Large sources are uploaded with multipart so peak memory stays near the
    /// configured part size rather than the full object size.
    fn write_from(
        &self,
        path: &Path,
        reader: &mut dyn ReadSeek,
        content_type: &str,
    ) -> Result<(), StorageError> {
        let mut data = Vec::new();
        reader.read_to_end(&mut data).map_err(|error| {
            StorageError::message(format!("failed to read upload source: {error}"))
        })?;
        self.write(path, &data, content_type)
    }

    /// Upload one chunk by streaming `reader` (consumed as the part body).
    fn upload_from(
        &self,
        reader: &mut dyn Read,
        path: &Path,
        content_type: &str,
        chunk: u32,
        chunks: u32,
        metadata: &mut UploadMetadata,
    ) -> Result<u32, StorageError> {
        let mut data = Vec::new();
        reader.read_to_end(&mut data).map_err(|error| {
            StorageError::message(format!("failed to read upload chunk: {error}"))
        })?;
        self.upload(&data, path, content_type, chunk, chunks, metadata)
    }

    /// Upload a seekable source using multipart parts, with concurrent part PUTs.
    ///
    /// Peak memory is roughly `(concurrency + 1) * part_size`. Adapters that cannot
    /// parallelize fall back to sequential part uploads with the same bound.
    fn upload_parallel(
        &self,
        source: &mut dyn ReadSeek,
        path: &Path,
        content_type: &str,
        options: ParallelUploadOptions,
    ) -> Result<(), StorageError>
    where
        Self: Sized,
    {
        upload_parallel_default(self, source, path, content_type, options)
    }

    /// Copy a file to another path on this device or another one.
    ///
    /// Large files are piped chunk by chunk so memory stays bounded by `chunk_size`.
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
        if chunk_size == 0 {
            return Err(StorageError::message(
                "chunk size must be greater than zero",
            ));
        }

        if let Some(destination) = to {
            return copy_between(self, destination, source, target, chunk_size);
        }

        copy_between(self, self, source, target, chunk_size)
    }

    /// Move a file from `source` to `target`.
    fn r#move(&self, source: &Path, target: &Path) -> Result<bool, StorageError>;

    /// Delete a file or directory. When `recursive` is true, directories are removed recursively.
    fn delete(&self, path: &Path, recursive: bool) -> Result<bool, StorageError>;

    /// Delete all files under a directory path relative to the device root.
    fn delete_path(&self, path: &str) -> Result<bool, StorageError>;

    fn exists(&self, path: &Path) -> bool;

    /// List files under the given prefix, one page at a time.
    fn list_files(
        &self,
        prefix: &Path,
        max: usize,
        cursor: Option<&str>,
    ) -> Result<FileList, StorageError>;

    fn get_file_size(&self, path: &Path) -> Result<u64, StorageError>;

    fn get_file_mime_type(&self, path: &Path) -> Result<String, StorageError>;

    fn get_file_hash(&self, path: &Path) -> Result<String, StorageError>;

    /// Create a directory at the specified path.
    fn create_directory(&self, path: &Path) -> Result<bool, StorageError>;

    /// Normalize a path string, resolving `.`, `..`, duplicate separators, and mixed slashes.
    ///
    /// Works like PHP `realpath` on path components even when the target does not exist.
    fn get_absolute_path(&self, path: &str) -> PathBuf {
        absolute_path(path)
    }
}

pub(crate) fn copy_between(
    source_device: &dyn Device,
    destination_device: &dyn Device,
    source: &Path,
    target: &Path,
    chunk_size: usize,
) -> Result<(), StorageError> {
    let size = source_device.get_file_size(source)?;
    let content_type = source_device.get_file_mime_type(source)?;

    if size == 0 {
        return destination_device.write(target, &[], &content_type);
    }

    // Always stream in windows of at most `chunk_size` - never load the full object
    // when it exceeds one chunk. Peak memory ≈ one chunk buffer.
    let total_chunks = size.div_ceil(chunk_size as u64);
    let mut metadata = UploadMetadata {
        content_type: Some(content_type.clone()),
        ..UploadMetadata::default()
    };
    let mut buffer = vec![0_u8; chunk_size];

    let result = (|| {
        for counter in 0..total_chunks {
            let offset = counter * chunk_size as u64;
            let window = ((size - offset) as usize).min(chunk_size);
            let mut cursor = Cursor::new(&mut buffer[..window]);
            let written =
                source_device.read_into(source, &mut cursor, offset, Some(window as u64))?;
            if written as usize != window {
                return Err(StorageError::message(format!(
                    "short read copying {}: expected {window} bytes, got {written}",
                    source.display()
                )));
            }
            destination_device.upload(
                &buffer[..window],
                target,
                &content_type,
                counter as u32 + 1,
                total_chunks as u32,
                &mut metadata,
            )?;
        }
        Ok::<(), StorageError>(())
    })();

    if let Err(error) = result {
        if let Some(upload_id) = metadata.upload_id.as_deref() {
            if !upload_id.is_empty() {
                let _ = destination_device.abort(target, upload_id);
            }
        }
        return Err(error);
    }

    Ok(())
}

fn upload_parallel_default(
    device: &dyn Device,
    source: &mut dyn ReadSeek,
    path: &Path,
    content_type: &str,
    options: ParallelUploadOptions,
) -> Result<(), StorageError> {
    if options.part_size == 0 {
        return Err(StorageError::message("part size must be greater than zero"));
    }
    if options.concurrency == 0 {
        return Err(StorageError::message(
            "upload concurrency must be greater than zero",
        ));
    }

    let size = source
        .seek(SeekFrom::End(0))
        .map_err(|error| StorageError::message(format!("failed to size upload source: {error}")))?;
    source.rewind().map_err(|error| {
        StorageError::message(format!("failed to rewind upload source: {error}"))
    })?;

    if size <= options.part_size as u64 {
        return device.write_from(path, source, content_type);
    }

    // Default path is sequential but memory-bounded (one part buffer). S3 overrides
    // this with concurrent part uploads for throughput.
    let part_size = options.part_size;
    let total_parts = size.div_ceil(part_size as u64) as u32;
    let mut metadata = UploadMetadata {
        content_type: Some(content_type.to_string()),
        ..UploadMetadata::default()
    };
    let mut buffer = vec![0_u8; part_size];
    let mut offset = 0_u64;

    let result = (|| {
        for part in 1..=total_parts {
            let window = ((size - offset) as usize).min(part_size);
            source
                .seek(SeekFrom::Start(offset))
                .map_err(|error| StorageError::message(format!("seek failed: {error}")))?;
            source
                .read_exact(&mut buffer[..window])
                .map_err(|error| StorageError::message(format!("read part failed: {error}")))?;
            offset += window as u64;
            device.upload(
                &buffer[..window],
                path,
                content_type,
                part,
                total_parts,
                &mut metadata,
            )?;
        }
        Ok::<(), StorageError>(())
    })();

    if let Err(error) = result {
        if let Some(upload_id) = metadata.upload_id.as_deref() {
            if !upload_id.is_empty() {
                let _ = device.abort(path, upload_id);
            }
        }
        return Err(error);
    }
    Ok(())
}

pub(crate) fn copy_reader_to_writer(
    reader: &mut dyn Read,
    writer: &mut dyn Write,
) -> Result<u64, StorageError> {
    let mut buffer = vec![0_u8; PIPE_CHUNK_SIZE];
    let mut total = 0_u64;
    loop {
        let read = reader
            .read(&mut buffer)
            .map_err(|error| StorageError::message(format!("stream read failed: {error}")))?;
        if read == 0 {
            break;
        }
        writer
            .write_all(&buffer[..read])
            .map_err(|error| StorageError::message(format!("stream write failed: {error}")))?;
        total += read as u64;
    }
    Ok(total)
}

/// Normalize a path string without requiring the target to exist.
pub fn absolute_path(path: &str) -> PathBuf {
    let normalized = path.replace('\\', "/");
    let mut parts = Vec::new();

    for part in normalized.split('/').filter(|segment| !segment.is_empty()) {
        if part == "." {
            continue;
        }
        if part == ".." {
            parts.pop();
        } else {
            parts.push(part);
        }
    }

    let mut result = PathBuf::from("/");
    for part in parts {
        result.push(part);
    }
    result
}

mod local;
#[cfg(feature = "s3")]
pub mod s3;

pub use local::Local;
#[cfg(feature = "s3")]
pub use s3::{AwsS3, Backblaze, DoSpaces, Linode, RetryStrategy, S3Response, Wasabi, S3};

#[cfg(test)]
mod tests {
    use super::absolute_path;
    use std::path::PathBuf;

    #[test]
    fn absolute_path_normalizes_mixed_separators() {
        assert_eq!(
            absolute_path("////storage/functions"),
            PathBuf::from("/storage/functions")
        );
        assert_eq!(
            absolute_path("storage/functions"),
            PathBuf::from("/storage/functions")
        );
        assert_eq!(
            absolute_path("/storage/functions"),
            PathBuf::from("/storage/functions")
        );
        assert_eq!(
            absolute_path("//storage///functions//"),
            PathBuf::from("/storage/functions")
        );
        assert_eq!(
            absolute_path(r"\\\storage\functions"),
            PathBuf::from("/storage/functions")
        );
        assert_eq!(
            absolute_path(r"..\\\//storage\//functions"),
            PathBuf::from("/storage/functions")
        );
        assert_eq!(
            absolute_path(r"./..\\\//storage\//functions"),
            PathBuf::from("/storage/functions")
        );
    }
}
