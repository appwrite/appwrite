//! OpenAPI 2.0 reader (PHP `Parser\OpenAPI2`).

use crate::error::{InvalidSpecification, OpenApiError};
use crate::json::Json;
use crate::model::{
    Encoding, Example, HttpMethod, MediaType, ObjectSchema, Operation, Parameter,
    ParameterLocation, PathItem, RequestBody, Response, Schema, Server,
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
    let consumes = media_names(
        ctx.document
            .get("consumes")
            .unwrap_or(crate::json::empty_array()),
        "#/consumes",
    )?;
    let produces = media_names(
        ctx.document
            .get("produces")
            .unwrap_or(crate::json::empty_array()),
        "#/produces",
    )?;

    let mut schemas = IndexMap::new();
    for (name, raw) in Value::object(
        ctx.document
            .get("definitions")
            .unwrap_or(crate::json::empty_object()),
        "#/definitions",
    )? {
        schemas.insert(
            name.clone(),
            ctx.schemas.read(raw, &format!("#/definitions/{name}"))?,
        );
    }

    let mut security_schemes = IndexMap::new();
    for (name, raw) in Value::object(
        ctx.document
            .get("securityDefinitions")
            .unwrap_or(crate::json::empty_object()),
        "#/securityDefinitions",
    )? {
        let data = ctx.resolve_object(raw, &format!("#/securityDefinitions/{name}"))?;
        security_schemes.insert(
            name.clone(),
            ctx.parse_security_scheme(&data, &format!("#/securityDefinitions/{name}"), true)?,
        );
    }

    Ok(Specification {
        version: ctx.version,
        info: ctx.parse_info()?,
        servers: parse_open_api2_servers(ctx)?,
        tags: ctx.parse_tags()?,
        paths: parse_paths(ctx, &security, &consumes, &produces)?,
        schemas,
        security_schemes,
        security,
        extensions: Value::extensions(&ctx.document),
        source_version: ctx.source_version.clone(),
        json_schema_dialect: None,
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

fn parse_open_api2_servers(ctx: &DocumentReader) -> Result<Vec<Server>, OpenApiError> {
    let host = Value::optional_string(&ctx.document, "host")?;
    let base_path = Value::optional_string(&ctx.document, "basePath")?.unwrap_or_default();
    let mut schemes = media_names(
        ctx.document
            .get("schemes")
            .unwrap_or(crate::json::empty_array()),
        "#/schemes",
    )?;
    let Some(host) = host else {
        return Ok(vec![]);
    };
    if schemes.is_empty() {
        schemes.push("http".into());
    }
    Ok(schemes
        .into_iter()
        .map(|scheme| {
            let mut url = format!("{scheme}://{host}");
            url.truncate(url.trim_end_matches('/').len());
            if base_path != "/" {
                url.push_str(&base_path);
            }
            Server::new(url)
        })
        .collect())
}

fn parse_paths(
    ctx: &DocumentReader,
    root_security: &[crate::model::SecurityRequirement],
    root_consumes: &[String],
    root_produces: &[String],
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
        let path_parameters = raw_parameters(
            ctx,
            data.get("parameters").unwrap_or(crate::json::empty_array()),
            &format!("{location}/parameters"),
        )?;
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
                    root_consumes,
                    root_produces,
                    &format!("{location}/{}", method.as_str()),
                )?,
            );
        }
        let mut parsed_path_parameters = Vec::new();
        for (index, parameter) in path_parameters.iter().enumerate() {
            let in_ = parameter.get("in").and_then(Json::as_str);
            if in_ != Some("body") && in_ != Some("formData") {
                parsed_path_parameters.push(parse_parameter(
                    ctx,
                    parameter,
                    &format!("{location}/parameters/{index}"),
                )?);
            }
        }
        paths.insert(
            path.clone(),
            PathItem {
                path: path.clone(),
                operations,
                parameters: parsed_path_parameters,
                summary: String::new(),
                description: String::new(),
                servers: Vec::new(),
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
    path_parameters: &[IndexMap<String, Json>],
    root_security: &[crate::model::SecurityRequirement],
    root_consumes: &[String],
    root_produces: &[String],
    location: &str,
) -> Result<Operation, OpenApiError> {
    let data = Value::object(raw, location)?;
    let operation_parameters = raw_parameters(
        ctx,
        data.get("parameters").unwrap_or(crate::json::empty_array()),
        &format!("{location}/parameters"),
    )?;
    let raw_parameters = merge_raw_parameters(path_parameters, &operation_parameters);
    let consumes = if data.contains_key("consumes") {
        media_names(
            data.get("consumes").unwrap_or(&Json::Null),
            &format!("{location}/consumes"),
        )?
    } else {
        root_consumes.to_vec()
    };
    let produces = if data.contains_key("produces") {
        media_names(
            data.get("produces").unwrap_or(&Json::Null),
            &format!("{location}/produces"),
        )?
    } else {
        root_produces.to_vec()
    };

    let mut parameters = Vec::new();
    let mut body = None;
    let mut form = Vec::new();
    for (index, parameter) in raw_parameters.iter().enumerate() {
        let in_ = parameter.get("in").and_then(Json::as_str);
        if in_ == Some("body") {
            if body.is_some() {
                return Err(InvalidSpecification(format!(
                    "Multiple body parameters at {location}"
                ))
                .into());
            }
            let schema = if parameter.contains_key("schema") {
                Some(ctx.schemas.read(
                    parameter.get("schema").unwrap_or(&Json::Null),
                    &format!("{location}/parameters/{index}/schema"),
                )?)
            } else {
                None
            };
            let mut content = IndexMap::new();
            for media_name in &consumes {
                content.insert(media_name.clone(), MediaType::new(schema.clone()));
            }
            body = Some(RequestBody {
                description: Value::optional_string(parameter, "description")?.unwrap_or_default(),
                required: parameter.get("required").is_some_and(Json::php_bool),
                content,
                extensions: Value::extensions(parameter),
            });
        } else if in_ == Some("formData") {
            form.push(parameter);
        } else {
            parameters.push(parse_parameter(
                ctx,
                parameter,
                &format!("{location}/parameters/{index}"),
            )?);
        }
    }
    if body.is_some() && !form.is_empty() {
        return Err(InvalidSpecification(format!(
            "Body and formData parameters cannot coexist at {location}"
        ))
        .into());
    }
    if !form.is_empty() {
        body = Some(parse_form_body(ctx, &form, &consumes, location)?);
    }

    let mut responses = IndexMap::new();
    for (status, raw_response) in Value::object(
        data.get("responses").unwrap_or(&Json::Null),
        &format!("{location}/responses"),
    )? {
        let value = ctx.resolve_object(raw_response, &format!("{location}/responses/{status}"))?;
        let examples_by_media = open_api2_examples(
            value.get("examples").unwrap_or(crate::json::empty_object()),
            &format!("{location}/responses/{status}/examples"),
        )?;
        let schema = if value.contains_key("schema") {
            Some(ctx.schemas.read(
                value.get("schema").unwrap_or(&Json::Null),
                &format!("{location}/responses/{status}/schema"),
            )?)
        } else {
            None
        };
        let mut media_names: Vec<String> = produces.clone();
        for key in examples_by_media.keys() {
            if !media_names.contains(key) {
                media_names.push(key.clone());
            }
        }
        let mut content = IndexMap::new();
        for media_name in media_names {
            let example = examples_by_media
                .get(&media_name)
                .map_or(Json::Null, |e| e.value.clone());
            content.insert(
                media_name,
                MediaType {
                    schema: schema.clone(),
                    example,
                    examples: IndexMap::new(),
                    encoding: IndexMap::new(),
                    extensions: IndexMap::new(),
                },
            );
        }
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
                    true,
                )?,
                content,
                extensions: Value::extensions(&value),
            },
        );
    }

    Ok(Operation {
        id: Value::optional_string(data, "operationId")?.unwrap_or_default(),
        method,
        path: path.to_owned(),
        tags: if data.contains_key("tags") {
            Value::list(
                data.get("tags").unwrap_or(crate::json::empty_array()),
                &format!("{location}/tags"),
            )?
            .iter()
            .map(json_strval)
            .collect()
        } else {
            Vec::new()
        },
        summary: Value::optional_string(data, "summary")?.unwrap_or_default(),
        description: Value::optional_string(data, "description")?.unwrap_or_default(),
        deprecated: data.get("deprecated").is_some_and(Json::php_bool),
        parameters,
        request_body: body,
        responses,
        security: if data.contains_key("security") {
            ctx.parse_security(
                data.get("security").unwrap_or(&Json::Null),
                &format!("{location}/security"),
            )?
        } else {
            root_security.to_vec()
        },
        servers: Vec::new(),
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

fn raw_parameters(
    ctx: &DocumentReader,
    raw: &Json,
    location: &str,
) -> Result<Vec<IndexMap<String, Json>>, OpenApiError> {
    let mut parameters = Vec::new();
    for (index, item) in Value::list(raw, location)?.iter().enumerate() {
        parameters.push(ctx.resolve_object(item, &format!("{location}/{index}"))?);
    }
    Ok(parameters)
}

fn parse_parameter(
    ctx: &DocumentReader,
    data: &IndexMap<String, Json>,
    location: &str,
) -> Result<Parameter, OpenApiError> {
    let location_value = Value::required_string(data, "in", location)?;
    let parameter_location = ParameterLocation::from_str_php(&location_value).ok_or_else(|| {
        InvalidSpecification(format!(
            "Unsupported parameter location '{location_value}' at {location}/in"
        ))
    })?;
    let required = data.get("required").is_some_and(Json::php_bool);
    if parameter_location == ParameterLocation::Path && !required {
        return Err(
            InvalidSpecification(format!("Path parameter must be required at {location}")).into(),
        );
    }
    Ok(Parameter {
        name: Value::required_string(data, "name", location)?,
        location: parameter_location,
        description: Value::optional_string(data, "description")?.unwrap_or_default(),
        required,
        deprecated: false,
        allow_empty_value: data.get("allowEmptyValue").is_some_and(Json::php_bool),
        schema: Some(ctx.schemas.read_parameter_fields(data, location)?),
        content: IndexMap::new(),
        style: None,
        explode: None,
        allow_reserved: false,
        extensions: Value::extensions(data),
    })
}

fn parse_form_body(
    ctx: &DocumentReader,
    form: &[&IndexMap<String, Json>],
    consumes: &[String],
    location: &str,
) -> Result<RequestBody, OpenApiError> {
    let mut properties = IndexMap::new();
    let mut required = Vec::new();
    let mut encoding = IndexMap::new();
    for (index, parameter) in form.iter().enumerate() {
        let name =
            Value::required_string(parameter, "name", &format!("{location}/parameters/{index}"))?;
        properties.insert(
            name.clone(),
            ctx.schemas
                .read_parameter_fields(parameter, &format!("{location}/parameters/{index}"))?,
        );
        if parameter.get("required").is_some_and(Json::php_bool) {
            required.push(name.clone());
        }
        encoding.insert(
            name,
            Encoding {
                content_type: if parameter.get("type").and_then(Json::as_str) == Some("file") {
                    Some("application/octet-stream".into())
                } else {
                    None
                },
                extensions: Value::extensions(parameter),
                ..Encoding::default()
            },
        );
    }
    let schema = Schema::Object(Box::new(ObjectSchema {
        meta: crate::model::SchemaMeta::default(),
        properties,
        required: required.clone(),
        additional_properties: None,
        min_properties: None,
        max_properties: None,
    }));
    let consumes = if consumes.is_empty() {
        vec!["application/x-www-form-urlencoded".to_owned()]
    } else {
        consumes.to_vec()
    };
    let mut content = IndexMap::new();
    for media_name in consumes {
        content.insert(
            media_name,
            MediaType {
                schema: Some(schema.clone()),
                example: Json::Null,
                examples: IndexMap::new(),
                encoding: encoding.clone(),
                extensions: IndexMap::new(),
            },
        );
    }
    Ok(RequestBody {
        description: String::new(),
        required: !required.is_empty(),
        content,
        extensions: IndexMap::new(),
    })
}

fn open_api2_examples(
    raw: &Json,
    location: &str,
) -> Result<IndexMap<String, Example>, OpenApiError> {
    let mut examples = IndexMap::new();
    for (media_name, value) in Value::object(raw, location)? {
        examples.insert(
            media_name.clone(),
            Example {
                value: value.clone(),
                ..Example::default()
            },
        );
    }
    Ok(examples)
}

fn merge_raw_parameters(
    inherited: &[IndexMap<String, Json>],
    operation: &[IndexMap<String, Json>],
) -> Vec<IndexMap<String, Json>> {
    let mut merged = inherited.to_vec();
    let mut indexes = IndexMap::new();
    for (index, parameter) in merged.iter().enumerate() {
        indexes.insert(raw_parameter_identity(parameter), index);
    }
    for parameter in operation {
        let identity = raw_parameter_identity(parameter);
        if let Some(&idx) = indexes.get(&identity) {
            merged[idx].clone_from(parameter);
        } else {
            indexes.insert(identity, merged.len());
            merged.push(parameter.clone());
        }
    }
    merged
}

fn raw_parameter_identity(parameter: &IndexMap<String, Json>) -> String {
    let in_ = parameter.get("in").map(json_strval).unwrap_or_default();
    let name = parameter.get("name").map(json_strval).unwrap_or_default();
    format!("{in_}\0{name}")
}

fn media_names(raw: &Json, location: &str) -> Result<Vec<String>, OpenApiError> {
    Ok(Value::list(raw, location)?
        .iter()
        .map(json_strval)
        .collect())
}
