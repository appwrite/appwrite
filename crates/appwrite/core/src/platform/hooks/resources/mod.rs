//! The request resources that carry behaviour (PHP:
//! `app/init/resources/request.php`), one module per `resource-*` entry of
//! `crates/appwrite/hooks.json`. PHP resolves them when a hook or the action
//! first injects them; the `api` group's init hooks resolve them in that
//! order (see [`crate::platform::lifecycle`]).

pub(in crate::platform) mod database_listeners;
pub(in crate::platform) mod hostnames;
pub(in crate::platform) mod impersonation;
pub(in crate::platform) mod key;
pub(in crate::platform) mod mode;
pub(in crate::platform) mod project;
pub(in crate::platform) mod resource_token;
pub(in crate::platform) mod team;
pub(in crate::platform) mod user;

use serde_json::Value;
use utopia_http::Request;

/// A request parameter, when it is a string (`$request->getParam()`).
pub(in crate::platform) fn string_param(request: &Request, key: &str) -> Option<String> {
    match request.params().get(key) {
        Some(Value::String(s)) => Some(s.clone()),
        _ => None,
    }
}
