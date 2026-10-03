use crate::{Validator, ValueType};
use serde_json::Value;

/// Globstar pattern matcher (`*` and `**`).
#[derive(Debug, Clone)]
pub struct Globstar {
    pattern: String,
}

impl Globstar {
    pub fn new(pattern: impl Into<String>) -> Self {
        Self {
            pattern: pattern.into(),
        }
    }

    fn match_glob(pattern: &str, value: &str) -> bool {
        // Convert simple glob to regex-ish matching
        let mut regex = String::from("^");
        let mut chars = pattern.chars().peekable();
        while let Some(c) = chars.next() {
            match c {
                '*' => {
                    if chars.peek() == Some(&'*') {
                        chars.next();
                        if chars.peek() == Some(&'/') {
                            chars.next();
                            regex.push_str("(?:.*/)?");
                        } else {
                            regex.push_str(".*");
                        }
                    } else {
                        regex.push_str("[^/]*");
                    }
                }
                '?' => regex.push_str("[^/]"),
                '.' | '+' | '(' | ')' | '|' | '^' | '$' | '{' | '}' | '[' | ']' | '\\' => {
                    regex.push('\\');
                    regex.push(c);
                }
                other => regex.push(other),
            }
        }
        regex.push('$');
        regex::Regex::new(&regex)
            .map(|re| re.is_match(value))
            .unwrap_or(false)
    }
}

impl Validator for Globstar {
    fn description(&self) -> String {
        format!("Value must match globstar pattern {}", self.pattern)
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        value
            .as_str()
            .is_some_and(|s| Self::match_glob(&self.pattern, s))
    }
}
