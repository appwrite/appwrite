//! PHP `Utopia\Database\Change`.

use crate::document::Document;

/// PHP `Utopia\Database\Change`.
#[derive(Debug, Clone, PartialEq)]
pub struct Change {
    old: Document,
    new: Document,
}

impl Change {
    #[must_use]
    pub fn new(old: Document, new: Document) -> Self {
        Self { old, new }
    }

    #[must_use]
    pub fn get_old(&self) -> &Document {
        &self.old
    }

    pub fn set_old(&mut self, old: Document) {
        self.old = old;
    }

    #[must_use]
    pub fn get_new(&self) -> &Document {
        &self.new
    }

    pub fn set_new(&mut self, new: Document) {
        self.new = new;
    }
}
