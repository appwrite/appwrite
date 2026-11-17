//! The `utopia` PHP extension: Utopia crates registered as PHP classes. The
//! value objects and enums those classes take live in the Composer packages
//! (`packages/<name>`), as plain PHP.
//!
//! `Utopia\Image\Canvas`, `Utopia\Image\Font`, `Utopia\DSN\DSN`,
//! `Utopia\Locale\Locale`, `Utopia\UserAgent\UserAgent` and `Utopia\Domains\Domain` are
//! always registered: they have no PHP implementation. Every other library still
//! has one, and its class is registered only when `_APP_EXTENSION_LIBRARIES`
//! names its package (`emails`, `validators`), so each can be canaried and
//! switched back to PHP with a restart, not a deploy.

mod canvas;
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

/// Whether `_APP_EXTENSION_LIBRARIES` names `package`.
fn enabled(package: &str) -> bool {
    std::env::var(LIBRARIES).is_ok_and(|list| list.split(',').any(|name| name.trim() == package))
}

/// The module PHP loads: every class of the extension that is switched on.
#[php_module]
#[php(startup = startup)]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    let module = module
        .name("utopia")
        .class::<canvas::Canvas>()
        .class::<canvas::Font>()
        .class::<dsn::Dsn>()
        .class::<locale::Locale>()
        .class::<user_agent::UserAgent>()
        .class::<domains::Domain>();
    let module = if enabled("emails") { module.class::<emails::Email>() } else { module };
    module.request_shutdown_function(request_shutdown)
}

/// Registers the classes built by hand rather than with `#[php_class]` that are switched on.
/// Runs in MINIT, before the `#[php_class]` classes are registered.
fn startup(_kind: i32, _module: i32) -> i32 {
    if !enabled("validators") {
        return 0;
    }
    ext_php_rs::internal::startup_guard(validators::register)
}

/// Forgets what the classes hold for the request when it ends, as PHP frees its static state.
extern "C" fn request_shutdown(kind: i32, module: i32) -> i32 {
    user_agent::request_shutdown();
    locale::request_shutdown(kind, module)
}
