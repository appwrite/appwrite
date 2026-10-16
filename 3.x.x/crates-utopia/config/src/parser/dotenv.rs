use crate::error::ParseError;
use crate::key::KeySpec;
use crate::parser::{empty_text_returns_empty_map, parse_text_contents, Parser};
use crate::source::SourceContent;
use serde_json::{Map, Value};
use utopia_validators::ValueType;

const TRUTHY: &[&str] = &["1", "true", "yes", "on", "enabled"];
const FALSY: &[&str] = &["0", "false", "no", "off", "disabled"];

/// Dotenv (`KEY=VALUE`) configuration parser.
#[derive(Debug, Clone, Copy, Default)]
pub struct DotenvParser;

impl Parser for DotenvParser {
    fn parse(
        &self,
        contents: &SourceContent,
        keys: &[KeySpec],
    ) -> Result<Map<String, Value>, ParseError> {
        let text = parse_text_contents(contents)?;
        if let Some(map) = empty_text_returns_empty_map(text) {
            return Ok(map);
        }

        let mut config = Map::new();
        for line in text.lines() {
            let line = line.trim();
            if line.is_empty() || line.starts_with('#') {
                continue;
            }

            let Some((name, raw_value)) = line.split_once('=') else {
                return Err(ParseError::InvalidDotenv);
            };

            let name = name.trim();
            if name.is_empty() || name == "0" {
                return Err(ParseError::InvalidDotenv);
            }

            let mut value = parse_value(raw_value)?;
            if let Some(key_spec) = keys.iter().find(|key| key.name == name) {
                if key_spec.wants_bool_coercion() {
                    value = coerce_bool(value);
                }
            }
            if let Value::String(s) = &value {
                if s.eq_ignore_ascii_case("null") {
                    value = Value::Null;
                }
            }

            config.insert(name.to_string(), value);
        }

        Ok(config)
    }
}

fn parse_value(raw: &str) -> Result<Value, ParseError> {
    let raw = raw.trim();
    if raw.is_empty() {
        return Ok(Value::String(String::new()));
    }

    let first = raw.as_bytes()[0];
    if first == b'"' || first == b'\'' {
        return Ok(Value::String(parse_quoted(raw, first as char)?));
    }

    let without_comment = match raw.find('#') {
        Some(index) => raw[..index].trim(),
        None => raw,
    };
    Ok(Value::String(without_comment.to_string()))
}

fn parse_quoted(raw: &str, quote: char) -> Result<String, ParseError> {
    let bytes = raw.as_bytes();
    let mut value = String::new();
    let mut index = 1;

    while index < bytes.len() {
        let ch = bytes[index] as char;
        if ch == '\\' && quote == '"' && index + 1 < bytes.len() {
            let next = bytes[index + 1] as char;
            if next == '"' || next == '\\' {
                value.push(next);
                index += 2;
                continue;
            }
        }

        if ch == quote {
            let rest = raw[index + 1..].trim();
            if !rest.is_empty() && !rest.starts_with('#') {
                return Err(ParseError::InvalidDotenv);
            }
            return Ok(value);
        }

        value.push(ch);
        index += 1;
    }

    Err(ParseError::InvalidDotenv)
}

fn coerce_bool(value: Value) -> Value {
    let Value::String(raw) = value else {
        return value;
    };
    let lowered = raw.to_ascii_lowercase();
    if TRUTHY.contains(&lowered.as_str()) {
        Value::Bool(true)
    } else if FALSY.contains(&lowered.as_str()) {
        Value::Bool(false)
    } else {
        Value::String(raw)
    }
}

impl KeySpec {
    pub(crate) fn wants_bool_coercion(&self) -> bool {
        self.coerce_bool || self.validator.value_type() == ValueType::Boolean
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::source::SourceContent;

    #[test]
    fn parses_basic_dotenv() {
        let parser = DotenvParser;
        let data = parser
            .parse(
                &SourceContent::Text("HOST=127.0.0.1\nPORT=3306".into()),
                &[],
            )
            .unwrap();
        assert_eq!(data["HOST"], Value::String("127.0.0.1".into()));
        assert_eq!(data["PORT"], Value::String("3306".into()));
    }
}
