//! [`Local`]: files on the local disk.

use std::any::Any;
use std::io;
use std::os::unix::fs::{MetadataExt, OpenOptionsExt};
use std::path::Path;

use async_trait::async_trait;
use bytes::Bytes;
use chrono::{DateTime, FixedOffset};
use md5::{Digest, Md5};
use utopia_async::io::{AsyncReadExt, AsyncWriteExt};

use crate::device::{self, Device, DeviceType, FileInfo, FileList, Metadata, Part};
use crate::{Body, Error, Result, mime};

/// How much a write reads from its body at once: 512 KB.
const PIPE_CHUNK_SIZE: usize = 524_288;

/// Up to this size (1 MB), a read without a length loads the rest of the
/// file at once instead of streaming it.
const WHOLE_READ_SIZE: u64 = 1 << 20;

/// Files on the local disk, under a root directory.
///
/// Writes are atomic: the body goes to a temporary file next to the target,
/// which is renamed over it, so a reader sees the whole old file or the
/// whole new one. The ETag of a local file is its MD5 hash. A chunked upload
/// keeps its chunks in a `tmp_<name>` directory next to the target until
/// they are joined.
///
/// Reads and writes do their file I/O in one blocking call each (a whole
/// small file at once), on the worker itself rather than through the
/// engine's blocking pool: `block_in_place` on a multi-threaded runtime, in place on
/// a current-thread one. Larger bodies stream from the blocking pool.
///
/// ```
/// use utopia_storage::{Body, Device, Local};
///
/// # utopia_async::run(async {
/// let dir = std::env::temp_dir().join("utopia-storage-doc-local");
/// let device = Local::new(dir.to_str().unwrap());
/// let path = device.path("hello.txt");
///
/// let etag = device.write(&path, Body::from("Hello World"), "text/plain").await?;
/// assert_eq!(etag, "b10a8db164e0754105b7a99be72e3fe5");
/// assert_eq!(&device.read(&path, 0, None, None).await?.bytes().await.unwrap()[..], b"Hello World");
/// assert!(device.delete(&path, false).await?);
/// # Ok::<(), utopia_storage::Error>(())
/// # }).unwrap();
/// ```
///
/// ```php
/// use Utopia\Psr7\Stream;
/// use Utopia\Storage\Device\Local;
///
/// $device = new Local(sys_get_temp_dir() . '/utopia-storage-doc-local');
/// $path = $device->getPath('hello.txt');
///
/// echo $device->write($path, new Stream('Hello World'), 'text/plain'), "\n"; // b10a8db164e0754105b7a99be72e3fe5
/// echo (string) $device->read($path), "\n"; // Hello World
/// $device->delete($path);
/// ```
///
/// PHP: `Utopia\Storage\Device\Local`.
#[derive(Debug, Clone)]
pub struct Local {
    root: String,
}

impl Local {
    /// A device for the files under `root`.
    ///
    /// PHP: `new Local($root)`.
    pub fn new(root: impl Into<String>) -> Self {
        Local { root: root.into() }
    }

    /// Creates a directory and its parents, with mode 0755. `true` when it
    /// exists afterwards (or already did).
    ///
    /// PHP: `createDirectory()`.
    pub async fn create_directory(&self, path: &str) -> bool {
        create_directory(path).await
    }

    /// The total size in bytes of the files under a directory, skipping
    /// names that start with a dot; -1 when it cannot be read.
    ///
    /// PHP: `getDirectorySize()`.
    pub async fn directory_size(&self, path: &str) -> i64 {
        if path.is_empty() {
            return -1;
        }
        let mut path = php_std::string::rtrim(path.as_bytes(), b"/").to_vec();
        path.push(b'/');
        directory_size(String::from_utf8_lossy(&path).into_owned()).await
    }

    /// The space available to unprivileged users on the root's filesystem,
    /// in bytes; 0 when unknown.
    ///
    /// PHP: `getPartitionFreeSpace()`.
    pub fn partition_free_space(&self) -> f64 {
        rustix::fs::statvfs(self.root.as_str()).map(|s| s.f_bavail as f64 * s.f_frsize as f64).unwrap_or(0.0)
    }

