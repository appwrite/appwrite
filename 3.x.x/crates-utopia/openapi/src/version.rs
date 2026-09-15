//! OpenAPI document version (PHP `Utopia\OpenAPI\Version`).

use crate::error::{OpenApiError, UnsupportedVersion};

/// Canonical OpenAPI version family.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Version {
    /// OpenAPI / Swagger 2.0
    V2,
    /// OpenAPI 3.0.x
    V30,
    /// OpenAPI 3.1.x
    V31,
}

impl Version {
    /// PHP enum value (`2.0`, `3.0`, `3.1`).
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::V2 => "2.0",
            Self::V30 => "3.0",
            Self::V31 => "3.1",
        }
    }

    /// Map a document `swagger` / `openapi` string to a version family.
    pub fn from_document_version(version: &str) -> Result<Self, OpenApiError> {
        if version == "2.0" {
            return Ok(Self::V2);
        }
        if regex_is_30(version) {
            return Ok(Self::V30);
        }
        if regex_is_31(version) {
            return Ok(Self::V31);
        }
        Err(UnsupportedVersion(format!("Unsupported OpenAPI version: {version}")).into())
    }
}

impl std::fmt::Display for Version {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.as_str())
    }
}

fn regex_is_30(version: &str) -> bool {
    // PHP: preg_match('/^3\.0(?:\.\d+)?$/D', $version)
    if !version.starts_with("3.0") {
        return false;
    }
    let rest = &version[3..];
    if rest.is_empty() {
        return true;
    }
    let Some(stripped) = rest.strip_prefix('.') else {
        return false;
    };
    !stripped.is_empty() && stripped.chars().all(|c| c.is_ascii_digit())
}

fn regex_is_31(version: &str) -> bool {
    if !version.starts_with("3.1") {
        return false;
    }
    let rest = &version[3..];
    if rest.is_empty() {
        return true;
    }
    let Some(stripped) = rest.strip_prefix('.') else {
        return false;
    };
    !stripped.is_empty() && stripped.chars().all(|c| c.is_ascii_digit())
}
