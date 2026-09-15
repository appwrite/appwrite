/// Validates that a filename contains only alphanumeric characters, `.`, `-`, and `_`.
#[derive(Debug, Clone, Copy, Default)]
pub struct FileName;

impl FileName {
    pub const DESCRIPTION: &'static str = "Filename is not valid";

    pub fn is_valid(name: &str) -> bool {
        if name.is_empty() {
            return false;
        }
        name.chars()
            .all(|ch| ch.is_ascii_alphanumeric() || matches!(ch, '.' | '-' | '_'))
    }

    pub fn description(&self) -> &'static str {
        Self::DESCRIPTION
    }
}

#[cfg(feature = "validators")]
impl utopia_validators::Validator for FileName {
    fn description(&self) -> String {
        Self::DESCRIPTION.to_string()
    }

    fn value_type(&self) -> utopia_validators::ValueType {
        utopia_validators::ValueType::String
    }

    fn is_valid(&self, value: &serde_json::Value) -> bool {
        value.as_str().is_some_and(Self::is_valid)
    }
}
