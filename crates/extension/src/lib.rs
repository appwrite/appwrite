//! The `utopia` PHP extension: Utopia crates registered as PHP classes. The
//! value objects and enums those classes take live in the Composer packages
//! (`packages/<name>`), as plain PHP.
//!
//! Every class is registered unconditionally: `Utopia\Image\Canvas`,
//! `Utopia\Image\Font`, `Utopia\DSN\DSN`, `Utopia\Locale\Locale`,
//! `Utopia\UserAgent\UserAgent`, `Utopia\Domains\Domain` and
//! `Utopia\Emails\Email` have no PHP implementation any more.

mod canvas;
mod domains;
mod dsn;
mod emails;
mod locale;
mod string;
mod user_agent;

use ext_php_rs::prelude::*;

/// The module PHP loads: every class of the extension.
#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .name("utopia")
        .class::<canvas::Canvas>()
        .class::<canvas::Font>()
        .class::<dsn::Dsn>()
        .class::<locale::Locale>()
        .class::<user_agent::UserAgent>()
        .class::<domains::Domain>()
        .class::<emails::Email>()
        .request_shutdown_function(request_shutdown)
}

/// Forgets what the classes hold for the request when it ends, as PHP frees its static state.
extern "C" fn request_shutdown(kind: i32, module: i32) -> i32 {
    user_agent::request_shutdown();
    locale::request_shutdown(kind, module)
}
