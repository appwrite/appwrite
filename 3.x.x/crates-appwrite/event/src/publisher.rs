//! Publisher traits. Rust port of `Appwrite\Event\Publisher\{Delete,Audit}`
//! (`src/Appwrite/Event/Publisher/{Delete,Audit}.php`), which in PHP wrap a
//! `Utopia\Queue\Publisher` (Redis-backed). That wiring belongs in
//! `apps/server`; this crate only defines the trait boundary plus an
//! in-memory implementation for tests and early integration.

use std::sync::Mutex;

use crate::message::{AuditMessage, DeleteMessage};

/// Rust port of `Appwrite\Event\Publisher\Delete`: enqueues a
/// [`DeleteMessage`] onto the `v1-deletes` queue.
pub trait DeletePublisher: Send + Sync {
    /// PHP `Delete::enqueue(DeleteMessage $message)`.
    fn enqueue(&self, message: DeleteMessage) -> bool;

    /// PHP `Delete::getSize(bool $failed = false)`.
    fn size(&self) -> usize;
}

/// Rust port of `Appwrite\Event\Publisher\Audit`: enqueues an
/// [`AuditMessage`] onto the `v1-audits` queue. PHP additionally no-ops in
/// self-hosted editions (`_APP_EDITION`); that policy belongs to the
/// `apps/server` wiring, not this trait.
pub trait AuditPublisher: Send + Sync {
    /// PHP `Audit::enqueue(AuditMessage $message)`.
    fn enqueue(&self, message: AuditMessage) -> bool;

    /// PHP `Audit::getSize(bool $failed = false)`.
    fn size(&self) -> usize;
}

/// In-memory [`DeletePublisher`] for tests and early integration, ahead of a
/// real `utopia-queue`/Redis-backed publisher in `apps/server`.
#[derive(Debug, Default)]
pub struct MemoryDeletePublisher {
    queue: Mutex<Vec<DeleteMessage>>,
}

impl MemoryDeletePublisher {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Snapshot of everything enqueued so far, oldest first.
    #[must_use]
    pub fn messages(&self) -> Vec<DeleteMessage> {
        self.queue
            .lock()
            .expect("MemoryDeletePublisher lock poisoned")
            .clone()
    }

    /// Remove and return everything enqueued so far, oldest first.
    pub fn drain(&self) -> Vec<DeleteMessage> {
        std::mem::take(
            &mut self
                .queue
                .lock()
                .expect("MemoryDeletePublisher lock poisoned"),
        )
    }
}

impl DeletePublisher for MemoryDeletePublisher {
    fn enqueue(&self, message: DeleteMessage) -> bool {
        self.queue
            .lock()
            .expect("MemoryDeletePublisher lock poisoned")
            .push(message);
        true
    }

    fn size(&self) -> usize {
        self.queue
            .lock()
            .expect("MemoryDeletePublisher lock poisoned")
            .len()
    }
}

/// In-memory [`AuditPublisher`] for tests and early integration, ahead of a
/// real `utopia-queue`/Redis-backed publisher in `apps/server`.
#[derive(Debug, Default)]
pub struct MemoryAuditPublisher {
    queue: Mutex<Vec<AuditMessage>>,
}

impl MemoryAuditPublisher {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Snapshot of everything enqueued so far, oldest first.
    #[must_use]
    pub fn messages(&self) -> Vec<AuditMessage> {
        self.queue
            .lock()
            .expect("MemoryAuditPublisher lock poisoned")
            .clone()
    }

    /// Remove and return everything enqueued so far, oldest first.
    pub fn drain(&self) -> Vec<AuditMessage> {
        std::mem::take(
            &mut self
                .queue
                .lock()
                .expect("MemoryAuditPublisher lock poisoned"),
        )
    }
}

impl AuditPublisher for MemoryAuditPublisher {
    fn enqueue(&self, message: AuditMessage) -> bool {
        self.queue
            .lock()
            .expect("MemoryAuditPublisher lock poisoned")
            .push(message);
        true
    }

    fn size(&self) -> usize {
        self.queue
            .lock()
            .expect("MemoryAuditPublisher lock poisoned")
            .len()
    }
}

/// Callback-backed [`DeletePublisher`], useful for tests that want to assert
/// on enqueue order without holding onto a [`MemoryDeletePublisher`]
/// reference (e.g. forwarding into another channel).
pub struct CallbackDeletePublisher<F: Fn(DeleteMessage) -> bool + Send + Sync> {
    callback: F,
    count: Mutex<usize>,
}

impl<F: Fn(DeleteMessage) -> bool + Send + Sync> std::fmt::Debug for CallbackDeletePublisher<F> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("CallbackDeletePublisher")
            .field("count", &self.count)
            .finish_non_exhaustive()
    }
}

impl<F: Fn(DeleteMessage) -> bool + Send + Sync> CallbackDeletePublisher<F> {
    #[must_use]
    pub fn new(callback: F) -> Self {
        Self {
            callback,
            count: Mutex::new(0),
        }
    }
}

impl<F: Fn(DeleteMessage) -> bool + Send + Sync> DeletePublisher for CallbackDeletePublisher<F> {
    fn enqueue(&self, message: DeleteMessage) -> bool {
        *self
            .count
            .lock()
            .expect("CallbackDeletePublisher lock poisoned") += 1;
        (self.callback)(message)
    }

    fn size(&self) -> usize {
        *self
            .count
            .lock()
            .expect("CallbackDeletePublisher lock poisoned")
    }
}
