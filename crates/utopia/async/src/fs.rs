//! Files and directories, without blocking the runtime: each operation runs
//! the standard library's on the engine's blocking pool, and [`File`] is the
//! engine's async file.
//!
//! PHP: the file functions (`file_get_contents()`, `fopen()`, `mkdir()`,
//! `scandir()`, ...) under Swoole's coroutine hooks.

use std::collections::VecDeque;
use std::ffi::OsString;
use std::io;
use std::path::{Path, PathBuf};

#[doc(no_inline)]
pub use std::fs::{FileType, Metadata, Permissions};

use crate::Engine;
use crate::adapter::{self, Adapter};
use crate::io::{impl_read, impl_write};

/// Runs a standard library file operation on the blocking pool.
async fn blocking<T: Send + 'static>(work: impl FnOnce() -> io::Result<T> + Send + 'static) -> io::Result<T> {
    match crate::spawn_blocking(work).await {
        Ok(result) => result,
        Err(_) => Err(io::Error::other("background task failed")),
    }
}

/// The canonical, absolute form of `path`, with every link resolved.
///
/// PHP: `realpath()`.
pub async fn canonicalize(path: impl AsRef<Path>) -> io::Result<PathBuf> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::canonicalize(path)).await
}

/// Copies the file `from` to `to`, permissions included; the bytes copied.
///
/// PHP: `copy()`.
pub async fn copy(from: impl AsRef<Path>, to: impl AsRef<Path>) -> io::Result<u64> {
    let (from, to) = (from.as_ref().to_owned(), to.as_ref().to_owned());
    blocking(move || std::fs::copy(from, to)).await
}

/// Creates the directory `path`; its parent must exist.
///
/// PHP: `mkdir($path)`.
pub async fn create_dir(path: impl AsRef<Path>) -> io::Result<()> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::create_dir(path)).await
}

/// Creates the directory `path` and every missing parent.
///
/// PHP: `mkdir($path, recursive: true)`.
pub async fn create_dir_all(path: impl AsRef<Path>) -> io::Result<()> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::create_dir_all(path)).await
}

/// Creates a hard link `link` to `original`.
///
/// PHP: `link()`.
pub async fn hard_link(original: impl AsRef<Path>, link: impl AsRef<Path>) -> io::Result<()> {
    let (original, link) = (original.as_ref().to_owned(), link.as_ref().to_owned());
    blocking(move || std::fs::hard_link(original, link)).await
}

/// The metadata of `path`, following links.
///
/// PHP: `stat()`.
pub async fn metadata(path: impl AsRef<Path>) -> io::Result<Metadata> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::metadata(path)).await
}

/// The bytes of the file `path`.
///
/// PHP: `file_get_contents()`.
pub async fn read(path: impl AsRef<Path>) -> io::Result<Vec<u8>> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::read(path)).await
}

/// The entries of the directory `path`.
///
/// PHP: `scandir()`, `opendir()` and `readdir()`.
pub async fn read_dir(path: impl AsRef<Path>) -> io::Result<ReadDir> {
    let path = path.as_ref().to_owned();
    let entries = blocking(move || std::fs::read_dir(path)).await?;
    Ok(ReadDir { entries: Some(entries), buffer: VecDeque::new() })
}

/// The target of the link `path`.
///
/// PHP: `readlink()`.
pub async fn read_link(path: impl AsRef<Path>) -> io::Result<PathBuf> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::read_link(path)).await
}

/// The text of the file `path`, which must be UTF-8.
pub async fn read_to_string(path: impl AsRef<Path>) -> io::Result<String> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::read_to_string(path)).await
}

/// Removes the empty directory `path`.
///
/// PHP: `rmdir()`.
pub async fn remove_dir(path: impl AsRef<Path>) -> io::Result<()> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::remove_dir(path)).await
}

/// Removes the directory `path` and everything in it.
pub async fn remove_dir_all(path: impl AsRef<Path>) -> io::Result<()> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::remove_dir_all(path)).await
}

/// Removes the file `path`.
///
/// PHP: `unlink()`.
pub async fn remove_file(path: impl AsRef<Path>) -> io::Result<()> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::remove_file(path)).await
}

/// Renames `from` to `to`, replacing `to`.
///
/// PHP: `rename()`.
pub async fn rename(from: impl AsRef<Path>, to: impl AsRef<Path>) -> io::Result<()> {
    let (from, to) = (from.as_ref().to_owned(), to.as_ref().to_owned());
    blocking(move || std::fs::rename(from, to)).await
}

/// Sets the permissions of `path`.
///
/// PHP: `chmod()`.
pub async fn set_permissions(path: impl AsRef<Path>, permissions: Permissions) -> io::Result<()> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::set_permissions(path, permissions)).await
}

