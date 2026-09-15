//! OpenAPI 3.0 / 3.1 reader (PHP `Parser\OpenAPI3`).

use crate::error::{InvalidSpecification, OpenApiError};
use crate::json::Json;
use crate::model::{
    HttpMethod, Operation, Parameter, ParameterLocation, PathItem, RequestBody, Response,
};
use crate::parser::reader::{json_strval, pointer_escape, DocumentReader};
use crate::parser::value::Value;
use crate::specification::Specification;
use indexmap::IndexMap;

pub fn read(ctx: &DocumentReader) -> Result<Specification, OpenApiError> {
    let security = ctx.parse_security(
        ctx.document
            .get("security")
            .unwrap_or(crate::json::empty_array()),
        "#/security",
    )?;
    let servers = ctx.parse_servers(
        ctx.document
            .get("servers")
            .unwrap_or(crate::json::empty_array()),
        "#/servers",
    )?;
    let components = Value::object(
        ctx.document
            .get("components")
            .unwrap_or(crate::json::empty_object()),
        "#/components",
    )?;

    let mut schemas = IndexMap::new();
    for (name, raw) in Value::object(
        components
            .get("schemas")
            .unwrap_or(crate::json::empty_object()),
        "#/components/schemas",
    )? {
        schemas.insert(
            name.clone(),
            ctx.schemas
                .read(raw, &format!("#/components/schemas/{name}"))?,
        );
    }

    let mut security_schemes = IndexMap::new();
    for (name, raw) in Value::object(
        components
            .get("securitySchemes")
            .unwrap_or(crate::json::empty_object()),
        "#/components/securitySchemes",
    )? {
        let data = ctx.resolve_object(raw, &format!("#/components/securitySchemes/{name}"))?;
        security_schemes.insert(
            name.clone(),
            ctx.parse_security_scheme(
                &data,
                &format!("#/components/securitySchemes/{name}"),
                false,
            )?,
        );
    }

    let paths = parse_paths(ctx, &security, &servers)?;

    Ok(Specification {
        version: ctx.version,
        info: ctx.parse_info()?,
        servers,
        tags: ctx.parse_tags()?,
        paths,
        schemas,
        security_schemes,
        security,
        extensions: Value::extensions(&ctx.document),
        source_version: ctx.source_version.clone(),
        json_schema_dialect: Value::optional_string(&ctx.document, "jsonSchemaDialect")?,
        external_documentation: if ctx.document.contains_key("externalDocs") {
            Some(ctx.parse_external_documentation(
                ctx.document.get("externalDocs").unwrap_or(&Json::Null),
                "#/externalDocs",
            )?)
        } else {
            None
        },
    })
}

fn parse_paths(
    ctx: &DocumentReader,
    root_security: &[crate::model::SecurityRequirement],
    root_servers: &[crate::model::Server],
) -> Result<IndexMap<String, PathItem>, OpenApiError> {
    let mut paths = IndexMap::new();
    for (path, raw) in Value::object(
        ctx.document
            .get("paths")
            .unwrap_or(crate::json::empty_object()),
        "#/paths",
    )? {
        let location = format!("#/paths/{}", pointer_escape(path));
        let data = ctx.resolve_object(raw, &location)?;
        let path_parameters = parse_parameters(
            ctx,
            data.get("parameters").unwrap_or(crate::json::empty_array()),
            &format!("{location}/parameters"),
        )?;
        let path_servers = if data.contains_key("servers") {
            ctx.parse_servers(
                data.get("servers").unwrap_or(&Json::Null),
                &format!("{location}/servers"),
            )?
        } else {
            root_servers.to_vec()
        };
        let mut operations = IndexMap::new();
        for method in HttpMethod::cases() {
            if !data.contains_key(method.as_str()) {
                continue;
            }
            operations.insert(
                method.as_str().to_owned(),
                parse_operation(
                    ctx,
                    path,
                    *method,
                    data.get(method.as_str()).unwrap_or(&Json::Null),
                    &path_parameters,
                    root_security,
                    &path_servers,
                    &format!("{location}/{}", method.as_str()),
                )?,
            );
        }
        paths.insert(
            path.clone(),
            PathItem {
                path: path.clone(),
                operations,
                parameters: path_parameters,
                summary: Value::optional_string(&data, "summary")?.unwrap_or_default(),
                description: Value::optional_string(&data, "description")?.unwrap_or_default(),
                servers: path_servers,
                extensions: Value::extensions(&data),
            },
        );
    }
    Ok(paths)
}

