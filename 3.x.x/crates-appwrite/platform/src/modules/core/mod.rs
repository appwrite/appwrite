//! Shared `api` group lifecycle hooks. Rust port of
//! `app/controllers/shared/api.php`'s `Http::init()`/`Http::shutdown()`
//! (scoped to the pieces the Users API needs -- see [`hooks::init`] for the
//! documented simplifications).

pub mod hooks;

use utopia_platform::{Module, Service};

/// Builds the `core` module: a `Service` carrying only the `api`-group
/// `Init`/`Error`/`Shutdown` hooks (PHP registers these on the global `App`
/// instance rather than inside a `Service`, but `utopia-platform`'s
/// `Platform::register_http_actions` walks every action of every service
/// regardless of HTTP-route metadata, so a dedicated hooks-only service is
/// the natural home here).
#[must_use]
pub fn module() -> Module {
    let service = Service::http()
        .add_action("apiInit", hooks::init::action())
        .add_action("apiError", hooks::error::action())
        .add_action("apiShutdown", hooks::shutdown::action());
    Module::new().add_service("core", service)
}
