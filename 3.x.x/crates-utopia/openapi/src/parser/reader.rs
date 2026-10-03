//! Shared document readers (PHP `AbstractReader`).

use crate::error::{InvalidSpecification, OpenApiError};
use crate::json::Json;
use crate::model::{
    Contact, Encoding, Example, ExternalDocumentation, Header, Info, License, MediaType, OAuthFlow,
    ParameterLocation, SecurityRequirement, SecurityScheme, SecuritySchemeType, Server,
    ServerVariable, Tag,
};
use crate::parser::schema::SchemaReader;
use crate::parser::value::Value;
use crate::reference::LocalResolver;
use crate::version::Version;
use indexmap::IndexMap;

#[derive(Debug)]
pub struct DocumentReader {
    pub document: IndexMap<String, Json>,
    pub version: Version,
    pub source_version: String,
    pub schemas: SchemaReader,
    pub resolver: LocalResolver,
}

impl DocumentReader {
    pub fn new(
        document: IndexMap<String, Json>,
        version: Version,
        source_version: String,
        schemas: SchemaReader,
    ) -> Self {
        let resolver = LocalResolver::new(document.clone());
        Self {
            document,
            version,
            source_version,
            schemas,
            resolver,
        }
    }

    pub fn parse_info(&self) -> Result<Info, OpenApiError> {
        let data = Value::object(self.document.get("info").unwrap_or(&Json::Null), "#/info")?;
        let contact = if data.contains_key("contact") {
            let value =
                Value::object(data.get("contact").unwrap_or(&Json::Null), "#/info/contact")?;
            Some(Contact {
                name: Value::optional_string(value, "name")?.unwrap_or_default(),
                url: Value::optional_string(value, "url")?,
                email: Value::optional_string(value, "email")?,
                extensions: Value::extensions(value),
            })
        } else {
            None
        };
        let license = if data.contains_key("license") {
            let value =
                Value::object(data.get("license").unwrap_or(&Json::Null), "#/info/license")?;
            Some(License {
                name: Value::required_string(value, "name", "#/info/license")?,
                url: Value::optional_string(value, "url")?,
                identifier: Value::optional_string(value, "identifier")?,
                extensions: Value::extensions(value),
            })
        } else {
            None
        };
        Ok(Info {
            title: Value::required_string(data, "title", "#/info")?,
            description: Value::optional_string(data, "description")?.unwrap_or_default(),
            version: Value::required_string(data, "version", "#/info")?,
            terms_of_service: Value::optional_string(data, "termsOfService")?,
            contact,
            license,
            extensions: Value::extensions(data),
        })
    }

    pub fn parse_tags(&self) -> Result<IndexMap<String, Tag>, OpenApiError> {
        let mut tags = IndexMap::new();
        let list = Value::list(
            self.document
                .get("tags")
                .unwrap_or(crate::json::empty_array()),
            "#/tags",
        )?;
        for (index, raw) in list.iter().enumerate() {
            let data = Value::object(raw, &format!("#/tags/{index}"))?;
            let name = Value::required_string(data, "name", &format!("#/tags/{index}"))?;
            let external_documentation = if data.contains_key("externalDocs") {
                Some(self.parse_external_documentation(
                    data.get("externalDocs").unwrap_or(&Json::Null),
                    &format!("#/tags/{index}/externalDocs"),
                )?)
            } else {
                None
            };
            tags.insert(
                name.clone(),
                Tag {
                    name,
                    description: Value::optional_string(data, "description")?.unwrap_or_default(),
                    external_documentation,
                    extensions: Value::extensions(data),
                },
            );
        }
        Ok(tags)
    }

    pub fn parse_external_documentation(
        &self,
        raw: &Json,
        location: &str,
    ) -> Result<ExternalDocumentation, OpenApiError> {
        let data = Value::object(raw, location)?;
        Ok(ExternalDocumentation {
            url: Value::required_string(data, "url", location)?,
            description: Value::optional_string(data, "description")?.unwrap_or_default(),
            extensions: Value::extensions(data),
        })
    }

