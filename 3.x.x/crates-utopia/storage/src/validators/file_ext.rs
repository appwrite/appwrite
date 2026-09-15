pub const JPEG: &str = "jpeg";
pub const JPG: &str = "jpg";
pub const GIF: &str = "gif";
pub const PNG: &str = "png";
pub const GZIP: &str = "gz";
pub const ZIP: &str = "zip";

/// Validates that a filename has an allowed extension.
#[derive(Debug, Clone)]
pub struct FileExt {
    allowed: Vec<String>,
}

impl FileExt {
    pub const DESCRIPTION: &'static str = "File extension is not valid";

    pub fn new(allowed: impl IntoIterator<Item = impl Into<String>>) -> Self {
        Self {
            allowed: allowed
                .into_iter()
                .map(|value| value.into().to_ascii_lowercase())
                .collect(),
        }
    }

    pub fn description(&self) -> &'static str {
        Self::DESCRIPTION
    }

    pub fn is_valid(&self, filename: &str) -> bool {
        let extension = std::path::Path::new(filename)
            .extension()
            .and_then(|value| value.to_str())
            .unwrap_or("")
            .to_ascii_lowercase();
        self.allowed.iter().any(|allowed| allowed == &extension)
    }
}

#[cfg(feature = "validators")]
impl utopia_validators::Validator for FileExt {
    fn description(&self) -> String {
        Self::DESCRIPTION.to_string()
    }

    fn value_type(&self) -> utopia_validators::ValueType {
        utopia_validators::ValueType::String
    }

    fn is_valid(&self, value: &serde_json::Value) -> bool {
        value
            .as_str()
            .is_some_and(|filename| self.is_valid(filename))
    }
}
