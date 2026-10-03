use crate::span::Span;

use parking_lot::Mutex;
use tokio::task::Id;

use crate::storage::Storage;

/// Task-local storage (PHP `Storage\Coroutine` / Swoole context).
///
/// Outside a Tokio task (`tokio::task::try_id()` is `None`), get/set are no-ops
/// matching PHP outside a Swoole coroutine.
#[derive(Debug, Default)]
pub struct Coroutine {
    spans: Mutex<std::collections::HashMap<Id, Span>>,
}

impl Coroutine {
    pub fn new() -> Self {
        Self::default()
    }
}

impl Storage for Coroutine {
    fn get(&self) -> Option<Span> {
        let id = tokio::task::try_id()?;
        self.spans.lock().get(&id).cloned()
    }

    fn set(&self, span: Option<Span>) {
        let Some(id) = tokio::task::try_id() else {
            return;
        };
        let mut spans = self.spans.lock();
        match span {
            Some(span) => {
                spans.insert(id, span);
            }
            None => {
                spans.remove(&id);
            }
        }
    }
}
