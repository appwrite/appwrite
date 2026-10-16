//! NATS header block (PHP `Utopia\NATS\Headers`).

use crate::error::{NatsError, ProtocolException};
use indexmap::IndexMap;
use std::ops::Index;

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Headers {
    headers: IndexMap<String, Vec<String>>,
    status: String,
    description: String,
}

impl Headers {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn set(&mut self, name: impl Into<String>, value: impl Into<String>) -> &mut Self {
        self.headers.insert(name.into(), vec![value.into()]);
        self
    }

    pub fn add(&mut self, name: impl Into<String>, value: impl Into<String>) -> &mut Self {
        self.headers
            .entry(name.into())
            .or_default()
            .push(value.into());
        self
    }

    pub fn get(&self, name: &str) -> Option<&str> {
        self.headers
            .get(name)
            .and_then(|v| v.first())
            .map(String::as_str)
    }

    pub fn get_all(&self, name: &str) -> Vec<String> {
        self.headers.get(name).cloned().unwrap_or_default()
    }

    pub fn has(&self, name: &str) -> bool {
        self.headers.contains_key(name)
    }

    pub fn delete(&mut self, name: &str) -> &mut Self {
        self.headers.shift_remove(name);
        self
    }

    pub fn all(&self) -> &IndexMap<String, Vec<String>> {
        &self.headers
    }

    pub fn get_status(&self) -> &str {
        &self.status
    }

    pub fn set_status(
        &mut self,
        status: impl Into<String>,
        description: impl Into<String>,
    ) -> &mut Self {
        self.status = status.into();
        self.description = description.into();
        self
    }

    pub fn get_description(&self) -> &str {
        &self.description
    }

    pub fn to_wire(&self) -> String {
        let mut result = String::from("NATS/1.0");
        if !self.status.is_empty() {
            result.push(' ');
            result.push_str(&self.status);
            if !self.description.is_empty() {
                result.push(' ');
                result.push_str(&self.description);
            }
        }
        result.push_str("\r\n");
        for (name, values) in &self.headers {
            for value in values {
                result.push_str(name);
                result.push_str(": ");
                result.push_str(value);
                result.push_str("\r\n");
            }
        }
        result.push_str("\r\n");
        result
    }

    pub fn from_wire(raw: &str) -> Result<Self, NatsError> {
        let mut headers = Self::new();
        let mut lines: Vec<&str> = raw.split("\r\n").collect();
        if lines.is_empty() {
            return Err(ProtocolException("Empty header block".into()).into());
        }
        let status_line = lines.remove(0);
        if !status_line.starts_with("NATS/1.0") {
            return Err(ProtocolException(format!("Invalid header version: {status_line}")).into());
        }
        let remainder = status_line[8..].trim();
        if !remainder.is_empty() {
            if let Some(space) = remainder.find(' ') {
                remainder[..space].clone_into(&mut headers.status);
                remainder[space + 1..].clone_into(&mut headers.description);
            } else {
                remainder.clone_into(&mut headers.status);
            }
        }
        for line in lines {
            if line.is_empty() {
                continue;
            }
            let Some(colon) = line.find(':') else {
                continue;
            };
            let name = line[..colon].to_owned();
            let value = line[colon + 1..].trim_start().to_owned();
            headers.headers.entry(name).or_default().push(value);
        }
        Ok(headers)
    }

    pub fn len(&self) -> usize {
        self.headers.len()
    }

    pub fn is_empty(&self) -> bool {
        self.headers.is_empty()
    }
}

impl Index<&str> for Headers {
    type Output = Vec<String>;
    fn index(&self, index: &str) -> &Self::Output {
        &self.headers[index]
    }
}
