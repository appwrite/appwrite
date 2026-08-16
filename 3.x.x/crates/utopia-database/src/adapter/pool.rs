//! Pool adapter (PHP `Utopia\Database\Adapter\Pool`).

use super::{Adapter, AdapterState};
use crate::adapter::memory::Memory;
use crate::error::{DatabaseError, Result};
use crate::value::AttrValue;
use utopia_pools::{Pool, Recover, RecoverCall, Stack};

/// PHP `Utopia\Database\Adapter\Pool`.
///
/// Checks an [`Adapter`] out of [`utopia_pools::Pool`] for each call. The
/// default construction uses in-memory adapters so unit tests do not need a
/// database. Live SQL pools are assembled by the caller.
pub struct PoolAdapter<A: Adapter + Recover + Send + 'static> {
    state: AdapterState,
    pool: Pool<A>,
}

impl<A: Adapter + Recover + Send + 'static> std::fmt::Debug for PoolAdapter<A> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("PoolAdapter").finish_non_exhaustive()
    }
}

impl Recover for Memory {
    fn reset(&mut self) -> RecoverCall {
        RecoverCall::Succeeded
    }
    fn reconnect(&mut self) -> RecoverCall {
        RecoverCall::Succeeded
    }
}

impl PoolAdapter<Memory> {
    /// Build a pool of Memory adapters (PHP tests construct `Pool` of `Adapter`).
    pub fn memory(name: impl Into<String>, size: usize) -> Result<Self> {
        let pool = Pool::new(Stack::new(), name, size, Memory::new, 10.0)
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        Ok(Self {
            state: AdapterState::default(),
            pool,
        })
    }
}

impl<A: Adapter + Recover + Send + 'static> PoolAdapter<A> {
    /// Wrap an existing pool.
    #[must_use]
    pub fn new(pool: Pool<A>) -> Self {
        Self {
            state: AdapterState::default(),
            pool,
        }
    }

    /// The underlying pool.
    #[must_use]
    pub fn pool(&self) -> &Pool<A> {
        &self.pool
    }

    fn with_adapter<R, F: FnOnce(&mut A) -> R>(&self, callback: F) -> Result<R> {
        self.pool
            .use_sync(callback)
            .map_err(|e| DatabaseError::database(e.to_string()))
    }
}

impl<A: Adapter + Recover + Send + 'static> Adapter for PoolAdapter<A> {
    fn state(&self) -> &AdapterState {
        &self.state
    }
    fn state_mut(&mut self) -> &mut AdapterState {
        &mut self.state
    }
    fn ping(&mut self) -> bool {
        self.with_adapter(Adapter::ping).unwrap_or(false)
    }
    fn get_driver(&self) -> AttrValue {
        AttrValue::from("pool")
    }
}
