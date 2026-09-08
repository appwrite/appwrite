//! `OAuth2` authorization-server helper value objects.

use std::collections::HashSet;
use std::error::Error;
use std::fmt::{Display, Formatter};

use serde_json::{Map, Value};
use url::Url;

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct InvalidRequestUriException {
    message: String,
}

impl InvalidRequestUriException {
    pub const ERROR_CODE: &'static str = "invalid_request";

    fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
        }
    }
}

impl Display for InvalidRequestUriException {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.message)
    }
}

impl Error for InvalidRequestUriException {}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct InvalidResourceException {
    message: String,
}

impl InvalidResourceException {
    pub const ERROR_CODE: &'static str = "invalid_target";

    fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
        }
    }
}

impl Display for InvalidResourceException {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.message)
    }
}

impl Error for InvalidResourceException {}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct InvalidPromptException {
    message: String,
}

impl InvalidPromptException {
    pub const ERROR_CODE: &'static str = "invalid_request";

    fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
        }
    }
}

impl Display for InvalidPromptException {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.message)
    }
}

impl Error for InvalidPromptException {}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct InvalidClientMetadataException {
    message: String,
}

impl InvalidClientMetadataException {
    fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
        }
    }
}

impl Display for InvalidClientMetadataException {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.message)
    }
}

impl Error for InvalidClientMetadataException {}

/// Pushed Authorization Request `request_uri` value (RFC 9126).
#[allow(clippy::upper_case_acronyms)]
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct PAR {
    prefix: String,
    id: String,
}

impl PAR {
    /// Build a PAR value from a deployment-specific prefix and stored request id.
    pub fn from_id(
        prefix: impl Into<String>,
        id: impl Into<String>,
    ) -> Result<Self, InvalidRequestUriException> {
        let prefix = prefix.into();
        let id = id.into();
        if prefix.is_empty() || id.is_empty() {
            return Err(InvalidRequestUriException::new(
                "request_uri prefix and id must be non-empty strings.",
            ));
        }

        Ok(Self { prefix, id })
    }

    /// Parse a PAR value after validating its prefix.
    pub fn from_request_uri(
        prefix: impl Into<String>,
        request_uri: impl AsRef<str>,
    ) -> Result<Self, InvalidRequestUriException> {
        let prefix = prefix.into();
        let request_uri = request_uri.as_ref();
        if prefix.is_empty() || !request_uri.starts_with(&prefix) {
            return Err(InvalidRequestUriException::new("Invalid request_uri."));
        }

        let id = &request_uri[prefix.len()..];
        if id.is_empty() {
            return Err(InvalidRequestUriException::new("Invalid request_uri."));
        }

        Ok(Self {
            prefix,
            id: id.to_owned(),
        })
    }

    /// Return the stored request id.
    pub fn id(&self) -> &str {
        &self.id
    }

    /// Serialize the value to the RFC 9126 `request_uri` parameter format.
    pub fn request_uri(&self) -> String {
        format!("{}{}", self.prefix, self.id)
    }
}

/// `OpenID` Connect prompt values.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Prompt {
    None,
    Login,
    Consent,
    SelectAccount,
}

impl Prompt {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::None => "none",
            Self::Login => "login",
            Self::Consent => "consent",
            Self::SelectAccount => "select_account",
        }
    }

    fn from_str(value: &str) -> Option<Self> {
        match value {
            "none" => Some(Self::None),
            "login" => Some(Self::Login),
            "consent" => Some(Self::Consent),
            "select_account" => Some(Self::SelectAccount),
            _ => None,
        }
    }
}

/// Parsed `OpenID` Connect prompt values.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Prompts {
    prompts: Vec<Prompt>,
}

impl Prompts {
    /// Parse the OIDC prompt request parameter.
    pub fn from_string(prompt: &str) -> Result<Self, InvalidPromptException> {
        if prompt.is_empty() {
            return Ok(Self { prompts: vec![] });
        }

        let mut prompts = Vec::new();
        for value in prompt.split(' ').filter(|value| !value.is_empty()) {
            let prompt = Prompt::from_str(value).ok_or_else(|| {
                InvalidPromptException::new(format!("Invalid prompt value '{value}'."))
            })?;

            if !prompts.contains(&prompt) {
                prompts.push(prompt);
            }
        }

        if prompts.contains(&Prompt::None) && prompts.len() > 1 {
            return Err(InvalidPromptException::new(
                "prompt=none cannot be combined with other prompt values.",
            ));
        }

        Ok(Self { prompts })
    }

