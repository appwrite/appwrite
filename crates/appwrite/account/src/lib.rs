//! The Account service (`app/controllers/api/account.php` and
//! `src/Appwrite/Platform/Modules/Account`).
//!
//! Every route keeps the PHP contract: path, parameters and validators (in
//! declaration order), groups, labels (scopes, events, audits, abuse limits,
//! `auth.type`, `session.allowActive`, `impersonation`), status codes,
//! cookies and response models. The lifecycle hooks those labels drive run
//! in `appwrite_core::platform`.

mod base;
mod http;
mod oauth2;

use appwrite_core::platform::{Module, Route};

/// The Account module.
pub struct Account;

pub(crate) const GROUPS: &[&str] = &["api", "account"];
pub(crate) const GROUPS_AUTH: &[&str] = &["api", "account", "auth"];
pub(crate) const GROUPS_SESSION: &[&str] = &["api", "account", "session"];
pub(crate) const GROUPS_AUTH_SESSION: &[&str] = &["api", "account", "auth", "session"];
pub(crate) const GROUPS_MFA: &[&str] = &["api", "account", "mfa"];

/// A route of the `account` SDK namespace (`namespace` given for routes
/// without an SDK method).
macro_rules! route {
    (namespace: $namespace:literal, $method:ident, $path:literal, $name:literal, $sdk:literal, $scopes:expr, $event:expr, $audit:expr, $groups:expr, $labels:expr, $action:path) => {
        appwrite_core::platform::Route {
            method: utopia_http::Method::$method,
            path: $path,
            name: $name,
            namespace: $namespace,
            sdk_method: $sdk,
            scopes: $scopes,
            event: $event,
            audit: $audit,
            groups: $groups,
            labels: $labels,
            action: appwrite_core::action!($action),
        }
    };
    ($method:ident, $path:literal, $name:literal, $sdk:literal, $scopes:expr, $event:expr, $audit:expr, $groups:expr, $labels:expr, $action:path) => {
        appwrite_core::platform::Route {
            method: utopia_http::Method::$method,
            path: $path,
            name: $name,
            namespace: "account",
            sdk_method: $sdk,
            scopes: $scopes,
            event: $event,
            audit: $audit,
            groups: $groups,
            labels: $labels,
            action: appwrite_core::action!($action),
        }
    };
}
pub(crate) use route;

impl Module for Account {
    fn name(&self) -> &'static str {
        "account"
    }

    fn routes(&self) -> Vec<Route> {
        use http::*;
        [account::routes(), sessions::routes(), tokens::routes(), jwts::routes(), mfa::routes(), passkeys::routes()]
            .concat()
    }
}
