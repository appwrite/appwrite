//! Exceptions matching `Utopia\OpenAPI\Exception`.

use thiserror::Error;

/// Marker for every OpenAPI parser error (PHP `OpenAPIException`).
pub trait OpenApiException: std::error::Error {}

/// Base parse failure (PHP `ParseException`).
#[derive(Debug, Error)]
#[error("{0}")]
pub struct ParseException(pub String);

impl OpenApiException for ParseException {}

/// Document is syntactically JSON but not a valid OpenAPI spec.
#[derive(Debug, Error)]
#[error("{0}")]
pub struct InvalidSpecification(pub String);

impl OpenApiException for InvalidSpecification {}

impl From<InvalidSpecification> for ParseException {
    fn from(value: InvalidSpecification) -> Self {
        Self(value.0)
    }
}

/// `$ref` cycle while expanding a Reference Object.
#[derive(Debug, Error)]
#[error("{0}")]
pub struct CircularReference(pub String);

impl OpenApiException for CircularReference {}

/// `$ref` pointer could not be resolved.
#[derive(Debug, Error)]
#[error("{0}")]
pub struct ReferenceNotFound(pub String);

impl OpenApiException for ReferenceNotFound {}

/// `swagger` / `openapi` version string is not 2.0, 3.0.x, or 3.1.x.
#[derive(Debug, Error)]
#[error("{0}")]
pub struct UnsupportedVersion(pub String);

impl OpenApiException for UnsupportedVersion {}

/// Unified error returned by [`crate::Parser`].
#[derive(Debug, Error)]
pub enum OpenApiError {
    #[error("{0}")]
    Parse(#[from] ParseException),
    #[error("{0}")]
    Invalid(#[from] InvalidSpecification),
    #[error("{0}")]
    Circular(#[from] CircularReference),
    #[error("{0}")]
    Reference(#[from] ReferenceNotFound),
    #[error("{0}")]
    Unsupported(#[from] UnsupportedVersion),
}

impl OpenApiException for OpenApiError {}

impl OpenApiError {
    pub fn message(&self) -> &str {
        match self {
            Self::Parse(e) => &e.0,
            Self::Invalid(e) => &e.0,
            Self::Circular(e) => &e.0,
            Self::Reference(e) => &e.0,
            Self::Unsupported(e) => &e.0,
        }
    }
}