    /// The total size of the root's filesystem in bytes; 0 when unknown.
    ///
    /// PHP: `getPartitionTotalSpace()`.
    pub fn partition_total_space(&self) -> f64 {
        rustix::fs::statvfs(self.root.as_str()).map(|s| s.f_blocks as f64 * s.f_frsize as f64).unwrap_or(0.0)
    }

    /// Writes `data` to a temporary file next to `path` and renames it over
    /// `path`, first creating the directory when `directory` is set. Returns
    /// the MD5 of the bytes written.
    ///
    /// The first chunk of the body is read ahead, so a body that fits in it
    /// is written in one blocking call: directory, temporary file, bytes
    /// and rename.
    async fn write_atomic(&self, path: &str, mut data: Body, directory: bool) -> Result<String> {
        let mut hash = Md5::new();
        let ahead = read_ahead(&mut data).await;
        let (chunk, last) = match &ahead {
            Ok((chunk, last)) => (Some(chunk.clone()), *last),
            Err(_) => (None, false),
        };
        if let Some(chunk) = &chunk {
            hash.update(chunk);
        }
        let target = path.to_owned();
        let opened = blocking(move || {
            let dir = php_std::path::dirname(&target);
            if directory && !make_directory(&dir) {
                return Err(Error::Storage(format!("Can't create directory {dir}")));
            }
            let (tmp, mut file) =
                temp_file(&dir, &format!("tmp_write_{}_", basename(&target))).map_err(|_| cant_write(&target))?;
            let Some(chunk) = chunk else { return Ok(Some((tmp, file))) };
            let written = io::Write::write_all(&mut file, &chunk);
            if written.is_ok() && !last {
                return Ok(Some((tmp, file)));
            }
            drop(file);
            if written.is_err() || std::fs::rename(&tmp, &target).is_err() {
                let _ = std::fs::remove_file(&tmp);
                return Err(cant_write(&target));
            }
            Ok(None)
        })
        .await?;
        let Some((tmp, mut file)) = opened else { return Ok(hex::encode(hash.finalize())) };
        let result = async {
            ahead.map_err(Error::Body)?;
            loop {
                let chunk: Bytes = data.read(PIPE_CHUNK_SIZE).await.map_err(Error::Body)?;
                if chunk.is_empty() {
                    break;
                }
                hash.update(&chunk);
                let target = path.to_owned();
                file = blocking(move || {
                    io::Write::write_all(&mut file, &chunk).map_err(|_| cant_write(&target))?;
                    Ok(file)
                })
                .await?;
            }
            drop(file);
            utopia_async::fs::rename(&tmp, path).await.map_err(|_| cant_write(path))
        }
        .await;
        if let Err(e) = result {
            let _ = utopia_async::fs::remove_file(&tmp).await;
            return Err(e);
        }
        Ok(hex::encode(hash.finalize()))
    }

    async fn count_chunks(&self, tmp: &str, path: &str) -> i64 {
        let prefix = format!("{}.part.", filename(path));
        let mut count = 0;
        let Ok(mut entries) = utopia_async::fs::read_dir(tmp).await else { return 0 };
        while let Ok(Some(entry)) = entries.next_entry().await {
            let name = entry.file_name();
            let name = name.to_string_lossy();
            if name.starts_with(&prefix) && ends_with_part_number(&name) {
                count += 1;
            }
        }
        count
    }

