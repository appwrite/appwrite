use crate::error::Contention;

/// PHP `Utopia\Lock\Lock`.
///
/// `timeout` is seconds. Negative waits forever; `0.0` does not wait.
pub trait Lock: Send + Sync {
    fn acquire(&self, timeout: f64) -> bool;
    fn try_acquire(&self) -> bool;
    fn release(&self);

    /// PHP `withLock($callback, $timeout = 0.0)`.
    fn with_lock<R, F: FnOnce() -> R>(&self, callback: F, timeout: f64) -> Result<R, Contention> {
        if !self.acquire(timeout) {
            return Err(self.contention());
        }
        let result = std::panic::catch_unwind(std::panic::AssertUnwindSafe(callback));
        self.release();
        match result {
            Ok(value) => Ok(value),
            Err(payload) => std::panic::resume_unwind(payload),
        }
    }

    fn contention(&self) -> Contention {
        Contention::new("Failed to acquire lock within timeout")
    }
}
