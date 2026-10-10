use std::path::Path;
use std::time::{Duration, Instant};

use rustix::fs::{Access, FlockOperation};

use crate::{Error, Lock, Wait};

/// `flock(2)` mode: PHP's `LOCK_EX` or `LOCK_SH`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Mode {
    Exclusive,
    Shared,
}

/// An advisory lock on a file, held through an open handle. Two handles on
/// the same path conflict, also within one process.
pub struct File {
    path: String,
    mode: Mode,
    handle: std::sync::Mutex<Option<std::fs::File>>,
}

impl File {
    pub fn new(path: impl Into<String>, mode: Mode) -> Self {
        Self { path: path.into(), mode, handle: std::sync::Mutex::new(None) }
    }

    pub fn path(&self) -> &str {
        &self.path
    }

    fn open(&self) -> Result<(), Error> {
        let mut handle = self.handle.lock().unwrap_or_else(|e| e.into_inner());
        if handle.is_some() {
            return Ok(());
        }
        let directory = php_std::path::dirname(&self.path);
        if !Path::new(&directory).is_dir() {
            return Err(Error::Lock(format!("Lock file directory does not exist: {directory}")));
        }
        if rustix::fs::access(directory.as_str(), Access::WRITE_OK).is_err() && !Path::new(&self.path).exists() {
            return Err(Error::Lock(format!("Lock file directory is not writable: {directory}")));
        }
        let file = std::fs::OpenOptions::new()
            .write(true)
            .create(true)
            .truncate(false)
            .open(&self.path)
            .map_err(|_| Error::Runtime(format!("Failed to open lock file: {}", self.path)))?;
        *handle = Some(file);
        Ok(())
    }
}

impl Lock for File {
    async fn acquire(&self, wait: Wait) -> Result<bool, Error> {
        self.open()?;
        let Wait::For(timeout) = wait else { return self.try_acquire().await };
        let deadline = Instant::now() + timeout;
        let mut delay = 0.01_f64;
        loop {
            if self.try_acquire().await? {
                return Ok(true);
            }
            let remaining = deadline.saturating_duration_since(Instant::now()).as_secs_f64();
            if remaining <= 0.0 {
                return Ok(false);
            }
            tokio::time::sleep(Duration::from_micros((delay.min(remaining) * 1_000_000.0) as u64)).await;
            delay = (delay * 2.0).min(0.25);
        }
    }

    async fn try_acquire(&self) -> Result<bool, Error> {
        self.open()?;
        let handle = self.handle.lock().unwrap_or_else(|e| e.into_inner());
        let Some(file) = handle.as_ref() else { return Ok(false) };
        let operation = match self.mode {
            Mode::Exclusive => FlockOperation::NonBlockingLockExclusive,
            Mode::Shared => FlockOperation::NonBlockingLockShared,
        };
        Ok(rustix::fs::flock(file, operation).is_ok())
    }

    async fn release(&self) -> Result<(), Error> {
        let mut handle = self.handle.lock().unwrap_or_else(|e| e.into_inner());
        if let Some(file) = handle.take() {
            let _ = rustix::fs::flock(&file, FlockOperation::Unlock);
        }
        Ok(())
    }

    fn contention(&self) -> Error {
        Error::Contention(format!("Failed to acquire file lock on {} within timeout", self.path))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn two_handles_conflict() {
        let path = std::env::temp_dir().join(format!("utopia-lock-test-{}", std::process::id()));
        let path = path.to_string_lossy().into_owned();
        let a = File::new(&path, Mode::Exclusive);
        let b = File::new(&path, Mode::Exclusive);
        assert!(a.try_acquire().await.unwrap());
        assert!(!b.try_acquire().await.unwrap());
        assert!(!b.acquire(Wait::For(Duration::from_millis(30))).await.unwrap());
        a.release().await.unwrap();
        assert!(b.try_acquire().await.unwrap());
        b.release().await.unwrap();
        let _ = std::fs::remove_file(&path);
    }

    #[tokio::test]
    async fn missing_directory() {
        let f = File::new("/nonexistent-dir-xyz/a.lock", Mode::Exclusive);
        let e = f.try_acquire().await.unwrap_err();
        assert_eq!(e.to_string(), "Lock file directory does not exist: /nonexistent-dir-xyz");
        assert_eq!(e.php_class(), "Utopia\\Lock\\Exception");
    }
}
