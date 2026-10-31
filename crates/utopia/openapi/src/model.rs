//! The canonical model, apart from schemas ([`crate::schema`]): what a
//! document describes, the same whichever OpenAPI version it was written in.
//!
//! Every map keeps the document's order. Free-form values (examples,
//! extensions) are PHP values ([`Value`], [`Array`]) exactly as decoded.

use indexmap::IndexMap;
use php_std::{Array, Value};

use crate::Schema;

/// A map keyed by names from the document, in document order.
pub type Map<T> = IndexMap<String, T>;

/// The `info` object.
///
/// PHP: `Utopia\OpenAPI\Model\Info`.
#[derive(Debug, Clone, PartialEq)]
pub struct Info {
    /// `title`.
    pub title: String,
    /// `description`, empty when missing.
    pub description: String,
    /// `version`: the version of the API, not of OpenAPI.
    pub version: String,
    /// `termsOfService`.
    pub terms_of_service: Option<String>,
    /// `contact`.
    pub contact: Option<Contact>,
    /// `license`.
    pub license: Option<License>,
    /// `x-` keys.
    pub extensions: Array,
}

/// The `info.contact` object.
///
/// PHP: `Utopia\OpenAPI\Model\Contact`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Contact {
    /// `name`, empty when missing.
    pub name: String,
    /// `url`.
    pub url: Option<String>,
    /// `email`.
    pub email: Option<String>,
    /// `x-` keys.
    pub extensions: Array,
}

/// The `info.license` object.
///
/// PHP: `Utopia\OpenAPI\Model\License`.
#[derive(Debug, Clone, PartialEq)]
pub struct License {
    /// `name`.
    pub name: String,
    /// `url`.
    pub url: Option<String>,
    /// `identifier` (an SPDX expression, OpenAPI 3.1).
    pub identifier: Option<String>,
    /// `x-` keys.
    pub extensions: Array,
}

/// A tag, with its documentation.
///
/// PHP: `Utopia\OpenAPI\Model\Tag`.
#[derive(Debug, Clone, PartialEq)]
pub struct Tag {
    /// `name`.
    pub name: String,
    /// `description`, empty when missing.
    pub description: String,
    /// `externalDocs`.
    pub external_documentation: Option<ExternalDocumentation>,
    /// `x-` keys.
    pub extensions: Array,
}

/// An `externalDocs` object.
///
/// PHP: `Utopia\OpenAPI\Model\ExternalDocumentation`.
#[derive(Debug, Clone, PartialEq)]
pub struct ExternalDocumentation {
    /// `url`.
    pub url: String,
    /// `description`, empty when missing.
    pub description: String,
    /// `x-` keys.
    pub extensions: Array,
}

/// A server the API is served from. OpenAPI 2.0's `host`, `basePath` and
/// `schemes` become one server per scheme.
///
/// PHP: `Utopia\OpenAPI\Model\Server`.
#[derive(Debug, Clone, PartialEq)]
pub struct Server {
    /// `url`, possibly with `{variable}` placeholders.
    pub url: String,
    /// `description`, empty when missing.
    pub description: String,
    /// `variables`.
    pub variables: Map<ServerVariable>,
    /// `x-` keys.
    pub extensions: Array,
}

impl Server {
    /// A server at `url`, with nothing else.
    pub fn new(url: impl Into<String>) -> Self {
        Server { url: url.into(), description: String::new(), variables: Map::new(), extensions: Array::new() }
    }
}

/// A placeholder of a server URL.
///
/// PHP: `Utopia\OpenAPI\Model\ServerVariable`.
#[derive(Debug, Clone, PartialEq)]
pub struct ServerVariable {
    /// `default`, cast to a string like PHP's `(string)`.
    pub default: String,
    /// `enum`, each value cast to a string.
    pub enum_values: Vec<String>,
    /// `description`, empty when missing.
    pub description: String,
    /// `x-` keys.
    pub extensions: Array,
}

/// An HTTP method an operation can use, in the order OpenAPI lists them.
///
/// PHP: `Utopia\OpenAPI\Model\HttpMethod`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum HttpMethod {
    /// `get`.
    Get,
    /// `post`.
    Post,
    /// `put`.
    Put,
    /// `patch`.
    Patch,
    /// `delete`.
    Delete,
    /// `head`.
    Head,
    /// `options`.
    Options,
    /// `trace`.
    Trace,
}

impl HttpMethod {
    /// Every method, in the order a path item is read.
    ///
    /// PHP: `HttpMethod::cases()`.
    pub const ALL: [HttpMethod; 8] = [
        HttpMethod::Get,
        HttpMethod::Post,
        HttpMethod::Put,
        HttpMethod::Patch,
        HttpMethod::Delete,
        HttpMethod::Head,
        HttpMethod::Options,
        HttpMethod::Trace,
    ];

