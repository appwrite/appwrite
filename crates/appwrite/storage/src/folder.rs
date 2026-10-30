//! `Appwrite\Utopia\Database\Validator\Folder`.

use serde_json::Value;
use utopia_validators::{Type, Validator};

/// The longest stored folder, including the trailing slash.
pub const MAX_LENGTH: usize = 2048;

/// A virtual folder: `/`-separated segments, no empty, `.` or `..` segment,
/// no leading slash, no control characters.
#[derive(Debug, Clone, Copy, Default)]
pub struct Folder;

impl Folder {
    /// Canonical stored form: a trailing slash when the folder is not empty.
    pub fn normalize(value: &str) -> String {
        if value.is_empty() {
            return String::new();
        }
        if value.ends_with('/') { value.to_owned() } else { format!("{value}/") }
    }
}

impl Validator for Folder {
    fn description(&self) -> String {
        format!(
            "Folder must be `/`-separated segments without empty, `.`, or `..` segments, must not start with `/`, must not contain control characters, and must be at most {MAX_LENGTH} characters long including the trailing slash."
        )
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(value) = value.as_str() else { return false };
        if value.is_empty() {
            return true;
        }
        if Folder::normalize(value).len() > MAX_LENGTH {
            return false;
        }
        if value.bytes().any(|b| b < 0x20 || b == 0x7f) {
            return false;
        }
        let trimmed = value.strip_suffix('/').unwrap_or(value);
        if trimmed.is_empty() {
            return false;
        }
        trimmed.split('/').all(|segment| !segment.is_empty() && segment != "." && segment != "..")
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn accepts_a_nested_folder_and_stores_a_trailing_slash() {
        assert!(Folder.is_valid(&json!("photos/2026")));
        assert_eq!(Folder::normalize("photos/2026"), "photos/2026/");
        assert_eq!(Folder::normalize(""), "");
        assert!(!Folder.is_valid(&json!("/photos")));
        assert!(!Folder.is_valid(&json!("photos/../x")));
        assert!(!Folder.is_valid(&json!("photos//x")));
    }
}
