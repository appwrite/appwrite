use crate::error::ParseError;
use crate::key::KeySpec;
use crate::parser::{
    empty_text_returns_empty_map, parse_text_contents, require_map, MapFormat, Parser,
};
use crate::source::SourceContent;
use serde_json::{Map, Value};

/// JSON configuration parser.
#[derive(Debug, Clone, Copy, Default)]
pub struct JsonParser;

impl Parser for JsonParser {
    fn parse(
        &self,
        contents: &SourceContent,
        _keys: &[KeySpec],
    ) -> Result<Map<String, Value>, ParseError> {
        let text = parse_text_contents(contents)?;
        if let Some(map) = empty_text_returns_empty_map(text) {
            return Ok(map);
        }
        if text == "[]" {
            return Ok(Map::new());
        }

        let value: Value = serde_json::from_str(text).map_err(|_| ParseError::InvalidJson)?;
        require_map(value, MapFormat::Json)
    }
}
