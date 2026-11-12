//! The `utopia` PHP extension: Utopia crates registered as the PHP classes of
//! their Composer packages (`packages/<name>`). A class registered here
//! exists before any autoloader runs, so Composer never loads the PHP one and
//! PHP code runs the Rust implementation unchanged.
//!
//! So far: `Utopia\Image\Image` (`packages/image/src/Image.php`).

mod image;

use ext_php_rs::prelude::*;

/// The module PHP loads: every class of the extension.
#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module.name("utopia").class::<image::Image>()
}
