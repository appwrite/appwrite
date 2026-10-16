/// Converts a PHP translation value (`string` or `null`) into `Option<String>`.
///
/// Used by [`crate::Locale::set_language_from_array`].
pub trait IntoTranslation {
    /// `Some` for a string translation, `None` for PHP `null`.
    fn into_translation(self) -> Option<String>;
}

impl IntoTranslation for &str {
    fn into_translation(self) -> Option<String> {
        Some(self.to_owned())
    }
}

impl IntoTranslation for String {
    fn into_translation(self) -> Option<String> {
        Some(self)
    }
}

impl IntoTranslation for &String {
    fn into_translation(self) -> Option<String> {
        Some(self.clone())
    }
}

impl IntoTranslation for Option<String> {
    fn into_translation(self) -> Option<String> {
        self
    }
}

impl IntoTranslation for Option<&str> {
    fn into_translation(self) -> Option<String> {
        self.map(str::to_owned)
    }
}
