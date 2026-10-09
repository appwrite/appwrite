use std::sync::atomic::{AtomicUsize, Ordering};

use crate::{Error, Lock, Wait};

/// At most `permits` holders at a time, within one process.
pub struct Semaphore {
    permits: usize,
    inner: tokio::sync::Semaphore,
    /// Permits handed out and not yet released; makes [`Lock::release`] idempotent.
    held: AtomicUsize,
}

impl Semaphore {
    /// Fails like PHP when `permits` is below 1.
    pub fn new(permits: i64) -> Result<Self, Error> {
        if permits < 1 {
            return Err(Error::InvalidArgument("Permits must be at least 1".into()));
        }
        let permits = permits as usize;
        Ok(Self { permits, inner: tokio::sync::Semaphore::new(permits), held: AtomicUsize::new(0) })
    }

    pub fn permits(&self) -> usize {
        self.permits
    }

    fn take(&self, permit: tokio::sync::SemaphorePermit<'_>) -> bool {
        permit.forget();
        self.held.fetch_add(1, Ordering::AcqRel);
        true
    }

    async fn acquire_inner(&self, wait: Wait) -> bool {
        match wait {
            Wait::None => self.inner.try_acquire().map(|p| self.take(p)).unwrap_or(false),
            Wait::For(d) => match tokio::time::timeout(d, self.inner.acquire()).await {
                Ok(Ok(p)) => self.take(p),
                _ => false,
            },
            Wait::Forever => self.inner.acquire().await.map(|p| self.take(p)).unwrap_or(false),
        }
    }

    fn release_inner(&self) {
        if self.held.fetch_update(Ordering::AcqRel, Ordering::Acquire, |h| h.checked_sub(1)).is_ok() {
            self.inner.add_permits(1);
        }
    }
}

impl Lock for Semaphore {
    async fn acquire(&self, wait: Wait) -> Result<bool, Error> {
        Ok(self.acquire_inner(wait).await)
    }

    async fn try_acquire(&self) -> Result<bool, Error> {
        Ok(self.acquire_inner(Wait::None).await)
    }

    async fn release(&self) -> Result<(), Error> {
        self.release_inner();
        Ok(())
    }

    fn contention(&self) -> Error {
        Error::Contention("Failed to acquire semaphore within timeout".into())
    }
}

/// One holder at a time, within one process.
pub struct Mutex(Semaphore);

impl Default for Mutex {
    fn default() -> Self {
        Self::new()
    }
}

impl Mutex {
    pub fn new() -> Self {
        Self(Semaphore { permits: 1, inner: tokio::sync::Semaphore::new(1), held: AtomicUsize::new(0) })
    }
}

impl Lock for Mutex {
    async fn acquire(&self, wait: Wait) -> Result<bool, Error> {
        Ok(self.0.acquire_inner(wait).await)
    }

    async fn try_acquire(&self) -> Result<bool, Error> {
        Ok(self.0.acquire_inner(Wait::None).await)
    }

    async fn release(&self) -> Result<(), Error> {
        self.0.release_inner();
        Ok(())
    }

    fn contention(&self) -> Error {
        Error::Contention("Failed to acquire mutex within timeout".into())
    }
}

#[cfg(test)]
mod tests {
    use std::sync::Arc;
    use std::time::Duration;

    use super::*;

    #[tokio::test]
    async fn mutex_excludes_and_release_is_idempotent() {
        let m = Mutex::new();
        assert!(m.try_acquire().await.unwrap());
        assert!(!m.try_acquire().await.unwrap());
        m.release().await.unwrap();
        m.release().await.unwrap();
        assert!(m.try_acquire().await.unwrap());
        assert!(!m.try_acquire().await.unwrap(), "a double release must not add a permit");
    }

    #[tokio::test]
    async fn semaphore_caps_concurrency() {
        let s = Arc::new(Semaphore::new(3).unwrap());
        let live = Arc::new(AtomicUsize::new(0));
        let max = Arc::new(AtomicUsize::new(0));
        let tasks: Vec<_> = (0..10)
            .map(|_| {
                let (s, live, max) = (s.clone(), live.clone(), max.clone());
                tokio::spawn(async move {
                    s.with_lock(Wait::Forever, async {
                        let now = live.fetch_add(1, Ordering::SeqCst) + 1;
                        max.fetch_max(now, Ordering::SeqCst);
                        tokio::time::sleep(Duration::from_millis(5)).await;
                        live.fetch_sub(1, Ordering::SeqCst);
                    })
                    .await
                    .unwrap();
                })
            })
            .collect();
        for t in tasks {
            t.await.unwrap();
        }
        assert_eq!(max.load(Ordering::SeqCst), 3);
    }

    #[test]
    fn rejects_invalid_permits() {
        assert_eq!(Semaphore::new(0).err().map(|e| e.to_string()).as_deref(), Some("Permits must be at least 1"));
    }
}