    async fn join_chunks(&self, path: &str, chunks: i64) -> Result<()> {
        let dir = php_std::path::dirname(path);
        let tmp = format!("{dir}/tmp_{}", basename(path));
        let failed = || Error::Storage(format!("Failed to open temporary assembly file {dir}"));
        let (assemble, dest) = {
            let (dir, prefix) = (dir.clone(), format!("tmp_assemble_{}_", basename(path)));
            blocking(move || temp_file(&dir, &prefix).map_err(|_| Error::Storage(String::new()))).await
        }
        .map_err(|_| failed())?;
        let mut dest = utopia_async::fs::File::from_std(dest);
        let mut parts = Vec::new();
        for i in 1..=chunks {
            let part = format!("{tmp}/{}.part.{i}", filename(path));
            let Ok(mut src) = utopia_async::fs::File::open(&part).await else {
                drop(dest);
                let _ = utopia_async::fs::remove_file(&assemble).await;
                // The chunks go once joined: a file in place means another
                // request assembled it meanwhile, and there is nothing left to do.
                if exists(path).await {
                    return Ok(());
                }
                return Err(Error::Storage(format!("Failed to open chunk {part}")));
            };
            if utopia_async::io::copy(&mut src, &mut dest).await.is_err() {
                drop(dest);
                let _ = utopia_async::fs::remove_file(&assemble).await;
                return Err(Error::Storage(format!("Failed to copy chunk {part}")));
            }
            parts.push(part);
        }
        let _ = dest.flush().await;
        drop(dest);
        if utopia_async::fs::rename(&assemble, path).await.is_err() {
            let _ = utopia_async::fs::remove_file(&assemble).await;
            return Err(Error::Storage(format!("Failed to finalize assembled file {path}")));
        }
        for part in parts {
            // PHP: a failure only raises a warning.
            let _ = utopia_async::fs::remove_file(part).await;
        }
        let _ = utopia_async::fs::remove_dir(&tmp).await;
        Ok(())
    }

    async fn delete_path_at(&self, path: &str) -> bool {
        let root_real = canonical(&self.root).await;
        let Some(real) = canonical(&format!("{}/{path}", self.root)).await else { return false };
        if !is_dir(&real).await {
            return false;
        }
        for file in scan_directory(&real).await {
            if is_dir(&file).await {
                // PHP strips the length of `root/` from the resolved path;
                // here the resolved root is stripped (see the spec's quirks).
                let base = match &root_real {
                    Some(r) => format!("{r}/"),
                    None => format!("{}/", self.root),
                };
                let relative = file.get(base.len()..).unwrap_or_default().to_owned();
                Box::pin(self.delete_path_at(&relative)).await;
            } else {
                let _ = self.delete(&file, true).await;
            }
        }
        utopia_async::fs::remove_dir(&real).await.is_ok()
    }
}

#[async_trait]
impl Device for Local {
    fn kind(&self) -> DeviceType {
        DeviceType::Local
    }

    fn root(&self) -> &str {
        &self.root
    }

    fn path(&self, filename: &str) -> String {
        device::absolute_path(&format!("{}/{filename}", self.root))
    }

    async fn prepare(&self, path: &str, _content_type: &str, _chunks: i64, metadata: &mut Metadata) -> Result<()> {
        create_directory(&php_std::path::dirname(path)).await;
        metadata.parts_mut();
        metadata.chunks.get_or_insert(0);
        Ok(())
    }

    async fn upload_chunk(
        &self,
        data: Body,
        path: &str,
        chunk: i64,
        chunks: i64,
        metadata: &mut Metadata,
    ) -> Result<i64> {
        let dir = php_std::path::dirname(path);
        create_directory(&dir).await;
        metadata.parts_mut();
        metadata.chunks.get_or_insert(0);
        if chunks == 1 {
            self.write_atomic(path, data, false).await?;
            metadata.parts_mut().insert(chunk, Part::Received);
            metadata.chunks = Some(1);
            metadata.whole = Some(true);
            return Ok(1);
        }
        let tmp = format!("{dir}/tmp_{}", basename(path));
        create_directory(&tmp).await;
        let part = format!("{tmp}/{}.part.{chunk}", filename(path));
        // A chunk uploaded again is not written twice.
        if !exists(&part).await {
            let file = utopia_async::fs::File::create(&part)
                .await
                .map_err(|_| Error::Storage(format!("Can't write file {part}")))?;
            pipe(file, &part, data).await?;
        }
        let received = self.count_chunks(&tmp, path).await;
        metadata.parts_mut().insert(chunk, Part::Received);
        metadata.chunks = Some(received);
        metadata.whole = Some(false);
        Ok(received)
    }

