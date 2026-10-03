//! Reference Object helpers (PHP `Utopia\OpenAPI\Reference`).

use crate::error::{CircularReference, OpenApiError, ReferenceNotFound};
use crate::json::Json;
use indexmap::IndexMap;

/// JSON Reference (`$ref` string wrapper).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Reference {
    pub value: String,
}

impl Reference {
    pub fn new(value: impl Into<String>) -> Self {
        Self {
            value: value.into(),
        }
    }

    pub fn is_local(&self) -> bool {
        self.value.starts_with('#')
    }
}

/// Resolution trail and optional base URI.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ResolutionContext {
    pub trail: Vec<String>,
    pub base_uri: Option<String>,
}

impl ResolutionContext {
    pub fn new(trail: Vec<String>, base_uri: Option<String>) -> Self {
        Self { trail, base_uri }
    }
}

/// Resolves a [`Reference`] against some document.
pub trait Resolver {
    fn resolve(
        &self,
        reference: &Reference,
        context: &ResolutionContext,
    ) -> Result<Json, OpenApiError>;
}

/// In-document JSON Pointer resolver (PHP `LocalResolver`).
#[derive(Clone, Debug)]
pub struct LocalResolver {
    document: Json,
}

impl LocalResolver {
    pub fn new(document: IndexMap<String, Json>) -> Self {
        Self {
            document: Json::Object(document),
        }
    }

    pub fn from_json(document: Json) -> Self {
        Self { document }
    }

    /// Resolve a chain of Reference Objects. Schema `$ref`s should not use this
    /// because recursive schema graphs must remain unexpanded.
    pub fn resolve_object(&self, reference: &str, trail: &[String]) -> Result<Json, OpenApiError> {
        let value = self.resolve(
            &Reference::new(reference),
            &ResolutionContext {
                trail: trail.to_vec(),
                base_uri: None,
            },
        )?;
        if let Json::Object(map) = &value {
            if let Some(Json::String(next)) = map.get("$ref") {
                let mut next_trail = trail.to_vec();
                next_trail.push(reference.to_owned());
                return self.resolve_object(next, &next_trail);
            }
        }
        Ok(value)
    }
}

impl Resolver for LocalResolver {
    fn resolve(
        &self,
        reference: &Reference,
        context: &ResolutionContext,
    ) -> Result<Json, OpenApiError> {
        let value = &reference.value;
        if !reference.is_local() {
            return Err(ReferenceNotFound(format!(
                "External reference is not configured: {value}"
            ))
            .into());
        }

        if context.trail.iter().any(|t| t == value) {
            let mut trail = context.trail.clone();
            trail.push(value.clone());
            return Err(
                CircularReference(format!("Circular reference: {}", trail.join(" -> "))).into(),
            );
        }

        let pointer = percent_decode(&value[1..]);
        if pointer.is_empty() {
            return Ok(self.document.clone());
        }
        if !pointer.starts_with('/') {
            return Err(ReferenceNotFound(format!("Invalid local JSON Pointer: {value}")).into());
        }

        let mut current = &self.document;
        for encoded_token in pointer[1..].split('/') {
            if invalid_escape(encoded_token) {
                return Err(ReferenceNotFound(format!(
                    "Invalid JSON Pointer escape in reference: {value}"
                ))
                .into());
            }
            let token = encoded_token.replace("~1", "/").replace("~0", "~");
            current = match current {
                Json::Object(map) => map.get(&token).ok_or_else(|| {
                    OpenApiError::from(ReferenceNotFound(format!("Reference not found: {value}")))
                })?,
                Json::Array(items) => {
                    let idx: usize = token.parse().map_err(|_| {
                        OpenApiError::from(ReferenceNotFound(format!(
                            "Reference not found: {value}"
                        )))
                    })?;
                    items.get(idx).ok_or_else(|| {
                        OpenApiError::from(ReferenceNotFound(format!(
                            "Reference not found: {value}"
                        )))
                    })?
                }
                _ => {
                    return Err(ReferenceNotFound(format!("Reference not found: {value}")).into());
                }
            };
        }

        Ok(current.clone())
    }
}

fn invalid_escape(token: &str) -> bool {
    let bytes = token.as_bytes();
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'~' {
            let next = bytes.get(i + 1).copied();
            if next != Some(b'0') && next != Some(b'1') {
                return true;
            }
            i += 2;
            continue;
        }
        i += 1;
    }
    false
}

fn percent_decode(input: &str) -> String {
    let mut out = String::new();
    let bytes = input.as_bytes();
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'%' && i + 2 < bytes.len() {
            let hex = &input[i + 1..i + 3];
            if let Ok(v) = u8::from_str_radix(hex, 16) {
                out.push(v as char);
                i += 3;
                continue;
            }
        }
        out.push(bytes[i] as char);
        i += 1;
    }
    out
}
