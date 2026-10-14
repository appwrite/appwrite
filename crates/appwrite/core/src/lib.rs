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
//! | [`locale`]    | translations (`app/init.php` `Locale` setup, request locale) |
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
pub mod datetime;
pub mod detector;
pub mod error;
pub mod event;
pub mod geo;
pub mod json;
pub mod locale;
pub mod locking;
pub mod network;
pub mod platform;
pub mod response;
pub mod validators;

pub use error::{Error, ErrorType};

/// Stable API version reported in error responses (`APP_VERSION_STABLE`).
pub const VERSION: &str = "2.5.0";

/// Result alias used across Appwrite crates.
pub type Result<T, E = Error> = std::result::Result<T, E>;

#[cfg(test)]
mod tests {
    /// `VERSION` follows PHP's `APP_VERSION_STABLE`, which a release bumps.
    #[test]
    fn version_is_php_stable_version() {
        let constants =
            std::fs::read_to_string(concat!(env!("CARGO_MANIFEST_DIR"), "/../../../app/init/constants.php"))
                .expect("app/init/constants.php");
        let line = constants.lines().find(|l| l.starts_with("const APP_VERSION_STABLE")).expect("APP_VERSION_STABLE");
        assert!(line.contains(&format!("'{}'", super::VERSION)), "{line}");
    }
}
