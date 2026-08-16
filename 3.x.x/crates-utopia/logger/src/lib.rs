//! Error and warning logging for Utopia.
//!
//! Rust port of [`utopia-php/logger`](https://github.com/utopia-php/logger).

mod adapter;
pub mod adapters;
mod breadcrumb;
mod error;
mod log;
mod logger;
mod user;

pub use adapter::Adapter;
pub use adapters::{AppSignal, LogOwl, Raygun, Sentry};
pub use breadcrumb::Breadcrumb;
pub use error::LoggerError;
pub use log::Log;
pub use logger::Logger;
pub use user::User;

/// SDK version advertised to providers (PHP `Logger::LIBRARY_VERSION`).
pub const LIBRARY_VERSION: &str = "0.1.0";

/// Registered provider names (PHP `Logger::PROVIDERS`).
pub const PROVIDERS: &[&str] = &["raygun", "sentry", "appSignal", "logOwl"];

/// Prelude for common logger types.
pub mod prelude {
    pub use crate::{
        Adapter, AppSignal, Breadcrumb, Log, LogOwl, Logger, LoggerError, Raygun, Sentry, User,
    };
}
