use serde_json::{Map, Value};
use std::collections::HashMap;
use std::env;
use std::fs;
use std::path::{Path, PathBuf};

/// Raw contents returned by a [`Source`].
#[derive(Debug, Clone, PartialEq)]
pub enum SourceContent {
    /// Text read from a file or in-memory string.
    Text(String),
    /// Pre-parsed key/value map (e.g. environment variables or in-memory data).
    Map(Map<String, Value>),
}

/// Loads configuration contents from a backing store.
pub trait Source {
    /// Returns the raw contents, or `None` when unavailable (e.g. missing file).
    fn contents(&self) -> Option<SourceContent>;
}

/// Reads configuration from a file path.
#[derive(Debug, Clone)]
pub struct FileSource {
    path: PathBuf,
}

impl FileSource {
    pub fn new(path: impl Into<PathBuf>) -> Self {
        Self { path: path.into() }
    }

    pub fn path(&self) -> &Path {
        &self.path
    }
}

impl Source for FileSource {
    fn contents(&self) -> Option<SourceContent> {
        if !self.path.exists() {
            return None;
        }
        fs::read_to_string(&self.path).ok().map(SourceContent::Text)
    }
}

/// Reads environment variables, optionally filtered by prefix.
#[derive(Debug, Clone, Default)]
pub struct EnvironmentSource {
    prefix: Option<String>,
}

impl EnvironmentSource {
    pub fn new() -> Self {
        Self::default()
    }

    /// Only include variables whose names start with `prefix`.
    pub fn with_prefix(prefix: impl Into<String>) -> Self {
        Self {
            prefix: Some(prefix.into()),
        }
    }
}

impl Source for EnvironmentSource {
    fn contents(&self) -> Option<SourceContent> {
        let mut map = Map::new();
        for (key, value) in env::vars() {
            if let Some(prefix) = &self.prefix {
                if !key.starts_with(prefix) {
                    continue;
                }
            }
            map.insert(key, Value::String(value));
        }
        Some(SourceContent::Map(map))
    }
}

/// In-memory configuration source (string or pre-parsed map).
#[derive(Debug, Clone)]
pub struct VariableSource {
    content: SourceContent,
}

impl VariableSource {
    pub fn from_text(contents: impl Into<String>) -> Self {
        Self {
            content: SourceContent::Text(contents.into()),
        }
    }

    pub fn from_map(map: impl IntoIterator<Item = (String, Value)>) -> Self {
        Self {
            content: SourceContent::Map(map.into_iter().collect()),
        }
    }

    pub fn from_hash_map(map: HashMap<String, Value>) -> Self {
        Self {
            content: SourceContent::Map(map.into_iter().collect()),
        }
    }
}

impl Source for VariableSource {
    fn contents(&self) -> Option<SourceContent> {
        Some(self.content.clone())
    }
}
