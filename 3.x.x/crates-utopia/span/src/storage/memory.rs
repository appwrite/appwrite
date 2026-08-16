use crate::span::Span;
use crate::storage::Storage;

/// Simple memory storage (PHP `Storage\Memory`).
#[derive(Debug, Default)]
pub struct Memory {
    span: parking_lot::Mutex<Option<Span>>,
}

impl Memory {
    pub fn new() -> Self {
        Self::default()
    }
}

impl Storage for Memory {
    fn get(&self) -> Option<Span> {
        self.span.lock().clone()
    }

    fn set(&self, span: Option<Span>) {
        *self.span.lock() = span;
    }
}
