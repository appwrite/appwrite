//! The init hooks (PHP's `Http::init()` hooks and the modules' `init` hook
//! actions), one module per `init` entry of `crates/appwrite/hooks.json`.
//!
//! [`crate::platform::lifecycle`] runs them in PHP's order: `general.php`'s
//! (`admin-console`, `request`, `security`, `certificates`, with the
//! resources they inject), then `shared/api.php`'s (`auth`, `abuse`,
//! `context`, `session-active`), then `shared/api/auth.php`'s
//! (`mfa-fresh`, `recent-session`, `auth-methods`), and last the hooks of
//! the route's other groups (`console-forbidden`). `usage-context` is kept
//! for the shutdown hooks, and `database-timeout` applies when the project
//! database is first opened.

pub(in crate::platform) mod abuse;
pub(in crate::platform) mod admin_console;
pub(in crate::platform) mod auth;
pub(in crate::platform) mod auth_methods;
pub(in crate::platform) mod certificates;
pub(in crate::platform) mod console_forbidden;
pub(in crate::platform) mod context;
pub(in crate::platform) mod database_timeout;
pub(in crate::platform) mod mfa_fresh;
pub(in crate::platform) mod recent_session;
pub(in crate::platform) mod request;
pub(in crate::platform) mod security;
pub(in crate::platform) mod session_active;
pub(in crate::platform) mod usage_context;