    /// Check whether a prompt value was requested.
    pub fn contains(&self, prompt: Prompt) -> bool {
        self.prompts.contains(&prompt)
    }

    /// Return prompt values for persistence or API boundaries.
    pub fn to_array(&self) -> Vec<&'static str> {
        self.prompts.iter().map(|prompt| prompt.as_str()).collect()
    }
}

impl Display for Prompts {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.to_array().join(" "))
    }
}

/// Registered redirect URIs for an `OAuth2` client.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RedirectUris {
    uris: Vec<String>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
struct LoopbackParts {
    host: String,
    path: String,
    query: String,
}

impl RedirectUris {
    /// Wrap stored registered URIs, ignoring empty entries.
    pub fn from<I, S>(uris: I) -> Self
    where
        I: IntoIterator<Item = S>,
        S: AsRef<str>,
    {
        let uris = uris
            .into_iter()
            .filter_map(|uri| {
                let uri = uri.as_ref();
                (!uri.is_empty()).then(|| uri.to_owned())
            })
            .collect();

        Self { uris }
    }

    /// True when a presented URI matches a registered URI.
    pub fn matches(&self, presented: &str, allow_loopback: bool) -> bool {
        if presented.is_empty() {
            return false;
        }

        if self.uris.iter().any(|registered| registered == presented) {
            return true;
        }

        if !allow_loopback {
            return false;
        }

        let Some(presented_parts) = loopback_parts(presented) else {
            return false;
        };

        self.uris
            .iter()
            .filter_map(|registered| loopback_parts(registered))
            .any(|registered_parts| registered_parts == presented_parts)
    }

    /// Stored redirect URIs.
    pub fn to_array(&self) -> &[String] {
        &self.uris
    }
}

/// Resource indicators requested by an `OAuth2` client (RFC 8707).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ResourceIndicators {
    resources: Vec<String>,
}

#[derive(Debug, Clone)]
pub enum ResourceInput {
    None,
    One(String),
    Many(Vec<Value>),
}

impl From<Option<&str>> for ResourceInput {
    fn from(value: Option<&str>) -> Self {
        value.map_or(Self::None, |value| Self::One(value.to_owned()))
    }
}

impl From<&str> for ResourceInput {
    fn from(value: &str) -> Self {
        Self::One(value.to_owned())
    }
}

impl From<Vec<&str>> for ResourceInput {
    fn from(value: Vec<&str>) -> Self {
        Self::Many(
            value
                .into_iter()
                .map(|resource| Value::String(resource.to_owned()))
                .collect(),
        )
    }
}

impl From<Vec<String>> for ResourceInput {
    fn from(value: Vec<String>) -> Self {
        Self::Many(value.into_iter().map(Value::String).collect())
    }
}

impl From<Vec<Value>> for ResourceInput {
    fn from(value: Vec<Value>) -> Self {
        Self::Many(value)
    }
}

impl ResourceIndicators {
    /// Parse and validate resource indicators plus an optional audience alias.
    pub fn from<T>(value: T, audience: Option<&str>) -> Result<Self, InvalidResourceException>
    where
        T: Into<ResourceInput>,
    {
        let resources = match value.into() {
            ResourceInput::None => Vec::new(),
            ResourceInput::One(value) if value.is_empty() => Vec::new(),
            ResourceInput::One(value) => vec![Value::String(value)],
            ResourceInput::Many(values) => values,
        };

        let mut normalized = Vec::new();
        for resource in resources {
            if !normalized.contains(&resource) {
                normalized.push(resource);
            }
        }

        let resources = Self::new(normalized)?;
        let Some(audience) = audience.filter(|audience| !audience.is_empty()) else {
            return Ok(resources);
        };

        let audience_resource = Self::new(vec![Value::String(audience.to_owned())])?;
        if resources.resources.is_empty() {
            return Ok(audience_resource);
        }
        if !audience_resource.is_subset_of(&resources) {
            return Err(InvalidResourceException::new(
                "audience must match one of the resource values when both parameters are provided.",
            ));
        }

        Ok(resources)
    }