    async fn finalize(&self, path: &str, chunks: i64, metadata: &mut Metadata) -> Result<bool> {
        let tmp = format!("{}/tmp_{}", php_std::path::dirname(path), basename(path));
        // A single chunk was written as the whole file, unless the upload was
        // prepared without knowing the count: then it is one part to join.
        let whole = match metadata.whole {
            Some(whole) => whole,
            None => !exists(&format!("{tmp}/{}.part.1", filename(path))).await,
        };
        if chunks == 1 && whole {
            return Ok(exists(path).await);
        }
        for i in 1..=chunks {
            if exists(&format!("{tmp}/{}.part.{i}", filename(path))).await {
                continue;
            }
            // The chunk directory goes once the parts are joined, so its
            // absence next to a file in place is a finalized upload.
            if !is_dir(&tmp).await && exists(path).await {
                return Ok(true);
            }
            return Err(Error::Upload(format!("Missing chunk {i}")));
        }
        self.join_chunks(path, chunks).await?;
        Ok(true)
    }

    async fn abort(&self, path: &str, _upload_id: &str) -> Result<bool> {
        if exists(path).await {
            let _ = utopia_async::fs::remove_file(path).await;
        }
        let dir = php_std::path::dirname(path);
        let tmp = format!("{dir}/tmp_{}/", basename(path));
        if !exists(&dir).await {
            return Err(Error::not_found(format!("File doesn't exist: {dir}")));
        }
        // Chunks that were already joined into the file leave no directory behind.
        if !exists(&tmp).await {
            return Ok(!exists(path).await);
        }
        for file in scan_directory(&tmp).await {
            let _ = self.delete(&file, true).await;
        }
        Ok(utopia_async::fs::remove_dir(&tmp).await.is_ok())
    }

    async fn read(&self, path: &str, offset: u64, length: Option<u64>, etag: Option<&str>) -> Result<Body> {
        let (path, etag) = (path.to_owned(), etag.map(str::to_owned));
        blocking(move || read_file(&path, offset, length, etag.as_deref())).await
    }

    async fn write(&self, path: &str, data: Body, _content_type: &str) -> Result<String> {
        self.write_atomic(path, data, true).await
    }

    async fn create(&self, path: &str, data: Body, _content_type: &str) -> Result<String> {
        let dir = php_std::path::dirname(path);
        if !exists(&dir).await && !create_directory(&dir).await {
            return Err(Error::Storage(format!("Can't create directory {dir}")));
        }
        let file = match utopia_async::fs::OpenOptions::new().write(true).create_new(true).open(path).await {
            Ok(file) => file,
            Err(_) if exists(path).await => {
                return Err(Error::precondition(format!("File {path} already exists")));
            }
            Err(_) => return Err(Error::Storage(format!("Can't write file {path}"))),
        };
        // The path is claimed the moment the file is created: a body that
        // fails must give it back, or the path can never be created again.
        let result = pipe(file, path, data).await;
        if result.is_err() {
            let _ = utopia_async::fs::remove_file(path).await;
        }
        result
    }

    async fn replace(&self, path: &str, data: Body, etag: &str, _content_type: &str) -> Result<String> {
        if !exists(path).await {
            return Err(Error::precondition(format!("File {path} is gone")));
        }
        if self.file_hash(path).await? != etag {
            return Err(Error::precondition(format!("File {path} no longer has ETag {etag}")));
        }
        self.write_atomic(path, data, false).await
    }

    async fn copy(&self, source: &str, target: &str, to: Option<&dyn Device>, chunk_size: i64) -> Result<bool> {
        device::copy(self, source, target, to, chunk_size).await
    }

    async fn move_file(&self, source: &str, target: &str) -> Result<bool> {
        if source == target {
            return Ok(false);
        }
        let dir = php_std::path::dirname(target);
        if !exists(&dir).await && !create_directory(&dir).await {
            return Err(Error::Storage(format!("Can't create directory {dir}")));
        }
        Ok(utopia_async::fs::rename(source, target).await.is_ok())
    }

    async fn delete(&self, path: &str, recursive: bool) -> Result<bool> {
        Ok(delete(path.to_owned(), recursive).await)
    }

