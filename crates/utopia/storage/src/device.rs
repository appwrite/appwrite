//! The [`Device`] trait every storage backend implements, and the values its
//! operations take and return.

use std::any::Any;

use async_trait::async_trait;
use chrono::{DateTime, FixedOffset};
use indexmap::IndexMap;

use crate::{Body, Error, Result};

/// The largest piece [`copy`] moves at once between devices: 20 MB.
///
/// PHP: `Device::COPY_CHUNK_SIZE`.
pub const COPY_CHUNK_SIZE: i64 = 20_000_000;

/// The kind of a device.
///
/// PHP: `Utopia\Storage\DeviceType`; [`DeviceType::as_str`] is its value.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum DeviceType {
    /// The local disk ([`Local`](crate::Local)).
    Local,
    /// Any S3-compatible service ([`S3`](crate::S3)).
    S3,
    /// Amazon S3 ([`S3::aws`](crate::S3::aws)).
    AwsS3,
    /// DigitalOcean Spaces.
    DoSpaces,
    /// Wasabi.
    Wasabi,
    /// Backblaze B2.
    Backblaze,
    /// Linode (Akamai) Object Storage.
    Linode,
}

impl DeviceType {
    /// The type's name: `local`, `s3`, `awss3`, `dospaces`, `wasabi`,
    /// `backblaze` or `linode`.
    pub const fn as_str(self) -> &'static str {
        match self {
            DeviceType::Local => "local",
            DeviceType::S3 => "s3",
            DeviceType::AwsS3 => "awss3",
            DeviceType::DoSpaces => "dospaces",
            DeviceType::Wasabi => "wasabi",
            DeviceType::Backblaze => "backblaze",
            DeviceType::Linode => "linode",
        }
    }
}

/// An Amazon S3 canned ACL applied to written objects.
///
/// PHP: `Utopia\Storage\Acl`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Acl {
    /// `private`.
    Private,
    /// `public-read`.
    PublicRead,
    /// `public-read-write`.
    PublicReadWrite,
    /// `authenticated-read`.
    AuthenticatedRead,
}

impl Acl {
    /// The header value, such as `public-read`.
    pub const fn as_str(self) -> &'static str {
        match self {
            Acl::Private => "private",
            Acl::PublicRead => "public-read",
            Acl::PublicReadWrite => "public-read-write",
            Acl::AuthenticatedRead => "authenticated-read",
        }
    }
}

/// Size, last modification and ETag of a file.
///
/// PHP: `Utopia\Storage\FileInfo`.
#[derive(Debug, Clone, PartialEq)]
pub struct FileInfo {
    /// The path, as the device names it.
    pub path: String,
    /// The size in bytes.
    pub size: u64,
    /// When it last changed, if known.
    pub modified_at: Option<DateTime<FixedOffset>>,
    /// Its ETag, if known: the MD5 hash of a local file, the object's ETag
    /// on S3.
    pub etag: Option<String>,
}

/// One page of a file listing.
///
/// PHP: `Utopia\Storage\FileList`.
#[derive(Debug, Clone, PartialEq)]
pub struct FileList {
    /// The files on this page.
    pub files: Vec<FileInfo>,
    /// Pass it back to [`Device::list_files`] for the next page; `None` on
    /// the last one.
    pub cursor: Option<String>,
}

/// A multipart upload that was prepared but neither finalized nor aborted.
///
/// PHP: `Utopia\Storage\UploadInfo`.
#[derive(Debug, Clone, PartialEq)]
pub struct UploadInfo {
    /// The object the upload writes.
    pub path: String,
    /// The upload's ID, for [`Device::abort`].
    pub upload_id: String,
    /// When it was started, if known.
    pub initiated_at: Option<DateTime<FixedOffset>>,
}

/// One page of multipart uploads in progress.
///
/// PHP: `Utopia\Storage\UploadList`.
#[derive(Debug, Clone, PartialEq)]
pub struct UploadList {
    /// The uploads on this page.
    pub uploads: Vec<UploadInfo>,
    /// Pass it back to [`S3::list_uploads`](crate::S3::list_uploads) for the
    /// next page; `None` on the last one.
    pub cursor: Option<String>,
}

/// What a device knows about a chunk it received.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Part {
    /// Stored (a local chunk, or a whole file uploaded in one piece).
    Received,
    /// Uploaded as an S3 part with this ETag.
    Etag(String),
}

/// The state of a chunked upload, carried by the caller from one chunk to
/// the next (Appwrite stores it with the upload).
///
/// PHP: the `$metadata` array passed by reference (`UploadMetadata`), with
/// its keys `parts`, `chunks`, `content_type`, `uploadId` and `whole`. A
/// field is `None` where PHP's key is missing.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Metadata {
    /// The chunks received, by number, in the order they arrived.
    pub parts: Option<IndexMap<i64, Part>>,
    /// How many chunks were received.
    pub chunks: Option<i64>,
    /// The content type of the upload.
    pub content_type: Option<String>,
    /// The S3 multipart upload ID.
    pub upload_id: Option<String>,
    /// Whether a single chunk was written as the whole file.
    pub whole: Option<bool>,
}

