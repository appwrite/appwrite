//! OpenAPI 2.0, 3.0 and 3.1 documents read into one version-independent,
//! immutable model.
//!
//! [`parse`] decodes a JSON document, detects its version from `swagger` or
//! `openapi`, and reads it into a [`Specification`]: servers, tags, paths
//! and their [`Operation`]s, named [`Schema`]s, security schemes. A 2.0
//! document and a 3.1 document describing the same service read into the
//! same shape: 2.0's `host` and `basePath` become servers, `body` and
//! `formData` parameters a request body, `definitions` the schemas.
//!
//! Object references (`$ref` to parameters, responses, examples, security
//! schemes, path items) are followed while reading, inside the document
//! only ([`LocalResolver`]); schema references are kept as
//! [`SchemaKind::Reference`], since recursive schemas are legal, and
//! [`Specification::resolve_schema`] follows them on request.
//!
//! Free-form values (`default`, `example`, `enum`, extensions) are PHP
//! values ([`Value`], [`Array`] from `php-std`) exactly as decoded, so
//! integers stay integers, floats floats, and key order is kept.
//!
//! PHP: ports `utopia-php/openapi`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Parser::parse($json)`, `(new Parser())->read($json)` | [`parse`] |
//! | `Parser::parse($array)`, `(new Parser())->read($array)` | [`parse_document`] |
//! | the `json_decode` step of `Parser::read()` | [`decode`], [`decode_document`] |
//! | `Specification` | [`Specification`] |
//! | `Version` | [`Version`] |
//! | `Model\*` (other than schemas) | [`model`]: [`Info`], [`PathItem`], [`Operation`], [`Parameter`], ... |
//! | `Model\Schema` and its subclasses, `Composition`, `Discriminator` | [`schema`]: [`Schema`], [`SchemaKind`], ... |
//! | `Parser\Reader`, `OpenAPI2`, `OpenAPI3`, `OpenAPI30`, `OpenAPI31` | [`Reader`], [`OpenApi2`], [`OpenApi3`] |
//! | `Parser\Schema\Reader`, `Parser\Schema\Dialect` | [`SchemaReader`], [`Dialect`] |
//! | `Parser\Value` | [`shape`] |
//! | `Reference\*` | [`Reference`], [`ResolutionContext`], [`Resolver`], [`LocalResolver`] |
//! | `Exception\*` | [`Error`] |
//!
//! ```
//! use utopia_openapi::{HttpMethod, SchemaKind, Version, parse};
//!
//! let spec = parse(br##"{
//!     "openapi": "3.1.0",
//!     "info": {"title": "Pets", "version": "1.0.0"},
//!     "paths": {"/pets/{id}": {"get": {
//!         "operationId": "getPet",
//!         "parameters": [{"name": "id", "in": "path", "required": true, "schema": {"type": "string"}}],
//!         "responses": {"200": {"description": "OK", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/Pet"}}}}}
//!     }}},
//!     "components": {"schemas": {"Pet": {"type": "object", "properties": {"name": {"type": ["string", "null"]}}}}}
//! }"##, None)?;
//!
//! assert_eq!(spec.version, Version::V3_1);
//! let get = spec.paths["/pets/{id}"].operation(HttpMethod::Get).unwrap();
//! assert_eq!(get.id, "getPet");
//! let schema = get.responses["200"].content["application/json"].schema.as_ref().unwrap();
//! assert_eq!(schema.reference(), Some("#/components/schemas/Pet"));
//! let pet = spec.resolve_schema(schema).as_object().unwrap();
//! assert!(pet.properties["name"].nullable);
//! assert!(matches!(pet.properties["name"].kind, SchemaKind::String(_)));
//! # Ok::<(), utopia_openapi::Error>(())
//! ```
//!
//! ```php
//! use Utopia\OpenAPI\Model\HttpMethod;
//! use Utopia\OpenAPI\Parser;
//! use Utopia\OpenAPI\Version;
//!
//! $spec = Parser::parse(json_encode([
//!     'openapi' => '3.1.0',
//!     'info' => ['title' => 'Pets', 'version' => '1.0.0'],
//!     'paths' => ['/pets/{id}' => ['get' => [
//!         'operationId' => 'getPet',
//!         'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
//!         'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]]]],
//!     ]]],
//!     'components' => ['schemas' => ['Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => ['string', 'null']]]]]],
//! ]));
//!
//! var_dump($spec->version === Version::V3_1);                         // bool(true)
//! $get = $spec->paths['/pets/{id}']->operation(HttpMethod::GET);
//! echo $get->id, "\n";                                                // getPet
//! $schema = $get->responses['200']->content['application/json']->schema;
//! echo $schema->reference, "\n";                                      // #/components/schemas/Pet
//! var_dump($spec->resolveSchema($schema)->properties['name']->nullable); // bool(true)
//! ```

#![warn(missing_docs)]

pub mod model;
mod reader;
mod reference;
pub mod schema;
pub mod shape;
mod specification;
mod version;

