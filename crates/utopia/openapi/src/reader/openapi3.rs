//! OpenAPI 3.0 and 3.1.

use php_std::{Array, Value};

use super::{
    Base, Reader, SchemaReader, flag, isset, optional, optional_flag, optional_or_empty, or_empty, path_location,
    required, strings,
};
use crate::model::{
    HttpMethod, Map, Operation, Parameter, ParameterLocation, PathItem, RequestBody, Response, SecurityRequirement,
    Server,
};
use crate::shape::{self, Loc, key_text};
use crate::{Error, Specification, Version};

/// Reads an OpenAPI 3.0 or 3.1 document. The minor version only changes
/// the schema [`Dialect`](super::Dialect) and whether `mutualTLS` security
/// schemes are accepted (3.1).
///
/// PHP: `Utopia\OpenAPI\Parser\OpenAPI3`, with `OpenAPI30` and `OpenAPI31`
/// selecting the minor version (here, `version`).
#[derive(Debug, Clone)]
pub struct OpenApi3<'a> {
    base: Base<'a>,
}

impl<'a> OpenApi3<'a> {
    /// A reader of `document`, which declares `source_version`.
    ///
    /// PHP: `new OpenAPI30(...)` / `new OpenAPI31($document, $version, $sourceVersion, $schemas)`.
    pub fn new(
        document: &'a Array,
        version: Version,
        source_version: impl Into<String>,
        schemas: SchemaReader,
    ) -> Self {
        OpenApi3 { base: Base::new(document, version, source_version.into(), schemas) }
    }
}

impl Reader for OpenApi3<'_> {
    /// PHP: `OpenAPI3::read()`.
    fn read(&self) -> Result<Specification, Error> {
        let base = &self.base;
        let security = base.document_security()?;
        let servers = base.servers(&or_empty(base.document.get_str("servers")), &Loc::Root("#/servers"))?;
        let raw = or_empty(base.document.get_str("components"));
        let components = shape::object_at(&raw, &Loc::Root("#/components"))?;

        let schemas_location = Loc::Root("#/components/schemas");
        let raw = or_empty(components.get_str("schemas"));
        let raw = shape::object_at(&raw, &schemas_location)?;
        let mut schemas = Map::with_capacity(raw.len());
        for (name, raw) in raw.iter() {
            schemas.insert(key_text(name), base.schemas.read_at(raw, &schemas_location.key(name))?);
        }

        let schemes_location = Loc::Root("#/components/securitySchemes");
        let raw = or_empty(components.get_str("securitySchemes"));
        let raw = shape::object_at(&raw, &schemes_location)?;
        let mut security_schemes = Map::with_capacity(raw.len());
        for (name, raw) in raw.iter() {
            let location = schemes_location.key(name);
            let data = base.resolve_object(raw, &location)?;
            security_schemes.insert(key_text(name), base.security_scheme(&data, &location, false)?);
        }

        Ok(Specification {
            version: base.version,
            info: base.info()?,
            tags: base.tags()?,
            paths: self.paths(&security, &servers)?,
            servers,
            schemas,
            security_schemes,
            security,
            extensions: shape::extensions(base.document),
            source_version: base.source_version.clone(),
            json_schema_dialect: optional(base.document, "jsonSchemaDialect")?,
            external_documentation: base.document_external_documentation()?,
        })
    }
}