    fn new(resources: Vec<Value>) -> Result<Self, InvalidResourceException> {
        let mut parsed = Vec::with_capacity(resources.len());
        let mut seen = HashSet::new();

        for resource in resources {
            let Some(resource) = resource.as_str() else {
                return Err(InvalidResourceException::new(
                    "resource must be a non-empty absolute URI.",
                ));
            };
            if resource.is_empty() {
                return Err(InvalidResourceException::new(
                    "resource must be a non-empty absolute URI.",
                ));
            }
            if !is_valid_resource(resource) {
                return Err(InvalidResourceException::new(
                    "resource must be an absolute HTTP(S) URI with no fragment component.",
                ));
            }
            if !seen.insert(resource.to_owned()) {
                return Err(InvalidResourceException::new(
                    "resources must not contain duplicates.",
                ));
            }

            parsed.push(resource.to_owned());
        }

        Ok(Self { resources: parsed })
    }

    /// Requested resources must be a subset of previously granted resources.
    pub fn is_subset_of(&self, granted: &Self) -> bool {
        self.resources
            .iter()
            .all(|resource| granted.resources.contains(resource))
    }

    /// Compare resource sets ignoring order.
    pub fn equals(&self, resources: &Self) -> bool {
        let mut left = self.resources.clone();
        let mut right = resources.resources.clone();
        left.sort();
        right.sort();
        left == right
    }

    /// Build the access-token audience list.
    pub fn audience(&self, default_audience: &str) -> Vec<String> {
        if self.resources.is_empty() {
            return vec![default_audience.to_owned()];
        }

        self.resources.clone()
    }

    /// Stored resource indicators.
    pub fn to_array(&self) -> &[String] {
        &self.resources
    }
}

/// Client Identifier URL from the OAuth Client ID Metadata Document specification.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ClientIdentifierUrl {
    value: String,
    host: String,
}

impl ClientIdentifierUrl {
    /// Detect values which should be handled as Client Identifier URLs.
    pub fn is_candidate(value: &str) -> bool {
        let value = value.to_ascii_lowercase();
        value.starts_with("https://") || value.starts_with("http://")
    }

    /// Parse and validate a Client Identifier URL.
    pub fn from_string(
        value: impl Into<String>,
        allow_http: bool,
    ) -> Result<Self, InvalidClientMetadataException> {
        let value = value.into();
        let missing_authority = value
            .split_once("://")
            .is_some_and(|(_, rest)| rest.starts_with('/'));
        if value.chars().any(char::is_whitespace) || missing_authority {
            return Err(InvalidClientMetadataException::new(
                "Client Identifier URL is malformed.",
            ));
        }

        let url = Url::parse(&value).map_err(|_| {
            InvalidClientMetadataException::new("Client Identifier URL is malformed.")
        })?;

        let scheme = url.scheme();
        if scheme != "https" && (!allow_http || scheme != "http") {
            return Err(InvalidClientMetadataException::new(
                "Client Identifier URL must use the https scheme.",
            ));
        }
        if !url.username().is_empty() || url.password().is_some() {
            return Err(InvalidClientMetadataException::new(
                "Client Identifier URL must not contain a userinfo component.",
            ));
        }
        if url.fragment().is_some() {
            return Err(InvalidClientMetadataException::new(
                "Client Identifier URL must not contain a fragment component.",
            ));
        }

        let host = url.host_str().unwrap_or_default();
        if host.is_empty() || !has_explicit_path(&value) {
            return Err(InvalidClientMetadataException::new(
                "Client Identifier URL must contain a host and a path component.",
            ));
        }
        if raw_path_segments(&value)
            .iter()
            .any(|segment| *segment == "." || *segment == "..")
        {
            return Err(InvalidClientMetadataException::new(
                "Client Identifier URL must not contain dot path segments.",
            ));
        }

        Ok(Self {
            value,
            host: host.to_owned(),
        })
    }

    pub fn to_string(&self) -> &str {
        &self.value
    }

    pub fn host(&self) -> &str {
        &self.host
    }
}

