use serde::Serialize;

/// Detected operating system metadata.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct OperatingSystem {
    pub code: Option<String>,
    pub name: Option<String>,
    pub version: Option<String>,
}

impl OperatingSystem {
    /// Empty / unknown operating system.
    pub fn new() -> Self {
        Self {
            code: None,
            name: None,
            version: None,
        }
    }

    /// Known operating system with optional version.
    pub fn known(code: &str, name: &str, version: Option<String>) -> Self {
        Self {
            code: Some(code.to_string()),
            name: Some(name.to_string()),
            version,
        }
    }

    /// Whether a known OS name was detected.
    pub fn is_known(&self) -> bool {
        self.name.is_some()
    }

    /// Serialize to a flat map (PHP `toArray` shape, `snake_case` keys).
    pub fn to_array(&self) -> OperatingSystemArray {
        OperatingSystemArray {
            code: self.code.clone(),
            name: self.name.clone(),
            version: self.version.clone(),
        }
    }
}

impl Default for OperatingSystem {
    fn default() -> Self {
        Self::new()
    }
}

/// `OperatingSystem::to_array()` output.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct OperatingSystemArray {
    pub code: Option<String>,
    pub name: Option<String>,
    pub version: Option<String>,
}
