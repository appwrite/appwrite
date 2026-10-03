//! OpenAPI document parser (PHP `Utopia\OpenAPI\Parser`).

mod openapi2;
mod openapi3;
pub mod reader;
pub mod schema;
pub mod value;

use crate::error::{InvalidSpecification, OpenApiError, ParseException};
use crate::json::Json;
use crate::parser::reader::DocumentReader;
use crate::parser::schema::{Dialect, SchemaReader};
use crate::specification::Specification;
use crate::version::Version;
use indexmap::IndexMap;

pub use schema::SchemaReader as SchemaDocumentReader;
pub use value::Value;

/// Input accepted by [`Parser::parse`] / [`Parser::read`].
#[derive(Clone, Debug)]
pub enum ParserInput {
    Text(String),
    Object(IndexMap<String, Json>),
    Json(Json),
}

impl From<&str> for ParserInput {
    fn from(value: &str) -> Self {
        Self::Text(value.to_owned())
    }
}

impl From<String> for ParserInput {
    fn from(value: String) -> Self {
        Self::Text(value)
    }
}

impl From<IndexMap<String, Json>> for ParserInput {
    fn from(value: IndexMap<String, Json>) -> Self {
        Self::Object(value)
    }
}

impl From<Json> for ParserInput {
    fn from(value: Json) -> Self {
        Self::Json(value)
    }
}

impl From<serde_json::Value> for ParserInput {
    fn from(value: serde_json::Value) -> Self {
        Self::Json(Json::from_serde(value))
    }
}

/// OpenAPI 2 / 3 / 3.1 parser.
#[derive(Debug, Clone, Copy, Default)]
pub struct Parser;

impl Parser {
    pub fn new() -> Self {
        Self
    }

    pub fn read(
        &self,
        input: impl Into<ParserInput>,
        version: Option<Version>,
    ) -> Result<Specification, OpenApiError> {
        let document = decode(input.into())?;
        let (detected, source_version) = detect_version(&document)?;
        if let Some(expected) = version {
            if expected != detected {
                return Err(InvalidSpecification(format!(
                    "Expected OpenAPI {}, document declares {source_version}",
                    expected.as_str()
                ))
                .into());
            }
        }
        let schemas = SchemaReader::new(Dialect::for_version(detected));
        let reader = DocumentReader::new(document, detected, source_version, schemas);
        match detected {
            Version::V2 => openapi2::read(&reader),
            Version::V30 | Version::V31 => openapi3::read(&reader),
        }
    }

    pub fn parse(
        input: impl Into<ParserInput>,
        version: Option<Version>,
    ) -> Result<Specification, OpenApiError> {
        Self::new().read(input, version)
    }
}

fn decode(input: ParserInput) -> Result<IndexMap<String, Json>, OpenApiError> {
    let document = match input {
        ParserInput::Object(map) => Json::Object(map),
        ParserInput::Json(json) => json,
        ParserInput::Text(text) => Json::parse_str(&text)
            .map_err(|e| OpenApiError::from(ParseException(format!("Invalid JSON: {e}"))))?,
    };
    match document {
        Json::Object(map) => Ok(map),
        Json::Array(items) if items.is_empty() => Ok(IndexMap::new()),
        Json::Array(_) => {
            Err(InvalidSpecification("The OpenAPI document root must be an object".into()).into())
        }
        _ => Err(InvalidSpecification("The OpenAPI document root must be an object".into()).into()),
    }
}

fn detect_version(document: &IndexMap<String, Json>) -> Result<(Version, String), OpenApiError> {
    if document.contains_key("swagger") {
        let Json::String(version) = document.get("swagger").unwrap_or(&Json::Null) else {
            return Err(
                InvalidSpecification("The 'swagger' version must be a string".into()).into(),
            );
        };
        return Ok((Version::from_document_version(version)?, version.clone()));
    }
    if document.contains_key("openapi") {
        let Json::String(version) = document.get("openapi").unwrap_or(&Json::Null) else {
            return Err(
                InvalidSpecification("The 'openapi' version must be a string".into()).into(),
            );
        };
        return Ok((Version::from_document_version(version)?, version.clone()));
    }
    Err(InvalidSpecification("Missing 'swagger' or 'openapi' version field".into()).into())
}
