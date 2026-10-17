//! [`Finite`]: a float or integer that is neither infinite nor NaN.
//!
//! PHP: `Utopia\Database\Validator\Finite` (8.3.4).

use crate::validator::is_numeric;
use crate::value::Value;

/// A float or an integer (when loose, also a numeric string) that is neither
/// infinite nor NaN, which no engine stores or compares as a double. Filter
/// values of a float attribute and having values are held to it.
///
/// ```
/// use utopia_database::validator::finite::Finite;
/// use utopia_database::value::{string, Value};
///
/// let finite = Finite::default();
/// assert!(finite.is_valid(&Value::Float(1.5)));
/// assert!(finite.is_valid(&Value::Int(3)));
/// assert!(!finite.is_valid(&Value::Float(f64::INFINITY)));
/// assert!(!finite.is_valid(&string("1.5")));
/// assert!(Finite::new(true).is_valid(&string("1.5")));
/// assert_eq!(finite.description(), "Value must be a finite float");
/// ```
///
/// PHP: `Utopia\Database\Validator\Finite` (`new Finite($loose)`).
#[derive(Clone, Copy, Debug, Default)]
pub struct Finite {
    loose: bool,
}

impl Finite {
    /// `new Finite($loose)`: when loose, a numeric string is a number too.
    pub fn new(loose: bool) -> Self {
        Self { loose }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        "Value must be a finite float".to_owned()
    }

    /// `isValid($value)`: FloatValidator's check, then `is_numeric($value)
    /// && is_finite((float) $value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        let number = match value {
            Value::Int(_) | Value::Float(_) => true,
            Value::Str(_) => self.loose && is_numeric(value),
            _ => false,
        };
        number && is_numeric(value) && value.to_double().is_finite()
    }

    /// `getType()`: `double`.
    pub fn type_name(&self) -> &'static str {
        "double"
    }
}

/// [`Finite::is_valid`] (not loose) of a query value: an integer, or a
/// finite float.
pub(crate) fn finite_query_value(value: &utopia_query::Value) -> bool {
    match value {
        utopia_query::Value::Int(_) => true,
        utopia_query::Value::Float(f) => f.is_finite(),
        _ => false,
    }
}

super::validator!(Finite, utopia_validators::Type::Float, false);

#[cfg(test)]
mod tests {
    use super::*;
    use crate::value::string;

    /// PHP 8.3.6 Validator/Finite.php:19-26: FloatValidator's check, then
    /// is_numeric() and is_finite((float)) of the value.
    #[test]
    fn refuses_infinite_and_nan_doubles() {
        let finite = Finite::default();
        assert!(finite.is_valid(&Value::Float(-1.7976931348623157e308)));
        assert!(!finite.is_valid(&Value::Float(f64::NEG_INFINITY)));
        assert!(!finite.is_valid(&Value::Float(f64::NAN)));
        assert!(!finite.is_valid(&Value::Bool(true)));
        assert!(!finite.is_valid(&Value::Null));
    }

    /// Finite.php:21: a loose FloatValidator takes a numeric string, which
    /// is_finite() then reads as the double it spells.
    #[test]
    fn a_loose_one_reads_numeric_strings() {
        let loose = Finite::new(true);
        assert!(loose.is_valid(&string(" 2.5")));
        assert!(!loose.is_valid(&string("1e999")));
        assert!(!loose.is_valid(&string("abc")));
    }

    /// Finite.php:12-15.
    #[test]
    fn describes_a_finite_float() {
        assert_eq!(Finite::default().description(), "Value must be a finite float");
        assert_eq!(utopia_validators::Validator::kind(&Finite::default()), utopia_validators::Type::Float);
    }
}
