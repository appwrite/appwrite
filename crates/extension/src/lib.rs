//! The `utopia` PHP extension: Utopia crates registered as PHP classes. The
//! value objects and enums those classes take live in the Composer packages
//! (`packages/<name>`), as plain PHP.
//!
//! `Utopia\Image\Canvas`, `Utopia\Image\Font`, `Utopia\DSN\DSN`,
//! `Utopia\Locale\Locale` and `Utopia\Domains\Domain` are always registered:
//! they have no PHP implementation. Every other library still has one, and its
//! class is registered only when `_APP_EXTENSION_LIBRARIES` names its package
//! (`user-agent`, `emails`), so each can be canaried and switched back to PHP
//! with a restart, not a deploy.

mod canvas;
mod domains;
mod dsn;
mod emails;
mod locale;
mod string;
mod user_agent;

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
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    let module = module
        .name("utopia")
        .class::<canvas::Canvas>()
        .class::<canvas::Font>()
        .class::<dsn::Dsn>()
        .class::<locale::Locale>()
        .class::<domains::Domain>();
    let module = if enabled("emails") { module.class::<emails::Email>() } else { module };
    let module = if enabled("user-agent") { module.class::<user_agent::UserAgent>() } else { module };
    module.request_shutdown_function(request_shutdown)
}

/// Forgets what the classes hold for the request when it ends, as PHP frees its static state.
extern "C" fn request_shutdown(kind: i32, module: i32) -> i32 {
    user_agent::request_shutdown();
    locale::request_shutdown(kind, module)
}
