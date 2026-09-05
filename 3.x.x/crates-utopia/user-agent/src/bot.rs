use serde::Serialize;

/// Detected bot / crawler metadata.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Bot {
    pub name: String,
    pub category: String,
}

impl Bot {
    /// Create a bot entry with the given name and category.
    pub fn new(name: &str, category: &str) -> Self {
        Self {
            name: name.to_string(),
            category: category.to_string(),
        }
    }

    /// Serialize to a flat map (PHP `toArray` shape).
    pub fn to_array(&self) -> BotArray {
        BotArray {
            name: self.name.clone(),
            category: self.category.clone(),
        }
    }
}

/// `Bot::to_array()` output.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct BotArray {
    pub name: String,
    pub category: String,
}
