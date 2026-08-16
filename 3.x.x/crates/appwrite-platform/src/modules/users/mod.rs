//! Users module. Rust port of `Appwrite\Platform\Modules\Users`
//! (`src/Appwrite/Platform/Modules/Users/`): every `/v1/users*` HTTP action,
//! grouped by resource the same way PHP's `Http/Users/*` directory tree is,
//! plus the shared `createUser` helper ([`base`]).
//!
//! Endpoints are grouped into fewer, resource-scoped files
//! (`http::crud`, `http::hashes`, ...) rather than one file per PHP action
//! class -- each function still maps 1:1 to a PHP `Http/Users/**/*.php`
//! action (see the module docs in `http/*.rs` for the mapping), but this
//! keeps 43 endpoints reviewable without 43 near-identical tiny files.

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
