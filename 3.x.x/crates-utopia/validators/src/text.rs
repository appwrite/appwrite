use crate::{Validator, ValueType};
use serde_json::Value;
use std::fmt::Write;

/// Validate string length (and optional character allow-list).
#[derive(Debug, Clone)]
pub struct Text {
    length: usize,
    min: usize,
    allow_list: Vec<char>,
}

impl Text {
    pub fn new(length: usize) -> Self {
        Self {
            length,
            min: 0,
            allow_list: Vec::new(),
        }
    }

    pub fn with_min(mut self, min: usize) -> Self {
        self.min = min;
        self
    }

    pub fn with_allow_list(mut self, list: impl IntoIterator<Item = char>) -> Self {
        self.allow_list = list.into_iter().collect();
        self
    }
}

impl Validator for Text {
    fn description(&self) -> String {
        let mut message = String::from("Value must be a valid string");
        if self.min == self.length && self.length != 0 {
            let _ = write!(message, " and exactly {} chars", self.length);
        } else {
            if self.min != 0 {
                let _ = write!(message, " and at least {} chars", self.min);
            }
            if self.length != 0 {
                let _ = write!(message, " and no longer than {} chars", self.length);
            }
        }
        if !self.allow_list.is_empty() {
            let chars: String = self
                .allow_list
                .iter()
                .map(|c| c.to_string())
                .collect::<Vec<_>>()
                .join(", ");
            let _ = write!(message, " and only consist of '{chars}' chars");
        }
        message
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(s) = value.as_str() else {
            return false;
        };
        let len = s.chars().count();
        if len < self.min {
            return false;
        }
        if self.length != 0 && len > self.length {
            return false;
        }
        if !self.allow_list.is_empty() {
            for ch in s.chars() {
                if !self.allow_list.contains(&ch) {
                    return false;
                }
            }
        }
        true
    }
}