impl Metadata {
    /// The multipart upload ID, unless missing or empty (PHP's `empty()`:
    /// `""` and `"0"` count as none).
    pub fn active_upload_id(&self) -> Option<&str> {
        self.upload_id.as_deref().filter(|id| !id.is_empty() && *id != "0")
    }

    pub(crate) fn parts_mut(&mut self) -> &mut IndexMap<i64, Part> {
        self.parts.get_or_insert_with(IndexMap::new)
    }
}

/// A storage backend: where files are written, read, listed and deleted.
///
/// Paths are absolute device paths, such as [`Device::path`] builds from a
/// file name. Uploads come in chunks: [`Device::upload`] each one with the
/// same [`Metadata`], and the device joins them once all have arrived.
/// Writes return the ETag of what they wrote; [`Device::create`] and
/// [`Device::replace`] are conditional, so of several writers racing for
/// the same file exactly one wins.
///
/// Implement it to add a device: [`copy`] is the streamed copy every device
/// can fall back to.
///
/// PHP: `Utopia\Storage\Device`.
#[async_trait]
pub trait Device: Send + Sync + Any {
    /// The kind of device.
    ///
    /// PHP: `getType()`.
    fn kind(&self) -> DeviceType;

    /// The directory (or bucket prefix) every path of this device is under.
    ///
    /// PHP: `getRoot()`.
    fn root(&self) -> &str;

    /// The full path of `filename` on this device.
    ///
    /// PHP: `getPath()`.
    fn path(&self, filename: &str) -> String;

    /// Prepares an upload of `chunks` chunks (0 when the count is not
    /// known yet), without sending any data.
    ///
    /// PHP: `prepare()`.
    async fn prepare(&self, path: &str, content_type: &str, chunks: i64, metadata: &mut Metadata) -> Result<()>;

    /// Stores chunk number `chunk` of `chunks`, without joining the upload.
    /// Returns how many chunks were received so far.
    ///
    /// PHP: the protected `uploadChunk()`.
    async fn upload_chunk(
        &self,
        data: Body,
        path: &str,
        chunk: i64,
        chunks: i64,
        metadata: &mut Metadata,
    ) -> Result<i64>;

    /// Completes a prepared upload once every chunk has arrived. The joined
    /// file replaces whatever was at the path; finalizing a completed upload
    /// again is not an error.
    ///
    /// PHP: `finalize()`.
    async fn finalize(&self, path: &str, chunks: i64, metadata: &mut Metadata) -> Result<bool>;

    /// Uploads chunk `chunk` of `chunks`: prepares the upload on first
    /// contact and finalizes it when the last chunk arrives (a whole file is
    /// one chunk of one). With `chunks` 0 nothing is finalized until
    /// [`Device::finalize`] is called. Returns how many chunks were received.
    ///
    /// PHP: `upload()`.
    async fn upload(
        &self,
        data: Body,
        path: &str,
        content_type: &str,
        chunk: i64,
        chunks: i64,
        metadata: &mut Metadata,
    ) -> Result<i64> {
        self.prepare(path, content_type, chunks, metadata).await?;
        let received = self.upload_chunk(data, path, chunk, chunks, metadata).await?;
        if chunks > 1 && chunks == received && !self.finalize(path, chunks, metadata).await? {
            return Err(Error::Upload(format!("Failed to finalize upload {path}")));
        }
        Ok(received)
    }

    /// Abandons a chunked upload and what it stored.
    ///
    /// PHP: `abort()`.
    async fn abort(&self, path: &str, upload_id: &str) -> Result<bool>;

    /// Reads `length` bytes (to the end when `None`) from `offset`. With an
    /// ETag, reads only while the file still carries it.
    ///
    /// PHP: `read()`.
    async fn read(&self, path: &str, offset: u64, length: Option<u64>, etag: Option<&str>) -> Result<Body>;

    /// Writes a file, replacing whatever was there. Returns its ETag.
    ///
    /// PHP: `write()`.
    async fn write(&self, path: &str, data: Body, content_type: &str) -> Result<String>;

    /// Writes a file where there is none yet, or fails with
    /// [`Error::PreconditionFailed`]. Returns its ETag.
    ///
    /// PHP: `create()`.
    async fn create(&self, path: &str, data: Body, content_type: &str) -> Result<String>;

    /// Writes over the file that carries `etag`, and no other, or fails with
    /// [`Error::PreconditionFailed`]. Returns the new ETag.
    ///
    /// PHP: `replace()`.
    async fn replace(&self, path: &str, data: Body, etag: &str, content_type: &str) -> Result<String>;

    /// Copies a file to `target`, on this device or onto `to`, in pieces of
    /// `chunk_size` bytes.
    ///
    /// PHP: `copy()`.
    async fn copy(&self, source: &str, target: &str, to: Option<&dyn Device>, chunk_size: i64) -> Result<bool>;