/// Creates a symbolic link `link` to `original`.
///
/// PHP: `symlink()`.
#[cfg(unix)]
pub async fn symlink(original: impl AsRef<Path>, link: impl AsRef<Path>) -> io::Result<()> {
    let (original, link) = (original.as_ref().to_owned(), link.as_ref().to_owned());
    blocking(move || std::os::unix::fs::symlink(original, link)).await
}

/// The metadata of `path` itself, not following a link.
///
/// PHP: `lstat()`.
pub async fn symlink_metadata(path: impl AsRef<Path>) -> io::Result<Metadata> {
    let path = path.as_ref().to_owned();
    blocking(move || std::fs::symlink_metadata(path)).await
}

/// Whether `path` exists (following links); an error when that cannot be
/// told (no permission).
///
/// PHP: `file_exists()`.
pub async fn try_exists(path: impl AsRef<Path>) -> io::Result<bool> {
    let path = path.as_ref().to_owned();
    blocking(move || path.try_exists()).await
}

/// Writes `contents` to the file `path`, creating or truncating it.
///
/// PHP: `file_put_contents()`.
pub async fn write(path: impl AsRef<Path>, contents: impl AsRef<[u8]>) -> io::Result<()> {
    let (path, contents) = (path.as_ref().to_owned(), contents.as_ref().to_owned());
    blocking(move || std::fs::write(path, contents)).await
}

/// How many entries [`ReadDir`] reads per trip to the blocking pool.
const BATCH: usize = 32;

/// The entries of a directory, from [`read_dir`].
#[derive(Debug)]
pub struct ReadDir {
    entries: Option<std::fs::ReadDir>,
    buffer: VecDeque<io::Result<DirEntry>>,
}

impl ReadDir {
    /// The next entry; `None` after the last.
    pub async fn next_entry(&mut self) -> io::Result<Option<DirEntry>> {
        if self.buffer.is_empty() {
            let Some(mut entries) = self.entries.take() else {
                return Ok(None);
            };
            let (entries, buffer) = blocking(move || {
                let buffer: VecDeque<_> = entries.by_ref().take(BATCH).map(|e| e.map(DirEntry)).collect();
                Ok((if buffer.len() == BATCH { Some(entries) } else { None }, buffer))
            })
            .await?;
            self.entries = entries;
            self.buffer = buffer;
        }
        self.buffer.pop_front().transpose()
    }
}

/// An entry of a directory.
#[derive(Debug)]
pub struct DirEntry(std::fs::DirEntry);

impl DirEntry {
    /// The entry's full path.
    pub fn path(&self) -> PathBuf {
        self.0.path()
    }

    /// The entry's name, without its directory.
    pub fn file_name(&self) -> OsString {
        self.0.file_name()
    }

    /// The entry's metadata, not following a link.
    pub async fn metadata(&self) -> io::Result<Metadata> {
        let path = self.0.path();
        blocking(move || std::fs::symlink_metadata(path)).await
    }

    /// The entry's type (file, directory, link).
    pub async fn file_type(&self) -> io::Result<FileType> {
        Ok(self.metadata().await?.file_type())
    }
}

/// How to open a file: for reading, writing, appending, creating,
/// truncating.
///
/// ```
/// use utopia_async::fs::OpenOptions;
/// use utopia_async::io::AsyncWriteExt;
///
/// utopia_async::run(async {
///     let path = std::env::temp_dir().join(format!("utopia-async-{}.log", std::process::id()));
///     let mut file = OpenOptions::new().create(true).append(true).open(&path).await?;
///     file.write_all(b"line\n").await?;
///     file.flush().await?;
///     assert_eq!(utopia_async::fs::read(&path).await?, b"line\n");
///     utopia_async::fs::remove_file(&path).await
/// })?;
/// # Ok::<(), std::io::Error>(())
/// ```
///
/// PHP: `fopen()`'s mode (`'r'`, `'w'`, `'a'`, `'x'`).
#[derive(Debug, Clone)]
pub struct OpenOptions(std::fs::OpenOptions);

impl OpenOptions {
    /// Options with everything off.
    pub fn new() -> OpenOptions {
        OpenOptions(std::fs::OpenOptions::new())
    }

    /// Opens for reading.
    pub fn read(&mut self, read: bool) -> &mut OpenOptions {
        self.0.read(read);
        self
    }

    /// Opens for writing.
    pub fn write(&mut self, write: bool) -> &mut OpenOptions {
        self.0.write(write);
        self
    }

    /// Writes at the end of the file.
    pub fn append(&mut self, append: bool) -> &mut OpenOptions {
        self.0.append(append);
        self
    }

    /// Empties the file on opening.
    pub fn truncate(&mut self, truncate: bool) -> &mut OpenOptions {
        self.0.truncate(truncate);
        self
    }

    /// Creates the file when it does not exist.
    pub fn create(&mut self, create: bool) -> &mut OpenOptions {
        self.0.create(create);
        self
    }