    async fn delete_path(&self, path: &str) -> Result<bool> {
        Ok(self.delete_path_at(path).await)
    }

    async fn exists(&self, path: &str) -> Result<bool> {
        Ok(exists(path).await)
    }

    async fn list_files(&self, prefix: &str, max: i64, cursor: Option<&str>) -> Result<FileList> {
        let mut paths = Vec::new();
        let mut pending = vec![String::from_utf8_lossy(php_std::string::rtrim(prefix.as_bytes(), b"/")).into_owned()];
        while let Some(directory) = pending.pop() {
            for entry in scan_directory(&directory).await {
                if is_dir(&entry).await {
                    pending.push(entry);
                } else {
                    paths.push(entry);
                }
            }
        }
        paths.sort_unstable_by(|a, b| a.as_bytes().cmp(b.as_bytes()));
        let offset = match cursor {
            Some(c) if php_std::value::numeric_str(c).is_some() => php_std::format::str_to_int(c.as_bytes()),
            _ => 0,
        };
        let page = array_slice(&paths, offset, max);
        let mut files = Vec::with_capacity(page.len());
        for path in page {
            let meta = utopia_async::fs::metadata(path).await.ok();
            files.push(FileInfo {
                path: path.clone(),
                size: meta.as_ref().map(|m| m.len()).unwrap_or(0),
                modified_at: meta.as_ref().and_then(|m| at(m.mtime())),
                etag: None,
            });
        }
        let next = offset + page.len() as i64;
        Ok(FileList { files, cursor: (next < paths.len() as i64).then(|| next.to_string()) })
    }

    async fn file_info(&self, path: &str) -> Result<FileInfo> {
        if !exists(path).await {
            return Err(Error::not_found(format!("File not found: {path}")));
        }
        let modified = utopia_async::fs::metadata(path).await.ok().and_then(|m| at(m.mtime()));
        Ok(FileInfo {
            path: path.to_owned(),
            size: self.file_size(path).await?,
            modified_at: modified,
            etag: Some(self.file_hash(path).await?),
        })
    }

    async fn file_size(&self, path: &str) -> Result<u64> {
        match utopia_async::fs::metadata(path).await {
            Ok(meta) => Ok(meta.len()),
            Err(_) if exists(path).await => Err(Error::Storage(format!("Failed to get size of file {path}"))),
            Err(_) => Err(Error::not_found(format!("File not found: {path}"))),
        }
    }

    async fn file_mime_type(&self, path: &str) -> Result<String> {
        if !exists(path).await {
            return Err(Error::not_found(format!("File not found: {path}")));
        }
        if is_dir(path).await {
            return Ok("directory".to_owned());
        }
        let mut file = utopia_async::fs::File::open(path)
            .await
            .map_err(|_| Error::Storage(format!("Failed to get mime type of file {path}")))?;
        let mut head = Vec::with_capacity(mime::SNIFF_LENGTH);
        (&mut file)
            .take(mime::SNIFF_LENGTH as u64)
            .read_to_end(&mut head)
            .await
            .map_err(|_| Error::Storage(format!("Failed to get mime type of file {path}")))?;
        Ok(mime::detect(&head).to_owned())
    }

    async fn file_hash(&self, path: &str) -> Result<String> {
        let failed = || async {
            if exists(path).await {
                Error::Storage(format!("Failed to hash file {path}"))
            } else {
                Error::not_found(format!("File not found: {path}"))
            }
        };
        let Ok(mut file) = utopia_async::fs::File::open(path).await else { return Err(failed().await) };
        match hash_file(&mut file).await {
            Ok(hash) => Ok(hash),
            Err(_) => Err(failed().await),
        }
    }

    fn as_any(&self) -> &dyn Any {
        self
    }
}

/// `file_exists()`: anything at the path, following symbolic links.
async fn exists(path: &str) -> bool {
    !path.is_empty() && utopia_async::fs::metadata(path).await.is_ok()
}

async fn is_dir(path: &str) -> bool {
    utopia_async::fs::metadata(path).await.is_ok_and(|m| m.is_dir())
}