    pub fn parse_servers(&self, raw: &Json, location: &str) -> Result<Vec<Server>, OpenApiError> {
        let mut servers = Vec::new();
        for (index, item) in Value::list(raw, location)?.iter().enumerate() {
            let data = Value::object(item, &format!("{location}/{index}"))?;
            let mut variables = IndexMap::new();
            let vars = Value::object(
                data.get("variables").unwrap_or(crate::json::empty_object()),
                &format!("{location}/{index}/variables"),
            )?;
            for (name, variable) in vars {
                let value =
                    Value::object(variable, &format!("{location}/{index}/variables/{name}"))?;
                let enum_values = if value.contains_key("enum") {
                    Value::list(
                        value.get("enum").unwrap_or(crate::json::empty_array()),
                        &format!("{location}/{index}/variables/{name}/enum"),
                    )?
                    .iter()
                    .map(json_strval)
                    .collect()
                } else {
                    Vec::new()
                };
                variables.insert(
                    name.clone(),
                    ServerVariable {
                        default: json_strval(
                            value.get("default").unwrap_or(&Json::String(String::new())),
                        ),
                        enum_values,
                        description: Value::optional_string(value, "description")?
                            .unwrap_or_default(),
                        extensions: Value::extensions(value),
                    },
                );
            }
            servers.push(Server {
                url: Value::required_string(data, "url", &format!("{location}/{index}"))?,
                description: Value::optional_string(data, "description")?.unwrap_or_default(),
                variables,
                extensions: Value::extensions(data),
            });
        }
        Ok(servers)
    }

    pub fn parse_security(
        &self,
        raw: &Json,
        location: &str,
    ) -> Result<Vec<SecurityRequirement>, OpenApiError> {
        let mut requirements = Vec::new();
        for (index, item) in Value::list(raw, location)?.iter().enumerate() {
            let data = Value::object(item, &format!("{location}/{index}"))?;
            let mut schemes = IndexMap::new();
            for (name, scopes) in data {
                let list = Value::list(scopes, &format!("{location}/{index}/{name}"))?;
                schemes.insert(name.clone(), list.iter().map(json_strval).collect());
            }
            requirements.push(SecurityRequirement { schemes });
        }
        Ok(requirements)
    }

    pub fn parse_media_types(
        &self,
        raw: &Json,
        location: &str,
    ) -> Result<IndexMap<String, MediaType>, OpenApiError> {
        let mut content = IndexMap::new();
        for (name, item) in Value::object(raw, location)? {
            let data = Value::object(item, &format!("{location}/{name}"))?;
            let mut examples = IndexMap::new();
            let examples_raw = Value::object(
                data.get("examples").unwrap_or(crate::json::empty_object()),
                &format!("{location}/{name}/examples"),
            )?;
            for (example_name, example_raw) in examples_raw {
                let example = self.resolve_object(
                    example_raw,
                    &format!("{location}/{name}/examples/{example_name}"),
                )?;
                examples.insert(
                    example_name.clone(),
                    Example {
                        summary: Value::optional_string(&example, "summary")?.unwrap_or_default(),
                        description: Value::optional_string(&example, "description")?
                            .unwrap_or_default(),
                        value: example.get("value").cloned().unwrap_or(Json::Null),
                        external_value: Value::optional_string(&example, "externalValue")?,
                        extensions: Value::extensions(&example),
                    },
                );
            }
            let mut encoding = IndexMap::new();
            let encoding_raw = Value::object(
                data.get("encoding").unwrap_or(crate::json::empty_object()),
                &format!("{location}/{name}/encoding"),
            )?;
            for (property, encoding_raw) in encoding_raw {
                let value = Value::object(
                    encoding_raw,
                    &format!("{location}/{name}/encoding/{property}"),
                )?;
                encoding.insert(
                    property.clone(),
                    Encoding {
                        content_type: Value::optional_string(value, "contentType")?,
                        headers: self.parse_headers(
                            value.get("headers").unwrap_or(crate::json::empty_object()),
                            &format!("{location}/{name}/encoding/{property}/headers"),
                            false,
                        )?,
                        style: Value::optional_string(value, "style")?,
                        explode: if value.contains_key("explode") {
                            Some(value.get("explode").is_some_and(Json::php_bool))
                        } else {
                            None
                        },
                        allow_reserved: value.get("allowReserved").is_some_and(Json::php_bool),
                        extensions: Value::extensions(value),
                    },
                );
            }
            content.insert(
                name.clone(),
                MediaType {
                    schema: if data.contains_key("schema") {
                        Some(self.schemas.read(
                            data.get("schema").unwrap_or(&Json::Null),
                            &format!("{location}/{name}/schema"),
                        )?)
                    } else {
                        None
                    },
                    example: data.get("example").cloned().unwrap_or(Json::Null),
                    examples,
                    encoding,
                    extensions: Value::extensions(data),
                },
            );
        }
        Ok(content)
    }

