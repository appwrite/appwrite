//! CLI runtime adapters.
//!
//! PHP `Utopia\CLI\Adapters`.

mod generic;
mod swoole;

pub use generic::Generic;
pub use swoole::Swoole;
