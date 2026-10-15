//! [`Filesystem`]: one file per key under a directory.

use std::future::Future;
use std::os::unix::ffi::OsStrExt;
use std::path::{Path, PathBuf};
use std::time::{SystemTime, UNIX_EPOCH};

use php_std::types::{Str, Value};

use crate::adapter::{Adapter, coerce, empty, now};
use crate::memory::fresh;
use crate::{Error, Result};

/// One file per key under a directory: the file's content is the value
/// (strings as they are, arrays as their items' strings joined) and its
/// modification time is the entry's timestamp. Keys containing `/` live in
/// subdirectories. Hashes are ignored.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, Filesystem};
///
/// # let dir = std::env::temp_dir().join(format!("utopia-cache-doc-{}", std::process::id()));
/// # utopia_async::Runtime::new()?.block_on(async {
/// let cache = Filesystem::new(&dir);
/// cache.save("notes/today", &Value::from("hello"), "", 0).await?;
/// assert_eq!(cache.load("notes/today", 60, "").await?, Some(Value::from("hello")));
/// assert_eq!(cache.size().await?, 5); // bytes
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # std::fs::remove_dir_all(&dir)?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Filesystem;
///
/// $dir = sys_get_temp_dir() . '/utopia-cache-doc-' . getmypid();
/// $cache = new Filesystem($dir);
/// $cache->save('notes/today', 'hello');
/// echo $cache->load('notes/today', 60), "\n"; // hello
/// echo $cache->getSize(), "\n"; // 5
/// exec('rm -rf ' . escapeshellarg($dir));
/// ```
#[derive(Debug, Clone)]
pub struct Filesystem {
    root: PathBuf,
}

impl Filesystem {
    /// Entries under `root` (created on the first save).
    pub fn new(root: impl Into<PathBuf>) -> Self {
        Filesystem { root: root.into() }
    }

    /// The root directory.
    pub fn root(&self) -> &Path {
        &self.root
    }

    /// The file holding `key`: the root, `/`, then the key.
    ///
    /// PHP: `getPath`.
    pub fn path(&self, key: &str) -> PathBuf {
        let mut path = self.root.as_os_str().as_bytes().to_vec();
        path.push(b'/');
        path.extend_from_slice(key.as_bytes());
        PathBuf::from(std::ffi::OsStr::from_bytes(&path))
    }

    /// The file of a fresh entry, opened for reading: the streaming load.
    ///
    /// PHP: `new Filesystem($path, streaming: true)`.
    pub async fn open(&self, key: &str, ttl: i64) -> Result<Option<utopia_async::fs::File>> {
        let file = self.path(key);
        if !fresh_file(&file, ttl).await {
            return Ok(Option::None);
        }
        Ok(utopia_async::fs::File::open(&file).await.ok())
    }
}

async fn fresh_file(file: &Path, ttl: i64) -> bool {
    match utopia_async::fs::metadata(file).await {
        Ok(meta) => fresh(mtime(&meta), ttl, now()),
        Err(_) => false,
    }
}

fn mtime(meta: &std::fs::Metadata) -> i64 {
    meta.modified().ok().and_then(|t| t.duration_since(UNIX_EPOCH).ok()).map(|d| d.as_secs() as i64).unwrap_or(0)
}

/// The bytes `file_put_contents` writes for `data` (an array's items as
/// strings, nested arrays as `Array`), and the error it raises after
/// writing when an item is an object that has no string form.
fn contents(data: &Value) -> (Vec<u8>, Option<Error>) {
    let mut out = Vec::new();
    let mut error = None;
    let mut push = |v: &Value| match v {
        Value::Array(_) => out.extend_from_slice(b"Array"),
        other => match other.to_php_string() {
            Ok(s) => out.extend_from_slice(s.as_bytes()),
            Err(e) => error = Some(Error::Engine(e.message)),
        },
    };
    match data {
        Value::Array(items) => items.values().for_each(&mut push),
        other => push(other),
    }
    (out, error)
}

impl Adapter for Filesystem {
    fn load(&self, key: &str, ttl: i64, _hash: &str) -> impl Future<Output = Result<Option<Value>>> + Send {
        let file = self.path(key);
        async move {
            if !fresh_file(&file, ttl).await {
                return Ok(Option::None);
            }
            Ok(match utopia_async::fs::read(&file).await {
                Ok(bytes) => Some(Value::Str(Str::from(bytes))),
                // A directory reads as an empty string.
                Err(_) if file.is_dir() => Some(Value::Str(Str::from(""))),
                Err(_) => Option::None,
            })
        }
    }

