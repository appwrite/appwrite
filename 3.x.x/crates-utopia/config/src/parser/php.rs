use crate::error::ParseError;
use crate::key::KeySpec;
use crate::parser::{parse_text_contents, Parser};
use crate::source::SourceContent;
use serde_json::{Map, Number, Value};

/// Parses PHP configuration files that `return [...];` a literal array.
#[derive(Debug, Clone, Copy, Default)]
pub struct PhpParser;

impl Parser for PhpParser {
    fn parse(
        &self,
        contents: &SourceContent,
        _keys: &[KeySpec],
    ) -> Result<Map<String, Value>, ParseError> {
        let text = parse_text_contents(contents)?;
        parse_php_return_array(text)
    }
}

fn parse_php_return_array(text: &str) -> Result<Map<String, Value>, ParseError> {
    if text.is_empty() {
        return Err(ParseError::InvalidPhp("empty input".into()));
    }

    let mut parser = PhpArrayParser::new(text);
    parser.skip_ws();

    if !parser.consume("<?php") {
        return Err(ParseError::InvalidPhp(
            "PHP config must start with <?php".into(),
        ));
    }

    parser.skip_ws();
    while parser.consume("declare") {
        parser.skip_ws();
        if !parser.consume("(") {
            return Err(ParseError::InvalidPhp(
                "invalid declare statement in PHP config".into(),
            ));
        }
        while !parser.is_eof() && !parser.rest().starts_with(");") {
            parser.bump();
        }
        if !parser.consume(");") {
            return Err(ParseError::InvalidPhp(
                "unterminated declare statement in PHP config".into(),
            ));
        }
        parser.skip_ws();
    }

    parser.skip_ws();

    if !parser.consume("return") {
        return Err(ParseError::InvalidPhp(
            "PHP config must contain a return statement".into(),
        ));
    }

    parser.skip_ws();
    let value = parser.parse_value()?;
    parser.skip_ws();
    parser.expect(";")?;
    parser.skip_ws();

    if !parser.is_eof() {
        return Err(ParseError::InvalidPhp(
            "unexpected trailing content in PHP config".into(),
        ));
    }

    match value {
        Value::Object(map) => Ok(map),
        _ => Err(ParseError::PhpNotArray),
    }
}

struct PhpArrayParser<'a> {
    input: &'a str,
    pos: usize,
}

impl<'a> PhpArrayParser<'a> {
    fn new(input: &'a str) -> Self {
        Self { input, pos: 0 }
    }

    fn is_eof(&self) -> bool {
        self.pos >= self.input.len()
    }

    fn rest(&self) -> &str {
        &self.input[self.pos..]
    }

    fn bump(&mut self) -> Option<char> {
        let ch = self.rest().chars().next()?;
        self.pos += ch.len_utf8();
        Some(ch)
    }

    fn skip_ws(&mut self) {
        loop {
            while self.rest().starts_with(|c: char| c.is_whitespace()) {
                self.pos += 1;
            }

            if self.rest().starts_with("//") {
                while let Some(ch) = self.bump() {
                    if ch == '\n' {
                        break;
                    }
                }
                continue;
            }

            if self.rest().starts_with('#') {
                while let Some(ch) = self.bump() {
                    if ch == '\n' {
                        break;
                    }
                }
                continue;
            }

            if self.rest().starts_with("/*") {
                while let Some(ch) = self.bump() {
                    if ch == '*' && self.rest().starts_with('/') {
                        self.pos += 1;
                        break;
                    }
                }
                continue;
            }

            break;
        }
    }

    fn consume(&mut self, token: &str) -> bool {
        self.skip_ws();
        if self.rest().starts_with(token) {
            self.pos += token.len();
            true
        } else {
            false
        }
    }

    fn expect(&mut self, token: &str) -> Result<(), ParseError> {
        if self.consume(token) {
            Ok(())
        } else {
            Err(ParseError::InvalidPhp(format!(
                "expected `{token}` at byte {}",
                self.pos
            )))
        }
    }