    /// The lowercase key of the method in a path item (`get`).
    ///
    /// PHP: `$method->value`.
    pub fn as_str(self) -> &'static str {
        match self {
            HttpMethod::Get => "get",
            HttpMethod::Post => "post",
            HttpMethod::Put => "put",
            HttpMethod::Patch => "patch",
            HttpMethod::Delete => "delete",
            HttpMethod::Head => "head",
            HttpMethod::Options => "options",
            HttpMethod::Trace => "trace",
        }
    }

    /// The method a path item key names (`get`), if any.
    ///
    /// PHP: `HttpMethod::tryFrom($value)`.
    pub fn from_name(name: &str) -> Option<Self> {
        HttpMethod::ALL.into_iter().find(|m| m.as_str() == name)
    }
}

/// The operations available on one path.
///
/// PHP: `Utopia\OpenAPI\Model\PathItem`.
#[derive(Debug, Clone, PartialEq)]
pub struct PathItem {
    /// The path (`/pets/{id}`).
    pub path: String,
    /// The operations, in [`HttpMethod::ALL`] order.
    pub operations: Vec<Operation>,
    /// Parameters shared by every operation of the path.
    pub parameters: Vec<Parameter>,
    /// `summary`, empty when missing.
    pub summary: String,
    /// `description`, empty when missing.
    pub description: String,
    /// The servers of the path: its own, or the document's.
    pub servers: Vec<Server>,
    /// `x-` keys.
    pub extensions: Array,
}

impl PathItem {
    /// The operation for `method`, if the path has one.
    ///
    /// PHP: `PathItem::operation($method)`.
    pub fn operation(&self, method: HttpMethod) -> Option<&Operation> {
        self.operations.iter().find(|o| o.method == method)
    }
}

/// One operation: a method on a path.
///
/// PHP: `Utopia\OpenAPI\Model\Operation`.
#[derive(Debug, Clone, PartialEq)]
pub struct Operation {
    /// `operationId`, empty when missing.
    pub id: String,
    /// The method.
    pub method: HttpMethod,
    /// The path.
    pub path: String,
    /// `tags`.
    pub tags: Vec<String>,
    /// `summary`, empty when missing.
    pub summary: String,
    /// `description`, empty when missing.
    pub description: String,
    /// `deprecated`.
    pub deprecated: bool,
    /// The parameters: the path's, overridden by the operation's own (same name and location).
    pub parameters: Vec<Parameter>,
    /// The request body (OpenAPI 2.0's `body` or `formData` parameters become one).
    pub request_body: Option<RequestBody>,
    /// The responses by status code (`200`, `default`).
    pub responses: Map<Response>,
    /// The security alternatives: the operation's own, or the document's.
    pub security: Vec<SecurityRequirement>,
    /// The servers: the operation's own, or the path's.
    pub servers: Vec<Server>,
    /// `externalDocs`.
    pub external_documentation: Option<ExternalDocumentation>,
    /// `x-` keys.
    pub extensions: Array,
}

impl Operation {
    /// An operation with only an id, a method and a path.
    pub fn new(id: impl Into<String>, method: HttpMethod, path: impl Into<String>) -> Self {
        Operation {
            id: id.into(),
            method,
            path: path.into(),
            tags: Vec::new(),
            summary: String::new(),
            description: String::new(),
            deprecated: false,
            parameters: Vec::new(),
            request_body: None,
            responses: Map::new(),
            security: Vec::new(),
            servers: Vec::new(),
            external_documentation: None,
            extensions: Array::new(),
        }
    }

    /// The security scheme names any alternative mentions, first seen first.
    ///
    /// This is not a combined requirement: alternatives and their OAuth
    /// scopes stay separate in [`Operation::security`].
    ///
    /// PHP: `Operation::acceptedSecuritySchemeNames()`.
    ///
    /// ```
    /// use utopia_openapi::{HttpMethod, Operation, SecurityRequirement};
    ///
    /// let mut operation = Operation::new("get", HttpMethod::Get, "/pets");
    /// operation.security = vec![
    ///     SecurityRequirement::new([("Project", vec![])]),
    ///     SecurityRequirement::new([("Project", vec![]), ("Session", vec![])]),
    /// ];
    /// assert_eq!(operation.accepted_security_scheme_names(), ["Project", "Session"]);
    /// assert_eq!(operation.required_security_scheme_names(), ["Project"]);
    /// ```
    ///
    /// ```php
    /// use Utopia\OpenAPI\Model\HttpMethod;
    /// use Utopia\OpenAPI\Model\Operation;
    /// use Utopia\OpenAPI\Model\SecurityRequirement;
    ///
    /// $operation = new Operation(id: 'get', method: HttpMethod::GET, path: '/pets', security: [
    ///     new SecurityRequirement(['Project' => []]),
    ///     new SecurityRequirement(['Project' => [], 'Session' => []]),
    /// ]);
    /// var_dump($operation->acceptedSecuritySchemeNames()); // ['Project', 'Session']
    /// var_dump($operation->requiredSecuritySchemeNames()); // ['Project']
    /// ```
    pub fn accepted_security_scheme_names(&self) -> Vec<&str> {
        let mut names: Vec<&str> = Vec::new();
        for requirement in &self.security {
            for name in requirement.schemes.keys() {
                if !names.contains(&name.as_str()) {
                    names.push(name);
                }
            }
        }
        names
    }

