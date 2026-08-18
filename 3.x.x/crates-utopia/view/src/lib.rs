//! View rendering for Utopia.
//!
//! Rust port of [`utopia-php/view`](https://github.com/utopia-php/view).

mod error;
mod escape;
mod template;
mod view;

pub use error::ViewError;
pub use view::{ExecArg, PrintFilter, View};

/// Prelude for common view types.
pub mod prelude {
    pub use crate::{ExecArg, PrintFilter, View, ViewError};
}
