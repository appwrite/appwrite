//! The Account service (`app/controllers/api/account.php` and
//! `src/Appwrite/Platform/Modules/Account`).
//!
//! Every route keeps the PHP contract: path, parameters and validators (in
//! declaration order), groups, labels (scopes, events, audits, abuse limits,
//! `auth.type`, `session.allowActive`, `impersonation`), status codes,
//! cookies and response models. The lifecycle hooks those labels drive run
//! in `appwrite_core::platform`. Mails and SMS go to PHP's workers through
//! the queues, in the messages PHP publishes.
//!
//! Routes are grouped by resource in `http/`; each file lists its own
//! routes. Legacy aliases (`/v1/account/verification`, ...) stay on PHP.

/// A route of the profile, recovery, verification, identity and target
/// files: the action, then optional groups and labels.
macro_rules! profile_route {
    ($method:ident, $path:literal, $name:literal, $sdk:literal, $scopes:expr, $event:expr, $audit:expr, $action:path) => {
        profile_route!(
            $method,
            $path,
            $name,
            $sdk,
            $scopes,
            $event,
            $audit,
            $action,
            $crate::GROUPS,
            ::appwrite_core::platform::Labels::NONE
        )
    };
    ($method:ident, $path:literal, $name:literal, $sdk:literal, $scopes:expr, $event:expr, $audit:expr, $action:path, $groups:expr, $labels:expr) => {
        ::appwrite_core::platform::Route {
            method: ::utopia_http::Method::$method,
            path: $path,
            name: $name,
            namespace: "account",
            sdk_method: $sdk,
            scopes: $scopes,
            event: $event,
            audit: $audit,
            groups: $groups,
            labels: $labels,
            action: ::appwrite_core::action!($action),
        }
    };
}

mod base;
mod http;
mod oauth2;

use appwrite_core::platform::{Audit, Labels, Module, Route};

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

pub(crate) const ACCOUNT: &[&str] = &["account"];

pub(crate) const fn audit(event: &'static str, resource: &'static str) -> Option<Audit> {
    Some(Audit { event, resource })
}

/// `abuse-limit` 10 with the given `abuse-key`s (the window stays an hour).
pub(crate) const fn abuse(keys: &'static [&'static str]) -> Labels {
    Labels { abuse_key: keys, abuse_limit: 10, ..Labels::NONE }
}

impl Module for Account {
    fn name(&self) -> &'static str {
        "account"
    }

    fn routes(&self) -> Vec<Route> {
        use http::*;
        [
            account::routes(),
            sessions::routes(),
            tokens::routes(),
            jwts::routes(),
            mfa::routes(),
            passkeys::routes(),
            profile::routes(),
            recovery::routes(),
            verifications::routes(),
            identities::routes(),
            targets::routes(),
        ]
        .concat()
    }
}
