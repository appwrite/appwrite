//! Readers: one decoded document of one OpenAPI version into a
//! [`Specification`].
//!
//! [`OpenApi2`] reads 2.0 (Swagger) documents and [`OpenApi3`] 3.0 and 3.1
//! ones; both build the same canonical model. Schemas are read by a
//! [`SchemaReader`] following the [`Dialect`] of the version.
//!
//! PHP: `Utopia\OpenAPI\Parser\*`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Parser\Reader` | [`Reader`] |
//! | `Parser\AbstractReader` | the shared part of [`OpenApi2`] and [`OpenApi3`] |
//! | `Parser\OpenAPI2` | [`OpenApi2`] |
//! | `Parser\OpenAPI3`, `OpenAPI30`, `OpenAPI31` | [`OpenApi3`] (the minor version is its `version`) |
//! | `Parser\Schema\Reader` | [`SchemaReader`] |
//! | `Parser\Schema\Dialect` | [`Dialect`] |

mod openapi2;
mod openapi3;
mod schema;

use std::borrow::Cow;

use php_std::{Array, Value};

pub use openapi2::OpenApi2;
pub use openapi3::OpenApi3;
pub use schema::{Dialect, SchemaReader};

use crate::model::{
    Contact, Encoding, Example, ExternalDocumentation, Header, Info, License, Map, MediaType, OAuthFlow,
    ParameterLocation, SecurityRequirement, SecurityScheme, SecuritySchemeType, Server, ServerVariable, Tag,
};
use crate::shape::{self, Loc, key_text, text};
use crate::{Error, LocalResolver, Specification, Version};

/// Reads one document into a [`Specification`].
///
/// PHP: `Utopia\OpenAPI\Parser\Reader`.
pub trait Reader {
    /// The specification the document describes.
    ///
    /// PHP: `Reader::read()`.
    fn read(&self) -> Result<Specification, Error>;
}

/// `$value ?? []` for a value read from a document.
fn or_empty(value: Option<&Value>) -> Cow<'_, Value> {
    match value {
        None | Some(Value::Null) => Cow::Owned(Value::Array(Array::new())),
        Some(v) => Cow::Borrowed(v),
    }
}

/// `$value ?? null`.
fn or_null(value: Option<&Value>) -> Value {
    value.cloned().unwrap_or(Value::Null)
}

/// `isset($data[$key])`.
fn isset<'v>(data: &'v Array, key: &str) -> Option<&'v Value> {
    data.get_str(key).filter(|v| !matches!(v, Value::Null))
}

/// `(bool) ($data[$key] ?? false)`.
fn flag(data: &Array, key: &str) -> bool {
    data.get_str(key).is_some_and(Value::truthy)
}

/// `array_key_exists($key, $data) ? (bool) $data[$key] : null`.
fn optional_flag(data: &Array, key: &str) -> Option<bool> {
    data.get_str(key).map(Value::truthy)
}

fn optional(data: &Array, key: &str) -> Result<Option<String>, Error> {
    Ok(shape::optional_string(data, key)?.map(text))
}

fn optional_or_empty(data: &Array, key: &str) -> Result<String, Error> {
    Ok(optional(data, key)?.unwrap_or_default())
}

fn required(data: &Array, key: &str, location: &Loc<'_>) -> Result<String, Error> {
    Ok(text(shape::required_at(data, key, location)?))
}

/// `(string) $value`; a `stdClass` cannot be cast.
fn cast(value: &Value) -> Result<String, Error> {
    Ok(text(&value.to_php_string().map_err(Error::Engine)?))
}

/// `array_map(strval(...), Value::list($raw, $location))`.
fn strings(raw: &Value, location: &Loc<'_>) -> Result<Vec<String>, Error> {
    shape::list_at(raw, location)?.values().map(cast).collect()
}

