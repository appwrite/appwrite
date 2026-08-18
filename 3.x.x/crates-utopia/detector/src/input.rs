/// Typed detector input (PHP `$inputs[] = ['type' => $type, 'content' => $content]`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Input {
    /// Input kind (`file`, `packages`, or empty for detectors that ignore type).
    pub type_: String,
    /// File name, language name, or package.json body.
    pub content: String,
}

impl Input {
    /// Create a typed input.
    #[must_use]
    pub fn new(content: impl Into<String>, type_: impl Into<String>) -> Self {
        Self {
            type_: type_.into(),
            content: content.into(),
        }
    }
}
