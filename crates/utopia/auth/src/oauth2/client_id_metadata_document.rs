use php_std::json::{self, Flags};
use php_std::zval::{Array, Key, Zval};

use super::{ClientIdentifierUrl, RedirectUris, parse_url};
use crate::Error;

/// Standard metadata whose values must be strings.
const STRING_PROPERTIES: [&str; 9] = [
    "client_name",
    "client_uri",
    "logo_uri",
    "policy_uri",
    "tos_uri",
    "jwks_uri",
    "scope",
    "software_id",
    "software_version",
];

/// JWK members carrying private or symmetric key material.
const PRIVATE_JWK_PARAMETERS: [&str; 8] = ["d", "dp", "dq", "k", "oth", "p", "q", "qi"];

/// A validated OAuth Client ID Metadata Document
/// (`Utopia\Auth\OAuth2\ClientIdMetadataDocument`). Unknown metadata is kept.
#[derive(Debug, Clone, PartialEq)]
pub struct ClientIdMetadataDocument {
    client_id: ClientIdentifierUrl,
    metadata: Array,
    token_endpoint_auth_method: Vec<u8>,
    grant_types: Vec<Vec<u8>>,
    response_types: Vec<Vec<u8>>,
    redirect_uris: RedirectUris,
}

fn invalid(message: impl Into<String>) -> Error {
    Error::InvalidClientMetadata(message.into())
}

fn key(name: &str) -> Key {
    Key::from_bytes(name.as_bytes())
}

/// `normalizeJsonValue()`: `stdClass` objects become arrays, recursively.
fn normalize(value: Zval) -> Zval {
    match value {
        Zval::Object(o) => Zval::Array(o.iter().map(|(k, v)| (Key::from_bytes(k), normalize(v.clone()))).collect()),
        Zval::Array(a) => Zval::Array(a.iter().map(|(k, v)| (k.clone(), normalize(v.clone()))).collect()),
        other => other,
    }
}

/// `stringList()`: a list of non-empty strings, or `default` when absent.
fn string_list(metadata: &Array, property: &str, default: &[&str]) -> Result<Vec<Vec<u8>>, Error> {
    let Some(values) = metadata.get(&key(property)) else {
        return Ok(default.iter().map(|s| s.as_bytes().to_vec()).collect());
    };
    let Zval::Array(values) = values else {
        return Err(invalid(format!("{property} must be a list of strings.")));
    };
    if !values.is_list() {
        return Err(invalid(format!("{property} must be a list of strings.")));
    }
    values
        .iter()
        .map(|(_, v)| match v {
            Zval::String(s) if !s.is_empty() => Ok(s.clone()),
            _ => Err(invalid(format!("{property} must contain non-empty strings."))),
        })
        .collect()
}

/// `validateRedirectUri()`.
fn validate_redirect_uri(uri: &[u8]) -> Result<(), Error> {
    let ok = parse_url(uri)
        .is_some_and(|p| p.scheme.is_some_and(|s| !s.is_empty() && s != b"0") && p.fragment.is_none());
    if !ok {
        return Err(invalid("redirect URIs must be absolute URIs without fragments."));
    }
    Ok(())
}

/// `validateJwks()`.
fn validate_jwks(jwks: &Zval) -> Result<(), Error> {
    let keys = match jwks {
        Zval::Array(set) => match set.get(&key("keys")) {
            Some(Zval::Array(keys)) if keys.is_list() => keys,
            _ => return Err(invalid("jwks must be a JSON Web Key Set object.")),
        },
        _ => return Err(invalid("jwks must be a JSON Web Key Set object.")),
    };
    for (_, jwk) in keys.iter() {
        let Zval::Array(jwk) = jwk else {
            return Err(invalid("jwks must contain JSON Web Key objects."));
        };
        if jwk.is_list() {
            return Err(invalid("jwks must contain JSON Web Key objects."));
        }
        if PRIVATE_JWK_PARAMETERS.iter().any(|p| jwk.get(&key(p)).is_some()) {
            return Err(invalid("jwks must not contain private or symmetric key material."));
        }
    }
    Ok(())
}

