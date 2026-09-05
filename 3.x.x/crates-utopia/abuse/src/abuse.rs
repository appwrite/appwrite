use crate::adapter::Adapter;
use crate::error::AbuseError;
use crate::logs::Logs;

/// Facade that forwards to an [`Adapter`].
#[derive(Debug, Clone)]
pub struct Abuse<A: Adapter> {
    adapter: A,
}

impl<A: Adapter> Abuse<A> {
    /// PHP `new Abuse($adapter)`.
    #[must_use]
    pub fn new(adapter: A) -> Self {
        Self { adapter }
    }

    /// PHP `check()`. `true` means abuse for limiter adapters.
    ///
    /// # Errors
    ///
    /// Propagates adapter errors.
    pub fn check(&mut self) -> Result<bool, AbuseError> {
        self.adapter.check()
    }

    /// PHP `getLogs(?int $offset = null, ?int $limit = 25)`.
    ///
    /// # Errors
    ///
    /// Propagates adapter errors.
    pub fn get_logs(
        &mut self,
        offset: Option<i64>,
        limit: Option<i64>,
    ) -> Result<Logs, AbuseError> {
        self.adapter.get_logs(offset, limit)
    }

    /// PHP `cleanup(int $timestamp)`.
    ///
    /// # Errors
    ///
    /// Propagates adapter errors.
    pub fn cleanup(&mut self, timestamp: i64) -> Result<bool, AbuseError> {
        self.adapter.cleanup(timestamp)
    }

    /// PHP `reset()`.
    ///
    /// # Errors
    ///
    /// Propagates adapter errors.
    pub fn reset(&mut self) -> Result<(), AbuseError> {
        self.adapter.reset()
    }

    /// Borrow the inner adapter (PHP keeps object identity between `Abuse` and the adapter).
    #[must_use]
    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    /// Mutably borrow the inner adapter.
    pub fn adapter_mut(&mut self) -> &mut A {
        &mut self.adapter
    }

    /// Unwrap the adapter.
    #[must_use]
    pub fn into_inner(self) -> A {
        self.adapter
    }
}
