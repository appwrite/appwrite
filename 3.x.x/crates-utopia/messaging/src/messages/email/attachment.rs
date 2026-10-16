//! PHP `Utopia\Messaging\Messages\Email\Attachment`.

/// PHP `Utopia\Messaging\Messages\Email\Attachment`.
#[derive(Debug, Clone)]
pub struct Attachment {
    name: String,
    path: String,
    type_name: String,
    content: Option<Vec<u8>>,
}

impl Attachment {
    /// PHP `__construct($name, $path, $type, $content = null)`.
    ///
    /// `$content` is a PHP string; here it is raw bytes.
    #[must_use]
    pub fn new(
        name: impl Into<String>,
        path: impl Into<String>,
        type_name: impl Into<String>,
        content: Option<Vec<u8>>,
    ) -> Self {
        Self {
            name: name.into(),
            path: path.into(),
            type_name: type_name.into(),
            content,
        }
    }

    /// PHP `getName`.
    #[must_use]
    pub fn get_name(&self) -> &str {
        &self.name
    }

    /// PHP `getPath`.
    #[must_use]
    pub fn get_path(&self) -> &str {
        &self.path
    }

    /// PHP `getType`.
    #[must_use]
    pub fn get_type(&self) -> &str {
        &self.type_name
    }

    /// PHP `getContent` (raw bytes).
    #[must_use]
    pub fn get_content(&self) -> Option<&[u8]> {
        self.content.as_deref()
    }
}