    pub fn parse_headers(
        &self,
        raw: &Json,
        location: &str,
        open_api2: bool,
    ) -> Result<IndexMap<String, Header>, OpenApiError> {
        let mut headers = IndexMap::new();
        for (name, item) in Value::object(raw, location)? {
            let data = self.resolve_object(item, &format!("{location}/{name}"))?;
            let schema = if data.contains_key("schema") {
                Some(self.schemas.read(
                    data.get("schema").unwrap_or(&Json::Null),
                    &format!("{location}/{name}/schema"),
                )?)
            } else if open_api2 && data.contains_key("type") {
                Some(
                    self.schemas
                        .read_parameter_fields(&data, &format!("{location}/{name}"))?,
                )
            } else {
                None
            };
            headers.insert(
                name.clone(),
                Header {
                    description: Value::optional_string(&data, "description")?.unwrap_or_default(),
                    required: data.get("required").is_some_and(Json::php_bool),
                    deprecated: data.get("deprecated").is_some_and(Json::php_bool),
                    schema,
                    content: if data.contains_key("content") {
                        self.parse_media_types(
                            data.get("content").unwrap_or(&Json::Null),
                            &format!("{location}/{name}/content"),
                        )?
                    } else {
                        IndexMap::new()
                    },
                    style: Value::optional_string(&data, "style")?,
                    explode: if data.contains_key("explode") {
                        Some(data.get("explode").is_some_and(Json::php_bool))
                    } else {
                        None
                    },
                    extensions: Value::extensions(&data),
                },
            );
        }
        Ok(headers)
    }

    pub fn resolve_object(
        &self,
        raw: &Json,
        location: &str,
    ) -> Result<IndexMap<String, Json>, OpenApiError> {
        let data = Value::object(raw, location)?;
        if data.contains_key("$ref") {
            let reference = Value::required_string(data, "$ref", location)?;
            let resolved = self.resolver.resolve_object(&reference, &[])?;
            return Value::object_owned(resolved, &reference);
        }
        Ok(data.clone())
    }

    pub fn parse_security_scheme(
        &self,
        data: &IndexMap<String, Json>,
        location: &str,
        open_api2: bool,
    ) -> Result<SecurityScheme, OpenApiError> {
        let type_ = Value::required_string(data, "type", location)?;
        if open_api2 && type_ == "basic" {
            return Ok(SecurityScheme {
                type_: SecuritySchemeType::Http,
                description: Value::optional_string(data, "description")?.unwrap_or_default(),
                name: None,
                location: None,
                scheme: Some("basic".into()),
                bearer_format: None,
                flows: IndexMap::new(),
                open_id_connect_url: None,
                extensions: Value::extensions(data),
            });
        }
        if type_ == "apiKey" {
            let location_value = Value::required_string(data, "in", location)?;
            let parameter_location =
                ParameterLocation::from_str_php(&location_value).ok_or_else(|| {
                    InvalidSpecification(format!(
                        "Unsupported API key location '{location_value}' at {location}/in"
                    ))
                })?;
            return Ok(SecurityScheme {
                type_: SecuritySchemeType::ApiKey,
                description: Value::optional_string(data, "description")?.unwrap_or_default(),
                name: Some(Value::required_string(data, "name", location)?),
                location: Some(parameter_location),
                scheme: None,
                bearer_format: None,
                flows: IndexMap::new(),
                open_id_connect_url: None,
                extensions: Value::extensions(data),
            });
        }
        if type_ == "oauth2" {
            let flows = if open_api2 {
                self.parse_open_api2_oauth_flows(data, location)?
            } else {
                self.parse_oauth_flows(
                    data.get("flows").unwrap_or(crate::json::empty_object()),
                    &format!("{location}/flows"),
                )?
            };
            return Ok(SecurityScheme {
                type_: SecuritySchemeType::Oauth2,
                description: Value::optional_string(data, "description")?.unwrap_or_default(),
                name: None,
                location: None,
                scheme: None,
                bearer_format: None,
                flows,
                open_id_connect_url: None,
                extensions: Value::extensions(data),
            });
        }
        if type_ == "http" {
            return Ok(SecurityScheme {
                type_: SecuritySchemeType::Http,
                description: Value::optional_string(data, "description")?.unwrap_or_default(),
                name: None,
                location: None,
                scheme: Some(
                    Value::required_string(data, "scheme", location)?.to_ascii_lowercase(),
                ),
                bearer_format: Value::optional_string(data, "bearerFormat")?,
                flows: IndexMap::new(),
                open_id_connect_url: None,
                extensions: Value::extensions(data),
            });
        }
        if type_ == "openIdConnect" {
            return Ok(SecurityScheme {
                type_: SecuritySchemeType::OpenIdConnect,
                description: Value::optional_string(data, "description")?.unwrap_or_default(),
                name: None,
                location: None,
                scheme: None,
                bearer_format: None,
                flows: IndexMap::new(),
                open_id_connect_url: Some(Value::required_string(
                    data,
                    "openIdConnectUrl",
                    location,
                )?),
                extensions: Value::extensions(data),
            });
        }
        if type_ == "mutualTLS" && self.version == Version::V31 {
            return Ok(SecurityScheme {
                type_: SecuritySchemeType::MutualTls,
                description: Value::optional_string(data, "description")?.unwrap_or_default(),
                name: None,
                location: None,
                scheme: None,
                bearer_format: None,
                flows: IndexMap::new(),
                open_id_connect_url: None,
                extensions: Value::extensions(data),
            });
        }
        Err(InvalidSpecification(format!(
            "Unsupported security scheme type '{type_}' at {location}"
        ))
        .into())
    }