use php_std::json::{self, Flags};
use php_std::types::EngineError;
pub use php_std::{Array, Value};

pub use model::{
    Contact, Encoding, Example, ExternalDocumentation, Header, HttpMethod, Info, License, MediaType, OAuthFlow,
    Operation, Parameter, ParameterLocation, PathItem, RequestBody, Response, SecurityRequirement, SecurityScheme,
    SecuritySchemeType, Server, ServerVariable, Tag,
};
pub use reader::{Dialect, OpenApi2, OpenApi3, Reader, SchemaReader};
pub use reference::{LocalResolver, Reference, ResolutionContext, Resolver};
pub use schema::{
    AdditionalProperties, Annotations, ArraySchema, CompositeSchema, Composition, Condition, ConditionalReference,
    Discriminator, NumericSchema, ObjectSchema, Schema, SchemaKind, StringSchema,
};
pub use specification::Specification;
pub use version::Version;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// Why a document could not be read.
///
/// PHP: the exceptions of `Utopia\OpenAPI\Exception`, all of them
/// `ParseException`s (and `OpenAPIException`s); one variant per class, with
/// the same message.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// The input is not valid JSON (`Invalid JSON: Syntax error`).
    ///
    /// PHP: `Utopia\OpenAPI\Exception\ParseException`.
    #[error("{0}")]
    Parse(String),
    /// The document is not a valid OpenAPI document; the message names the
    /// location (`Expected string #/info/title`).
    ///
    /// PHP: `Utopia\OpenAPI\Exception\InvalidSpecification`.
    #[error("{0}")]
    InvalidSpecification(String),
    /// The document declares a version this library does not read.
    ///
    /// PHP: `Utopia\OpenAPI\Exception\UnsupportedVersion`.
    #[error("{0}")]
    UnsupportedVersion(String),
    /// A reference points nowhere, or outside the document.
    ///
    /// PHP: `Utopia\OpenAPI\Exception\ReferenceNotFound`.
    #[error("{0}")]
    ReferenceNotFound(String),
    /// A chain of references comes back to itself.
    ///
    /// PHP: `Utopia\OpenAPI\Exception\CircularReference`.
    #[error("{0}")]
    CircularReference(String),
    /// A value PHP cannot cast to a string where the reader casts one (an
    /// empty object, `stdClass`, in a decoded document given as an array).
    ///
    /// PHP: the engine's `Error` (`Object of class stdClass could not be
    /// converted to string`).
    #[error("{0}")]
    Engine(EngineError),
}

impl Error {
    /// The name of the PHP exception class this error matches.
    ///
    /// PHP: the class `utopia-php/openapi` (or the engine) throws.
    ///
    /// ```
    /// use utopia_openapi::Error;
    ///
    /// assert_eq!(Error::Parse("x".into()).php_class(), "Utopia\\OpenAPI\\Exception\\ParseException");
    /// ```
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Parse(_) => "Utopia\\OpenAPI\\Exception\\ParseException",
            Error::InvalidSpecification(_) => "Utopia\\OpenAPI\\Exception\\InvalidSpecification",
            Error::UnsupportedVersion(_) => "Utopia\\OpenAPI\\Exception\\UnsupportedVersion",
            Error::ReferenceNotFound(_) => "Utopia\\OpenAPI\\Exception\\ReferenceNotFound",
            Error::CircularReference(_) => "Utopia\\OpenAPI\\Exception\\CircularReference",
            Error::Engine(error) => error.class,
        }
    }
}

/// `PHP_JSON_PARSER_DEFAULT_DEPTH`, the depth `Parser::read()` decodes with.
const DEPTH: i64 = 512;

/// Whether any key of `value` starts with a NUL byte: `json_decode` into
/// objects refuses those property names, while decoding into arrays takes them.
fn has_nul_key(value: &Value) -> bool {
    match value {
        Value::Array(a) | Value::Object(a) => a
            .iter()
            .any(|(k, v)| matches!(k, php_std::types::KeyRef::Str(s) if s.first() == Some(&0)) || has_nul_key(v)),
        _ => false,
    }
}

/// `(array) $object`, recursively: a decoded `stdClass` becomes an array
/// with numeric property names as integer keys.
fn normalize(value: Value) -> Value {
    match value {
        Value::Object(properties) | Value::Array(properties) => Value::Array(
            properties
                .into_iter()
                .map(|(key, item)| {
                    let key = match key {
                        php_std::ArrayKey::Str(s) => php_std::ArrayKey::normalize(s),
                        key => key,
                    };
                    (key, normalize(item))
                })
                .collect(),
        ),
        other => other,
    }
}

