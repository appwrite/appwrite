use std::fs::{File, OpenOptions};
use std::path::{Path, PathBuf};
use std::thread;
use std::time::{Duration, Instant};

use parking_lot::Mutex;

use crate::error::{Contention, LockError};
use crate::lock::Lock;

/// PHP `LOCK_SH`.
pub const LOCK_SH: i32 = 1;
/// PHP `LOCK_EX`.
pub const LOCK_EX: i32 = 2;

/// PHP `Utopia\Lock\File`.
#[derive(Debug)]
pub struct FileLock {
    path: PathBuf,
    exclusive: bool,
    handle: Mutex<Option<File>>,
}

impl FileLock {
    pub fn new(path: impl AsRef<Path>, mode: i32) -> Self {
        Self {
            path: path.as_ref().to_path_buf(),
            exclusive: mode != LOCK_SH,
            handle: Mutex::new(None),
        }
    }

    pub fn with_exclusive(path: impl AsRef<Path>) -> Self {
        Self::new(path, LOCK_EX)
    }

    fn open(&self) -> Result<(), LockError> {
        let mut slot = self.handle.lock();
        if slot.is_some() {
            return Ok(());
        }
        let directory = self.path.parent().unwrap_or_else(|| Path::new("."));
        if !directory.is_dir() {
            return Err(LockError::new(format!(
                "Lock file directory does not exist: {}",
                directory.display()
            )));
        }
        let file = OpenOptions::new()
            .create(true)
            .truncate(false)
            .read(true)
            .write(true)
            .open(&self.path)
            .map_err(|err| LockError::new(format!("Failed to open lock file: {err}")))?;
        *slot = Some(file);
        Ok(())
    }

    fn try_flock(&self) -> bool {
        if self.open().is_err() {
            return false;
        }
        let slot = self.handle.lock();
        let Some(file) = slot.as_ref() else {
            return false;
        };
        let result = if self.exclusive {
            fs2::FileExt::try_lock_exclusive(file)
        } else {
            fs2::FileExt::try_lock_shared(file)
        };
        result.is_ok()
    }
}

impl Lock for FileLock {
    fn acquire(&self, timeout: f64) -> bool {
        if timeout <= 0.0 {
            return self.try_acquire();
        }
        let deadline = Instant::now() + Duration::from_secs_f64(timeout);
        let mut delay = Duration::from_millis(10);
        loop {
            if self.try_acquire() {
                return true;
            }
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                return false;
            }
            thread::sleep(delay.min(remaining));
            delay = (delay * 2).min(Duration::from_millis(250));
        }
    }

    fn try_acquire(&self) -> bool {
        self.try_flock()
    }

    fn release(&self) {
        let mut slot = self.handle.lock();
        if let Some(file) = slot.take() {
            let _ = fs2::FileExt::unlock(&file);
            drop(file);
        }
    }

    fn contention(&self) -> Contention {
        Contention::new(format!(
            "Failed to acquire file lock on {} within timeout",
            self.path.display()
        ))
    }
}
