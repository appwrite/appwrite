use serde_json::Value;
use std::fmt::Debug;

/// Typed matching semantics for a specific attribute (e.g. IP addresses).
///
/// Registered on the [`crate::Firewall`] and consulted by conditions before
/// falling back to the default comparison logic.
///
/// Tri-state contract for [`Attribute::compare`]:
/// - `Some(true)` - handled, the value matches
/// - `Some(false)` - handled, the value definitively does not match (default
///   comparison is skipped)
/// - `None` - not handled, fall back to the default comparison semantics
///
/// Probed for every non-logical operator except `isNull` / `isNotNull`. Negated
/// operators probe their positive counterpart (`notEqual` as `equal`,
/// `notContains` as `contains`, …) with the negation applied by the engine, so
/// a type only implements the positive semantics. Any-of operators (`equal`,
/// `contains`) probe once per expected value; `startsWith` / `endsWith` and
/// relational operators probe with their single reference value;
/// `between` / `notBetween` probe once with the full `[start, end]` pair as
/// `$expected`.
pub trait Attribute: Send + Sync + Debug {
    /// Attempt a typed comparison of an attribute value against an expected value.
    fn compare(&self, method: &str, value: &Value, expected: &Value) -> Option<bool>;

    /// Validate a single expected value for a given operator at rule-creation time.
    ///
    /// Returns an error message, or `None` when the value is valid.
    fn validate_value(&self, method: &str, expected: &Value) -> Option<String>;
}

impl<T: Attribute + ?Sized> Attribute for Box<T> {
    fn compare(&self, method: &str, value: &Value, expected: &Value) -> Option<bool> {
        (**self).compare(method, value, expected)
    }

    fn validate_value(&self, method: &str, expected: &Value) -> Option<String> {
        (**self).validate_value(method, expected)
    }
}

impl<T: Attribute + ?Sized> Attribute for std::sync::Arc<T> {
    fn compare(&self, method: &str, value: &Value, expected: &Value) -> Option<bool> {
        (**self).compare(method, value, expected)
    }

    fn validate_value(&self, method: &str, expected: &Value) -> Option<String> {
        (**self).validate_value(method, expected)
    }
}
