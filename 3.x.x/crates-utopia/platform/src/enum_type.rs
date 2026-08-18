use std::collections::HashMap;

/// Metadata for whitelist-backed parameters (generated enum registry).
///
/// Rust port of [`Utopia\Platform\Enum`](https://github.com/utopia-php/platform).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Enum {
    /// Generated enum name.
    pub name: Option<String>,
    /// Mapping of whitelist values to generated enum case names.
    pub map: Option<HashMap<String, String>>,
    /// Whitelist values to omit from generated enums.
    pub exclude: Option<Vec<String>>,
}

impl Enum {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn with_name(mut self, name: impl Into<String>) -> Self {
        self.name = Some(name.into());
        self
    }

    pub fn with_map(mut self, map: HashMap<String, String>) -> Self {
        self.map = Some(map);
        self
    }

    pub fn with_exclude(mut self, exclude: Vec<String>) -> Self {
        self.exclude = Some(exclude);
        self
    }
}
