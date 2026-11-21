use std::sync::atomic::{AtomicUsize, Ordering};

/// Decrements the in-flight counter when a request ends.
pub(super) struct InFlight<'a>(pub &'a AtomicUsize);

impl Drop for InFlight<'_> {
    /// Decrements the counter.
    fn drop(&mut self) {
        self.0.fetch_sub(1, Ordering::SeqCst);
    }
}
