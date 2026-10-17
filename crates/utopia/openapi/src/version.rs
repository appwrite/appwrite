//! [`Version`]: the OpenAPI versions this library reads.

use std::fmt;

use crate::Error;

/// An OpenAPI version this library reads. Coarser than the version string a
/// document declares: `3.0.0` and `3.0.3` are both [`Version::V3_0`].
///
/// PHP: `Utopia\OpenAPI\Version`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Version {
    /// OpenAPI 2.0 (Swagger). PHP: `Version::V2`.
    V2,
    /// OpenAPI 3.0. PHP: `Version::V3_0`.
    V3_0,
    /// OpenAPI 3.1. PHP: `Version::V3_1`.
    V3_1,
}

impl Version {
    /// The version a document's `swagger` or `openapi` string declares:
    /// exactly `2.0`, or `3.0` / `3.1` with an optional numeric patch
    /// (`3.1.1`). Anything else is [`Error::UnsupportedVersion`].
    ///
    /// PHP: `Version::fromDocumentVersion($version)`.
    ///
    /// ```
    /// use utopia_openapi::Version;
    ///
    /// assert_eq!(Version::from_document_version("3.0.3")?, Version::V3_0);
    /// assert_eq!(Version::from_document_version("2.0")?, Version::V2);
    /// let error = Version::from_document_version("3.2.0").unwrap_err();
    /// assert_eq!(error.to_string(), "Unsupported OpenAPI version: 3.2.0");
    /// # Ok::<(), utopia_openapi::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\OpenAPI\Version;
    ///
    /// var_dump(Version::fromDocumentVersion('3.0.3') === Version::V3_0); // bool(true)
    /// var_dump(Version::fromDocumentVersion('2.0') === Version::V2);     // bool(true)
    /// try {
    ///     Version::fromDocumentVersion('3.2.0');
    /// } catch (\Utopia\OpenAPI\Exception\UnsupportedVersion $e) {
    ///     echo $e->getMessage(), "\n"; // Unsupported OpenAPI version: 3.2.0
    /// }
    /// ```
    pub fn from_document_version(version: &str) -> Result<Self, Error> {
        if version == "2.0" {
            return Ok(Version::V2);
        }
        let minor = |prefix: &str| {
            version.strip_prefix(prefix).is_some_and(|rest| {
                rest.is_empty()
                    || rest
                        .strip_prefix('.')
                        .is_some_and(|patch| !patch.is_empty() && patch.bytes().all(|b| b.is_ascii_digit()))
            })
        };
        if minor("3.0") {
            return Ok(Version::V3_0);
        }
        if minor("3.1") {
            return Ok(Version::V3_1);
        }
        Err(Error::UnsupportedVersion(format!("Unsupported OpenAPI version: {version}")))
    }

    /// The version's name (`2.0`, `3.0`, `3.1`).
    ///
    /// PHP: `$version->value`.
    pub fn as_str(self) -> &'static str {
        match self {
            Version::V2 => "2.0",
            Version::V3_0 => "3.0",
            Version::V3_1 => "3.1",
        }
    }
}

impl fmt::Display for Version {
    /// Writes [`Version::as_str`].
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.as_str())
    }
}
