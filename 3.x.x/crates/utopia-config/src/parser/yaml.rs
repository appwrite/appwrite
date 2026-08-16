use crate::error::ParseError;
use crate::key::KeySpec;
use crate::parser::{
    empty_text_returns_empty_map, parse_text_contents, require_map, MapFormat, Parser,
};
use crate::source::SourceContent;
use serde_json::{Map, Value};

/// YAML configuration parser.
#[derive(Debug, Clone, Copy, Default)]
pub struct YamlParser;

impl Parser for YamlParser {
    fn parse(
        &self,
        contents: &SourceContent,
        _keys: &[KeySpec],
    ) -> Result<Map<String, Value>, ParseError> {
        let text = parse_text_contents(contents)?;
        if let Some(map) = empty_text_returns_empty_map(text) {
            return Ok(map);
        }

        let value: Value = serde_yaml::from_str(text).map_err(|err| {
            if err.to_string().contains("invalid type") {
                ParseError::NotYamlMapping
            } else {
                ParseError::InvalidYaml(err.to_string())
            }
        })?;

        if matches!(value, Value::Null) {
            return Err(ParseError::InvalidYamlFile);
        }

        require_map(value, MapFormat::Yaml)
    }
}