async fn canonical(path: &str) -> Option<String> {
    utopia_async::fs::canonicalize(path).await.ok().map(|p| p.to_string_lossy().into_owned())
}

/// `createDirectory()`: `mkdir -p` with mode 0755, `true` when it exists.
async fn create_directory(path: &str) -> bool {
    let path = path.to_owned();
    blocking(move || Ok(make_directory(&path))).await.unwrap_or(false)
}

/// [`create_directory`], on the calling thread.
fn make_directory(path: &str) -> bool {
    use std::os::unix::fs::DirBuilderExt;

    if !path.is_empty() && std::fs::metadata(path).is_ok() {
        return true;
    }
    std::fs::DirBuilder::new().recursive(true).mode(0o755).create(path).is_ok()
}

/// Runs blocking file work without a trip to the blocking pool, which costs
/// more than most local file operations: on a multi-threaded runtime the
/// worker becomes a blocking thread for the call (`block_in_place`) and
/// hands its other tasks to another; a current-thread runtime, which has no
/// other worker to hand them to, runs it in place, as PHP does.
async fn blocking<T: Send + 'static>(work: impl FnOnce() -> Result<T> + Send + 'static) -> Result<T> {
    utopia_async::block_in_place(work)
}

/// The error of a write that failed after the directory was in place.
fn cant_write(path: &str) -> Error {
    Error::Storage(format!("Can't write file {path}"))
}

/// The first chunk of a body, from its start when it is seekable, and
/// whether it was the last.
async fn read_ahead(data: &mut Body) -> io::Result<(Bytes, bool)> {
    if data.is_seekable() {
        data.rewind().await?;
    }
    let chunk = data.read(PIPE_CHUNK_SIZE).await?;
    if chunk.is_empty() {
        return Ok((chunk, true));
    }
    if chunk.len() < PIPE_CHUNK_SIZE {
        // A short chunk usually means the end; a body that has more gives it
        // back here, and the write goes on chunk by chunk.
        let rest = data.read(PIPE_CHUNK_SIZE).await?;
        if rest.is_empty() {
            return Ok((chunk, true));
        }
        let mut joined = Vec::with_capacity(chunk.len() + rest.len());
        joined.extend_from_slice(&chunk);
        joined.extend_from_slice(&rest);
        return Ok((Bytes::from(joined), false));
    }
    Ok((chunk, false))
}

/// PHP `basename()` of a Unix path.
fn basename(path: &str) -> &str {
    let trimmed = path.trim_end_matches('/');
    match trimmed.rfind('/') {
        Some(i) => &trimmed[i + 1..],
        None => trimmed,
    }
}

/// PHP `pathinfo($path, PATHINFO_FILENAME)`: the base name without its
/// last extension.
fn filename(path: &str) -> &str {
    let base = basename(path);
    match base.rfind('.') {
        Some(i) => &base[..i],
        None => base,
    }
}

/// Whether a chunk file name ends in `.part.<digits>`.
fn ends_with_part_number(name: &str) -> bool {
    let digits = name.bytes().rev().take_while(u8::is_ascii_digit).count();
    digits > 0 && name[..name.len() - digits].ends_with(".part.")
}

