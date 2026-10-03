//! [`CloudEvents`](https://cloudevents.io) v1.0 types for Utopia.
//!
//! Rust port of [`utopia-php/cloudevents`](https://github.com/utopia-php/cloudevents).

mod error;
mod event;

pub use error::CloudEventError;
pub use event::{CloudEvent, ExtensionValue, TIME_FORMAT};

/// Prelude for the PHP-shaped surface.
pub mod prelude {
    pub use crate::{CloudEvent, CloudEventError, ExtensionValue, TIME_FORMAT};
}
