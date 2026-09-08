mod dotenv;
mod json;
mod none;
mod php;
mod yaml;

use crate::error::ParseError;
use crate::key::KeySpec;
use crate::source::SourceContent;
use serde_json::{Map, Value};

pub use dotenv::DotenvParser;
pub use json::JsonParser;
pub use none::NoneParser;
pub use php::PhpParser;
pub use yaml::YamlParser;

/// Parses raw configuration contents into a key/value map.
pub trait Parser {
    fn parse(
        &self,
        contents: &SourceContent,
        keys: &[KeySpec],
    ) -> Result<Map<String, Value>, ParseError>;
}

/// Map format expected by a structured config parser.
#[derive(Debug, Clone, Copy)]
pub(crate) enum MapFormat {
    Json,
    Yaml,
}

impl MapFormat {
    fn not_map_error(self) -> ParseError {
        match self {
            Self::Json => ParseError::NotJsonObject,
            Self::Yaml => ParseError::NotYamlMapping,
        }
    }
}

/// A config must be a key/value map. Reject scalars, sequences, and list-shaped maps.
pub(crate) fn require_map(
    value: Value,
    format: MapFormat,
) -> Result<Map<String, Value>, ParseError> {
    match value {
        Value::Object(map) => {
            if is_list_shaped(&map) {
                return Err(format.not_map_error());
            }
            Ok(map)
        }
        Value::Array(arr) if arr.is_empty() => Ok(Map::new()),
        Value::Null if matches!(format, MapFormat::Json) => Err(ParseError::NotJsonObject),
        Value::Null => Err(ParseError::NotYamlMapping),
        _ => Err(format.not_map_error()),
    }
}

fn is_list_shaped(map: &Map<String, Value>) -> bool {
    if map.is_empty() {
        return false;
    }
    let mut indices = Vec::with_capacity(map.len());
    for key in map.keys() {
        let Ok(index) = key.parse::<usize>() else {
            return false;
        };
        indices.push(index);
    }
    indices.sort_unstable();
    indices == (0..indices.len()).collect::<Vec<_>>()
}

pub(crate) fn parse_text_contents(contents: &SourceContent) -> Result<&str, ParseError> {
    match contents {
        SourceContent::Text(text) => Ok(text.as_str()),
        SourceContent::Map(_) => Err(ParseError::ContentsNotString),
    }
}

pub(crate) fn empty_text_returns_empty_map(text: &str) -> Option<Map<String, Value>> {
    if text.is_empty() || text == "0" {
        Some(Map::new())
    } else {
        None
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn list_shaped_map_is_detected() {
        let mut map = Map::new();
        map.insert("0".into(), Value::String("a".into()));
        map.insert("1".into(), Value::String("b".into()));
        assert!(is_list_shaped(&map));

        let mut named = Map::new();
        named.insert("name".into(), Value::String("a".into()));
        assert!(!is_list_shaped(&named));
    }
}
