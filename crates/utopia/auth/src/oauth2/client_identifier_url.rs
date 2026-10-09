use php_std::filter::{FILTER_VALIDATE_URL, Options, Value, filter_var};
use php_std::string::strtolower;

use super::parse_url;
use crate::Error;

/// A Client Identifier URL of the OAuth Client ID Metadata Document draft
/// (`Utopia\Auth\OAuth2\ClientIdentifierUrl`), kept verbatim: client ids
/// compare as plain strings.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ClientIdentifierUrl {
    value: Vec<u8>,
    host: Vec<u8>,
}

fn invalid(message: &str) -> Error {
    Error::InvalidClientMetadata(message.to_owned())
}

impl ClientIdentifierUrl {
    /// `isCandidate($value)`: starts with `https://` or `http://`, in any case.
    pub fn is_candidate(value: &[u8]) -> bool {
        let lower = strtolower(value);
        lower.starts_with(b"https://") || lower.starts_with(b"http://")
    }

    /// `fromString($value, $allowHttp)`.
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

    /// `toString()`.
    pub fn as_bytes(&self) -> &[u8] {
        &self.value
    }

    /// `host()`.
    pub fn host(&self) -> &[u8] {
        &self.host
    }
}