/// Validated OAuth Client ID Metadata Document.
#[derive(Debug, Clone, PartialEq)]
pub struct ClientIdMetadataDocument {
    client_id: ClientIdentifierUrl,
    metadata: Map<String, Value>,
    token_endpoint_auth_method: String,
    grant_types: Vec<String>,
    response_types: Vec<String>,
    redirect_uris: RedirectUris,
}

impl ClientIdMetadataDocument {
    const STRING_METADATA_PROPERTIES: &'static [&'static str] = &[
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

    const PRIVATE_JWK_PARAMETERS: &'static [&'static str] =
        &["d", "dp", "dq", "k", "oth", "p", "q", "qi"];

    /// Parse a JSON Client ID Metadata Document.
    pub fn from_json(
        client_id: ClientIdentifierUrl,
        json: &str,
    ) -> Result<Self, InvalidClientMetadataException> {
        let decoded: Value = serde_json::from_str(json).map_err(|_| {
            InvalidClientMetadataException::new("Client ID Metadata Document is not valid JSON.")
        })?;
        let Value::Object(metadata) = decoded else {
            return Err(InvalidClientMetadataException::new(
                "Client ID Metadata Document must be a JSON object.",
            ));
        };

        Self::from_array(client_id, metadata)
    }

    /// Validate an already decoded Client ID Metadata Document.
    pub fn from_array(
        client_id: ClientIdentifierUrl,
        metadata: Map<String, Value>,
    ) -> Result<Self, InvalidClientMetadataException> {
        if metadata.get("client_id").and_then(Value::as_str) != Some(client_id.to_string()) {
            return Err(InvalidClientMetadataException::new(
                "client_id must exactly match the Client Identifier URL.",
            ));
        }

        for property in ["client_secret", "client_secret_expires_at"] {
            if metadata.contains_key(property) {
                return Err(InvalidClientMetadataException::new(format!(
                    "Client ID Metadata Documents must not contain {property}."
                )));
            }
        }

        let token_endpoint_auth_method = metadata
            .get("token_endpoint_auth_method")
            .and_then(Value::as_str)
            .filter(|value| !value.is_empty())
            .ok_or_else(|| {
                InvalidClientMetadataException::new(
                    "token_endpoint_auth_method must be explicitly declared.",
                )
            })?;
        if token_endpoint_auth_method.starts_with("client_secret_") {
            return Err(InvalidClientMetadataException::new(
                "token_endpoint_auth_method must not use a shared symmetric secret.",
            ));
        }
        let token_endpoint_auth_method = token_endpoint_auth_method.to_owned();

        let grant_types = string_list(&metadata, "grant_types", &["authorization_code"])?;
        let response_types = string_list(&metadata, "response_types", &["code"])?;
        let redirect_uris = string_list(&metadata, "redirect_uris", &[])?;
        for redirect_uri in &redirect_uris {
            validate_redirect_uri(redirect_uri)?;
        }

        string_list(&metadata, "contacts", &[])?;
        let post_logout_redirect_uris = string_list(&metadata, "post_logout_redirect_uris", &[])?;
        for redirect_uri in &post_logout_redirect_uris {
            validate_redirect_uri(redirect_uri)?;
        }

        for property in Self::STRING_METADATA_PROPERTIES {
            if metadata.contains_key(*property) && !metadata[*property].is_string() {
                return Err(InvalidClientMetadataException::new(format!(
                    "{property} must be a string."
                )));
            }
        }

        if metadata.contains_key("jwks") && metadata.contains_key("jwks_uri") {
            return Err(InvalidClientMetadataException::new(
                "jwks and jwks_uri must not both be present.",
            ));
        }
        if let Some(jwks) = metadata.get("jwks") {
            validate_jwks(jwks)?;
        }

        Ok(Self {
            client_id,
            metadata,
            token_endpoint_auth_method,
            grant_types,
            response_types,
            redirect_uris: RedirectUris::from(redirect_uris.iter().map(String::as_str)),
        })
    }

    pub fn client_id(&self) -> &ClientIdentifierUrl {
        &self.client_id
    }

    pub fn token_endpoint_auth_method(&self) -> &str {
        &self.token_endpoint_auth_method
    }

    pub fn grant_types(&self) -> &[String] {
        &self.grant_types
    }

    pub fn response_types(&self) -> &[String] {
        &self.response_types
    }

    pub fn redirect_uris(&self) -> &RedirectUris {
        &self.redirect_uris
    }

    pub fn get(&self, property: &str) -> Option<&Value> {
        self.metadata.get(property)
    }

    pub fn to_array(&self) -> &Map<String, Value> {
        &self.metadata
    }
}

