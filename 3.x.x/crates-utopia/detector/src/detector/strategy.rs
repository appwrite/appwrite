use crate::error::DetectorError;

/// PHP `Utopia\Detector\Detector\Strategy`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Strategy {
    value: String,
}

impl Strategy {
    /// PHP `Strategy::FILEMATCH`.
    pub const FILEMATCH: &'static str = "filematch";
    /// PHP `Strategy::EXTENSION`.
    pub const EXTENSION: &'static str = "extension";
    /// PHP `Strategy::LANGUAGES`.
    pub const LANGUAGES: &'static str = "languages";

    /// PHP `__construct(string $value)`.
    pub fn new(value: impl Into<String>) -> Result<Self, DetectorError> {
        let value = value.into();
        if !matches!(
            value.as_str(),
            Self::FILEMATCH | Self::EXTENSION | Self::LANGUAGES
        ) {
            return Err(DetectorError::InvalidStrategy(value));
        }
        Ok(Self { value })
    }

    /// PHP `getValue()`.
    #[must_use]
    pub fn get_value(&self) -> &str {
        &self.value
    }
}