#[allow(clippy::too_many_arguments)]
fn parse_operation(
    ctx: &DocumentReader,
    path: &str,
    method: HttpMethod,
    raw: &Json,
    path_parameters: &[Parameter],
    root_security: &[crate::model::SecurityRequirement],
    inherited_servers: &[crate::model::Server],
    location: &str,
) -> Result<Operation, OpenApiError> {
    let data = Value::object(raw, location)?;
    let operation_parameters = parse_parameters(
        ctx,
        data.get("parameters").unwrap_or(crate::json::empty_array()),
        &format!("{location}/parameters"),
    )?;
    let parameters = merge_parameters(path_parameters, &operation_parameters);
    let request_body = if data.contains_key("requestBody") {
        let value = ctx.resolve_object(
            data.get("requestBody").unwrap_or(&Json::Null),
            &format!("{location}/requestBody"),
        )?;
        Some(RequestBody {
            description: Value::optional_string(&value, "description")?.unwrap_or_default(),
            required: value.get("required").is_some_and(Json::php_bool),
            content: ctx.parse_media_types(
                value.get("content").unwrap_or(crate::json::empty_object()),
                &format!("{location}/requestBody/content"),
            )?,
            extensions: Value::extensions(&value),
        })
    } else {
        None
    };

    let mut responses = IndexMap::new();
    for (status, raw_response) in Value::object(
        data.get("responses").unwrap_or(&Json::Null),
        &format!("{location}/responses"),
    )? {
        let value = ctx.resolve_object(raw_response, &format!("{location}/responses/{status}"))?;
        responses.insert(
            status.clone(),
            Response {
                description: Value::required_string(
                    &value,
                    "description",
                    &format!("{location}/responses/{status}"),
                )?,
                headers: ctx.parse_headers(
                    value.get("headers").unwrap_or(crate::json::empty_object()),
                    &format!("{location}/responses/{status}/headers"),
                    false,
                )?,
                content: ctx.parse_media_types(
                    value.get("content").unwrap_or(crate::json::empty_object()),
                    &format!("{location}/responses/{status}/content"),
                )?,
                extensions: Value::extensions(&value),
            },
        );
    }

    let tags = if data.contains_key("tags") {
        Value::list(
            data.get("tags").unwrap_or(crate::json::empty_array()),
            &format!("{location}/tags"),
        )?
        .iter()
        .map(json_strval)
        .collect()
    } else {
        Vec::new()
    };
    let security = if data.contains_key("security") {
        ctx.parse_security(
            data.get("security").unwrap_or(&Json::Null),
            &format!("{location}/security"),
        )?
    } else {
        root_security.to_vec()
    };
    let servers = if data.contains_key("servers") {
        ctx.parse_servers(
            data.get("servers").unwrap_or(&Json::Null),
            &format!("{location}/servers"),
        )?
    } else {
        inherited_servers.to_vec()
    };

    Ok(Operation {
        id: Value::optional_string(data, "operationId")?.unwrap_or_default(),
        method,
        path: path.to_owned(),
        tags,
        summary: Value::optional_string(data, "summary")?.unwrap_or_default(),
        description: Value::optional_string(data, "description")?.unwrap_or_default(),
        deprecated: data.get("deprecated").is_some_and(Json::php_bool),
        parameters,
        request_body,
        responses,
        security,
        servers,
        external_documentation: if data.contains_key("externalDocs") {
            Some(ctx.parse_external_documentation(
                data.get("externalDocs").unwrap_or(&Json::Null),
                &format!("{location}/externalDocs"),
            )?)
        } else {
            None
        },
        extensions: Value::extensions(data),
    })
}

fn parse_parameters(
    ctx: &DocumentReader,
    raw: &Json,
    location: &str,
) -> Result<Vec<Parameter>, OpenApiError> {
    let mut parameters = Vec::new();
    for (index, item) in Value::list(raw, location)?.iter().enumerate() {
        let data = ctx.resolve_object(item, &format!("{location}/{index}"))?;
        let location_value = Value::required_string(&data, "in", &format!("{location}/{index}"))?;
        let parameter_location =
            ParameterLocation::from_str_php(&location_value).ok_or_else(|| {
                InvalidSpecification(format!(
                    "Unsupported parameter location '{location_value}' at {location}/{index}/in"
                ))
            })?;
        let required = data.get("required").is_some_and(Json::php_bool);
        if parameter_location == ParameterLocation::Path && !required {
            return Err(InvalidSpecification(format!(
                "Path parameter must be required at {location}/{index}"
            ))
            .into());
        }
        parameters.push(Parameter {
            name: Value::required_string(&data, "name", &format!("{location}/{index}"))?,
            location: parameter_location,
            description: Value::optional_string(&data, "description")?.unwrap_or_default(),
            required,
            deprecated: data.get("deprecated").is_some_and(Json::php_bool),
            allow_empty_value: data.get("allowEmptyValue").is_some_and(Json::php_bool),
            schema: if data.contains_key("schema") {
                Some(ctx.schemas.read(
                    data.get("schema").unwrap_or(&Json::Null),
                    &format!("{location}/{index}/schema"),
                )?)
            } else {
                None
            },
            content: if data.contains_key("content") {
                ctx.parse_media_types(
                    data.get("content").unwrap_or(&Json::Null),
                    &format!("{location}/{index}/content"),
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
            allow_reserved: data.get("allowReserved").is_some_and(Json::php_bool),
            extensions: Value::extensions(&data),
        });
    }
    Ok(parameters)
}

fn merge_parameters(inherited: &[Parameter], operation: &[Parameter]) -> Vec<Parameter> {
    let mut merged = inherited.to_vec();
    let mut indexes = IndexMap::new();
    for (index, parameter) in merged.iter().enumerate() {
        indexes.insert(parameter.identity(), index);
    }
    for parameter in operation {
        let identity = parameter.identity();
        if let Some(&idx) = indexes.get(&identity) {
            merged[idx] = parameter.clone();
        } else {
            indexes.insert(identity, merged.len());
            merged.push(parameter.clone());
        }
    }
    merged
}