/// What every version's reader shares (PHP's `AbstractReader`).
#[derive(Debug, Clone)]
pub(crate) struct Base<'a> {
    pub(crate) document: &'a Array,
    pub(crate) version: Version,
    pub(crate) source_version: String,
    pub(crate) schemas: SchemaReader,
    pub(crate) resolver: LocalResolver<'a>,
}

impl<'a> Base<'a> {
    pub(crate) fn new(document: &'a Array, version: Version, source_version: String, schemas: SchemaReader) -> Self {
        Base { document, version, source_version, schemas, resolver: LocalResolver::new(document) }
    }

    fn field(&self, key: &str) -> Option<&'a Value> {
        self.document.get_str(key)
    }

    pub(crate) fn info(&self) -> Result<Info, Error> {
        let location = Loc::Root("#/info");
        let null = Value::Null;
        let data = shape::object_at(self.field("info").unwrap_or(&null), &location)?;
        let contact = match isset(&data, "contact") {
            Some(raw) => {
                let value = shape::object_at(raw, &Loc::Root("#/info/contact"))?;
                Some(Contact {
                    name: optional_or_empty(&value, "name")?,
                    url: optional(&value, "url")?,
                    email: optional(&value, "email")?,
                    extensions: shape::extensions(&value),
                })
            }
            None => None,
        };
        let license = match isset(&data, "license") {
            Some(raw) => {
                let license_location = Loc::Root("#/info/license");
                let value = shape::object_at(raw, &license_location)?;
                Some(License {
                    name: required(&value, "name", &license_location)?,
                    url: optional(&value, "url")?,
                    identifier: optional(&value, "identifier")?,
                    extensions: shape::extensions(&value),
                })
            }
            None => None,
        };
        Ok(Info {
            title: required(&data, "title", &location)?,
            description: optional_or_empty(&data, "description")?,
            version: required(&data, "version", &location)?,
            terms_of_service: optional(&data, "termsOfService")?,
            contact,
            license,
            extensions: shape::extensions(&data),
        })
    }

    pub(crate) fn tags(&self) -> Result<Map<Tag>, Error> {
        let location = Loc::Root("#/tags");
        let raw = or_empty(self.field("tags"));
        let list = shape::list_at(&raw, &location)?;
        let mut tags = Map::with_capacity(list.len());
        for (index, raw) in list.values().enumerate() {
            let tag_location = location.index(index);
            let data = shape::object_at(raw, &tag_location)?;
            let name = required(&data, "name", &tag_location)?;
            let tag = Tag {
                name: name.clone(),
                description: optional_or_empty(&data, "description")?,
                external_documentation: match isset(&data, "externalDocs") {
                    Some(raw) => Some(self.external_documentation(raw, &tag_location.name("externalDocs"))?),
                    None => None,
                },
                extensions: shape::extensions(&data),
            };
            tags.insert(name, tag);
        }
        Ok(tags)
    }

    pub(crate) fn external_documentation(
        &self,
        raw: &Value,
        location: &Loc<'_>,
    ) -> Result<ExternalDocumentation, Error> {
        let data = shape::object_at(raw, location)?;
        Ok(ExternalDocumentation {
            url: required(&data, "url", location)?,
            description: optional_or_empty(&data, "description")?,
            extensions: shape::extensions(&data),
        })
    }

    /// `externalDocs` of the document, when set.
    pub(crate) fn document_external_documentation(&self) -> Result<Option<ExternalDocumentation>, Error> {
        match isset(self.document, "externalDocs") {
            Some(raw) => Ok(Some(self.external_documentation(raw, &Loc::Root("#/externalDocs"))?)),
            None => Ok(None),
        }
    }

    pub(crate) fn servers(&self, raw: &Value, location: &Loc<'_>) -> Result<Vec<Server>, Error> {
        let list = shape::list_at(raw, location)?;
        let mut servers = Vec::with_capacity(list.len());
        for (index, item) in list.values().enumerate() {
            let server_location = location.index(index);
            let data = shape::object_at(item, &server_location)?;
            let variables_location = server_location.name("variables");
            let raw_variables = or_empty(data.get_str("variables"));
            let raw_variables = shape::object_at(&raw_variables, &variables_location)?;
            let mut variables = Map::with_capacity(raw_variables.len());
            for (name, variable) in raw_variables.iter() {
                let variable_location = variables_location.key(name);
                let value = shape::object_at(variable, &variable_location)?;
                let enum_values = match isset(&value, "enum") {
                    Some(raw) => Some(shape::list_at(raw, &variable_location.name("enum"))?),
                    None => None,
                };
                let default = match value.get_str("default") {
                    None | Some(Value::Null) => String::new(),
                    Some(default) => cast(default)?,
                };
                let enum_values = match enum_values {
                    Some(list) => list.values().map(cast).collect::<Result<_, _>>()?,
                    None => Vec::new(),
                };
                variables.insert(
                    key_text(name),
                    ServerVariable {
                        default,
                        enum_values,
                        description: optional_or_empty(&value, "description")?,
                        extensions: shape::extensions(&value),
                    },
                );
            }
            servers.push(Server {
                url: required(&data, "url", &server_location)?,
                description: optional_or_empty(&data, "description")?,
                variables,
                extensions: shape::extensions(&data),
            });
        }
        Ok(servers)
    }

    pub(crate) fn security(&self, raw: &Value, location: &Loc<'_>) -> Result<Vec<SecurityRequirement>, Error> {
        let list = shape::list_at(raw, location)?;
        let mut requirements = Vec::with_capacity(list.len());
        for (index, item) in list.values().enumerate() {
            let requirement_location = location.index(index);
            let data = shape::object_at(item, &requirement_location)?;
            let mut schemes = Map::with_capacity(data.len());
            for (name, scopes) in data.iter() {
                schemes.insert(key_text(name), strings(scopes, &requirement_location.key(name))?);
            }
            requirements.push(SecurityRequirement { schemes });
        }
        Ok(requirements)
    }

    /// The document's `security`.
    pub(crate) fn document_security(&self) -> Result<Vec<SecurityRequirement>, Error> {
        self.security(&or_empty(self.field("security")), &Loc::Root("#/security"))
    }

    pub(crate) fn media_types(&self, raw: &Value, location: &Loc<'_>) -> Result<Map<MediaType>, Error> {
        let content = shape::object_at(raw, location)?;
        let mut media = Map::with_capacity(content.len());
        for (name, item) in content.iter() {
            let media_location = location.key(name);
            let data = shape::object_at(item, &media_location)?;
            let examples_location = media_location.name("examples");
            let raw_examples = or_empty(data.get_str("examples"));
            let raw_examples = shape::object_at(&raw_examples, &examples_location)?;
            let mut examples = Map::with_capacity(raw_examples.len());
            for (example_name, example_raw) in raw_examples.iter() {
                let example = self.resolve_object(example_raw, &examples_location.key(example_name))?;
                examples.insert(
                    key_text(example_name),
                    Example {
                        summary: optional_or_empty(&example, "summary")?,
                        description: optional_or_empty(&example, "description")?,
                        value: or_null(example.get_str("value")),
                        external_value: optional(&example, "externalValue")?,
                        extensions: shape::extensions(&example),
                    },
                );
            }
            let encoding_location = media_location.name("encoding");
            let raw_encoding = or_empty(data.get_str("encoding"));
            let raw_encoding = shape::object_at(&raw_encoding, &encoding_location)?;
            let mut encoding = Map::with_capacity(raw_encoding.len());
            for (property, encoding_raw) in raw_encoding.iter() {
                let property_location = encoding_location.key(property);
                let value = shape::object_at(encoding_raw, &property_location)?;
                encoding.insert(
                    key_text(property),
                    Encoding {
                        content_type: optional(&value, "contentType")?,
                        headers: self.headers(
                            &or_empty(value.get_str("headers")),
                            &property_location.name("headers"),
                            false,
                        )?,
                        style: optional(&value, "style")?,
                        explode: optional_flag(&value, "explode"),
                        allow_reserved: flag(&value, "allowReserved"),
                        extensions: shape::extensions(&value),
                    },
                );
            }
            let schema = match data.get_str("schema") {
                Some(raw) => Some(self.schemas.read_at(raw, &media_location.name("schema"))?),
                None => None,
            };
            media.insert(
                key_text(name),
                MediaType {
                    schema,
                    example: or_null(data.get_str("example")),
                    examples,
                    encoding,
                    extensions: shape::extensions(&data),
                },
            );
        }
        Ok(media)
    }

    pub(crate) fn headers(&self, raw: &Value, location: &Loc<'_>, openapi2: bool) -> Result<Map<Header>, Error> {
        let raw = shape::object_at(raw, location)?;
        let mut headers = Map::with_capacity(raw.len());
        for (name, item) in raw.iter() {
            let header_location = location.key(name);
            let data = self.resolve_object(item, &header_location)?;
            let schema = if let Some(raw) = data.get_str("schema") {
                Some(self.schemas.read_at(raw, &header_location.name("schema"))?)
            } else if openapi2 && isset(&data, "type").is_some() {
                Some(self.schemas.parameter_fields_at(&data, &header_location)?)
            } else {
                None
            };
            headers.insert(
                key_text(name),
                Header {
                    description: optional_or_empty(&data, "description")?,
                    required: flag(&data, "required"),
                    deprecated: flag(&data, "deprecated"),
                    schema,
                    content: match isset(&data, "content") {
                        Some(raw) => self.media_types(raw, &header_location.name("content"))?,
                        None => Map::new(),
                    },
                    style: optional(&data, "style")?,
                    explode: optional_flag(&data, "explode"),
                    extensions: shape::extensions(&data),
                },
            );
        }
        Ok(headers)
    }

    /// The object at `raw`, or the object its `$ref` chain leads to.
    pub(crate) fn resolve_object<'v>(&self, raw: &'v Value, location: &Loc<'_>) -> Result<Cow<'v, Array>, Error> {
        let data = shape::object_at(raw, location)?;
        if isset(&data, "$ref").is_none() {
            return Ok(data);
        }
        let reference = required(&data, "$ref", location)?;
        let resolved = self.resolver.resolve_object(&reference, Vec::new())?;
        let object = shape::object_at(&resolved, &Loc::Root(&reference))?.into_owned();
        Ok(Cow::Owned(object))
    }

    pub(crate) fn security_scheme(
        &self,
        data: &Array,
        location: &Loc<'_>,
        openapi2: bool,
    ) -> Result<SecurityScheme, Error> {
        let kind = shape::required_at(data, "type", location)?;
        let mut scheme = match kind.as_bytes() {
            b"basic" if openapi2 => SecurityScheme {
                description: optional_or_empty(data, "description")?,
                scheme: Some("basic".to_owned()),
                ..SecurityScheme::new(SecuritySchemeType::Http)
            },
            b"apiKey" => {
                let in_value = shape::required_at(data, "in", location)?;
                let Some(parameter_location) = ParameterLocation::from_name(in_value) else {
                    return Err(Error::InvalidSpecification(format!(
                        "Unsupported API key location '{}' at {location}/in",
                        text(in_value)
                    )));
                };
                SecurityScheme {
                    description: optional_or_empty(data, "description")?,
                    name: Some(required(data, "name", location)?),
                    location: Some(parameter_location),
                    ..SecurityScheme::new(SecuritySchemeType::ApiKey)
                }
            }
            b"oauth2" => {
                let flows = if openapi2 {
                    self.openapi2_oauth_flows(data, location)?
                } else {
                    self.oauth_flows(&or_empty(data.get_str("flows")), &location.name("flows"))?
                };
                SecurityScheme {
                    description: optional_or_empty(data, "description")?,
                    flows,
                    ..SecurityScheme::new(SecuritySchemeType::OAuth2)
                }
            }
            b"http" => SecurityScheme {
                description: optional_or_empty(data, "description")?,
                scheme: Some(text(shape::required_at(data, "scheme", location)?).to_ascii_lowercase()),
                bearer_format: optional(data, "bearerFormat")?,
                ..SecurityScheme::new(SecuritySchemeType::Http)
            },
            b"openIdConnect" => SecurityScheme {
                description: optional_or_empty(data, "description")?,
                open_id_connect_url: Some(required(data, "openIdConnectUrl", location)?),
                ..SecurityScheme::new(SecuritySchemeType::OpenIdConnect)
            },
            b"mutualTLS" if self.version == Version::V3_1 => SecurityScheme {
                description: optional_or_empty(data, "description")?,
                ..SecurityScheme::new(SecuritySchemeType::MutualTls)
            },
            _ => {
                return Err(Error::InvalidSpecification(format!(
                    "Unsupported security scheme type '{}' at {location}",
                    text(kind)
                )));
            }
        };
        scheme.extensions = shape::extensions(data);
        Ok(scheme)
    }

    fn oauth_flows(&self, raw: &Value, location: &Loc<'_>) -> Result<Map<OAuthFlow>, Error> {
        let raw = shape::object_at(raw, location)?;
        let mut flows = Map::with_capacity(raw.len());
        for (name, item) in raw.iter() {
            let flow_location = location.key(name);
            let data = shape::object_at(item, &flow_location)?;
            flows.insert(
                key_text(name),
                OAuthFlow {
                    authorization_url: optional(&data, "authorizationUrl")?,
                    token_url: optional(&data, "tokenUrl")?,
                    refresh_url: optional(&data, "refreshUrl")?,
                    scopes: string_map(&or_empty(data.get_str("scopes")), &flow_location.name("scopes"))?,
                },
            );
        }
        Ok(flows)
    }

    fn openapi2_oauth_flows(&self, data: &Array, location: &Loc<'_>) -> Result<Map<OAuthFlow>, Error> {
        let flow = shape::required_at(data, "flow", location)?;
        let name = match flow.as_bytes() {
            b"implicit" => "implicit",
            b"password" => "password",
            b"application" => "clientCredentials",
            b"accessCode" => "authorizationCode",
            _ => {
                return Err(Error::InvalidSpecification(format!(
                    "Unsupported OAuth flow '{}' at {location}",
                    text(flow)
                )));
            }
        };
        let mut flows = Map::with_capacity(1);
        flows.insert(
            name.to_owned(),
            OAuthFlow {
                authorization_url: optional(data, "authorizationUrl")?,
                token_url: optional(data, "tokenUrl")?,
                refresh_url: None,
                scopes: string_map(&or_empty(data.get_str("scopes")), &location.name("scopes"))?,
            },
        );
        Ok(flows)
    }
}

fn string_map(raw: &Value, location: &Loc<'_>) -> Result<Map<String>, Error> {
    let raw = shape::object_at(raw, location)?;
    let mut map = Map::with_capacity(raw.len());
    for (key, value) in raw.iter() {
        let Value::Str(value) = value else {
            return Err(Error::InvalidSpecification(format!("Expected string at {location}/{}", key_text(key))));
        };
        map.insert(key_text(key), text(value));
    }
    Ok(map)
}

/// The location of a path item: `#/paths/` and the path with `~` and `/`
/// escaped as in a JSON Pointer.
pub(crate) fn path_location(path: &str) -> String {
    let mut location = String::with_capacity(path.len() + 8);
    location.push_str("#/paths/");
    for c in path.chars() {
        match c {
            '~' => location.push_str("~0"),
            '/' => location.push_str("~1"),
            c => location.push(c),
        }
    }
    location
}
