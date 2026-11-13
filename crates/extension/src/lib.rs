//! The `utopia` PHP extension: Utopia crates registered as PHP classes. The
//! value objects and enums those classes take live in the Composer packages
//! (`packages/<name>`), as plain PHP.
//!
//! `Utopia\Image\Canvas` and `Utopia\Image\Font` are always registered: their
//! package has no PHP implementation. Every other library still has one, and
//! its class is registered only when `_APP_EXTENSION_LIBRARIES` names its
//! package (`dsn`), so each can be canaried and switched back to PHP with a
//! restart, not a deploy.

mod canvas;
mod dsn;

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
    let module = module.name("utopia").class::<canvas::Canvas>().class::<canvas::Font>();
    if enabled("dsn") { module.class::<dsn::Dsn>() } else { module }
}
