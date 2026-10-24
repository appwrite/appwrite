//! OpenAPI 2.0 (Swagger).

use std::borrow::Cow;

use php_std::{Array, Value};

use super::{
    Base, Reader, SchemaReader, cast, flag, isset, optional, optional_or_empty, or_empty, path_location, required,
    strings,
};
use crate::model::{
    Encoding, Example, HttpMethod, Map, MediaType, Operation, Parameter, ParameterLocation, PathItem, RequestBody,
    Response, SecurityRequirement, Server,
};
use crate::schema::{ObjectSchema, SchemaKind};
use crate::shape::{self, Loc, key_text};
use crate::{Error, Schema, Specification, Version};

/// Reads an OpenAPI 2.0 document: `host`, `basePath` and `schemes` become
/// servers, `definitions` the schemas, `body` and `formData` parameters a
/// request body (one media type per `consumes`), and response schemas one
/// media type per `produces`.
///
/// PHP: `Utopia\OpenAPI\Parser\OpenAPI2`.
#[derive(Debug, Clone)]
pub struct OpenApi2<'a> {
    base: Base<'a>,
}

impl<'a> OpenApi2<'a> {
    /// A reader of `document`, which declares `source_version`; `version`
    /// decides the version-gated features (OpenAPI 3.1's `mutualTLS`).
    ///
    /// PHP: `new OpenAPI2($document, $version, $sourceVersion, $schemas)`.
    pub fn new(
        document: &'a Array,
        version: Version,
        source_version: impl Into<String>,
        schemas: SchemaReader,
    ) -> Self {
        OpenApi2 { base: Base::new(document, version, source_version.into(), schemas) }
    }
}

impl Reader for OpenApi2<'_> {
    /// PHP: `OpenAPI2::read()`.
    fn read(&self) -> Result<Specification, Error> {
        let base = &self.base;
        let security = base.document_security()?;
        let consumes = media_names(&or_empty(base.document.get_str("consumes")), &Loc::Root("#/consumes"))?;
        let produces = media_names(&or_empty(base.document.get_str("produces")), &Loc::Root("#/produces"))?;

        let definitions_location = Loc::Root("#/definitions");
        let raw = or_empty(base.document.get_str("definitions"));
        let definitions = shape::object_at(&raw, &definitions_location)?;
        let mut schemas = Map::with_capacity(definitions.len());
        for (name, raw) in definitions.iter() {
            schemas.insert(key_text(name), base.schemas.read_at(raw, &definitions_location.key(name))?);
        }

        let schemes_location = Loc::Root("#/securityDefinitions");
        let raw = or_empty(base.document.get_str("securityDefinitions"));
        let definitions = shape::object_at(&raw, &schemes_location)?;
        let mut security_schemes = Map::with_capacity(definitions.len());
        for (name, raw) in definitions.iter() {
            let location = schemes_location.key(name);
            let data = base.resolve_object(raw, &location)?;
            security_schemes.insert(key_text(name), base.security_scheme(&data, &location, true)?);
        }

        Ok(Specification {
            version: base.version,
            info: base.info()?,
            servers: self.servers()?,
            tags: base.tags()?,
            paths: self.paths(&security, &consumes, &produces)?,
            schemas,
            security_schemes,
            security,
            extensions: shape::extensions(base.document),
            source_version: base.source_version.clone(),
            json_schema_dialect: None,
            external_documentation: base.document_external_documentation()?,
        })
    }
}

/// `array_map(strval(...), Value::list($raw, $location))`.
fn media_names(raw: &Value, location: &Loc<'_>) -> Result<Vec<String>, Error> {
    strings(raw, location)
}

/// `(string) ($parameter[$key] ?? '')`.
fn identity_part(parameter: &Array, key: &str) -> Result<String, Error> {
    match parameter.get_str(key) {
        None | Some(Value::Null) => Ok(String::new()),
        Some(value) => cast(value),
    }
}

