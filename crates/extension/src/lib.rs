//! The `utopia` PHP extension: Utopia crates registered as PHP classes. The
//! value objects and enums those classes take live in the Composer packages
//! (`packages/<name>`), as plain PHP.
//!
//! `Utopia\Image\Canvas`, `Utopia\Image\Font`, `Utopia\DSN\DSN`,
//! `Utopia\Locale\Locale`, `Utopia\UserAgent\UserAgent`, `Utopia\Domains\Domain`,
//! `Utopia\Emails\Email`, the validators of `utopia-php/validators` (`Utopia\Validator\Validator`
//! and its leaves) and the password hashes of `utopia-php/auth` (`Utopia\Auth\Hash` and
//! `Hashes\*`) are always registered: they have no PHP implementation.
//!
//! A library that still has one registers its classes only when `_APP_EXTENSION_LIBRARIES` names
//! its package (`if enabled("<package>")` in [`startup`] or [`get_module`]), so it can be canaried
//! and switched back to PHP with a restart, not a deploy. None does today.

mod auth;
mod canvas;
mod classes;
mod domains;
mod dsn;
mod emails;
mod locale;
mod string;
mod user_agent;
mod validators;

use ext_php_rs::prelude::*;

/// The environment variable listing the packages the extension replaces,
/// comma separated.
const LIBRARIES: &str = "_APP_EXTENSION_LIBRARIES";

/// Whether `_APP_EXTENSION_LIBRARIES` names `package`. Unused while no library is switchable; the
/// next one to be canaried calls it.
#[allow(dead_code)]
fn enabled(package: &str) -> bool {
    std::env::var(LIBRARIES).is_ok_and(|list| list.split(',').any(|name| name.trim() == package))
}

/// The module PHP loads: every class of the extension.
#[php_module]
#[php(startup = startup)]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    let module = module
        .name("utopia")
        .class::<canvas::Canvas>()
        .class::<canvas::Font>()
        .class::<dsn::Dsn>()
        .class::<domains::Domain>()
        .class::<emails::Email>();
    module.request_shutdown_function(request_shutdown)
}

/// Registers the classes built by hand rather than with `#[php_class]`: `Locale`, `UserAgent`, the
/// validators and the password hashes. Runs in MINIT, before the `#[php_class]` classes are registered.
fn startup(_kind: i32, _module: i32) -> i32 {
    ext_php_rs::internal::startup_guard(|| {
        locale::register()?;
        user_agent::register()?;
        // The leaves extend the base, so it is registered first.
        validators::register()?;
        validators::scalar::register()?;
        validators::text::register()?;
        validators::network::register()?;
        validators::compose::register()?;
        auth::register()?;
        Ok(())
    })
}

/// Forgets what the classes hold for the request when it ends, as PHP frees its static state.
extern "C" fn request_shutdown(kind: i32, module: i32) -> i32 {
    user_agent::request_shutdown();
    locale::request_shutdown(kind, module)
}
