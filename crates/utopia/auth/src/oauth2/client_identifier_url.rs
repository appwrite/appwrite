//! Client identifier URLs: OAuth2 clients identified by the URL of their
//! metadata document.

use php_std::filter::{FILTER_VALIDATE_URL, Options, Value, filter_var};
use php_std::string::strtolower;

use super::parse_url;
use crate::Error;

/// A client identifier URL, from the OAuth Client ID Metadata Document draft.
///
/// It is kept verbatim: client ids compare as plain strings.
///
/// PHP: `Utopia\Auth\OAuth2\ClientIdentifierUrl`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ClientIdentifierUrl {
    value: Vec<u8>,
    host: Vec<u8>,
}

fn invalid(message: &str) -> Error {
    Error::InvalidClientMetadata(message.to_owned())
}

impl ClientIdentifierUrl {
    /// Whether a client id looks like a URL: it starts with `https://` or
    /// `http://`, in any case.
    ///
    /// PHP: `isCandidate($value)`.
    pub fn is_candidate(value: &[u8]) -> bool {
        let lower = strtolower(value);
        lower.starts_with(b"https://") || lower.starts_with(b"http://")
    }

    /// Validates a client identifier URL.
    ///
    /// It must be a well-formed `https` URL (`http` too with `allow_http`)
    /// with a host and a path, and without userinfo, a fragment or `.`/`..`
    /// path segments; otherwise it fails with [`Error::InvalidClientMetadata`].
    ///
    /// PHP: `fromString($value, $allowHttp)`.
    pub fn from_string(value: &[u8], allow_http: bool) -> Result<Self, Error> {
        let parts = parse_url(value);
        let valid = matches!(
            filter_var(&Value::Str(value.to_vec()), FILTER_VALIDATE_URL, &Options::Flags(0)),
            Ok(f) if f.value != Value::Bool(false)
        );
        let Some(parts) = parts.filter(|_| valid) else {
            return Err(invalid("Client Identifier URL is malformed."));
        };
        let scheme = strtolower(parts.scheme.as_deref().unwrap_or_default()).into_owned();
        if scheme != b"https" && (!allow_http || scheme != b"http") {
            return Err(invalid("Client Identifier URL must use the https scheme."));
        }
        if parts.user.is_some() || parts.pass.is_some() {
            return Err(invalid("Client Identifier URL must not contain a userinfo component."));
        }
        if parts.fragment.is_some() {
            return Err(invalid("Client Identifier URL must not contain a fragment component."));
        }
        let host = parts.host.unwrap_or_default();
        let path = parts.path.unwrap_or_default();
        if host.is_empty() || path.is_empty() {
            return Err(invalid("Client Identifier URL must contain a host and a path component."));
        }
        if path.split(|b| *b == b'/').any(|s| s == b"." || s == b"..") {
            return Err(invalid("Client Identifier URL must not contain dot path segments."));
        }
        Ok(Self { value: value.to_vec(), host })
    }

    /// The URL, exactly as given.
    ///
    /// PHP: `toString()`.
    pub fn as_bytes(&self) -> &[u8] {
        &self.value
    }

    /// The URL's host.
    ///
    /// PHP: `host()`.
    pub fn host(&self) -> &[u8] {
        &self.host
    }
}