    fn parse_oauth_flows(
        &self,
        raw: &Json,
        location: &str,
    ) -> Result<IndexMap<String, OAuthFlow>, OpenApiError> {
        let mut flows = IndexMap::new();
        for (name, item) in Value::object(raw, location)? {
            let data = Value::object(item, &format!("{location}/{name}"))?;
            flows.insert(
                name.clone(),
                OAuthFlow {
                    authorization_url: Value::optional_string(data, "authorizationUrl")?,
                    token_url: Value::optional_string(data, "tokenUrl")?,
                    refresh_url: Value::optional_string(data, "refreshUrl")?,
                    scopes: self.string_map(
                        data.get("scopes").unwrap_or(crate::json::empty_object()),
                        &format!("{location}/{name}/scopes"),
                    )?,
                },
            );
        }
        Ok(flows)
    }

    fn parse_open_api2_oauth_flows(
        &self,
        data: &IndexMap<String, Json>,
        location: &str,
    ) -> Result<IndexMap<String, OAuthFlow>, OpenApiError> {
        let flow = Value::required_string(data, "flow", location)?;
        let name = match flow.as_str() {
            "implicit" => "implicit",
            "password" => "password",
            "application" => "clientCredentials",
            "accessCode" => "authorizationCode",
            _ => {
                return Err(InvalidSpecification(format!(
                    "Unsupported OAuth flow '{flow}' at {location}"
                ))
                .into());
            }
        };
        let mut flows = IndexMap::new();
        flows.insert(
            name.to_owned(),
            OAuthFlow {
                authorization_url: Value::optional_string(data, "authorizationUrl")?,
                token_url: Value::optional_string(data, "tokenUrl")?,
                refresh_url: None,
                scopes: self.string_map(
                    data.get("scopes").unwrap_or(crate::json::empty_object()),
                    &format!("{location}/scopes"),
                )?,
            },
        );
        Ok(flows)
    }

    fn string_map(
        &self,
        raw: &Json,
        location: &str,
    ) -> Result<IndexMap<String, String>, OpenApiError> {
        let mut result = IndexMap::new();
        for (key, value) in Value::object(raw, location)? {
            let Json::String(s) = value else {
                return Err(
                    InvalidSpecification(format!("Expected string at {location}/{key}")).into(),
                );
            };
            result.insert(key.clone(), s.clone());
        }
        Ok(result)
    }
}

pub fn json_strval(value: &Json) -> String {
    match value {
        Json::String(s) => s.clone(),
        Json::Number(n) => match n {
            crate::json::JsonNumber::Int(i) => i.to_string(),
            crate::json::JsonNumber::UInt(u) => u.to_string(),
            crate::json::JsonNumber::Float(f) => f.to_string(),
        },
        Json::Bool(b) => {
            if *b {
                "1".into()
            } else {
                String::new()
            }
        }
        _ => String::new(),
    }
}

pub fn pointer_escape(path: &str) -> String {
    path.replace('~', "~0").replace('/', "~1")
}
