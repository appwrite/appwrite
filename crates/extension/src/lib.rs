//! The `utopia` PHP extension: Utopia crates registered as the PHP classes of
//! their Composer packages (`packages/<name>`). A class registered here
//! exists before any autoloader runs, so Composer never loads the PHP one and
//! PHP code runs the Rust implementation unchanged.
//!
//! `Utopia\Image\Image` is always registered: its package has no PHP
//! implementation left. Every other library still has one, and its class is
//! registered only when `_APP_EXTENSION_LIBRARIES` names its package (`dsn`),
//! so each can be canaried and switched back to PHP with a restart, not a
//! deploy.

mod dsn;
mod image;

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
    let module = module.name("utopia").class::<image::Image>();
    if enabled("dsn") { module.class::<dsn::Dsn>() } else { module }
}
