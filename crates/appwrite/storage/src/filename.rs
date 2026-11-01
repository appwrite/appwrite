//! `Appwrite\Utopia\Database\Validator\Filename`.

use serde_json::Value;
use utopia_validators::{Text, Type, Validator};

/// A file name: [`Text`] limits, and no control characters.
#[derive(Debug, Clone)]
pub struct Filename(pub Text);

impl Validator for Filename {
    fn description(&self) -> String {
        format!("{} and must not contain control characters", self.0.description())
    }

    fn is_valid(&self, value: &Value) -> bool {
        self.0.is_valid(value) && value.as_str().is_some_and(|name| !name.bytes().any(|b| b < 0x20 || b == 0x7f))
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
    fn refuses_control_characters() {
        let name = Filename(Text::new(128));
        assert!(name.is_valid(&json!("page.html")));
        assert!(!name.is_valid(&json!("page\n.html")));
        assert!(!name.is_valid(&json!("page\u{7f}.html")));
        assert!(!name.is_valid(&json!("")));
        assert_eq!(
            name.description(),
            "Value must be a valid string and at least 1 chars and no longer than 128 chars and must not contain control characters"
        );
        let upload = Filename(Text::with_min(0, 0));
        assert!(upload.is_valid(&json!("")));
        assert!(!upload.is_valid(&json!("a\tb")));
        assert_eq!(
            upload.description(),
            "Value must be a valid string and exactly 0 chars and must not contain control characters"
        );
    }
}
