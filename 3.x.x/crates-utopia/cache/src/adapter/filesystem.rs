use std::fs;
use std::path::{Path, PathBuf};

use crate::adapter::Adapter;
use crate::error::CacheError;
use crate::value::{unix_now, CacheValue, LoadResult, SaveResult};

/// PHP `Utopia\Cache\Adapter\Filesystem`.
#[derive(Debug, Clone)]
pub struct Filesystem {
    path: String,
    /// PHP `$streaming`. Rust always returns file contents as a string
    /// (no PHP resource handles).
    streaming: bool,
}

impl Filesystem {
    /// PHP `__construct(string $path, bool $streaming = false)`.
    #[must_use]
    pub fn new(path: impl Into<String>) -> Self {
        Self::with_streaming(path, false)
    }

    #[must_use]
    pub fn with_streaming(path: impl Into<String>, streaming: bool) -> Self {
        Self {
            path: path.into(),
            streaming,
        }
    }

    /// PHP `getPath($filename)` = `$path . DIRECTORY_SEPARATOR . $filename`.
    #[must_use]
    pub fn get_path(&self, filename: &str) -> String {
        format!("{}{}{}", self.path, std::path::MAIN_SEPARATOR, filename)
    }

    fn file_mtime(path: &Path) -> Option<i64> {
        let meta = fs::metadata(path).ok()?;
        let modified = meta.modified().ok()?;
        Some(
            modified
                .duration_since(std::time::UNIX_EPOCH)
                .map(|d| d.as_secs() as i64)
                .unwrap_or(0),
        )
    }

    fn directory_size(dir: &Path) -> i64 {
        let mut size = 0_i64;
        let entries = match fs::read_dir(dir) {
            Ok(e) => e,
            Err(_) => return 0,
        };
        for entry in entries.flatten() {
            let path = entry.path();
            if path.is_file() {
                size += fs::metadata(&path).map(|m| m.len() as i64).unwrap_or(0);
            } else if path.is_dir() {
                size += Self::directory_size(&path);
            }
        }
        size
    }

    fn delete_directory(path: &Path) -> Result<bool, CacheError> {
        if !path.is_dir() {
            return Err(CacheError::NotADirectory(path.display().to_string()));
        }
        let mut any = false;
        let entries = fs::read_dir(path).map_err(|_| CacheError::Glob)?;
        for entry in entries.flatten() {
            any = true;
            let child = entry.path();
            if child.is_dir() {
                Self::delete_directory(&child)?;
            } else {
                let _ = fs::remove_file(&child);
            }
        }
        if !any {
            // PHP `glob` on an empty dir returns `[]`, which is falsy → exception.
            return Err(CacheError::Glob);
        }
        Ok(fs::remove_dir(path).is_ok())
    }
}

impl Adapter for Filesystem {
    fn load(&self, key: &str, ttl: i64, _hash: &str) -> Result<LoadResult, CacheError> {
        let file = PathBuf::from(self.get_path(key));
        if !file.exists() {
            return Ok(LoadResult::Miss);
        }
        let mtime = match Self::file_mtime(&file) {
            Some(t) => t,
            None => return Ok(LoadResult::Miss),
        };
        if mtime + ttl <= unix_now() {
            return Ok(LoadResult::Miss);
        }
        let contents = fs::read_to_string(&file).unwrap_or_default();
        let _ = self.streaming;
        Ok(LoadResult::Hit(CacheValue::String(contents)))
    }

    fn save(&self, key: &str, data: &CacheValue, _hash: &str) -> Result<SaveResult, CacheError> {
        if data.is_php_empty() {
            return Ok(SaveResult::Failed);
        }
        let file = PathBuf::from(self.get_path(key));
        if let Some(dir) = file.parent() {
            if !dir.exists() && fs::create_dir_all(dir).is_err() && !dir.exists() {
                return Err(CacheError::CreateDirectory(dir.display().to_string()));
            }
        }
        let bytes = data.php_file_bytes();
        match fs::write(&file, &bytes) {
            Ok(()) => Ok(SaveResult::Saved(data.clone())),
            Err(_) => Ok(SaveResult::Failed),
        }
    }

    fn touch(&self, key: &str, _hash: &str) -> Result<bool, CacheError> {
        let file = PathBuf::from(self.get_path(key));
        if !file.exists() {
            return Ok(false);
        }
        let now = filetime_now();
        match set_mtime(&file, now) {
            Ok(()) => Ok(true),
            Err(_) => Ok(false),
        }
    }

    fn list(&self, _key: &str) -> Result<Vec<String>, CacheError> {
        Ok(Vec::new())
    }

    fn purge(&self, key: &str, _hash: &str) -> Result<bool, CacheError> {
        let file = PathBuf::from(self.get_path(key));
        if file.exists() {
            Ok(fs::remove_file(file).is_ok())
        } else {
            Ok(false)
        }
    }

    fn flush(&self) -> Result<bool, CacheError> {
        Self::delete_directory(Path::new(&self.path))
    }

    fn ping(&self) -> bool {
        let path = Path::new(&self.path);
        path.exists() && is_writable(path) && is_readable(path)
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        Ok(Self::directory_size(Path::new(&self.path)))
    }

    fn get_name(&self, _key: Option<&str>) -> String {
        "filesystem".into()
    }
}

fn filetime_now() -> std::time::SystemTime {
    std::time::SystemTime::now()
}

fn set_mtime(path: &Path, now: std::time::SystemTime) -> std::io::Result<()> {
    let file = fs::OpenOptions::new().write(true).open(path)?;
    file.set_modified(now)
}

fn is_readable(path: &Path) -> bool {
    fs::metadata(path)
        .map(|m| !m.permissions().readonly() || path.is_dir())
        .unwrap_or(false)
        || fs::read_dir(path).is_ok()
        || fs::File::open(path).is_ok()
}

fn is_writable(path: &Path) -> bool {
    if !path.exists() {
        return false;
    }
    let probe = path.join(".utopia-cache-write-probe");
    match fs::write(&probe, b"") {
        Ok(()) => {
            let _ = fs::remove_file(&probe);
            true
        }
        Err(_) => fs::metadata(path)
            .map(|m| !m.permissions().readonly())
            .unwrap_or(false),
    }
}