/// Decodes JSON as the parser does: `json_decode` into objects, then every
/// object into an array (so `{}` is an empty array, and `{"0": "a"}` the
/// list `["a"]`). Invalid JSON is [`Error::Parse`] with PHP's message.
///
/// PHP: the `json_decode()` and normalization steps of `Parser::read()`.
///
/// ```
/// use utopia_openapi::{Value, decode};
///
/// assert!(matches!(decode(br#"{"a": 1}"#)?, Value::Array(_)));
/// assert_eq!(decode(b"{").unwrap_err().to_string(), "Invalid JSON: Syntax error");
/// # Ok::<(), utopia_openapi::Error>(())
/// ```
///
/// ```php
/// use Utopia\OpenAPI\Parser;
///
/// try {
///     Parser::parse('{');
/// } catch (\Utopia\OpenAPI\Exception\ParseException $e) {
///     echo $e->getMessage(), "\n"; // Invalid JSON: Syntax error
/// }
/// ```
pub fn decode(json: &[u8]) -> Result<Value, Error> {
    // Decoding into arrays gives the same values as decoding into objects and
    // casting them, except that it accepts NUL-prefixed keys: only then (or
    // on an error, to report the one PHP reports) decode into objects.
    if let Ok(value) = json::decode(json, Some(true), DEPTH, Flags::NONE)
        && !has_nul_key(&value)
    {
        return Ok(value);
    }
    match json::decode(json, Some(false), DEPTH, Flags::NONE) {
        Ok(value) => Ok(normalize(value)),
        Err(error) => Err(Error::Parse(format!("Invalid JSON: {}", error.message()))),
    }
}

fn root_error() -> Error {
    Error::InvalidSpecification("The OpenAPI document root must be an object".to_owned())
}

/// Decodes a JSON document ([`decode`]) whose root must be an object.
pub fn decode_document(json: &[u8]) -> Result<Array, Error> {
    match decode(json)? {
        Value::Array(document) if document.is_empty() || !document.is_list() => Ok(document),
        _ => Err(root_error()),
    }
}

/// Reads a JSON document. With `version`, the document must declare that
/// version ([`Error::InvalidSpecification`] otherwise).
///
/// PHP: `Parser::parse($json, $version)` and `(new Parser())->read($json, $version)`.
///
/// ```
/// use utopia_openapi::{Version, parse};
///
/// let json = br#"{"openapi": "3.0.0", "info": {"title": "x", "version": "1"}, "paths": {}}"#;
/// assert_eq!(parse(json, Some(Version::V3_0))?.source_version, "3.0.0");
/// let error = parse(json, Some(Version::V3_1)).unwrap_err();
/// assert_eq!(error.to_string(), "Expected OpenAPI 3.1, document declares 3.0.0");
/// # Ok::<(), utopia_openapi::Error>(())
/// ```
///
/// ```php
/// use Utopia\OpenAPI\Parser;
/// use Utopia\OpenAPI\Version;
///
/// $json = '{"openapi": "3.0.0", "info": {"title": "x", "version": "1"}, "paths": {}}';
/// echo Parser::parse($json, Version::V3_0)->sourceVersion, "\n"; // 3.0.0
/// try {
///     Parser::parse($json, Version::V3_1);
/// } catch (\Utopia\OpenAPI\Exception\InvalidSpecification $e) {
///     echo $e->getMessage(), "\n"; // Expected OpenAPI 3.1, document declares 3.0.0
/// }
/// ```
pub fn parse(json: impl AsRef<[u8]>, version: Option<Version>) -> Result<Specification, Error> {
    let document = decode_document(json.as_ref())?;
    read(&document, version)
}

/// Reads an already decoded document: an array whose root is an object
/// (objects inside may be arrays or `stdClass`).
///
/// PHP: `Parser::parse($array, $version)` and `(new Parser())->read($array, $version)`.
pub fn parse_document(document: &Array, version: Option<Version>) -> Result<Specification, Error> {
    if !document.is_empty() && document.is_list() {
        return Err(root_error());
    }
    read(document, version)
}

fn read(document: &Array, version: Option<Version>) -> Result<Specification, Error> {
    let (detected, source_version) = detect_version(document)?;
    if let Some(version) = version
        && version != detected
    {
        return Err(Error::InvalidSpecification(format!(
            "Expected OpenAPI {version}, document declares {source_version}"
        )));
    }
    let schemas = SchemaReader::for_version(detected);
    match detected {
        Version::V2 => OpenApi2::new(document, detected, source_version, schemas).read(),
        Version::V3_0 | Version::V3_1 => OpenApi3::new(document, detected, source_version, schemas).read(),
    }
}

/// The version a document declares in `swagger` (2.0) or `openapi` (3.x),
/// and the string itself.
fn detect_version(document: &Array) -> Result<(Version, String), Error> {
    for key in ["swagger", "openapi"] {
        if let Some(declared) = document.get_str(key) {
            let Value::Str(declared) = declared else {
                return Err(Error::InvalidSpecification(format!("The '{key}' version must be a string")));
            };
            let declared = shape::text(declared);
            return Ok((Version::from_document_version(&declared)?, declared));
        }
    }
    Err(Error::InvalidSpecification("Missing 'swagger' or 'openapi' version field".to_owned()))
}