    /// The security scheme names every alternative mentions, in the first
    /// alternative's order. None without alternatives or with an anonymous
    /// (empty) one. OAuth scopes may still differ between alternatives.
    ///
    /// PHP: `Operation::requiredSecuritySchemeNames()`.
    pub fn required_security_scheme_names(&self) -> Vec<&str> {
        let Some(first) = self.security.first() else { return Vec::new() };
        let mut names: Vec<&str> = first.schemes.keys().map(String::as_str).collect();
        for requirement in &self.security[1..] {
            names.retain(|name| requirement.schemes.contains_key(*name));
        }
        names
    }
}

/// Where a parameter is sent.
///
/// PHP: `Utopia\OpenAPI\Model\ParameterLocation`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum ParameterLocation {
    /// `path`.
    Path,
    /// `query`.
    Query,
    /// `header`.
    Header,
    /// `cookie`.
    Cookie,
}

impl ParameterLocation {
    /// The value of `in` (`path`).
    ///
    /// PHP: `$location->value`.
    pub fn as_str(self) -> &'static str {
        match self {
            ParameterLocation::Path => "path",
            ParameterLocation::Query => "query",
            ParameterLocation::Header => "header",
            ParameterLocation::Cookie => "cookie",
        }
    }

    /// The location an `in` value names, if any.
    ///
    /// PHP: `ParameterLocation::tryFrom($value)`.
    pub fn from_name(name: &[u8]) -> Option<Self> {
        Some(match name {
            b"path" => ParameterLocation::Path,
            b"query" => ParameterLocation::Query,
            b"header" => ParameterLocation::Header,
            b"cookie" => ParameterLocation::Cookie,
            _ => return None,
        })
    }
}

/// A parameter of an operation.
///
/// PHP: `Utopia\OpenAPI\Model\Parameter`.
#[derive(Debug, Clone, PartialEq)]
pub struct Parameter {
    /// `name`.
    pub name: String,
    /// `in`.
    pub location: ParameterLocation,
    /// `description`, empty when missing.
    pub description: String,
    /// `required` (always true for a path parameter).
    pub required: bool,
    /// `deprecated`.
    pub deprecated: bool,
    /// `allowEmptyValue`.
    pub allow_empty_value: bool,
    /// `schema` (OpenAPI 2.0: the schema keywords written on the parameter).
    pub schema: Option<Schema>,
    /// `content`.
    pub content: Map<MediaType>,
    /// `style`.
    pub style: Option<String>,
    /// `explode`.
    pub explode: Option<bool>,
    /// `allowReserved`.
    pub allow_reserved: bool,
    /// `x-` keys.
    pub extensions: Array,
}

impl Parameter {
    /// What makes a parameter unique in an operation: its location and name,
    /// joined by a NUL byte (`query\0limit`).
    ///
    /// PHP: `Parameter::identity()`.
    pub fn identity(&self) -> String {
        format!("{}\0{}", self.location.as_str(), self.name)
    }
}

/// The body of a request.
///
/// PHP: `Utopia\OpenAPI\Model\RequestBody`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct RequestBody {
    /// `description`, empty when missing.
    pub description: String,
    /// `required`.
    pub required: bool,
    /// The body by media type.
    pub content: Map<MediaType>,
    /// `x-` keys.
    pub extensions: Array,
}

/// One response of an operation.
///
/// PHP: `Utopia\OpenAPI\Model\Response`.
#[derive(Debug, Clone, PartialEq)]
pub struct Response {
    /// `description`.
    pub description: String,
    /// `headers`.
    pub headers: Map<Header>,
    /// The body by media type.
    pub content: Map<MediaType>,
    /// `x-` keys.
    pub extensions: Array,
}

/// A body in one media type.
///
/// PHP: `Utopia\OpenAPI\Model\MediaType`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct MediaType {
    /// `schema`.
    pub schema: Option<Schema>,
    /// `example`, `null` when missing.
    pub example: Value,
    /// `examples`.
    pub examples: Map<Example>,
    /// `encoding`, by property name.
    pub encoding: Map<Encoding>,
    /// `x-` keys.
    pub extensions: Array,
}

