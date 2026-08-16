use crate::span::Span;
use crate::storage::{Coroutine, Memory, Storage};

/// Auto-detecting storage (PHP `Storage\Auto`).
///
/// Uses [`Coroutine`] inside a Tokio task and [`Memory`] otherwise.
#[derive(Debug, Default)]
pub struct Auto {
    memory: Memory,
    coroutine: Coroutine,
}

impl Auto {
    pub fn new() -> Self {
        Self::default()
    }

    fn in_task() -> bool {
        tokio::task::try_id().is_some()
    }
}

impl Storage for Auto {
    fn get(&self) -> Option<Span> {
        if Self::in_task() {
            self.coroutine.get()
        } else {
            self.memory.get()
        }
    }

    fn set(&self, span: Option<Span>) {
        if Self::in_task() {
            self.coroutine.set(span);
        } else {
            self.memory.set(span);
        }
    }
}
