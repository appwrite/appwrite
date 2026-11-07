//! Resource indicators: the `resource` request parameter (RFC 8707).

use php_std::string::strtolower;
use php_std::{Array, Str, Value};

use super::parse_url;
use crate::Error;

/// The resource indicators of a request (RFC 8707): distinct absolute
/// http(s) URIs without fragments.
///
/// PHP: `Utopia\Auth\OAuth2\ResourceIndicators`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct ResourceIndicators(Vec<Vec<u8>>);

/// The `resource` request parameter: absent, one value, or several.
#[derive(Debug, Clone, PartialEq)]
pub enum Resources<'a> {
    /// No `resource` was given.
    None,
    /// One resource.
    One(&'a [u8]),
    /// Several resources, as a list.
    Many(&'a Array),
}

fn invalid(message: &str) -> Error {
    Error::InvalidResource(message.to_owned())
}

fn is_valid(resource: &[u8]) -> bool {
    let Some(parts) = parse_url(resource) else {
        return false;
    };
    let Some(scheme) = parts.scheme else {
        return false;
    };
    let scheme = strtolower(&scheme);
    (scheme.as_ref() == b"http" || scheme.as_ref() == b"https")
        && parts.fragment.is_none()
        // `!empty($parts['host'])`: "" and "0" are empty.
        && parts.host.is_some_and(|h| !h.is_empty() && h != b"0")
}

impl ResourceIndicators {
    /// The private constructor's validation of a normalised list.
    fn new(resources: Vec<Value>) -> Result<Self, Error> {
        let mut seen: Vec<Vec<u8>> = Vec::with_capacity(resources.len());
        for resource in resources {
            let Value::Str(resource) = resource else {
                return Err(invalid("resource must be a non-empty absolute URI."));
            };
            if resource.is_empty() {
                return Err(invalid("resource must be a non-empty absolute URI."));
            }
            if !is_valid(&resource) {
                return Err(invalid("resource must be an absolute HTTP(S) URI with no fragment component."));
            }
            if seen.iter().any(|s| s[..] == resource[..]) {
                return Err(invalid("resources must not contain duplicates."));
            }
            seen.push(resource.to_vec());
        }
        Ok(Self(seen))
    }

    /// Parses the `resource` parameter, with an optional legacy `audience`.
    ///
    /// Duplicates collapse. The `audience` must be one of the resources, or
    /// is the only one when there are none. Fails with
    /// [`Error::InvalidResource`] when a value is not an absolute http(s) URI
    /// without a fragment, or the audience is not among the resources.
    ///
    /// PHP: `ResourceIndicators::from($value, $audience)`.
    pub fn from(value: Resources<'_>, audience: Option<&[u8]>) -> Result<Self, Error> {
        let values: Vec<Value> = match value {
            Resources::None | Resources::One(b"") => vec![],
            Resources::One(s) => vec![Value::Str(Str::copy_from(s))],
            Resources::Many(list) => list.iter().map(|(_, v)| v.clone()).collect(),
        };
        let mut normalized: Vec<Value> = Vec::with_capacity(values.len());
        for v in values {
            if !normalized.iter().any(|n| identical(n, &v)) {
                normalized.push(v);
            }
        }
        let resources = Self::new(normalized)?;
        let Some(audience) = audience.filter(|a| !a.is_empty()) else {
            return Ok(resources);
        };
        let audience = Self::new(vec![Value::Str(Str::copy_from(audience))])?;
        if resources.0.is_empty() {
            return Ok(audience);
        }
        if !audience.is_subset_of(&resources) {
            return Err(invalid("audience must match one of the resource values when both parameters are provided."));
        }
        Ok(resources)
    }

    /// Whether every resource is among `granted`.
    ///
    /// PHP: `isSubsetOf($granted)`.
    pub fn is_subset_of(&self, granted: &ResourceIndicators) -> bool {
        self.0.iter().all(|r| granted.0.contains(r))
    }

    /// Whether both hold the same resources, in any order.
    ///
    /// PHP: `equals($resources)`.
    pub fn equals(&self, other: &ResourceIndicators) -> bool {
        let (mut left, mut right) = (self.0.clone(), other.0.clone());
        left.sort();
        right.sort();
        left == right
    }

    /// The token audience: the resources, or `default` when there are none.
    ///
    /// PHP: `audience($defaultAudience)`.
    pub fn audience(&self, default: &[u8]) -> Vec<Vec<u8>> {
        if self.0.is_empty() { vec![default.to_vec()] } else { self.0.clone() }
    }

    /// The resources, in request order.
    ///
    /// PHP: `toArray()`.
    pub fn to_vec(&self) -> &[Vec<u8>] {
        &self.0
    }
}

/// `===` for the values a request carries.
fn identical(a: &Value, b: &Value) -> bool {
    match (a, b) {
        (Value::Float(x), Value::Float(y)) => x == y,
        _ => a == b,
    }
}