/// A named example.
///
/// PHP: `Utopia\OpenAPI\Model\Example`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Example {
    /// `summary`, empty when missing.
    pub summary: String,
    /// `description`, empty when missing.
    pub description: String,
    /// `value`, `null` when missing.
    pub value: Value,
    /// `externalValue`.
    pub external_value: Option<String>,
    /// `x-` keys.
    pub extensions: Array,
}

/// How one property of a multipart or form body is encoded.
///
/// PHP: `Utopia\OpenAPI\Model\Encoding`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Encoding {
    /// `contentType`.
    pub content_type: Option<String>,
    /// `headers`.
    pub headers: Map<Header>,
    /// `style`.
    pub style: Option<String>,
    /// `explode`.
    pub explode: Option<bool>,
    /// `allowReserved`.
    pub allow_reserved: bool,
    /// `x-` keys.
    pub extensions: Array,
}

/// A response or encoding header.
///
/// PHP: `Utopia\OpenAPI\Model\Header`.
#[derive(Debug, Clone, PartialEq)]
pub struct Header {
    /// `description`, empty when missing.
    pub description: String,
    /// `required`.
    pub required: bool,
    /// `deprecated`.
    pub deprecated: bool,
    /// `schema` (OpenAPI 2.0: the schema keywords written on the header).
    pub schema: Option<Schema>,
    /// `content`.
    pub content: Map<MediaType>,
    /// `style`.
    pub style: Option<String>,
    /// `explode`.
    pub explode: Option<bool>,
    /// `x-` keys.
    pub extensions: Array,
}

/// One security alternative: every scheme it names applies together, each
/// with its OAuth scopes.
///
/// PHP: `Utopia\OpenAPI\Model\SecurityRequirement`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct SecurityRequirement {
    /// Scheme name to scopes.
    pub schemes: Map<Vec<String>>,
}

impl SecurityRequirement {
    /// A requirement of these schemes, in order.
    pub fn new<N: Into<String>>(schemes: impl IntoIterator<Item = (N, Vec<String>)>) -> Self {
        SecurityRequirement { schemes: schemes.into_iter().map(|(name, scopes)| (name.into(), scopes)).collect() }
    }
}

/// The kind of a security scheme.
///
/// PHP: `Utopia\OpenAPI\Model\SecuritySchemeType`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum SecuritySchemeType {
    /// `apiKey`.
    ApiKey,
    /// `http` (OpenAPI 2.0's `basic` is `http` with scheme `basic`).
    Http,
    /// `oauth2`.
    OAuth2,
    /// `openIdConnect`.
    OpenIdConnect,
    /// `mutualTLS` (OpenAPI 3.1).
    MutualTls,
}

impl SecuritySchemeType {
    /// The value of `type` (`apiKey`).
    ///
    /// PHP: `$type->value`.
    pub fn as_str(self) -> &'static str {
        match self {
            SecuritySchemeType::ApiKey => "apiKey",
            SecuritySchemeType::Http => "http",
            SecuritySchemeType::OAuth2 => "oauth2",
            SecuritySchemeType::OpenIdConnect => "openIdConnect",
            SecuritySchemeType::MutualTls => "mutualTLS",
        }
    }
}

/// A security scheme.
///
/// PHP: `Utopia\OpenAPI\Model\SecurityScheme`.
#[derive(Debug, Clone, PartialEq)]
pub struct SecurityScheme {
    /// `type`.
    pub kind: SecuritySchemeType,
    /// `description`, empty when missing.
    pub description: String,
    /// `name` of the API key.
    pub name: Option<String>,
    /// `in` of the API key.
    pub location: Option<ParameterLocation>,
    /// `scheme` of HTTP authentication, lowercase.
    pub scheme: Option<String>,
    /// `bearerFormat`.
    pub bearer_format: Option<String>,
    /// OAuth flows by name (`authorizationCode`).
    pub flows: Map<OAuthFlow>,
    /// `openIdConnectUrl`.
    pub open_id_connect_url: Option<String>,
    /// `x-` keys.
    pub extensions: Array,
}

impl SecurityScheme {
    /// A scheme of `kind` with nothing else.
    pub fn new(kind: SecuritySchemeType) -> Self {
        SecurityScheme {
            kind,
            description: String::new(),
            name: None,
            location: None,
            scheme: None,
            bearer_format: None,
            flows: Map::new(),
            open_id_connect_url: None,
            extensions: Array::new(),
        }
    }
}

/// One OAuth flow.
///
/// PHP: `Utopia\OpenAPI\Model\OAuthFlow`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct OAuthFlow {
    /// `authorizationUrl`.
    pub authorization_url: Option<String>,
    /// `tokenUrl`.
    pub token_url: Option<String>,
    /// `refreshUrl`.
    pub refresh_url: Option<String>,
    /// Scope name to description.
    pub scopes: Map<String>,
}
