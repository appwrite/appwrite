use crate::error::ParseError;
use crate::key::KeySpec;
use crate::parser::Parser;
use crate::source::SourceContent;
use serde_json::{Map, Value};

/// Pass-through parser for pre-parsed maps.
#[derive(Debug, Clone, Copy, Default)]
pub struct NoneParser;

impl Parser for NoneParser {
    fn parse(
        &self,
        contents: &SourceContent,
        _keys: &[KeySpec],
    ) -> Result<Map<String, Value>, ParseError> {
        match contents {
            SourceContent::Map(map) => Ok(map.clone()),
            SourceContent::Text(_) => Err(ParseError::ContentsNotMap),
        }
    }
}