/// A new, empty file `dir/prefix??????`, created exclusively with mode 0644
/// (less the umask), as `tempnam()` and the `chmod()` after it leave it.
fn temp_file(dir: &str, prefix: &str) -> io::Result<(String, std::fs::File)> {
    for _ in 0..100 {
        let suffix: String = (0..6)
            .map(|_| {
                const CHARS: &[u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
                CHARS[(random() % CHARS.len() as u64) as usize] as char
            })
            .collect();
        let path = format!("{dir}/{prefix}{suffix}");
        match std::fs::OpenOptions::new().write(true).create_new(true).mode(0o644).open(&path) {
            Ok(file) => return Ok((path, file)),
            Err(e) if e.kind() == io::ErrorKind::AlreadyExists => continue,
            Err(e) => return Err(e),
        }
    }
    Err(io::Error::other("no unique temporary name"))
}

fn random() -> u64 {
    use std::hash::{BuildHasher, Hasher};
    let mut h = std::collections::hash_map::RandomState::new().build_hasher();
    h.write_u128(std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap_or_default().as_nanos());
    h.finish()
}

/// Pipes a body into an open file and closes it. Returns the MD5 of the
/// bytes written, the file's ETag.
async fn pipe(mut file: utopia_async::fs::File, path: &str, mut data: Body) -> Result<String> {
    if data.is_seekable() {
        data.rewind().await.map_err(Error::Body)?;
    }
    let mut hash = Md5::new();
    loop {
        let chunk: Bytes = data.read(PIPE_CHUNK_SIZE).await.map_err(Error::Body)?;
        if chunk.is_empty() {
            break;
        }
        hash.update(&chunk);
        file.write_all(&chunk).await.map_err(|_| Error::Storage(format!("Can't write file {path}")))?;
    }
    file.flush().await.map_err(|_| Error::Storage(format!("Can't write file {path}")))?;
    Ok(hex::encode(hash.finalize()))
}

/// `read()`, in one blocking call: open, check the ETag, seek,
/// and read the range when there is one (or the rest of a small file).
fn read_file(path: &str, offset: u64, length: Option<u64>, etag: Option<&str>) -> Result<Body> {
    use std::io::{Read, Seek};

    let failed = || Error::Storage(format!("Failed to read file {path}"));
    // PHP checks file_exists() before fopen(); opening first gives the same
    // answers and saves a stat when the file is there.
    let mut file = match std::fs::File::open(path) {
        Ok(file) => file,
        Err(_) if path.is_empty() || std::fs::metadata(path).is_err() => {
            return Err(Error::not_found("File not found"));
        }
        Err(_) => return Err(failed()),
    };
    if let Some(etag) = etag {
        let mut hash = Md5::new();
        let mut buffer = vec![0; 1 << 16];
        loop {
            let n = file.read(&mut buffer).map_err(|_| failed())?;
            if n == 0 {
                break;
            }
            hash.update(&buffer[..n]);
        }
        if hex::encode(hash.finalize()) != etag {
            return Err(Error::precondition(format!("File {path} no longer has ETag {etag}")));
        }
        file.rewind().map_err(|_| failed())?;
    }
    if offset > 0 {
        file.seek(io::SeekFrom::Start(offset)).map_err(|_| Error::Storage(format!("Failed to seek file {path}")))?;
    }
    let (limit, capacity) = match length {
        Some(length) => (length, length.min(1 << 24)),
        None => {
            // A small file is read now, in this same call, rather than in
            // one more per read of the body; a large one is streamed.
            let rest = file.metadata().map_err(|_| failed())?.len().saturating_sub(offset);
            if rest > WHOLE_READ_SIZE {
                return Ok(Body::from_std_file(file));
            }
            (u64::MAX, rest)
        }
    };
    let mut window = Vec::with_capacity(capacity as usize);
    file.take(limit).read_to_end(&mut window).map_err(|_| failed())?;
    Ok(Body::from(window))
}

/// The MD5 of an open file, read from where it is.
async fn hash_file(file: &mut utopia_async::fs::File) -> io::Result<String> {
    let mut hash = Md5::new();
    let mut buffer = vec![0; 1 << 16];
    loop {
        let n = file.read(&mut buffer).await?;
        if n == 0 {
            break;
        }
        hash.update(&buffer[..n]);
    }
    Ok(hex::encode(hash.finalize()))
}

/// `new \DateTimeImmutable('@' . $timestamp)`: UTC.
fn at(timestamp: i64) -> Option<DateTime<FixedOffset>> {
    DateTime::from_timestamp(timestamp, 0).map(|d| d.fixed_offset())
}

/// The files and directories directly inside a directory, hidden ones
/// included: the names `glob('dir/*')` matches in byte order, then those
/// `glob('dir/.[!.]*')` matches.
async fn scan_directory(dir: &str) -> Vec<String> {
    let dir = String::from_utf8_lossy(php_std::string::rtrim(dir.as_bytes(), b"/")).into_owned();
    let read = if dir.is_empty() { "/" } else { dir.as_str() };
    let mut visible = Vec::new();
    let mut hidden = Vec::new();
    if let Ok(mut entries) = utopia_async::fs::read_dir(read).await {
        while let Ok(Some(entry)) = entries.next_entry().await {
            let name = entry.file_name().to_string_lossy().into_owned();
            if !name.starts_with('.') {
                visible.push(name);
            } else if name.len() >= 2 && name.as_bytes()[1] != b'.' {
                hidden.push(name);
            }
        }
    }
    visible.sort_unstable();
    hidden.sort_unstable();
    visible.into_iter().chain(hidden).map(|name| format!("{dir}/{name}")).collect()
}

/// `delete()`: a file or link, or with `recursive` a directory and all in it.
async fn delete(path: String, recursive: bool) -> bool {
    let meta = utopia_async::fs::symlink_metadata(&path).await;
    let followed_dir = is_dir(&path).await;
    if followed_dir && recursive {
        let Ok(mut entries) = utopia_async::fs::read_dir(&path).await else { return false };
        let mut names = Vec::new();
        while let Ok(Some(entry)) = entries.next_entry().await {
            names.push(entry.file_name().to_string_lossy().into_owned());
        }
        names.sort_unstable();
        for name in names {
            if !Box::pin(delete(format!("{path}/{name}"), true)).await {
                return false;
            }
        }
        return utopia_async::fs::remove_dir(&path).await.is_ok();
    }
    match meta {
        Ok(m) if (m.is_file() || m.file_type().is_symlink()) || (!followed_dir && Path::new(&path).is_file()) => {
            utopia_async::fs::remove_file(&path).await.is_ok()
        }
        _ => false,
    }
}

async fn directory_size(path: String) -> i64 {
    let Ok(mut entries) = utopia_async::fs::read_dir(&path).await else { return -1 };
    let mut size = 0i64;
    while let Ok(Some(entry)) = entries.next_entry().await {
        let name = entry.file_name().to_string_lossy().into_owned();
        if name.starts_with('.') {
            continue;
        }
        let full = format!("{path}{name}");
        if is_dir(&full).await {
            size += Box::pin(directory_size(format!("{full}/"))).await;
        } else {
            size += utopia_async::fs::metadata(&full).await.map(|m| m.len() as i64).unwrap_or(0);
        }
    }
    size
}

/// PHP `array_slice($list, $offset, $length)` on a list.
fn array_slice<T>(list: &[T], offset: i64, length: i64) -> &[T] {
    let count = list.len() as i64;
    if offset > count {
        return &[];
    }
    let start = if offset < 0 { (count + offset).max(0) } else { offset };
    let length = if length < 0 { (count - start + length).max(0) } else { length.min(count - start) };
    &list[start as usize..(start + length) as usize]
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Small and large bodies (the large one written in several chunks and
    /// read back as a stream) on both runtime flavors.
    async fn round_trip(name: &str) {
        let device = Local::new(std::env::temp_dir().join(format!("utopia-storage-{name}")).to_string_lossy());
        for size in [0, 11, PIPE_CHUNK_SIZE * 2 + 7, WHOLE_READ_SIZE as usize + 3] {
            let data: Vec<u8> = (0..size).map(|i| (i % 251) as u8).collect();
            let path = device.path(&format!("nested/{size}.bin"));
            let etag = device.write(&path, Body::from(data.clone()), "").await.unwrap();
            assert_eq!(etag, hex::encode(Md5::digest(&data)));
            let read = device.read(&path, 0, None, Some(&etag)).await.unwrap().bytes().await.unwrap();
            assert_eq!(read.len(), size);
            assert!(read[..] == data[..]);
            if size > 20 {
                let range = device.read(&path, 5, Some(10), None).await.unwrap().bytes().await.unwrap();
                assert_eq!(&range[..], &data[5..15]);
            }
        }
        assert!(device.delete(device.root(), true).await.unwrap());
    }

    #[utopia_async::test]
    async fn round_trip_on_current_thread() {
        round_trip("current-thread").await;
    }

    #[utopia_async::test(threads = 2)]
    async fn round_trip_on_multi_thread() {
        round_trip("multi-thread").await;
    }
}
