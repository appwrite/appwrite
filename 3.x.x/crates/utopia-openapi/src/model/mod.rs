//! Canonical OpenAPI model types (PHP `Utopia\OpenAPI\Model`).

use crate::json::Json;
use indexmap::IndexMap;
use std::ops::Deref;

/// HTTP method names as they appear on Path Item Objects.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum HttpMethod {
    Get,
    Post,
    Put,
    Patch,
    Delete,
    Head,
    Options,
    Trace,
}

impl HttpMethod {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Get => "get",
            Self::Post => "post",
            Self::Put => "put",
            Self::Patch => "patch",
            Self::Delete => "delete",
            Self::Head => "head",
            Self::Options => "options",
            Self::Trace => "trace",
        }
    }

    pub fn cases() -> &'static [Self] {
        &[
            Self::Get,
            Self::Post,
            Self::Put,
            Self::Patch,
            Self::Delete,
            Self::Head,
            Self::Options,
            Self::Trace,
        ]
    }
}

/// Parameter / API-key location.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum ParameterLocation {
    Path,
    Query,
    Header,
    Cookie,
}

impl ParameterLocation {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Path => "path",
            Self::Query => "query",
            Self::Header => "header",
            Self::Cookie => "cookie",
        }
    }

    pub fn from_str_php(value: &str) -> Option<Self> {
        match value {
            "path" => Some(Self::Path),
            "query" => Some(Self::Query),
            "header" => Some(Self::Header),
            "cookie" => Some(Self::Cookie),
            _ => None,
        }
    }
}

/// Security scheme type.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum SecuritySchemeType {
    ApiKey,
    Http,
    Oauth2,
    OpenIdConnect,
    MutualTls,
}

impl SecuritySchemeType {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::ApiKey => "apiKey",
            Self::Http => "http",
            Self::Oauth2 => "oauth2",
            Self::OpenIdConnect => "openIdConnect",
            Self::MutualTls => "mutualTLS",
        }
    }
}

/// Schema composition keyword.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Composition {
    OneOf,
    AnyOf,
    AllOf,
}

impl Composition {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::OneOf => "oneOf",
            Self::AnyOf => "anyOf",
            Self::AllOf => "allOf",
        }
    }
}

/// Fields shared by every schema variant (PHP `Schema` base).
#[derive(Clone, Debug, PartialEq, Default)]
pub struct SchemaMeta {
    pub title: Option<String>,
    pub description: String,
    /// PHP field `enum`.
    pub enum_values: Vec<Json>,
    pub nullable: bool,
    pub default: Json,
    pub format: Option<String>,
    pub read_only: bool,
    pub write_only: bool,
    pub deprecated: bool,
    pub example: Json,
    pub extensions: IndexMap<String, Json>,
}

/// `additionalProperties` as bool or nested schema.
#[derive(Clone, Debug, PartialEq)]
pub enum AdditionalProperties {
    Boolean(bool),
    Schema(Box<Schema>),
}

/// Canonical schema tree.
#[derive(Clone, Debug, PartialEq)]
pub enum Schema {
    Any(AnySchema),
    Never(NeverSchema),
    String(StringSchema),
    Integer(IntegerSchema),
    Number(NumberSchema),
    Boolean(BooleanSchema),
    Array(Box<ArraySchema>),
    Object(Box<ObjectSchema>),
    Composite(Box<CompositeSchema>),
    Reference(ReferenceSchema),
}

impl Schema {
    pub fn meta(&self) -> &SchemaMeta {
        match self {
            Self::Any(s) => &s.meta,
            Self::Never(s) => &s.meta,
            Self::String(s) => &s.meta,
            Self::Integer(s) => &s.meta,
            Self::Number(s) => &s.meta,
            Self::Boolean(s) => &s.meta,
            Self::Array(s) => &s.meta,
            Self::Object(s) => &s.meta,
            Self::Composite(s) => &s.meta,
            Self::Reference(s) => &s.meta,
        }
    }

    pub fn nullable(&self) -> bool {
        self.meta().nullable
    }

    pub fn extensions(&self) -> &IndexMap<String, Json> {
        &self.meta().extensions
    }

    pub fn enum_values(&self) -> &[Json] {
        &self.meta().enum_values
    }

    pub fn as_object(&self) -> Option<&ObjectSchema> {
        match self {
            Self::Object(s) => Some(s),
            _ => None,
        }
    }

    pub fn as_string(&self) -> Option<&StringSchema> {
        match self {
            Self::String(s) => Some(s),
            _ => None,
        }
    }

    pub fn as_integer(&self) -> Option<&IntegerSchema> {
        match self {
            Self::Integer(s) => Some(s),
            _ => None,
        }
    }

