//! Validators for request parameters.
//!
//! Values are `serde_json::Value`s produced by `utopia-http` from the JSON body
//! or the (PHP bracket-style) query string. The validators reproduce the
//! acceptance rules *and* the human-readable descriptions of the PHP
//! `Utopia\Validator\*` classes, because the descriptions are part of the API
//! contract (they end up in `Invalid `x` param: <description>` errors).
//!
//! Validators are plain structs; there is no allocation on the success path.

/// PHP value semantics, re-exported from `php-std` for existing callers.
pub use php_std::value as php;

mod hostname;
mod list;
mod scalar;

pub use hostname::Hostname;
pub use list::{AllOf, ArrayList, Assoc, Nullable, WhiteList};
pub use scalar::{Boolean, Integer, Numeric, Range, Text};

pub use serde_json::Value;

/// A parameter validator.
pub trait Validator: Send + Sync {
    /// Human-readable description, used verbatim in error messages.
    fn description(&self) -> String;

    /// Whether `value` is acceptable.
    fn is_valid(&self, value: &Value) -> bool;

    /// Validates `value`; on failure returns the description of the failing rule.
    ///
    /// Composite validators override this to report the inner rule that failed.
    fn check(&self, value: &Value) -> Result<(), String> {
        if self.is_valid(value) { Ok(()) } else { Err(self.description()) }
    }

    /// Whether the validator expects an array value.
    fn is_array(&self) -> bool {
        false
    }

    /// Whether `null` is an acceptable value (used for "null means omitted" handling).
    fn accepts_null(&self) -> bool {
        self.is_valid(&Value::Null)
    }
}

impl<V: Validator + ?Sized> Validator for &V {
    fn description(&self) -> String {
        (**self).description()
    }
    fn is_valid(&self, value: &Value) -> bool {
        (**self).is_valid(value)
    }
    fn check(&self, value: &Value) -> Result<(), String> {
        (**self).check(value)
    }
    fn is_array(&self) -> bool {
        (**self).is_array()
    }
}

impl<V: Validator + ?Sized> Validator for Box<V> {
    fn description(&self) -> String {
        (**self).description()
    }
    fn is_valid(&self, value: &Value) -> bool {
        (**self).is_valid(value)
    }
    fn check(&self, value: &Value) -> Result<(), String> {
        (**self).check(value)
    }
    fn is_array(&self) -> bool {
        (**self).is_array()
    }
}

/// PHP `number_format($n)`: thousands separated by commas, no decimals.
pub fn number_format(n: i128) -> String {
    let negative = n < 0;
    let digits = n.unsigned_abs().to_string();
    let mut out = String::with_capacity(digits.len() + digits.len() / 3 + 1);
    if negative {
        out.push('-');
    }
    let first = digits.len() % 3;
    for (i, ch) in digits.chars().enumerate() {
        if i != 0 && (i + 3 - first).is_multiple_of(3) {
            out.push(',');
        }
        out.push(ch);
    }
    out
}

#[cfg(test)]
mod tests {
    use super::number_format;

    #[test]
    fn formats_numbers_like_php() {
        assert_eq!(number_format(0), "0");
        assert_eq!(number_format(128), "128");
        assert_eq!(number_format(3600), "3,600");
        assert_eq!(number_format(31536000), "31,536,000");
        assert_eq!(number_format(-2147483648), "-2,147,483,648");
        assert_eq!(number_format(9223372036854775807), "9,223,372,036,854,775,807");
    }
}
