//! Lightweight parent/child dependency injection container.
//!
//! Rust port of [`utopia-php/di`](https://github.com/utopia-php/di).

mod container;
mod error;
mod resource;

pub use container::Container;
pub use error::{ContainerError, NotFoundError};
pub use resource::Resource;

/// Prelude for common DI types.
pub mod prelude {
    pub use crate::{Container, ContainerError, NotFoundError, Resource};
}
