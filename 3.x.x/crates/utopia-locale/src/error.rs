use thiserror::Error;

/// Errors raised by [`crate::Locale`] when [`crate::Locale::exceptions`] is `true`.
///
/// Messages match `utopia-php/locale` `Exception` strings.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum LocaleError {
    /// PHP `Exception('Translation file not found.')`.
    #[error("Translation file not found.")]
    TranslationFileNotFound,

    /// PHP `Exception('Locale not found')`.
    #[error("Locale not found")]
    LocaleNotFound,

    /// PHP `Exception('Key named "{key}" not found')`.
    #[error("Key named \"{key}\" not found")]
    KeyNotFound {
        /// Translation key that was requested.
        key: String,
    },
}