    fn parse_value(&mut self) -> Result<Value, ParseError> {
        self.skip_ws();

        if self.consume("[") {
            return self.parse_array();
        }

        if matches!(self.rest().chars().next(), Some('\'' | '"')) {
            return self.parse_string().map(Value::String);
        }

        if self.consume("true") {
            return Ok(Value::Bool(true));
        }
        if self.consume("false") {
            return Ok(Value::Bool(false));
        }
        if self.consume("null") {
            return Ok(Value::Null);
        }

        if self.rest().starts_with('-') || self.rest().starts_with(|c: char| c.is_ascii_digit()) {
            return self.parse_number();
        }

        Err(ParseError::InvalidPhp(format!(
            "unexpected token at byte {}",
            self.pos
        )))
    }

    fn parse_array(&mut self) -> Result<Value, ParseError> {
        self.skip_ws();
        let mut map = Map::new();
        let mut index = 0_u64;

        if self.consume("]") {
            return Ok(Value::Object(map));
        }

        loop {
            self.skip_ws();
            if self.consume("]") {
                break;
            }

            if self.rest().starts_with('\'') || self.rest().starts_with('"') {
                let marker = self.pos;
                let key = self.parse_string()?;
                self.skip_ws();
                if self.consume("=>") {
                    self.skip_ws();
                    let value = self.parse_value()?;
                    map.insert(key, value);
                } else {
                    self.pos = marker;
                    let value = self.parse_value()?;
                    map.insert(index.to_string(), value);
                    index += 1;
                }
            } else if self
                .rest()
                .starts_with(|c: char| c.is_ascii_digit() || c == '-')
            {
                let marker = self.pos;
                let key_value = self.parse_number()?;
                self.skip_ws();
                if self.consume("=>") {
                    self.skip_ws();
                    let value = self.parse_value()?;
                    let key = match &key_value {
                        Value::Number(num) => num.to_string(),
                        Value::String(s) => s.clone(),
                        _ => return Err(ParseError::InvalidPhp("invalid array key".into())),
                    };
                    map.insert(key, value);
                } else {
                    self.pos = marker;
                    let value = self.parse_number()?;
                    map.insert(index.to_string(), value);
                    index += 1;
                }
            } else {
                let value = self.parse_value()?;
                map.insert(index.to_string(), value);
                index += 1;
            }

            self.skip_ws();
            if self.consume("]") {
                break;
            }
            self.expect(",")?;
        }

        Ok(Value::Object(map))
    }

    fn parse_string(&mut self) -> Result<String, ParseError> {
        let quote = self
            .bump()
            .ok_or_else(|| ParseError::InvalidPhp("expected quote".into()))?;
        let mut value = String::new();

        while let Some(ch) = self.bump() {
            if ch == quote {
                return Ok(value);
            }
            if ch == '\\' {
                let escaped = self
                    .bump()
                    .ok_or_else(|| ParseError::InvalidPhp("unterminated escape sequence".into()))?;
                value.push(match escaped {
                    'n' => '\n',
                    'r' => '\r',
                    't' => '\t',
                    '\\' => '\\',
                    '\'' => '\'',
                    '"' => '"',
                    other => other,
                });
                continue;
            }
            value.push(ch);
        }

        Err(ParseError::InvalidPhp("unterminated string".into()))
    }

