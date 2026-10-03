//! Shared Hook/action builders for Utopia servers.
//!
//! Rust port of [`utopia-php/servers`](https://github.com/utopia-php/servers).

mod enum_meta;
mod error;
mod hook;
mod param;

pub use enum_meta::EnumMeta;
pub use error::HookError;
pub use hook::{ArgumentKind, Hook};
pub use param::ParamDef;

pub mod prelude {
    pub use crate::{ArgumentKind, EnumMeta, Hook, HookError, ParamDef};
}
