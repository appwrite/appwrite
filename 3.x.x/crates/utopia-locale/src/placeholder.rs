/// A `get_text` placeholder value (PHP `string|int`, always stringified).
///
/// PHP `(string) $placeholderValue` is applied before `str_replace`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Placeholder(String);

impl Placeholder {
    /// Builds a placeholder from any [`ToString`] value.
    pub fn new(value: impl ToString) -> Self {
        Self(value.to_string())
    }

    /// String form used in `{{key}}` substitution.
    pub fn as_str(&self) -> &str {
        &self.0
    }
}

impl From<&str> for Placeholder {
    fn from(value: &str) -> Self {
        Self(value.to_owned())
    }
}

impl From<String> for Placeholder {
    fn from(value: String) -> Self {
        Self(value)
    }
}

impl From<&String> for Placeholder {
    fn from(value: &String) -> Self {
        Self(value.clone())
    }
}

macro_rules! from_int {
    ($($t:ty),* $(,)?) => {
        $(
            impl From<$t> for Placeholder {
                fn from(value: $t) -> Self {
                    Self(value.to_string())
                }
            }
        )*
    };
}

from_int!(i8, i16, i32, i64, i128, isize, u8, u16, u32, u64, u128, usize);
