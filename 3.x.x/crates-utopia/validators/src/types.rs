/// Validator type tags matching utopia-php/validators.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ValueType {
    Boolean,
    Integer,
    Float,
    String,
    Array,
    Object,
    Mixed,
}

impl ValueType {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Boolean => "boolean",
            Self::Integer => "integer",
            Self::Float => "double",
            Self::String => "string",
            Self::Array => "array",
            Self::Object => "object",
            Self::Mixed => "mixed",
        }
    }
}
