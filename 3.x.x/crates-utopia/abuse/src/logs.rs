use serde_json::Value;

use crate::database::Document;

/// PHP `getLogs()` return value: a Redis-style map or a list of documents.
#[derive(Debug, Clone, PartialEq)]
pub enum Logs {
    /// Associative array (`array<string, mixed>`), including empty `[]`.
    Map(Vec<(String, Value)>),
    /// Database / `TablesDB` document list.
    Documents(Vec<Document>),
}

impl Logs {
    /// Empty PHP `[]`.
    #[must_use]
    pub fn empty() -> Self {
        Self::Map(Vec::new())
    }

    /// Whether the log set has no entries.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        match self {
            Self::Map(entries) => entries.is_empty(),
            Self::Documents(docs) => docs.is_empty(),
        }
    }

    /// Number of entries.
    #[must_use]
    pub fn len(&self) -> usize {
        match self {
            Self::Map(entries) => entries.len(),
            Self::Documents(docs) => docs.len(),
        }
    }

    /// Lookup a Redis-style key.
    #[must_use]
    pub fn get(&self, key: &str) -> Option<&Value> {
        match self {
            Self::Map(entries) => entries
                .iter()
                .find(|(item, _)| item == key)
                .map(|(_, value)| value),
            Self::Documents(_) => None,
        }
    }

    /// Redis-style pairs.
    #[must_use]
    pub fn as_map(&self) -> Option<&[(String, Value)]> {
        match self {
            Self::Map(entries) => Some(entries.as_slice()),
            Self::Documents(_) => None,
        }
    }

    /// Document list.
    #[must_use]
    pub fn as_documents(&self) -> Option<&[Document]> {
        match self {
            Self::Documents(docs) => Some(docs.as_slice()),
            Self::Map(_) => None,
        }
    }
}

impl Default for Logs {
    fn default() -> Self {
        Self::empty()
    }
}
