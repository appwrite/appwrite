//! Appwrite CORS, origin, and platform hostname helpers.
//!
//! Rust port of PHP `Appwrite\Network\*` (`src/Appwrite/Network/`) and the
//! allow-list hostname matcher CORS uses from `Utopia\Validator\Hostname`.
//!
//! ```
//! use appwrite_network::{Cors, HEADER_ALLOW_ORIGIN};
//!
//! let cors = Cors::from_allowed_hosts(["example.com"]).unwrap();
//! let headers = cors.headers("https://example.com");
//! assert_eq!(
//!     headers.iter().find(|(k, _)| k == HEADER_ALLOW_ORIGIN).map(|(_, v)| v.as_str()),
//!     Some("https://example.com")
//! );
//! ```

mod config;
mod cors;
mod hostname;
mod hosts;
mod origin;
mod platform;
mod url;

pub use config::{ALLOWED_HEADERS, ALLOWED_METHODS, EXPOSED_HEADERS, MAX_AGE};
pub use cors::{
    Cors, CorsError, HEADER_ALLOW_CREDENTIALS, HEADER_ALLOW_HEADERS, HEADER_ALLOW_METHODS,
    HEADER_ALLOW_ORIGIN, HEADER_EXPOSE_HEADERS, HEADER_MAX_AGE,
};
pub use hostname::HostnameList;
pub use hosts::{allowed_hostnames, platform_hostnames, platform_schemes, AllowedHostnames};
pub use origin::Origin;
pub use platform::{
    get_hostnames, get_name_by_scheme, get_schemes, map_deprecated_type, LOOPBACK_ALIASES,
    LOOPBACK_HOSTNAME, SCHEME_ANDROID, SCHEME_CHROME_EXTENSION, SCHEME_EDGE_EXTENSION,
    SCHEME_FIREFOX_EXTENSION, SCHEME_HTTP, SCHEME_HTTPS, SCHEME_IOS, SCHEME_LINUX, SCHEME_MACOS,
    SCHEME_SAFARI_EXTENSION, SCHEME_TAURI, SCHEME_TVOS, SCHEME_WATCHOS, SCHEME_WINDOWS,
    TYPE_ANDROID, TYPE_APPLE, TYPE_LINUX, TYPE_SCHEME, TYPE_UNKNOWN, TYPE_WEB, TYPE_WINDOWS,
};
pub use url::url_host;