impl ClientIdMetadataDocument {
    /// `fromJson($clientId, $json)`.
    pub fn from_json(client_id: ClientIdentifierUrl, json: &[u8]) -> Result<Self, Error> {
        let decoded = json::decode(json, None, json::DEFAULT_DEPTH, Flags::THROW_ON_ERROR)
            .map_err(|_| invalid("Client ID Metadata Document is not valid JSON."))?;
        if !matches!(decoded, Zval::Object(_)) {
            return Err(invalid("Client ID Metadata Document must be a JSON object."));
        }
        let Zval::Array(metadata) = normalize(decoded) else {
            return Err(invalid("Client ID Metadata Document must be a JSON object."));
        };
        Self::from_array(client_id, metadata)
    }

    /// `fromArray($clientId, $metadata)`.
    pub fn from_array(client_id: ClientIdentifierUrl, metadata: Array) -> Result<Self, Error> {
        if metadata.get(&key("client_id")) != Some(&Zval::String(client_id.as_bytes().to_vec())) {
            return Err(invalid("client_id must exactly match the Client Identifier URL."));
        }
        for property in ["client_secret", "client_secret_expires_at"] {
            if metadata.get(&key(property)).is_some() {
                return Err(invalid(format!("Client ID Metadata Documents must not contain {property}.")));
            }
        }
        let method = match metadata.get(&key("token_endpoint_auth_method")) {
            Some(Zval::String(m)) if !m.is_empty() => m.clone(),
            _ => return Err(invalid("token_endpoint_auth_method must be explicitly declared.")),
        };
        if method.starts_with(b"client_secret_") {
            return Err(invalid("token_endpoint_auth_method must not use a shared symmetric secret."));
        }
        let grant_types = string_list(&metadata, "grant_types", &["authorization_code"])?;
        let response_types = string_list(&metadata, "response_types", &["code"])?;
        let redirect_uris = string_list(&metadata, "redirect_uris", &[])?;
        for uri in &redirect_uris {
            validate_redirect_uri(uri)?;
        }
        string_list(&metadata, "contacts", &[])?;
        for uri in string_list(&metadata, "post_logout_redirect_uris", &[])? {
            validate_redirect_uri(&uri)?;
        }
        for property in STRING_PROPERTIES {
            if let Some(v) = metadata.get(&key(property))
                && !matches!(v, Zval::String(_))
            {
                return Err(invalid(format!("{property} must be a string.")));
            }
        }
        let jwks = metadata.get(&key("jwks"));
        if jwks.is_some() && metadata.get(&key("jwks_uri")).is_some() {
            return Err(invalid("jwks and jwks_uri must not both be present."));
        }
        if let Some(jwks) = jwks {
            validate_jwks(jwks)?;
        }
        let redirect_uris = RedirectUris::from_strings(redirect_uris.iter().map(Vec::as_slice));
        Ok(Self {
            client_id,
            metadata,
            token_endpoint_auth_method: method,
            grant_types,
            response_types,
            redirect_uris,
        })
    }

    /// `clientId()`.
    pub fn client_id(&self) -> &ClientIdentifierUrl {
        &self.client_id
    }

    /// `tokenEndpointAuthMethod()`.
    pub fn token_endpoint_auth_method(&self) -> &[u8] {
        &self.token_endpoint_auth_method
    }

    /// `grantTypes()`.
    pub fn grant_types(&self) -> &[Vec<u8>] {
        &self.grant_types
    }

    /// `responseTypes()`.
    pub fn response_types(&self) -> &[Vec<u8>] {
        &self.response_types
    }

    /// `redirectUris()`.
    pub fn redirect_uris(&self) -> &RedirectUris {
        &self.redirect_uris
    }

    /// `get($property)`: the stored value, including `null` (`None` when absent).
    pub fn get(&self, property: &[u8]) -> Option<&Zval> {
        self.metadata.get(&Key::from_bytes(property))
    }

    /// `toArray()`.
    pub fn to_array(&self) -> &Array {
        &self.metadata
    }
}