    pub fn as_array(&self) -> Option<&ArraySchema> {
        match self {
            Self::Array(s) => Some(s),
            _ => None,
        }
    }

    pub fn as_composite(&self) -> Option<&CompositeSchema> {
        match self {
            Self::Composite(s) => Some(s),
            _ => None,
        }
    }

    pub fn as_reference(&self) -> Option<&ReferenceSchema> {
        match self {
            Self::Reference(s) => Some(s),
            _ => None,
        }
    }
}

#[derive(Clone, Debug, PartialEq, Default)]
pub struct AnySchema {
    pub meta: SchemaMeta,
}

#[derive(Clone, Debug, PartialEq, Default)]
pub struct NeverSchema {
    pub meta: SchemaMeta,
}

#[derive(Clone, Debug, PartialEq, Default)]
pub struct BooleanSchema {
    pub meta: SchemaMeta,
}

#[derive(Clone, Debug, PartialEq, Default)]
pub struct StringSchema {
    pub meta: SchemaMeta,
    pub min_length: Option<i64>,
    pub max_length: Option<i64>,
    pub pattern: Option<String>,
}

impl Deref for StringSchema {
    type Target = SchemaMeta;
    fn deref(&self) -> &Self::Target {
        &self.meta
    }
}

#[derive(Clone, Debug, PartialEq, Default)]
pub struct IntegerSchema {
    pub meta: SchemaMeta,
    pub minimum: Option<JsonNumberOrInt>,
    pub maximum: Option<JsonNumberOrInt>,
    pub exclusive_minimum: bool,
    pub exclusive_maximum: bool,
    pub multiple_of: Option<JsonNumberOrInt>,
}

impl Deref for IntegerSchema {
    type Target = SchemaMeta;
    fn deref(&self) -> &Self::Target {
        &self.meta
    }
}

#[derive(Clone, Debug, PartialEq)]
pub struct NumberSchema {
    pub meta: SchemaMeta,
    pub minimum: Option<JsonNumberOrInt>,
    pub maximum: Option<JsonNumberOrInt>,
    pub exclusive_minimum: bool,
    pub exclusive_maximum: bool,
    pub multiple_of: Option<JsonNumberOrInt>,
}

/// Numeric bound that may be int or float (PHP `int|float`).
#[derive(Clone, Debug, PartialEq)]
pub enum JsonNumberOrInt {
    Int(i64),
    Float(f64),
}

impl JsonNumberOrInt {
    pub fn as_i64(&self) -> Option<i64> {
        match self {
            Self::Int(v) => Some(*v),
            Self::Float(v) if v.fract() == 0.0 => Some(*v as i64),
            Self::Float(_) => None,
        }
    }
}

#[derive(Clone, Debug, PartialEq)]
pub struct ArraySchema {
    pub meta: SchemaMeta,
    pub items: Schema,
    pub min_items: Option<i64>,
    pub max_items: Option<i64>,
    pub unique_items: bool,
}

#[derive(Clone, Debug, PartialEq)]
pub struct ObjectSchema {
    pub meta: SchemaMeta,
    pub properties: IndexMap<String, Schema>,
    pub required: Vec<String>,
    pub additional_properties: Option<AdditionalProperties>,
    pub min_properties: Option<i64>,
    pub max_properties: Option<i64>,
}

impl Deref for ObjectSchema {
    type Target = SchemaMeta;
    fn deref(&self) -> &Self::Target {
        &self.meta
    }
}

