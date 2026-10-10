use crate::Error;

/// A Pushed Authorization Request `request_uri` (RFC 9126): a deployment
/// prefix plus a stored request id (`Utopia\Auth\OAuth2\PAR`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Par {
    prefix: Vec<u8>,
    id: Vec<u8>,
}

impl Par {
    /// `PAR::fromId($prefix, $id)`.
    pub fn from_id(prefix: &[u8], id: &[u8]) -> Result<Self, Error> {
        if prefix.is_empty() || id.is_empty() {
            return Err(Error::InvalidRequestUri("request_uri prefix and id must be non-empty strings.".into()));
        }
        Ok(Self { prefix: prefix.to_vec(), id: id.to_vec() })
    }

    /// `PAR::fromRequestUri($prefix, $requestUri)`.
    pub fn from_request_uri(prefix: &[u8], request_uri: &[u8]) -> Result<Self, Error> {
        let invalid = || Error::InvalidRequestUri("Invalid request_uri.".into());
        if prefix.is_empty() {
            return Err(invalid());
        }
        let id = request_uri.strip_prefix(prefix).ok_or_else(invalid)?;
        if id.is_empty() {
            return Err(invalid());
        }
        Ok(Self { prefix: prefix.to_vec(), id: id.to_vec() })
    }

    /// `id()`.
    pub fn id(&self) -> &[u8] {
        &self.id
    }

    /// `requestUri()`.
    pub fn request_uri(&self) -> Vec<u8> {
        [self.prefix.as_slice(), self.id.as_slice()].concat()
    }
}
