//! The global hooks: PHP's `Http::init()`, `shutdown()`, `error()`,
//! `options()` and `wildcard()` hooks (`app/controllers/general.php`,
//! `app/controllers/shared/api.php`, `app/controllers/shared/api/auth.php`),
//! the modules' hook actions, and the request resources that carry
//! behaviour (`app/init/resources/request.php`). Each module is one entry of
//! `crates/appwrite/hooks.json`, named after its id, under its kind.
//!
//! PHP's order, which [`super::lifecycle`] follows:
//!
//! 1. `OPTIONS` requests get [`preflight`] alone;
//! 2. a request no route matches runs [`wildcard`]'s route;
//! 3. the [`init`] hooks, by group, with the [`resources`] they inject
//!    resolved as they are first used;
//! 4. the action;
//! 5. on success, the [`shutdown`] hooks, after the response is sent;
//!    on failure, the [`error`] hook instead.

pub(in crate::platform) mod error;
pub(in crate::platform) mod init;
pub(in crate::platform) mod preflight;
pub(in crate::platform) mod resources;
pub(in crate::platform) mod shutdown;
pub(in crate::platform) mod wildcard;
