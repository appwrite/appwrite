use serde_json::Value;

use crate::Validator;
use crate::php;

/// `Utopia\Validator\WhiteList`.
#[derive(Debug, Clone)]
pub struct WhiteList {
    list: Vec<String>,
    strict: bool,
}

impl WhiteList {
    /// Non-strict (case-insensitive) white list.
    pub fn new(list: &[&str]) -> Self {
        Self { list: list.iter().map(|s| s.to_lowercase()).collect(), strict: false }
    }

    pub fn strict(list: &[&str]) -> Self {
        Self { list: list.iter().map(|s| (*s).to_owned()).collect(), strict: true }
    }
}

impl Validator for WhiteList {
    fn description(&self) -> String {
        format!("Value must be one of ({})", self.list.join(", "))
    }

    fn is_valid(&self, value: &Value) -> bool {
        if php::is_array(value) {
            return false;
        }
        if self.strict {
            return match value {
                Value::String(s) => self.list.iter().any(|l| l == s),
                _ => false,
            };
        }
        let Some(s) = php::to_string(value) else {
            return false;
        };
        let s = s.to_lowercase();
        self.list.contains(&s)
    }
}

/// `Utopia\Validator\ArrayList`.
pub struct ArrayList<V> {
    pub inner: V,
    pub length: usize,
}

impl<V: Validator> ArrayList<V> {
    pub fn new(inner: V, length: usize) -> Self {
        Self { inner, length }
    }
}

impl<V: Validator> Validator for ArrayList<V> {
    fn description(&self) -> String {
        let mut msg = String::from("Value must a valid array");
        if self.length > 0 {
            msg.push_str(&format!(" no longer than {} items", self.length));
        }
        let inner = self.inner.description();
        if !inner.is_empty() && inner != "0" {
            msg.push_str(" and ");
            msg.push_str(&inner);
        }
        msg
    }

    fn is_valid(&self, value: &Value) -> bool {
        if !php::is_array(value) {
            return false;
        }
        if !php::array_values(value).all(|v| self.inner.is_valid(v)) {
            return false;
        }
        self.length == 0 || php::array_len(value) <= self.length
    }

    fn is_array(&self) -> bool {
        true
    }
}

/// `Utopia\Validator\Assoc`: a non-list PHP array whose JSON fits `length` bytes.
#[derive(Debug, Clone, Copy)]
pub struct Assoc {
    pub length: usize,
}

impl Default for Assoc {
    fn default() -> Self {
        Self { length: 65535 }
    }
}

impl Validator for Assoc {
    fn description(&self) -> String {
        "Value must be a valid object.".to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        if !php::is_array(value) {
            return false;
        }
        // PHP json_encode size (slashes escaped); serde_json is a close lower bound,
        // add the number of forward slashes to match PHP's escaping.
        let encoded = serde_json::to_string(value).unwrap_or_default();
        let size = encoded.len() + encoded.bytes().filter(|b| *b == b'/').count();
        if size > self.length {
            return false;
        }
        match value {
            // A list (keys 0..n-1) is not an assoc array; `[]` passes because
            // `array_keys([]) !== range(0, -1)`.
            Value::Array(a) => a.is_empty(),
            Value::Object(o) => !o.keys().enumerate().all(|(i, k)| *k == i.to_string()),
            _ => false,
        }
    }
}

/// `Utopia\Validator\Nullable`.
pub struct Nullable<V>(pub V);

impl<V: Validator> Validator for Nullable<V> {
    fn description(&self) -> String {
        format!("{} or null", self.0.description())
    }

    fn is_valid(&self, value: &Value) -> bool {
        value.is_null() || self.0.is_valid(value)
    }

    fn check(&self, value: &Value) -> Result<(), String> {
        if value.is_null() {
            return Ok(());
        }
        self.0.check(value).map_err(|inner| format!("{inner} or null"))
    }
}

/// `Utopia\Validator\AllOf`: every rule must pass; reports the first failing rule.
pub struct AllOf<'a>(pub Vec<Box<dyn Validator + 'a>>);

impl Validator for AllOf<'_> {
    fn description(&self) -> String {
        self.0.first().map(|v| v.description()).unwrap_or_default()
    }

    fn is_valid(&self, value: &Value) -> bool {
        self.0.iter().all(|v| v.is_valid(value))
    }

    fn check(&self, value: &Value) -> Result<(), String> {
        for rule in &self.0 {
            if !rule.is_valid(value) {
                return Err(rule.description());
            }
        }
        Ok(())
    }

    fn is_array(&self) -> bool {
        true
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::Text;
    use serde_json::json;

    #[test]
    fn white_list() {
        let w = WhiteList::new(&["email", "sms", "push"]);
        assert!(w.is_valid(&json!("EMAIL")));
        assert!(!w.is_valid(&json!("fax")));
        assert_eq!(w.description(), "Value must be one of (email, sms, push)");
    }

    #[test]
    fn assoc() {
        let a = Assoc::default();
        assert!(a.is_valid(&json!({"a": 1})));
        assert!(a.is_valid(&json!([])));
        assert!(!a.is_valid(&json!({})));
        assert!(!a.is_valid(&json!(["a"])));
        assert!(!a.is_valid(&json!("x")));
    }

    #[test]
    fn array_list() {
        let v = ArrayList::new(Text::new(3), 2);
        assert!(v.is_valid(&json!(["a", "b"])));
        assert!(!v.is_valid(&json!(["a", "b", "c"])));
        assert!(!v.is_valid(&json!(["abcd"])));
        assert_eq!(
            v.description(),
            "Value must a valid array no longer than 2 items and Value must be a valid string and at least 1 chars and no longer than 3 chars"
        );
    }
}
