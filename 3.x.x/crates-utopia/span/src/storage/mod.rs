//! Span context storage (PHP `Utopia\Span\Storage`).

mod auto;
mod coroutine;
mod memory;

pub use auto::Auto;
pub use coroutine::Coroutine;
pub use memory::Memory;

use crate::span::Span;

/// PHP `Utopia\Span\Storage\Storage`.
pub trait Storage: Send + Sync {
    fn get(&self) -> Option<Span>;
    fn set(&self, span: Option<Span>);
}