    /// Moves a file; `false` when source and target are the same.
    ///
    /// PHP: `move()`.
    async fn move_file(&self, source: &str, target: &str) -> Result<bool>;

    /// Deletes a file, or a directory with everything in it when
    /// `recursive`.
    ///
    /// PHP: `delete()`.
    async fn delete(&self, path: &str, recursive: bool) -> Result<bool>;

    /// Deletes everything under `path`, relative to the root.
    ///
    /// PHP: `deletePath()`.
    async fn delete_path(&self, path: &str) -> Result<bool>;

    /// Whether there is a file at the path.
    ///
    /// PHP: `exists()`.
    async fn exists(&self, path: &str) -> Result<bool>;

    /// One page of at most `max` files under `prefix`.
    ///
    /// PHP: `listFiles()`.
    async fn list_files(&self, prefix: &str, max: i64, cursor: Option<&str>) -> Result<FileList>;

    /// Size, last modification and ETag of a file.
    ///
    /// PHP: `getFileInfo()`.
    async fn file_info(&self, path: &str) -> Result<FileInfo>;

    /// The size of a file in bytes.
    ///
    /// PHP: `getFileSize()`.
    async fn file_size(&self, path: &str) -> Result<u64>;

    /// The MIME type of a file.
    ///
    /// PHP: `getFileMimeType()`.
    async fn file_mime_type(&self, path: &str) -> Result<String>;

    /// The MD5 hash of a file, in hex.
    ///
    /// PHP: `getFileHash()`.
    async fn file_hash(&self, path: &str) -> Result<String>;

    /// The device as [`Any`], to reach a concrete device's own methods.
    fn as_any(&self) -> &dyn Any;
}

/// Resolves `.`, `..` and repeated separators in a path, without touching
/// the disk, into an absolute path.
///
/// PHP: `Device::getAbsolutePath()` (backslashes count as separators).
///
/// ```
/// assert_eq!(utopia_storage::absolute_path("a/./b/../c//d"), "/a/c/d");
/// assert_eq!(utopia_storage::absolute_path("../.."), "/");
/// ```
///
/// ```php
/// use Utopia\Storage\Device\Local;
///
/// echo (new Local())->getAbsolutePath('a/./b/../c//d'), "\n"; // /a/c/d
/// ```
pub fn absolute_path(path: &str) -> String {
    let mut parts: Vec<&str> = Vec::new();
    for part in path.split(['/', '\\']) {
        match part {
            "" | "." => {}
            ".." => {
                parts.pop();
            }
            part => parts.push(part),
        }
    }
    let mut out = String::with_capacity(path.len() + 1);
    out.push('/');
    out.push_str(&parts.join("/"));
    out
}

/// Whether `a` and `b` are the same device object.
#[cfg(feature = "s3")]
pub(crate) fn same(a: &dyn Device, b: &dyn Device) -> bool {
    std::ptr::addr_eq(a as *const dyn Device, b as *const dyn Device)
}

/// The streamed copy every device can fall back to: a file up to
/// `chunk_size` is read and written whole, a larger one goes up as a chunked
/// upload, which is aborted if a chunk fails once a multipart upload was
/// started.
///
/// PHP: the base `Device::copy()`.
pub async fn copy(
    from: &dyn Device,
    source: &str,
    target: &str,
    to: Option<&dyn Device>,
    chunk_size: i64,
) -> Result<bool> {
    if chunk_size <= 0 {
        return Err(Error::InvalidArgument("Chunk size must be greater than zero".into()));
    }
    let to = to.unwrap_or(from);
    let size = from.file_size(source).await?;
    let content_type = from.file_mime_type(source).await?;
    let chunk_size = chunk_size as u64;
    if size <= chunk_size {
        let data = from.read(source, 0, None, None).await?;
        to.write(target, data, &content_type).await?;
        return Ok(true);
    }
    let total = size.div_ceil(chunk_size) as i64;
    let mut metadata = Metadata { content_type: Some(content_type.clone()), ..Metadata::default() };
    for counter in 0..total {
        let result = async {
            let data = from.read(source, counter as u64 * chunk_size, Some(chunk_size), None).await?;
            to.upload(data, target, &content_type, counter + 1, total, &mut metadata).await
        }
        .await;
        if let Err(e) = result {
            // Best effort, and only once a multipart upload was actually
            // started: aborting without one could delete a destination the
            // copy never touched.
            if let Some(id) = metadata.upload_id.as_deref().filter(|id| !id.is_empty()) {
                let _ = to.abort(target, id).await;
            }
            return Err(e);
        }
    }
    Ok(true)
}

/// The move every device can fall back to: a copy, then a delete of the
/// source. `false` when source and target are the same.
///
/// PHP: the base `Device::move()`.
pub async fn move_file(device: &dyn Device, source: &str, target: &str) -> Result<bool> {
    if source == target {
        return Ok(false);
    }
    if device.copy(source, target, None, COPY_CHUNK_SIZE).await? {
        return device.delete(source, false).await;
    }
    Ok(false)
}
