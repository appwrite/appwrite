//! Classes built by hand with [`ClassBuilder`](ext_php_rs::builders::ClassBuilder) rather than
//! `#[php_class]`: a class with no Rust object behind it and no constructor unless PHP's has
//! one, whose state lives in typed PHP properties, so PHP subclasses extend it as they would
//! a class declared in PHP. [`engine`] reaches the parts of the engine ext-php-rs does not
//! wrap; [`leaf`] builds and registers a class and its methods. `Locale`, `UserAgent`, the
//! validators and the auth hashes are built this way.

pub mod engine;
pub mod leaf;
pub mod retarget;