fn is_in(parameter: &Array, location: &str) -> bool {
    matches!(parameter.get_str("in"), Some(Value::Str(s)) if s.as_bytes() == location.as_bytes())
}

impl OpenApi2<'_> {
    fn servers(&self) -> Result<Vec<Server>, Error> {
        let document = self.base.document;
        let host = optional(document, "host")?;
        let base_path = optional_or_empty(document, "basePath")?;
        let mut schemes = media_names(&or_empty(document.get_str("schemes")), &Loc::Root("#/schemes"))?;
        let Some(host) = host else { return Ok(Vec::new()) };
        if schemes.is_empty() {
            schemes.push("http".to_owned());
        }
        let suffix = if base_path == "/" { "" } else { base_path.as_str() };
        Ok(schemes
            .into_iter()
            .map(|scheme| {
                let url = format!("{scheme}://{host}");
                Server::new(format!("{}{suffix}", url.trim_end_matches('/')))
            })
            .collect())
    }

    fn paths(
        &self,
        root_security: &[SecurityRequirement],
        root_consumes: &[String],
        root_produces: &[String],
    ) -> Result<Map<PathItem>, Error> {
        let base = &self.base;
        let raw = or_empty(base.document.get_str("paths"));
        let raw = shape::object_at(&raw, &Loc::Root("#/paths"))?;
        let mut paths = Map::with_capacity(raw.len());
        for (path, item) in raw.iter() {
            let path = key_text(path);
            let location_text = path_location(&path);
            let location = Loc::Root(&location_text);
            let data = base.resolve_object(item, &location)?;
            let parameters_location = location.name("parameters");
            let raw_parameters = or_empty(data.get_str("parameters"));
            let path_parameters = self.raw_parameters(&raw_parameters, &parameters_location)?;
            let mut operations = Vec::new();
            for method in HttpMethod::ALL {
                let Some(raw) = data.get_str(method.as_str()) else { continue };
                operations.push(self.operation(
                    &path,
                    method,
                    raw,
                    &path_parameters,
                    root_security,
                    root_consumes,
                    root_produces,
                    &location.name(method.as_str()),
                )?);
            }
            let mut parameters = Vec::new();
            for (index, parameter) in path_parameters.iter().enumerate() {
                if !is_in(parameter, "body") && !is_in(parameter, "formData") {
                    parameters.push(self.parameter(parameter, &parameters_location.index(index))?);
                }
            }
            let item = PathItem {
                path: path.clone(),
                operations,
                parameters,
                summary: String::new(),
                description: String::new(),
                servers: Vec::new(),
                extensions: shape::extensions(&data),
            };
            paths.insert(path, item);
        }
        Ok(paths)
    }

    #[allow(clippy::too_many_arguments)]
    fn operation(
        &self,
        path: &str,
        method: HttpMethod,
        raw: &Value,
        path_parameters: &[Cow<'_, Array>],
        root_security: &[SecurityRequirement],
        root_consumes: &[String],
        root_produces: &[String],
        location: &Loc<'_>,
    ) -> Result<Operation, Error> {
        let base = &self.base;
        let data = shape::object_at(raw, location)?;
        let parameters_location = location.name("parameters");
        let raw_parameters = or_empty(data.get_str("parameters"));
        let own = self.raw_parameters(&raw_parameters, &parameters_location)?;
        let merged = merge(path_parameters, own)?;
        let consumes = match data.get_str("consumes") {
            Some(raw) => Cow::Owned(media_names(raw, &location.name("consumes"))?),
            None => Cow::Borrowed(root_consumes),
        };
        let produces = match data.get_str("produces") {
            Some(raw) => Cow::Owned(media_names(raw, &location.name("produces"))?),
            None => Cow::Borrowed(root_produces),
        };

        let mut parameters = Vec::new();
        let mut body: Option<RequestBody> = None;
        let mut form: Vec<&Array> = Vec::new();
        for (index, parameter) in merged.iter().enumerate() {
            if is_in(parameter, "body") {
                if body.is_some() {
                    return Err(Error::InvalidSpecification(format!("Multiple body parameters at {location}")));
                }
                let schema = match parameter.get_str("schema") {
                    Some(raw) => Some(base.schemas.read_at(raw, &parameters_location.index(index).name("schema"))?),
                    None => None,
                };
                let content = consumes
                    .iter()
                    .map(|media| (media.clone(), MediaType { schema: schema.clone(), ..MediaType::default() }))
                    .collect();
                body = Some(RequestBody {
                    description: optional_or_empty(parameter, "description")?,
                    required: flag(parameter, "required"),
                    content,
                    extensions: shape::extensions(parameter),
                });
            } else if is_in(parameter, "formData") {
                form.push(parameter);
            } else {
                parameters.push(self.parameter(parameter, &parameters_location.index(index))?);
            }
        }
        if body.is_some() && !form.is_empty() {
            return Err(Error::InvalidSpecification(format!(
                "Body and formData parameters cannot coexist at {location}"
            )));
        }
        if !form.is_empty() {
            body = Some(self.form_body(&form, &consumes, location)?);
        }

        let responses_location = location.name("responses");
        let null = Value::Null;
        let raw_responses = shape::object_at(data.get_str("responses").unwrap_or(&null), &responses_location)?;
        let mut responses = Map::with_capacity(raw_responses.len());
        for (status, raw) in raw_responses.iter() {
            let response_location = responses_location.key(status);
            let value = base.resolve_object(raw, &response_location)?;
            let examples = self.examples(&or_empty(value.get_str("examples")), &response_location.name("examples"))?;
            let schema = match value.get_str("schema") {
                Some(raw) => Some(base.schemas.read_at(raw, &response_location.name("schema"))?),
                None => None,
            };
            let mut content: Map<MediaType> = Map::new();
            for media in produces.iter().chain(examples.keys()) {
                if content.contains_key(media) {
                    continue;
                }
                let example = examples.get(media).map_or(Value::Null, |e| e.value.clone());
                content.insert(media.clone(), MediaType { schema: schema.clone(), example, ..MediaType::default() });
            }
            responses.insert(
                key_text(status),
                Response {
                    description: required(&value, "description", &response_location)?,
                    headers: base.headers(
                        &or_empty(value.get_str("headers")),
                        &response_location.name("headers"),
                        true,
                    )?,
                    content,
                    extensions: shape::extensions(&value),
                },
            );
        }

        Ok(Operation {
            id: optional_or_empty(&data, "operationId")?,
            method,
            path: path.to_owned(),
            tags: match isset(&data, "tags") {
                Some(raw) => strings(raw, &location.name("tags"))?,
                None => Vec::new(),
            },
            summary: optional_or_empty(&data, "summary")?,
            description: optional_or_empty(&data, "description")?,
            deprecated: flag(&data, "deprecated"),
            parameters,
            request_body: body,
            responses,
            security: match data.get_str("security") {
                Some(raw) => base.security(raw, &location.name("security"))?,
                None => root_security.to_vec(),
            },
            servers: Vec::new(),
            external_documentation: match isset(&data, "externalDocs") {
                Some(raw) => Some(base.external_documentation(raw, &location.name("externalDocs"))?),
                None => None,
            },
            extensions: shape::extensions(&data),
        })
    }

    fn raw_parameters<'v>(&self, raw: &'v Value, location: &Loc<'_>) -> Result<Vec<Cow<'v, Array>>, Error> {
        let list = shape::list_at(raw, location)?;
        list.values().enumerate().map(|(index, item)| self.base.resolve_object(item, &location.index(index))).collect()
    }

    fn parameter(&self, data: &Array, location: &Loc<'_>) -> Result<Parameter, Error> {
        let in_value = shape::required_at(data, "in", location)?;
        let Some(parameter_location) = ParameterLocation::from_name(in_value) else {
            return Err(Error::InvalidSpecification(format!(
                "Unsupported parameter location '{}' at {location}/in",
                shape::text(in_value)
            )));
        };
        let is_required = flag(data, "required");
        if parameter_location == ParameterLocation::Path && !is_required {
            return Err(Error::InvalidSpecification(format!("Path parameter must be required at {location}")));
        }
        Ok(Parameter {
            name: required(data, "name", location)?,
            location: parameter_location,
            description: optional_or_empty(data, "description")?,
            required: is_required,
            deprecated: false,
            allow_empty_value: flag(data, "allowEmptyValue"),
            schema: Some(self.base.schemas.parameter_fields_at(data, location)?),
            content: Map::new(),
            style: None,
            explode: None,
            allow_reserved: false,
            extensions: shape::extensions(data),
        })
    }

    fn form_body(&self, form: &[&Array], consumes: &[String], location: &Loc<'_>) -> Result<RequestBody, Error> {
        let parameters_location = location.name("parameters");
        let mut properties = Map::with_capacity(form.len());
        let mut required_names = Vec::new();
        let mut encoding = Map::with_capacity(form.len());
        for (index, parameter) in form.iter().enumerate() {
            let parameter_location = parameters_location.index(index);
            let name = required(parameter, "name", &parameter_location)?;
            properties.insert(name.clone(), self.base.schemas.parameter_fields_at(parameter, &parameter_location)?);
            if flag(parameter, "required") {
                required_names.push(name.clone());
            }
            let is_file = matches!(parameter.get_str("type"), Some(Value::Str(s)) if s.as_bytes() == b"file");
            encoding.insert(
                name,
                Encoding {
                    content_type: is_file.then(|| "application/octet-stream".to_owned()),
                    extensions: shape::extensions(parameter),
                    ..Encoding::default()
                },
            );
        }
        let required_body = !required_names.is_empty();
        let schema = Schema::new(SchemaKind::Object(ObjectSchema {
            properties,
            required: required_names,
            ..ObjectSchema::default()
        }));
        let default_media = ["application/x-www-form-urlencoded".to_owned()];
        let consumes = if consumes.is_empty() { &default_media[..] } else { consumes };
        let content = consumes
            .iter()
            .map(|media| {
                let media_type =
                    MediaType { schema: Some(schema.clone()), encoding: encoding.clone(), ..MediaType::default() };
                (media.clone(), media_type)
            })
            .collect();
        Ok(RequestBody { description: String::new(), required: required_body, content, extensions: Array::new() })
    }

    fn examples(&self, raw: &Value, location: &Loc<'_>) -> Result<Map<Example>, Error> {
        let raw = shape::object_at(raw, location)?;
        Ok(raw
            .iter()
            .map(|(media, value)| (key_text(media), Example { value: value.clone(), ..Example::default() }))
            .collect())
    }
}

/// The path's parameters with the operation's own replacing those of the
/// same location and name (PHP compares `(string) in` and `(string) name`).
fn merge<'v>(inherited: &[Cow<'v, Array>], own: Vec<Cow<'v, Array>>) -> Result<Vec<Cow<'v, Array>>, Error> {
    let mut merged: Vec<Cow<'v, Array>> = inherited.to_vec();
    let mut identities: Vec<(String, usize)> = Vec::with_capacity(merged.len());
    for (index, parameter) in merged.iter().enumerate() {
        let identity = identity(parameter)?;
        match identities.iter_mut().find(|(seen, _)| *seen == identity) {
            Some(entry) => entry.1 = index,
            None => identities.push((identity, index)),
        }
    }
    for parameter in own {
        let identity = identity(&parameter)?;
        match identities.iter().find(|(seen, _)| *seen == identity) {
            Some((_, index)) => merged[*index] = parameter,
            None => {
                identities.push((identity, merged.len()));
                merged.push(parameter);
            }
        }
    }
    Ok(merged)
}

fn identity(parameter: &Array) -> Result<String, Error> {
    Ok(format!("{}\0{}", identity_part(parameter, "in")?, identity_part(parameter, "name")?))
}
