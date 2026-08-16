//! Appwrite platform composition layer.
//!
//! Wires together the Users-API foundation crates (`appwrite-exception`,
//! `appwrite-hooks`, `appwrite-locale`, `appwrite-auth`, `appwrite-event`,
//! `appwrite-response`, `appwrite-database`) on top of `utopia-platform`'s
//! `Module`/DI composition, mirroring how `app/init.php` wires
//! `Appwrite\*` services (`$hooks`, `$publisherForDeletes`,
//! `$publisherForAudits`, ...) into the Utopia `App`/`Platform` at boot.

use std::sync::Arc;

use appwrite_auth::Password;
use appwrite_event::{
    AuditPublisher, DeletePublisher, MemoryAuditPublisher, MemoryDeletePublisher,
};
use appwrite_exception::Exception;
use appwrite_hooks::Hooks;
use utopia_di::{Container, Resource};
use utopia_platform::{Module, Platform};
use utopia_validators::Validator;

pub mod modules;
pub mod state;

pub use state::AppwriteState;

/// Appwrite platform facade.
pub struct AppwritePlatform {
    inner: Platform,
    di: Container,
    hooks: Hooks,
    deletes: MemoryDeletePublisher,
    audits: MemoryAuditPublisher,
}

impl std::fmt::Debug for AppwritePlatform {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("AppwritePlatform")
            .field("platform", &self.inner)
            .field("hooks", &self.hooks)
            .finish_non_exhaustive()
    }
}

impl AppwritePlatform {
    /// Create an empty platform with a root DI container, delete/audit
    /// queue publishers, and the default `passwordValidator` hook
    /// registered -- mirroring `app/init.php`'s baseline `Hooks::add()`
    /// call before project-specific policy (strength/dictionary/history)
    /// is layered on top.
    #[must_use]
    pub fn new() -> Self {
        let mut hooks = Hooks::new();
        hooks.add(appwrite_hooks::PASSWORD_VALIDATOR, |params| {
            let password = params.first().and_then(|v| v.as_str()).unwrap_or_default();
            serde_json::json!(Password::new(false).is_valid(&serde_json::json!(password)))
        });

        Self {
            inner: Platform::new(Module::new()),
            di: Container::new(),
            hooks,
            deletes: MemoryDeletePublisher::new(),
            audits: MemoryAuditPublisher::new(),
        }
    }

    /// Access the utopia platform.
    #[must_use]
    pub fn platform(&self) -> &Platform {
        &self.inner
    }

    /// Access the DI container.
    #[must_use]
    pub fn di(&self) -> &Container {
        &self.di
    }

    /// Access the hook registry (e.g. `appwrite_hooks::PASSWORD_VALIDATOR`).
    #[must_use]
    pub fn hooks(&self) -> &Hooks {
        &self.hooks
    }

    /// Mutable access to the hook registry, for registering project-level
    /// validators at boot.
    #[must_use]
    pub fn hooks_mut(&mut self) -> &mut Hooks {
        &mut self.hooks
    }

    /// The `v1-deletes` queue publisher. In-memory for now; `apps/server`
    /// wires a Redis-backed `DeletePublisher` in its place.
    #[must_use]
    pub fn deletes(&self) -> &MemoryDeletePublisher {
        &self.deletes
    }

    /// The `v1-audits` queue publisher. In-memory for now; `apps/server`
    /// wires a Redis-backed `AuditPublisher` in its place.
    #[must_use]
    pub fn audits(&self) -> &MemoryAuditPublisher {
        &self.audits
    }

    /// Readiness check that touches every foundation crate this platform
    /// composes: the hook registry, the delete/audit publishers, the
    /// response model catalog, the database `unique()` sentinel, and the
    /// `utopia-http` mode constant it will boot with.
    pub fn ensure_ready(&self) -> Result<(), Exception> {
        if !self.hooks.has(appwrite_hooks::PASSWORD_VALIDATOR) {
            return Err(Exception::with_message(
                Exception::GENERAL_SERVER_ERROR,
                "default password validator hook missing",
            ));
        }
        let _ = self.deletes.size();
        let _ = self.audits.size();
        let _ = appwrite_response::MODEL_USER;
        let _ = appwrite_database::UNIQUE_SENTINEL;
        let _ = utopia_http::Mode::Development;
        Ok(())
    }
}

impl Default for AppwritePlatform {
    fn default() -> Self {
        Self::new()
    }
}

/// Build the global DI resources container plus a [`Platform`] with the
/// `core` (shared `api`-group `Init`/`Error`/`Shutdown` hooks) and `users`
/// modules registered, ready for [`Platform::init_http`].
///
/// The returned [`Container`] must be the one handed to the
/// [`utopia_http::Http`] adapter (e.g. `HyperServer::bind(&bind,
/// resources)`), since every request's DI container is a
/// [`Container::child`] of it -- this is how the `api`-group `Init` hook and
/// every route action resolve `appwriteState`, `hooks`, `publisherForDeletes`,
/// and `publisherForAudits` (PHP's globally-bound `app/init.php` resources)
/// without re-registering them per request.
#[must_use]
pub fn build(state: Arc<AppwriteState>) -> (Container, Platform) {
    let resources = Container::new();
    resources.set_cached("appwriteState", Resource::new(state.clone()));
    resources.set_cached("hooks", Resource::new(state.hooks.clone()));
    resources.set_cached("publisherForDeletes", Resource::new(state.deletes.clone()));
    resources.set_cached("publisherForAudits", Resource::new(state.audits.clone()));
    resources.set_cached(
        "passwordsDictionary",
        Resource::new(state.passwords_dictionary.clone()),
    );

    let platform = Platform::new(modules::core::module()).add_module(modules::users::module());

    (resources, platform)
}
