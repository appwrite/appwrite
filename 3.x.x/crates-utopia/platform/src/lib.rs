//! Object-oriented application layer for Utopia.
//!
//! Rust port of [`utopia-php/platform`](https://github.com/utopia-php/platform).

mod action;
#[cfg(feature = "cli")]
mod cli;
mod enum_type;
mod error;
mod hook_meta;
#[cfg(feature = "http")]
mod http;
mod module;
mod platform;
mod service;
#[cfg(feature = "worker")]
mod worker;

#[cfg(feature = "cli")]
pub use action::CliActionCallback;
pub use action::{Action, ActionOption, ActionType, HttpMethod, ParamDef, SyncCallback};
#[cfg(feature = "cli")]
pub use cli::{CliRegistrar, UtopiaCliRegistrar};
pub use enum_type::Enum;
pub use error::{PlatformError, Result};
#[cfg(feature = "http")]
pub use http::{HttpRegistrar, UtopiaHttpRegistrar};
pub use module::Module;
pub use platform::{is_hook_action, Platform};
pub use service::{Service, ServiceType};
#[cfg(feature = "worker")]
pub use worker::{
    GenericWorker, RegisteredWorkerHook, WorkerHookKind, WorkerHookRegistrar, WorkerRegistrar,
};

pub mod prelude {
    pub use crate::{
        Action, ActionType, Enum, HttpMethod, Module, Platform, PlatformError, Result, Service,
        ServiceType,
    };
    #[cfg(feature = "cli")]
    pub use crate::{CliRegistrar, UtopiaCliRegistrar};
    #[cfg(feature = "worker")]
    pub use crate::{GenericWorker, WorkerRegistrar};
    #[cfg(feature = "http")]
    pub use crate::{HttpRegistrar, UtopiaHttpRegistrar};
}
