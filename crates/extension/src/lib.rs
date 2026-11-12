//! The `utopia` PHP extension: Utopia crates registered as PHP classes. The
//! value objects and enums those classes take live in the Composer packages
//! (`packages/<name>`), as plain PHP.
//!
//! So far: `Utopia\Image\Canvas` and `Utopia\Image\Font`.

mod canvas;

use ext_php_rs::prelude::*;

/// The module PHP loads: every class of the extension.
#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module.name("utopia").class::<canvas::Canvas>().class::<canvas::Font>()
}
