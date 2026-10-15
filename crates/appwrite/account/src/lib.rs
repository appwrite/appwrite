//! The Account service (`app/controllers/api/account.php` and
//! `src/Appwrite/Platform/Modules/Account`).
//!
//! Every route keeps the PHP contract: path, parameters and validators (in
//! declaration order), groups, labels (abuse limits, auth type), scopes,
//! events, audits, status codes and response models. Mails and SMS go to
//! PHP's workers through the queues, in the messages PHP publishes.
//!
//! Routes are grouped by resource in `http/`; each file lists its own
//! routes. Legacy aliases (`/v1/account/verification`, ...) stay on PHP.

macro_rules! route {
    ($method:ident, $path:literal, $name:literal, $sdk:literal, $scopes:expr, $event:expr, $audit:expr, $action:path) => {
        route!(
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

use appwrite_core::platform::{Audit, Labels, Module, Route};

/// The Account module.
pub struct Account;

pub(crate) const GROUPS: &[&str] = &["api", "account"];
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
        let mut routes = Vec::new();
        routes.extend(http::profile::routes());
        routes.extend(http::recovery::routes());
        routes.extend(http::verifications::routes());
        routes.extend(http::identities::routes());
        routes.extend(http::targets::routes());
        routes
    }
}
