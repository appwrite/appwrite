//! Locale and translation helpers for Utopia.
//!
//! Rust port of [`utopia-php/locale`](https://github.com/utopia-php/locale).

mod error;
mod locale;
mod placeholder;
mod translation;

pub use error::LocaleError;
pub use locale::{Locale, EXCEPTIONS};
pub use placeholder::Placeholder;
pub use translation::IntoTranslation;