impl OpenApi3<'_> {
    fn paths(&self, root_security: &[SecurityRequirement], root_servers: &[Server]) -> Result<Map<PathItem>, Error> {
        let base = &self.base;
        let raw = or_empty(base.document.get_str("paths"));
        let raw = shape::object_at(&raw, &Loc::Root("#/paths"))?;
        let mut paths = Map::with_capacity(raw.len());
        for (path, item) in raw.iter() {
            let path = key_text(path);
            let location_text = path_location(&path);
            let location = Loc::Root(&location_text);
            let data = base.resolve_object(item, &location)?;
            let path_parameters =
                self.parameters(&or_empty(data.get_str("parameters")), &location.name("parameters"))?;
            let path_servers = match data.get_str("servers") {
                Some(raw) => base.servers(raw, &location.name("servers"))?,
                None => root_servers.to_vec(),
            };
            let mut operations = Vec::new();
            for method in HttpMethod::ALL {
                let Some(raw) = data.get_str(method.as_str()) else { continue };
                operations.push(self.operation(
                    &path,
                    method,
                    raw,
                    &path_parameters,
                    root_security,
                    &path_servers,
                    &location.name(method.as_str()),
                )?);
            }
            let item = PathItem {
                path: path.clone(),
                operations,
                parameters: path_parameters,
                summary: optional_or_empty(&data, "summary")?,
                description: optional_or_empty(&data, "description")?,
                servers: path_servers,
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
        path_parameters: &[Parameter],
        root_security: &[SecurityRequirement],
        inherited_servers: &[Server],
        location: &Loc<'_>,
    ) -> Result<Operation, Error> {
        let base = &self.base;
        let data = shape::object_at(raw, location)?;
        let own = self.parameters(&or_empty(data.get_str("parameters")), &location.name("parameters"))?;
        let parameters = merge(path_parameters, own);
        let request_body = match data.get_str("requestBody") {
            Some(raw) => {
                let body_location = location.name("requestBody");
                let value = base.resolve_object(raw, &body_location)?;
                Some(RequestBody {
                    description: optional_or_empty(&value, "description")?,
                    required: flag(&value, "required"),
                    content: base.media_types(&or_empty(value.get_str("content")), &body_location.name("content"))?,
                    extensions: shape::extensions(&value),
                })
            }
            None => None,
        };

        let responses_location = location.name("responses");
        let null = Value::Null;
        let raw_responses = shape::object_at(data.get_str("responses").unwrap_or(&null), &responses_location)?;
        let mut responses = Map::with_capacity(raw_responses.len());
        for (status, raw) in raw_responses.iter() {
            let response_location = responses_location.key(status);
            let value = base.resolve_object(raw, &response_location)?;
            responses.insert(
                key_text(status),
                Response {
                    description: required(&value, "description", &response_location)?,
                    headers: base.headers(
                        &or_empty(value.get_str("headers")),
                        &response_location.name("headers"),
                        false,
                    )?,
                    content: base
                        .media_types(&or_empty(value.get_str("content")), &response_location.name("content"))?,
                    extensions: shape::extensions(&value),
                },
            );
        }

        let tags = match isset(&data, "tags") {
            Some(raw) => strings(raw, &location.name("tags"))?,
            None => Vec::new(),
        };
        let security = match data.get_str("security") {
            Some(raw) => base.security(raw, &location.name("security"))?,
            None => root_security.to_vec(),
        };
        let servers = match data.get_str("servers") {
            Some(raw) => base.servers(raw, &location.name("servers"))?,
            None => inherited_servers.to_vec(),
        };

        Ok(Operation {
            id: optional_or_empty(&data, "operationId")?,
            method,
            path: path.to_owned(),
            tags,
            summary: optional_or_empty(&data, "summary")?,
            description: optional_or_empty(&data, "description")?,
            deprecated: flag(&data, "deprecated"),
            parameters,
            request_body,
            responses,
            security,
            servers,
            external_documentation: match isset(&data, "externalDocs") {
                Some(raw) => Some(base.external_documentation(raw, &location.name("externalDocs"))?),
                None => None,
            },
            extensions: shape::extensions(&data),
        })
    }

    fn parameters(&self, raw: &Value, location: &Loc<'_>) -> Result<Vec<Parameter>, Error> {
        let base = &self.base;
        let list = shape::list_at(raw, location)?;
        let mut parameters = Vec::with_capacity(list.len());
        for (index, item) in list.values().enumerate() {
            let parameter_location = location.index(index);
            let data = base.resolve_object(item, &parameter_location)?;
            let in_value = shape::required_at(&data, "in", &parameter_location)?;
            let Some(parameter_location_value) = ParameterLocation::from_name(in_value) else {
                return Err(Error::InvalidSpecification(format!(
                    "Unsupported parameter location '{}' at {parameter_location}/in",
                    shape::text(in_value)
                )));
            };
            let is_required = flag(&data, "required");
            if parameter_location_value == ParameterLocation::Path && !is_required {
                return Err(Error::InvalidSpecification(format!(
                    "Path parameter must be required at {parameter_location}"
                )));
            }
            parameters.push(Parameter {
                name: required(&data, "name", &parameter_location)?,
                location: parameter_location_value,
                description: optional_or_empty(&data, "description")?,
                required: is_required,
                deprecated: flag(&data, "deprecated"),
                allow_empty_value: flag(&data, "allowEmptyValue"),
                schema: match data.get_str("schema") {
                    Some(raw) => Some(base.schemas.read_at(raw, &parameter_location.name("schema"))?),
                    None => None,
                },
                content: match isset(&data, "content") {
                    Some(raw) => base.media_types(raw, &parameter_location.name("content"))?,
                    None => Map::new(),
                },
                style: optional(&data, "style")?,
                explode: optional_flag(&data, "explode"),
                allow_reserved: flag(&data, "allowReserved"),
                extensions: shape::extensions(&data),
            });
        }
        Ok(parameters)
    }
}

/// The path's parameters with the operation's own replacing those of the
/// same [`Parameter::identity`].
fn merge(inherited: &[Parameter], own: Vec<Parameter>) -> Vec<Parameter> {
    let mut merged = inherited.to_vec();
    let mut identities: Vec<(String, usize)> = Vec::with_capacity(merged.len());
    for (index, parameter) in merged.iter().enumerate() {
        let identity = parameter.identity();
        match identities.iter_mut().find(|(seen, _)| *seen == identity) {
            Some(entry) => entry.1 = index,
            None => identities.push((identity, index)),
        }
    }
    for parameter in own {
        let identity = parameter.identity();
        match identities.iter().find(|(seen, _)| *seen == identity) {
            Some((_, index)) => merged[*index] = parameter,
            None => {
                identities.push((identity, merged.len()));
                merged.push(parameter);
            }
        }
    }
    merged
}