#[derive(Clone, Debug, PartialEq)]
pub struct CompositeSchema {
    pub meta: SchemaMeta,
    pub composition: Option<Composition>,
    pub schemas: Vec<Schema>,
    pub not: Option<Schema>,
    pub discriminator: Option<Discriminator>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct ReferenceSchema {
    pub meta: SchemaMeta,
    pub reference: String,
}

impl Deref for ReferenceSchema {
    type Target = SchemaMeta;
    fn deref(&self) -> &Self::Target {
        &self.meta
    }
}

#[derive(Clone, Debug, PartialEq)]
pub struct Discriminator {
    pub property_name: String,
    pub mapping: IndexMap<String, String>,
    pub extensions: IndexMap<String, Json>,
}

impl Discriminator {
    pub fn new(property_name: impl Into<String>) -> Self {
        Self {
            property_name: property_name.into(),
            mapping: IndexMap::new(),
            extensions: IndexMap::new(),
        }
    }
}

#[derive(Clone, Debug, PartialEq)]
pub struct Contact {
    pub name: String,
    pub url: Option<String>,
    pub email: Option<String>,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct License {
    pub name: String,
    pub url: Option<String>,
    pub identifier: Option<String>,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct Info {
    pub title: String,
    pub description: String,
    pub version: String,
    pub terms_of_service: Option<String>,
    pub contact: Option<Contact>,
    pub license: Option<License>,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct ServerVariable {
    pub default: String,
    pub enum_values: Vec<String>,
    pub description: String,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct Server {
    pub url: String,
    pub description: String,
    pub variables: IndexMap<String, ServerVariable>,
    pub extensions: IndexMap<String, Json>,
}

impl Server {
    pub fn new(url: impl Into<String>) -> Self {
        Self {
            url: url.into(),
            description: String::new(),
            variables: IndexMap::new(),
            extensions: IndexMap::new(),
        }
    }
}

#[derive(Clone, Debug, PartialEq)]
pub struct ExternalDocumentation {
    pub url: String,
    pub description: String,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct Tag {
    pub name: String,
    pub description: String,
    pub external_documentation: Option<ExternalDocumentation>,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq, Default)]
pub struct Example {
    pub summary: String,
    pub description: String,
    pub value: Json,
    pub external_value: Option<String>,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq, Default)]
pub struct Encoding {
    pub content_type: Option<String>,
    pub headers: IndexMap<String, Header>,
    pub style: Option<String>,
    pub explode: Option<bool>,
    pub allow_reserved: bool,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct MediaType {
    pub schema: Option<Schema>,
    pub example: Json,
    pub examples: IndexMap<String, Example>,
    pub encoding: IndexMap<String, Encoding>,
    pub extensions: IndexMap<String, Json>,
}

impl MediaType {
    pub fn new(schema: Option<Schema>) -> Self {
        Self {
            schema,
            example: Json::Null,
            examples: IndexMap::new(),
            encoding: IndexMap::new(),
            extensions: IndexMap::new(),
        }
    }
}

#[derive(Clone, Debug, PartialEq)]
pub struct Header {
    pub description: String,
    pub required: bool,
    pub deprecated: bool,
    pub schema: Option<Schema>,
    pub content: IndexMap<String, MediaType>,
    pub style: Option<String>,
    pub explode: Option<bool>,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct Parameter {
    pub name: String,
    pub location: ParameterLocation,
    pub description: String,
    pub required: bool,
    pub deprecated: bool,
    pub allow_empty_value: bool,
    pub schema: Option<Schema>,
    pub content: IndexMap<String, MediaType>,
    pub style: Option<String>,
    pub explode: Option<bool>,
    pub allow_reserved: bool,
    pub extensions: IndexMap<String, Json>,
}

impl Parameter {
    pub fn identity(&self) -> String {
        format!("{}\0{}", self.location.as_str(), self.name)
    }
}

#[derive(Clone, Debug, PartialEq)]
pub struct RequestBody {
    pub description: String,
    pub required: bool,
    pub content: IndexMap<String, MediaType>,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct Response {
    pub description: String,
    pub headers: IndexMap<String, Header>,
    pub content: IndexMap<String, MediaType>,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct Operation {
    pub id: String,
    pub method: HttpMethod,
    pub path: String,
    pub tags: Vec<String>,
    pub summary: String,
    pub description: String,
    pub deprecated: bool,
    pub parameters: Vec<Parameter>,
    pub request_body: Option<RequestBody>,
    pub responses: IndexMap<String, Response>,
    pub security: Vec<SecurityRequirement>,
    pub servers: Vec<Server>,
    pub external_documentation: Option<ExternalDocumentation>,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct PathItem {
    pub path: String,
    pub operations: IndexMap<String, Operation>,
    pub parameters: Vec<Parameter>,
    pub summary: String,
    pub description: String,
    pub servers: Vec<Server>,
    pub extensions: IndexMap<String, Json>,
}

impl PathItem {
    pub fn operation(&self, method: HttpMethod) -> Option<&Operation> {
        self.operations.get(method.as_str())
    }
}

#[derive(Clone, Debug, PartialEq)]
pub struct OAuthFlow {
    pub authorization_url: Option<String>,
    pub token_url: Option<String>,
    pub refresh_url: Option<String>,
    pub scopes: IndexMap<String, String>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct SecurityScheme {
    pub type_: SecuritySchemeType,
    pub description: String,
    pub name: Option<String>,
    pub location: Option<ParameterLocation>,
    pub scheme: Option<String>,
    pub bearer_format: Option<String>,
    pub flows: IndexMap<String, OAuthFlow>,
    pub open_id_connect_url: Option<String>,
    pub extensions: IndexMap<String, Json>,
}

#[derive(Clone, Debug, PartialEq)]
pub struct SecurityRequirement {
    pub schemes: IndexMap<String, Vec<String>>,
}

impl SecurityRequirement {
    pub fn new(schemes: IndexMap<String, Vec<String>>) -> Self {
        Self { schemes }
    }
}