    /// Creates the file, failing when it exists.
    pub fn create_new(&mut self, create_new: bool) -> &mut OpenOptions {
        self.0.create_new(create_new);
        self
    }

    /// The permissions of a file it creates (before the umask).
    #[cfg(unix)]
    pub fn mode(&mut self, mode: u32) -> &mut OpenOptions {
        std::os::unix::fs::OpenOptionsExt::mode(&mut self.0, mode);
        self
    }

    /// Extra `open(2)` flags.
    #[cfg(unix)]
    pub fn custom_flags(&mut self, flags: i32) -> &mut OpenOptions {
        std::os::unix::fs::OpenOptionsExt::custom_flags(&mut self.0, flags);
        self
    }

    /// Opens `path` with these options.
    pub async fn open(&self, path: impl AsRef<Path>) -> io::Result<File> {
        let (options, path) = (self.0.clone(), path.as_ref().to_owned());
        let file = blocking(move || options.open(path)).await?;
        Ok(File::from_std(file))
    }
}

impl Default for OpenOptions {
    fn default() -> Self {
        Self::new()
    }
}

/// Creates directories, with a mode and recursively.
#[derive(Debug, Clone, Default)]
pub struct DirBuilder {
    recursive: bool,
    #[cfg(unix)]
    mode: Option<u32>,
}

impl DirBuilder {
    /// A builder that creates one directory with the default mode.
    pub fn new() -> DirBuilder {
        DirBuilder::default()
    }

    /// Creates the missing parents too.
    pub fn recursive(&mut self, recursive: bool) -> &mut DirBuilder {
        self.recursive = recursive;
        self
    }

    /// The permissions of the directories it creates (before the umask).
    #[cfg(unix)]
    pub fn mode(&mut self, mode: u32) -> &mut DirBuilder {
        self.mode = Some(mode);
        self
    }

    /// Creates the directory `path`.
    ///
    /// PHP: `mkdir($path, $mode, $recursive)`.
    pub async fn create(&self, path: impl AsRef<Path>) -> io::Result<()> {
        let path = path.as_ref().to_owned();
        let mut builder = std::fs::DirBuilder::new();
        builder.recursive(self.recursive);
        #[cfg(unix)]
        if let Some(mode) = self.mode {
            std::os::unix::fs::DirBuilderExt::mode(&mut builder, mode);
        }
        blocking(move || builder.create(path)).await
    }
}

/// An open file. Read, write and seek it with the traits of [`crate::io`];
/// flush it before dropping it to see write errors.
///
/// PHP: a file stream from `fopen()`.
#[derive(Debug)]
pub struct File {
    inner: <Engine as Adapter>::File,
}

impl File {
    /// Opens `path` for reading.
    pub async fn open(path: impl AsRef<Path>) -> io::Result<File> {
        OpenOptions::new().read(true).open(path).await
    }

    /// Opens `path` for writing, creating or truncating it.
    pub async fn create(path: impl AsRef<Path>) -> io::Result<File> {
        OpenOptions::new().write(true).create(true).truncate(true).open(path).await
    }

    /// Options to open a file with.
    pub fn options() -> OpenOptions {
        OpenOptions::new()
    }

    /// Takes over an open standard library file.
    pub fn from_std(file: std::fs::File) -> File {
        File { inner: adapter::File::from_std(file) }
    }

    /// The standard library file, once pending writes are done.
    pub async fn into_std(self) -> std::fs::File {
        adapter::File::into_std(self.inner).await
    }

    /// The file's metadata.
    pub async fn metadata(&self) -> io::Result<Metadata> {
        adapter::File::metadata(&self.inner).await
    }

    /// Truncates or extends the file to `size` bytes.
    ///
    /// PHP: `ftruncate()`.
    pub async fn set_len(&self, size: u64) -> io::Result<()> {
        adapter::File::set_len(&self.inner, size).await
    }

    /// Flushes the file's data and metadata to disk.
    ///
    /// PHP: `fsync()`.
    pub async fn sync_all(&self) -> io::Result<()> {
        adapter::File::sync_all(&self.inner).await
    }

    /// Flushes the file's data to disk.
    ///
    /// PHP: `fdatasync()`.
    pub async fn sync_data(&self) -> io::Result<()> {
        adapter::File::sync_data(&self.inner).await
    }
}

impl_read!(File);
impl_write!(File);

impl crate::io::AsyncSeek for File {
    #[inline]
    fn start_seek(self: std::pin::Pin<&mut Self>, position: io::SeekFrom) -> io::Result<()> {
        std::pin::Pin::new(&mut self.get_mut().inner).start_seek(position)
    }

    #[inline]
    fn poll_complete(
        self: std::pin::Pin<&mut Self>,
        cx: &mut std::task::Context<'_>,
    ) -> std::task::Poll<io::Result<u64>> {
        std::pin::Pin::new(&mut self.get_mut().inner).poll_complete(cx)
    }
}