fn loopback_parts(uri: &str) -> Option<LoopbackParts> {
    let url = Url::parse(uri).ok()?;
    let host = url.host_str()?.to_ascii_lowercase();
    if url.scheme() != "http"
        || !matches!(host.as_str(), "localhost" | "127.0.0.1" | "::1" | "[::1]")
        || !url.username().is_empty()
        || url.password().is_some()
        || url.fragment().is_some()
    {
        return None;
    }

    Some(LoopbackParts {
        host,
        path: url.path().to_owned(),
        query: url.query().unwrap_or_default().to_owned(),
    })
}

fn is_valid_resource(resource: &str) -> bool {
    Url::parse(resource).is_ok_and(|url| {
        matches!(url.scheme(), "http" | "https")
            && url.fragment().is_none()
            && url.host_str().is_some_and(|host| !host.is_empty())
    })
}

fn has_explicit_path(value: &str) -> bool {
    let Some(after_scheme) = value.split_once("://").map(|(_, rest)| rest) else {
        return false;
    };
    let authority_end = after_scheme
        .find(['/', '?', '#'])
        .unwrap_or(after_scheme.len());
    after_scheme[authority_end..].starts_with('/')
}

fn raw_path_segments(value: &str) -> Vec<&str> {
    let Some(after_scheme) = value.split_once("://").map(|(_, rest)| rest) else {
        return Vec::new();
    };
    let Some(path_start) = after_scheme.find('/') else {
        return Vec::new();
    };
    let path_and_after = &after_scheme[path_start + 1..];
    let path_end = path_and_after
        .find(['?', '#'])
        .unwrap_or(path_and_after.len());
    path_and_after[..path_end].split('/').collect()
}

fn string_list(
    metadata: &Map<String, Value>,
    property: &str,
    default: &[&str],
) -> Result<Vec<String>, InvalidClientMetadataException> {
    let Some(values) = metadata.get(property) else {
        return Ok(default.iter().map(|value| (*value).to_owned()).collect());
    };
    let Some(values) = values.as_array() else {
        return Err(InvalidClientMetadataException::new(format!(
            "{property} must be a list of strings."
        )));
    };

    values
        .iter()
        .map(|value| {
            value
                .as_str()
                .filter(|value| !value.is_empty())
                .map(str::to_owned)
                .ok_or_else(|| {
                    InvalidClientMetadataException::new(format!(
                        "{property} must contain non-empty strings."
                    ))
                })
        })
        .collect()
}

fn validate_redirect_uri(uri: &str) -> Result<(), InvalidClientMetadataException> {
    if Url::parse(uri).map_or(true, |url| url.fragment().is_some()) {
        return Err(InvalidClientMetadataException::new(
            "redirect URIs must be absolute URIs without fragments.",
        ));
    }
    Ok(())
}

fn validate_jwks(jwks: &Value) -> Result<(), InvalidClientMetadataException> {
    let Some(jwks) = jwks.as_object() else {
        return Err(InvalidClientMetadataException::new(
            "jwks must be a JSON Web Key Set object.",
        ));
    };
    let Some(keys) = jwks.get("keys").and_then(Value::as_array) else {
        return Err(InvalidClientMetadataException::new(
            "jwks must be a JSON Web Key Set object.",
        ));
    };

    for jwk in keys {
        let Some(jwk) = jwk.as_object() else {
            return Err(InvalidClientMetadataException::new(
                "jwks must contain JSON Web Key objects.",
            ));
        };

        for parameter in ClientIdMetadataDocument::PRIVATE_JWK_PARAMETERS {
            if jwk.contains_key(*parameter) {
                return Err(InvalidClientMetadataException::new(
                    "jwks must not contain private or symmetric key material.",
                ));
            }
        }
    }

    Ok(())
}