    fn parse_number(&mut self) -> Result<Value, ParseError> {
        let start = self.pos;
        if self.rest().starts_with('-') {
            self.pos += 1;
        }
        while self.rest().starts_with(|c: char| c.is_ascii_digit()) {
            self.pos += 1;
        }
        if self.rest().starts_with('.') {
            self.pos += 1;
            while self.rest().starts_with(|c: char| c.is_ascii_digit()) {
                self.pos += 1;
            }
        }

        let token = self.input[start..self.pos].trim();
        if token.is_empty() || token == "-" {
            return Err(ParseError::InvalidPhp("invalid number".into()));
        }

        self.skip_ws();
        if self.rest().starts_with('+') {
            let left_num: f64 = token
                .parse()
                .map_err(|_| ParseError::InvalidPhp(format!("invalid number `{token}`")))?;
            self.pos += 1;
            self.skip_ws();
            let right = self.parse_number()?;
            let right_num = match right {
                Value::Number(num) => num
                    .as_f64()
                    .ok_or_else(|| ParseError::InvalidPhp("invalid numeric result".into()))?,
                _ => return Err(ParseError::InvalidPhp("invalid numeric expression".into())),
            };
            return number_value(left_num + right_num);
        }

        if token.contains('.') {
            let float: f64 = token
                .parse()
                .map_err(|_| ParseError::InvalidPhp(format!("invalid float `{token}`")))?;
            number_value(float)
        } else {
            let int: i64 = token
                .parse()
                .map_err(|_| ParseError::InvalidPhp(format!("invalid integer `{token}`")))?;
            Ok(Value::Number(Number::from(int)))
        }
    }
}

fn number_value(value: f64) -> Result<Value, ParseError> {
    if value.fract() == 0.0 && value >= i64::MIN as f64 && value <= i64::MAX as f64 {
        Ok(Value::Number(Number::from(value as i64)))
    } else {
        Number::from_f64(value)
            .map(Value::Number)
            .ok_or_else(|| ParseError::InvalidPhp("invalid numeric result".into()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn parse(text: &str) -> Result<Map<String, Value>, ParseError> {
        PhpParser.parse(&SourceContent::Text(text.into()), &[])
    }

    #[test]
    fn basic_types() {
        let php = r#"<?php
            return [
                "string" => "hello world",
                "unicode_string" => "ä你こحب🌍",
                "integer" => 42,
                "float" => 3.14159,
                "negative" => -50,
                "boolean_true" => true,
                "boolean_false" => false,
                "null_value" => null,
            ];
        "#;
        let data = parse(php).unwrap();
        assert_eq!(data["string"], json!("hello world"));
        assert_eq!(data["unicode_string"], json!("ä你こحب🌍"));
        assert_eq!(data["integer"], json!(42));
        assert_eq!(data["negative"], json!(-50));
        assert_eq!(data["boolean_true"], json!(true));
        assert_eq!(data["boolean_false"], json!(false));
        assert_eq!(data["null_value"], Value::Null);
    }

    #[test]
    fn nested_arrays() {
        let php = r#"<?php return [
            "simple_array" => [1, 2, 3, 4, 5],
            "mixed_array" => ["string", 42, true, null, 3.14],
            "nested_array" => [[1, 2, 3], ["a", "b", "c", "d"], [true, false]],
            "empty_array" => [],
        ];"#;
        let data = parse(php).unwrap();
        assert_eq!(data["simple_array"]["0"], json!(1));
        assert_eq!(data["simple_array"]["4"], json!(5));
        assert_eq!(data["mixed_array"]["0"], json!("string"));
        assert_eq!(data["nested_array"]["1"]["1"], json!("b"));
        assert!(data["empty_array"].as_object().unwrap().is_empty());
    }

    #[test]
    fn edge_cases() {
        assert!(parse("<?php return [];").unwrap().is_empty());
        let data = parse("<?php return [ 'key' => 5 + 3 ];").unwrap();
        assert_eq!(data["key"], json!(8));
    }

    #[test]
    fn rejects_missing_start() {
        assert!(matches!(
            parse("return [];"),
            Err(ParseError::InvalidPhp(_))
        ));
    }

    #[test]
    fn rejects_missing_code() {
        assert!(matches!(parse("<?php"), Err(ParseError::InvalidPhp(_))));
    }

    #[test]
    fn rejects_wrong_syntax() {
        assert!(matches!(
            parse("<?php return [};"),
            Err(ParseError::InvalidPhp(_))
        ));
    }

    #[test]
    fn rejects_empty_string() {
        assert!(matches!(parse(""), Err(ParseError::InvalidPhp(_))));
    }
}