    fn save(&self, key: &str, data: &Value, _hash: &str, _ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        let file = self.path(key);
        let bytes = coerce(data, "Utopia\\Cache\\Adapter\\Filesystem::save(): Argument #2 ($data)")
            .map(|data| (!empty(&data)).then(|| contents(&data)));
        async move {
            let Some((bytes, error)) = bytes? else { return Ok(false) };
            if let Some(dir) = file.parent()
                && utopia_async::fs::metadata(dir).await.is_err()
                && utopia_async::fs::create_dir_all(dir).await.is_err()
                && utopia_async::fs::metadata(dir).await.is_err()
            {
                return Err(Error::Exception(format!("Can't create directory {}", dir.display())));
            }
            let written = match utopia_async::fs::write(&file, &bytes).await {
                Ok(()) => !bytes.is_empty(),
                Err(_) => false,
            };
            match error {
                Some(error) => Err(error),
                None => Ok(written),
            }
        }
    }

    fn touch(&self, key: &str, _hash: &str) -> impl Future<Output = Result<bool>> + Send {
        let file = self.path(key);
        async move {
            let touched = utopia_async::spawn_blocking(move || {
                let now = SystemTime::now();
                std::fs::File::open(&file)
                    .and_then(|f| f.set_times(std::fs::FileTimes::new().set_accessed(now).set_modified(now)))
                    .is_ok()
            })
            .await
            .unwrap_or(false);
            Ok(touched)
        }
    }

    async fn list(&self, _key: &str) -> Result<Vec<String>> {
        Ok(Vec::new())
    }

    fn purge(&self, key: &str, _hash: &str) -> impl Future<Output = Result<bool>> + Send {
        let file = self.path(key);
        async move {
            if utopia_async::fs::symlink_metadata(&file).await.is_err() {
                return Ok(false);
            }
            Ok(utopia_async::fs::remove_file(&file).await.is_ok())
        }
    }

    fn flush(&self) -> impl Future<Output = Result<bool>> + Send {
        let root = self.root.clone();
        async move { utopia_async::spawn_blocking(move || delete_directory(&root)).await.unwrap_or(Ok(false)) }
    }

    fn ping(&self) -> impl Future<Output = Result<bool>> + Send {
        let root = self.root.clone();
        async move {
            Ok(utopia_async::spawn_blocking(move || {
                root.exists()
                    && rustix::fs::access(&root, rustix::fs::Access::WRITE_OK).is_ok()
                    && rustix::fs::access(&root, rustix::fs::Access::READ_OK).is_ok()
            })
            .await
            .unwrap_or(false))
        }
    }

    fn size(&self) -> impl Future<Output = Result<i64>> + Send {
        let root = self.root.clone();
        async move { Ok(utopia_async::spawn_blocking(move || directory_size(&root)).await.unwrap_or(0)) }
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "filesystem"
    }
}

/// Entries not starting with a dot, sorted by name.
///
/// PHP: `glob($dir . '*')`.
fn glob(dir: &Path) -> Vec<PathBuf> {
    let Ok(entries) = std::fs::read_dir(dir) else { return Vec::new() };
    let mut paths: Vec<PathBuf> =
        entries.flatten().filter(|e| !e.file_name().as_bytes().starts_with(b".")).map(|e| e.path()).collect();
    paths.sort_by(|a, b| a.as_os_str().as_bytes().cmp(b.as_os_str().as_bytes()));
    paths
}

fn directory_size(dir: &Path) -> i64 {
    glob(dir)
        .iter()
        .map(|path| match std::fs::metadata(path) {
            Ok(meta) if meta.is_file() => meta.len() as i64,
            Ok(meta) if meta.is_dir() => directory_size(path),
            _ => 0,
        })
        .sum()
}

/// Deletes `path` and everything under it. Fails when `path` is not a
/// directory, or when it or a subdirectory is empty (where `glob()` finds
/// nothing).
///
/// PHP: `deleteDirectory`.
fn delete_directory(path: &Path) -> Result<bool> {
    if !path.is_dir() {
        return Err(Error::Exception(format!("{} must be a directory", path.display())));
    }
    let files = glob(path);
    if files.is_empty() {
        return Err(Error::Exception("Error happened during glob".into()));
    }
    for file in files {
        if file.is_dir() {
            delete_directory(&file)?;
        } else {
            let _ = std::fs::remove_file(&file);
        }
    }
    Ok(std::fs::remove_dir(path).is_ok())
}
