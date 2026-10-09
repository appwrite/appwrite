//! Appwrite core for Rust.
//!
//! The Rust counterpart of `src/Appwrite` and `app/`: everything that is
//! Appwrite-specific but shared by every service module.
//!
//! | module        | PHP counterpart                                           |
//! |---------------|-----------------------------------------------------------|
//! | [`config`]    | `app/init/constants.php`, env vars                        |
//! | [`error`]     | `Appwrite\Extend\Exception`, `app/config/errors.php`      |
//! | [`auth`]      | `Appwrite\Auth`, `app/config/roles.php`, JWT, keys        |
//! | [`crypto`]    | the `encrypt` database filter                             |
//! | [`database`]  | `Appwrite\Database`, collection documents and schemas     |
//! | [`detector`]  | `Appwrite\Detector` (session OS, client, device)           |
//! | [`event`]     | `Appwrite\Event` publishers                               |
//! | [`network`]   | `Appwrite\Network` (CORS, origin, IP)                     |
//! | [`response`]  | `Appwrite\Utopia\Response` and models                     |
//! | [`platform`]  | `Appwrite\Platform`: modules, routes, request lifecycle   |
//!
//! Service modules (Users, Teams, ...) live in their own crates and plug into
//! [`platform::Platform`]. Appwrite Cloud extends CE by registering extra
//! modules and implementing [`platform::Hooks`], without forking CE.

pub mod auth;
pub mod config;
pub mod crypto;
pub mod database;
pub mod detector;
pub mod error;
pub mod event;
pub mod geo;
pub mod json;
pub mod locking;
pub mod network;
pub mod platform;
pub mod response;
pub mod validators;

pub use error::{Error, ErrorType};

/// Stable API version reported in error responses (`APP_VERSION_STABLE`).
pub const VERSION: &str = "2.4.0";

/// Result alias used across Appwrite crates.
pub type Result<T, E = Error> = std::result::Result<T, E>;
