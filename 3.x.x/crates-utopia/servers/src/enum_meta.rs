use std::collections::HashMap;

/// Whitelist-backed parameter enum metadata for `OpenAPI` and codegen registries.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct EnumMeta {
    pub name: Option<String>,
    pub map: Option<HashMap<String, String>>,
    pub exclude: Option<Vec<String>>,
}

impl EnumMeta {
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
