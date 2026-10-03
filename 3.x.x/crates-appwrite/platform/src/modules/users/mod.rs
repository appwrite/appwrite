//! Users module. Rust port of `Appwrite\Platform\Modules\Users`
//! (`src/Appwrite/Platform/Modules/Users/`): every `/v1/users*` HTTP action,
//! with `http/users/` mirroring PHP's `Http/Users/` one file per action class,
//! plus the shared `createUser` helper ([`base`]).

pub mod base;
pub mod http;
pub mod queries;
pub mod services;
pub mod validators;

use utopia_platform::{Module, Service};

/// Builds the `users` module: one `Service` carrying all 43 `/v1/users*`
/// HTTP actions (PHP `Appwrite\Platform\Modules\Users\Module` registering
/// `Services\Http`).
#[must_use]
pub fn module() -> Module {
    let service = services::http::register(Service::http());
    Module::new().add_service("users", service)
}
