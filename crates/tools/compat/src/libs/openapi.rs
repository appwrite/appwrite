//! Compat adapter for `openapi`: maps `tests/compat/openapi/spec.json` operations onto `utopia-openapi`.
//!
//! Documents and free-form values cross as `php_std::typed` values. A parsed
//! specification is dumped field by field, in the shape `Adapter.php` writes:
//! maps as `[key, value]` pairs, schemas as `{kind, ...keywords, ...annotations}`.

use std::hint::black_box;

use serde_json::{Value as Json, json};
use utopia_openapi::model::Map;
use utopia_openapi::shape;
use utopia_openapi::{
    AdditionalProperties, Annotations, Array, CompositeSchema, Composition, Dialect, Discriminator, Error,
    ExternalDocumentation, Header, HttpMethod, LocalResolver, MediaType, OpenApi2, OpenApi3, Operation, Parameter,
    PathItem, Reader, Reference, ResolutionContext, Resolver, Schema, SchemaKind, SchemaReader, SecurityRequirement,
    SecurityScheme, Server, Specification, Value, Version, decode, parse, parse_document,
};

use super::php_std::typed;
use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "openapi.parse",
    "openapi.operation",
    "openapi.operations_by_tag",
    "openapi.resolve_schema",
    "version.from",
    "dialect.for",
    "schema.read",
    "schema.read_parameter_fields",
    "composite.new",
    "value.object",
    "value.list",
    "value.string_list",
    "value.required_string",
    "value.optional_string",
    "value.nullable_int",
    "value.nullable_number",
    "value.extensions",
    "reference.is_local",
    "resolver.resolve",
    "resolver.resolve_object",
    "operation.security_names",
    "reader.read",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("parse_2_0", "Parse the OpenAPI 2.0 fixture from JSON"),
    ("parse_3_0", "Parse the OpenAPI 3.0 fixture from JSON"),
    ("parse_3_1", "Parse the OpenAPI 3.1 fixture from JSON"),
    ("parse_document", "Read the decoded OpenAPI 3.1 fixture"),
    ("schema_read", "Read an object schema with five properties"),
    ("resolve_schema", "Resolve a two-step schema reference chain"),
];

/// `Adapter::SCHEMA`.
const SCHEMA: &str = r##"{"type":"object","required":["id","name"],"properties":{"id":{"type":"integer","format":"int64"},"name":{"type":"string","minLength":1},"tag":{"type":["string","null"]},"status":{"type":"string","enum":["available","adopted"]},"parent":{"$ref":"#/components/schemas/Pet"}},"additionalProperties":false}"##;

/// `Adapter::REFERENCES`.
const REFERENCES: &str = r##"{"openapi":"3.1.0","info":{"title":"References","version":"1"},"components":{"schemas":{"Binary":{"type":"string","format":"binary"},"Alias":{"$ref":"#/components/schemas/Binary"},"Chained":{"$ref":"#/components/schemas/Alias"}}}}"##;

/// The fixtures of `packages/openapi/tests/Fixtures`.
fn fixture(name: &str) -> Result<&'static str, Fault> {
    Ok(match name {
        "openapi-2.0.json" => include_str!("../../../../../packages/openapi/tests/Fixtures/openapi-2.0.json"),
        "openapi-3.0.json" => include_str!("../../../../../packages/openapi/tests/Fixtures/openapi-3.0.json"),
        "openapi-3.1.json" => include_str!("../../../../../packages/openapi/tests/Fixtures/openapi-3.1.json"),
        _ => return Err(Fault::new(format!("unknown fixture {name}"))),
    })
}

fn fault(error: Error) -> Fault {
    Fault::new(error.to_string())
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "parse_2_0" | "parse_3_0" | "parse_3_1" => {
            let json = fixture(match name {
                "parse_2_0" => "openapi-2.0.json",
                "parse_3_0" => "openapi-3.0.json",
                _ => "openapi-3.1.json",
            })?;
            Bench::sync(move || {
                black_box(parse(black_box(json), None).is_ok());
            })
        }
        "parse_document" => {
            let document = utopia_openapi::decode_document(fixture("openapi-3.1.json")?.as_bytes()).map_err(fault)?;
            Bench::sync(move || {
                black_box(parse_document(black_box(&document), None).is_ok());
            })
        }
        "schema_read" => {
            let raw = decode(SCHEMA.as_bytes()).map_err(fault)?;
            let reader = SchemaReader::new(Dialect::for_version(Version::V3_1));
            Bench::sync(move || {
                black_box(reader.read(black_box(&raw), "#/components/schemas/Pet").is_ok());
            })
        }
        "resolve_schema" => {
            let spec = parse(REFERENCES, None).map_err(fault)?;
            Bench::sync(move || {
                black_box(spec.resolve_schema(black_box(&spec.schemas["Chained"])));
            })
        }
        _ => return Err(Fault::new(format!("openapi: no benchmark `{name}`"))),
    })
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn version(name: &str) -> Result<Version, Fault> {
    Ok(match name {
        "2.0" => Version::V2,
        "3.0" => Version::V3_0,
        "3.1" => Version::V3_1,
        _ => return Err(Fault::new(format!("unknown version {name}"))),
    })
}

fn method(name: &str) -> Result<HttpMethod, Fault> {
    HttpMethod::from_name(name).ok_or_else(|| Fault::new(format!("unknown method {name}")))
}

fn array(value: &Json) -> Result<Array, Fault> {
    typed::decode_array(value)
}

/// `(array) Typed::decode($value)`.
fn cast_array(value: Option<&Json>) -> Result<Array, Fault> {
    match value.map(typed::decode).transpose()? {
        None | Some(Value::Null) => Ok(Array::new()),
        Some(Value::Array(a) | Value::Object(a)) => Ok(a),
        Some(_) => Err(Fault::new("expected an array")),
    }
}

fn opt_decode(value: Option<&Json>) -> Result<Value, Fault> {
    value.map(typed::decode).transpose().map(Option::unwrap_or_default)
}

/// `{text} | {fixture} | {document}`, optional `{version}`.
fn parse_args(a: &Args<'_>) -> Result<Result<Specification, Error>, Fault> {
    let version = a.opt_str("version")?.map(version).transpose()?;
    if let Some(name) = a.opt_str("fixture")? {
        return Ok(parse(fixture(name)?, version));
    }
    if let Some(text) = a.opt("text") {
        let text = crate::adapter::bytes(text).ok_or_else(|| Fault::new("text must be a string"))?;
        return Ok(parse(text, version));
    }
    Ok(parse_document(&array(a.value("document")?)?, version))
}

fn locate<'s>(spec: &'s Specification, at: &[Json]) -> Option<&'s Schema> {
    let s = |i: usize| at.get(i).and_then(Json::as_str);
    match s(0)? {
        "schemas" => spec.schemas.get(s(1)?),
        "property" => spec.schemas.get(s(1)?)?.as_object()?.properties.get(s(2)?),
        "response" => spec
            .paths
            .get(s(1)?)?
            .operation(HttpMethod::from_name(s(2)?)?)?
            .responses
            .get(s(3)?)?
            .content
            .get(s(4)?)?
            .schema
            .as_ref(),
        "body" => spec
            .paths
            .get(s(1)?)?
            .operation(HttpMethod::from_name(s(2)?)?)?
            .request_body
            .as_ref()?
            .content
            .get(s(3)?)?
            .schema
            .as_ref(),
        _ => None,
    }
}

fn text(s: &str) -> Json {
    Json::String(s.to_owned())
}

fn pairs<T>(map: &Map<T>, dump: impl Fn(&T) -> Json) -> Json {
    Json::Array(map.iter().map(|(k, v)| json!([k, dump(v)])).collect())
}

fn extensions(a: &Array) -> Json {
    typed::dump(&Value::Array(a.clone()))
}

fn number(n: Option<php_std::value::Number>) -> Json {
    typed::dump(&match n {
        None => Value::Null,
        Some(php_std::value::Number::Int(i)) => Value::Int(i),
        Some(php_std::value::Number::Float(f)) => Value::Float(f),
    })
}

fn spec(spec: &Specification) -> Json {
    let info = &spec.info;
    json!({
        "version": spec.version.as_str(),
        "sourceVersion": spec.source_version,
        "jsonSchemaDialect": spec.json_schema_dialect,
        "info": {
            "title": info.title,
            "description": info.description,
            "version": info.version,
            "termsOfService": info.terms_of_service,
            "contact": info.contact.as_ref().map(|c| json!({
                "name": c.name,
                "url": c.url,
                "email": c.email,
                "extensions": extensions(&c.extensions),
            })),
            "license": info.license.as_ref().map(|l| json!({
                "name": l.name,
                "url": l.url,
                "identifier": l.identifier,
                "extensions": extensions(&l.extensions),
            })),
            "extensions": extensions(&info.extensions),
        },
        "servers": spec.servers.iter().map(server).collect::<Vec<_>>(),
        "tags": pairs(&spec.tags, |tag| json!({
            "name": tag.name,
            "description": tag.description,
            "externalDocumentation": external(&tag.external_documentation),
            "extensions": extensions(&tag.extensions),
        })),
        "paths": pairs(&spec.paths, path_item),
        "schemas": pairs(&spec.schemas, |s| schema(Some(s))),
        "securitySchemes": pairs(&spec.security_schemes, security_scheme),
        "security": spec.security.iter().map(requirement).collect::<Vec<_>>(),
        "extensions": extensions(&spec.extensions),
        "externalDocumentation": external(&spec.external_documentation),
        "operations": spec.operations().map(|o| json!([o.path, o.method.as_str()])).collect::<Vec<_>>(),
    })
}

fn external(docs: &Option<ExternalDocumentation>) -> Json {
    match docs {
        None => Json::Null,
        Some(d) => json!({"url": d.url, "description": d.description, "extensions": extensions(&d.extensions)}),
    }
}

fn server(server: &Server) -> Json {
    json!({
        "url": server.url,
        "description": server.description,
        "variables": pairs(&server.variables, |v| json!({
            "default": v.default,
            "enum": v.enum_values,
            "description": v.description,
            "extensions": extensions(&v.extensions),
        })),
        "extensions": extensions(&server.extensions),
    })
}

fn requirement(requirement: &SecurityRequirement) -> Json {
    pairs(&requirement.schemes, |scopes| json!(scopes))
}

fn security_scheme(scheme: &SecurityScheme) -> Json {
    json!({
        "type": scheme.kind.as_str(),
        "description": scheme.description,
        "name": scheme.name,
        "location": scheme.location.map(|l| l.as_str()),
        "scheme": scheme.scheme,
        "bearerFormat": scheme.bearer_format,
        "flows": pairs(&scheme.flows, |flow| json!({
            "authorizationUrl": flow.authorization_url,
            "tokenUrl": flow.token_url,
            "refreshUrl": flow.refresh_url,
            "scopes": pairs(&flow.scopes, |d| text(d)),
        })),
        "openIdConnectUrl": scheme.open_id_connect_url,
        "extensions": extensions(&scheme.extensions),
    })
}

fn path_item(item: &PathItem) -> Json {
    json!({
        "path": item.path,
        "operations": item.operations.iter().map(|o| json!([o.method.as_str(), operation(Some(o))])).collect::<Vec<_>>(),
        "parameters": item.parameters.iter().map(parameter).collect::<Vec<_>>(),
        "summary": item.summary,
        "description": item.description,
        "servers": item.servers.iter().map(server).collect::<Vec<_>>(),
        "extensions": extensions(&item.extensions),
    })
}

fn operation(operation: Option<&Operation>) -> Json {
    let Some(o) = operation else { return Json::Null };
    json!({
        "id": o.id,
        "method": o.method.as_str(),
        "path": o.path,
        "tags": o.tags,
        "summary": o.summary,
        "description": o.description,
        "deprecated": o.deprecated,
        "parameters": o.parameters.iter().map(parameter).collect::<Vec<_>>(),
        "requestBody": o.request_body.as_ref().map(|b| json!({
            "description": b.description,
            "required": b.required,
            "content": pairs(&b.content, media_type),
            "extensions": extensions(&b.extensions),
        })),
        "responses": pairs(&o.responses, |r| json!({
            "description": r.description,
            "headers": pairs(&r.headers, header),
            "content": pairs(&r.content, media_type),
            "extensions": extensions(&r.extensions),
        })),
        "security": o.security.iter().map(requirement).collect::<Vec<_>>(),
        "servers": o.servers.iter().map(server).collect::<Vec<_>>(),
        "externalDocumentation": external(&o.external_documentation),
        "extensions": extensions(&o.extensions),
        "accepted": o.accepted_security_scheme_names(),
        "required": o.required_security_scheme_names(),
    })
}

fn parameter(p: &Parameter) -> Json {
    json!({
        "name": p.name,
        "location": p.location.as_str(),
        "description": p.description,
        "required": p.required,
        "deprecated": p.deprecated,
        "allowEmptyValue": p.allow_empty_value,
        "schema": schema(p.schema.as_ref()),
        "content": pairs(&p.content, media_type),
        "style": p.style,
        "explode": p.explode,
        "allowReserved": p.allow_reserved,
        "extensions": extensions(&p.extensions),
        "identity": bytes_value(p.identity().as_bytes()),
    })
}

fn media_type(m: &MediaType) -> Json {
    json!({
        "schema": schema(m.schema.as_ref()),
        "example": typed::dump(&m.example),
        "examples": pairs(&m.examples, |e| json!({
            "summary": e.summary,
            "description": e.description,
            "value": typed::dump(&e.value),
            "externalValue": e.external_value,
            "extensions": extensions(&e.extensions),
        })),
        "encoding": pairs(&m.encoding, |e| json!({
            "contentType": e.content_type,
            "headers": pairs(&e.headers, header),
            "style": e.style,
            "explode": e.explode,
            "allowReserved": e.allow_reserved,
            "extensions": extensions(&e.extensions),
        })),
        "extensions": extensions(&m.extensions),
    })
}

fn header(h: &Header) -> Json {
    json!({
        "description": h.description,
        "required": h.required,
        "deprecated": h.deprecated,
        "schema": schema(h.schema.as_ref()),
        "content": pairs(&h.content, media_type),
        "style": h.style,
        "explode": h.explode,
        "extensions": extensions(&h.extensions),
    })
}

fn schema(schema: Option<&Schema>) -> Json {
    let Some(s) = schema else { return Json::Null };
    let mut out = serde_json::Map::new();
    out.insert("kind".into(), text(s.kind.name()));
    let fields = match &s.kind {
        SchemaKind::Object(o) => json!({
            "properties": pairs(&o.properties, |p| self::schema(Some(p))),
            "required": o.required,
            "additionalProperties": match &o.additional_properties {
                None => Json::Null,
                Some(AdditionalProperties::Allowed(b)) => Json::Bool(*b),
                Some(AdditionalProperties::Schema(s)) => self::schema(Some(s)),
            },
            "minProperties": o.min_properties,
            "maxProperties": o.max_properties,
        }),
        SchemaKind::Array(a) => json!({
            "items": self::schema(Some(&a.items)),
            "minItems": a.min_items,
            "maxItems": a.max_items,
            "uniqueItems": a.unique_items,
        }),
        SchemaKind::String(k) => json!({
            "minLength": k.min_length,
            "maxLength": k.max_length,
            "pattern": k.pattern,
            "enumName": k.enum_name,
            "enumKeys": k.enum_keys,
            "open": k.open,
        }),
        SchemaKind::Integer(n) | SchemaKind::Number(n) => json!({
            "minimum": number(n.minimum),
            "maximum": number(n.maximum),
            "exclusiveMinimum": n.exclusive_minimum,
            "exclusiveMaximum": n.exclusive_maximum,
            "multipleOf": number(n.multiple_of),
        }),
        SchemaKind::Composite(c) => json!({
            "composition": c.composition.map(Composition::as_str),
            "schemas": c.schemas.iter().map(|m| self::schema(Some(m))).collect::<Vec<_>>(),
            "not": self::schema(c.not.as_deref()),
            "discriminator": c.discriminator.as_ref().map(|d| json!({
                "propertyName": d.property_name,
                "mapping": pairs(&d.mapping, |r| text(r)),
                "extensions": extensions(&d.extensions),
            })),
            "stringEnum": self::schema(c.string_enum()),
            "openStringEnumBranch": self::schema(c.open_string_enum_branch()),
            "conditionalReferences": c.conditional_references(s).iter().map(|case| json!({
                "reference": case.reference,
                "conditions": case.conditions.iter().map(|condition| json!({
                    "propertyName": condition.property_name,
                    "value": typed::dump(&condition.value),
                })).collect::<Vec<_>>(),
            })).collect::<Vec<_>>(),
        }),
        SchemaKind::Reference(r) => json!({"reference": r}),
        SchemaKind::Boolean | SchemaKind::Any | SchemaKind::Never => json!({}),
    };
    if let Json::Object(fields) = fields {
        out.extend(fields);
    }
    let annotations = json!({
        "title": s.title,
        "description": s.description,
        "nullable": s.nullable,
        "default": typed::dump(&s.default),
        "enum": typed::dump(&Value::Array(Array::from_list(s.enum_values.clone()))),
        "format": s.format,
        "readOnly": s.read_only,
        "writeOnly": s.write_only,
        "deprecated": s.deprecated,
        "example": typed::dump(&s.example),
        "extensions": extensions(&s.extensions),
    });
    if let Json::Object(annotations) = annotations {
        out.extend(annotations);
    }
    Json::Object(out)
}

fn result<T>(r: Result<T, Error>, dump: impl FnOnce(T) -> Json) -> Outcome {
    match r {
        Ok(v) => Outcome::Ok(dump(v)),
        Err(e) => err(e),
    }
}

pub async fn call(op: &str, args: &Json, _session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(match op {
        "openapi.parse" => result(parse_args(&a)?, |s| spec(&s)),
        "openapi.operation" => match parse_args(&a)? {
            Ok(s) => {
                let method = method(a.str("method")?)?;
                Outcome::Ok(operation(s.paths.get(a.str("path")?).and_then(|p| p.operation(method))))
            }
            Err(e) => err(e),
        },
        "openapi.operations_by_tag" => match parse_args(&a)? {
            Ok(s) => Outcome::Ok(Json::Array(
                s.operations_by_tag(a.str("tag")?).map(|o| json!([o.path, o.method.as_str(), o.id])).collect(),
            )),
            Err(e) => err(e),
        },
        "openapi.resolve_schema" => match parse_args(&a)? {
            Ok(s) => {
                let at = a.array("at")?;
                let schema =
                    locate(&s, at).ok_or_else(|| Fault::new(format!("no schema at {}", Json::Array(at.clone()))))?;
                let resolved = s.resolve_schema(schema);
                let name = s.schemas.iter().find(|(_, v)| std::ptr::eq(*v, resolved)).map(|(k, _)| k.clone());
                Outcome::Ok(json!({
                    "name": name,
                    "same": std::ptr::eq(resolved, schema),
                    "schema": self::schema(Some(resolved)),
                }))
            }
            Err(e) => err(e),
        },
        "version.from" => result(Version::from_document_version(a.str("version")?), |v| text(v.as_str())),
        "dialect.for" => {
            let d = Dialect::for_version(version(a.str("version")?)?);
            Outcome::Ok(
                json!({"booleanSchemas": d.boolean_schemas, "typeArrays": d.type_arrays, "constKeyword": d.const_keyword}),
            )
        }
        "schema.read" => {
            let reader = SchemaReader::for_version(version(a.opt_str("version")?.unwrap_or("3.1"))?);
            let raw = opt_decode(a.opt("raw"))?;
            result(reader.read(&raw, a.opt_str("location")?.unwrap_or("#/x")), |s| schema(Some(&s)))
        }
        "schema.read_parameter_fields" => {
            let reader = SchemaReader::for_version(version(a.opt_str("version")?.unwrap_or("3.1"))?);
            let data = array(a.value("data")?)?;
            result(reader.read_parameter_fields(&data, a.opt_str("location")?.unwrap_or("#/x")), |s| schema(Some(&s)))
        }
        "composite.new" => {
            let reader = SchemaReader::for_version(Version::V3_1);
            let mut schemas = Vec::new();
            for raw in a.opt("schemas").and_then(Json::as_array).into_iter().flatten() {
                match reader.read(&typed::decode(raw)?, "#/member") {
                    Ok(s) => schemas.push(s),
                    Err(e) => return Ok(err(e)),
                }
            }
            let not = match a.opt("not").filter(|v| !v.is_null()) {
                Some(raw) => match reader.read(&typed::decode(raw)?, "#/not") {
                    Ok(s) => Some(s),
                    Err(e) => return Ok(err(e)),
                },
                None => None,
            };
            let discriminator = match a.opt("discriminator").filter(|v| !v.is_null()) {
                Some(d) => {
                    let d = Args(d);
                    let mapping = cast_array(d.opt("mapping"))?
                        .iter()
                        .map(|(k, v)| match v {
                            Value::Str(s) => Ok((shape_key(k), String::from_utf8_lossy(s).into_owned())),
                            _ => Err(Fault::new("mapping values must be strings")),
                        })
                        .collect::<Result<Map<String>, Fault>>()?;
                    Some(Discriminator {
                        property_name: d.str("propertyName")?.to_owned(),
                        mapping,
                        extensions: cast_array(d.opt("extensions"))?,
                    })
                }
                None => None,
            };
            let composition = match a.opt_str("composition")? {
                Some("oneOf") => Some(Composition::OneOf),
                Some("anyOf") => Some(Composition::AnyOf),
                Some("allOf") => Some(Composition::AllOf),
                Some(other) => return Err(Fault::new(format!("unknown composition {other}"))),
                None => None,
            };
            let annotations = Annotations {
                title: a.opt_str("title")?.map(str::to_owned),
                description: a.opt_str("description")?.unwrap_or("").to_owned(),
                nullable: a.opt_bool("nullable")?.unwrap_or(false),
                default: opt_decode(a.opt("default"))?,
                enum_values: cast_array(a.opt("enum"))?.values().cloned().collect(),
                format: a.opt_str("format")?.map(str::to_owned),
                read_only: a.opt_bool("readOnly")?.unwrap_or(false),
                write_only: a.opt_bool("writeOnly")?.unwrap_or(false),
                deprecated: a.opt_bool("deprecated")?.unwrap_or(false),
                example: opt_decode(a.opt("example"))?,
                extensions: cast_array(a.opt("extensions"))?,
            };
            let built = CompositeSchema::compose(
                composition,
                schemas,
                not,
                discriminator,
                annotations,
                a.opt_str("location")?.unwrap_or("#"),
            );
            result(built, |s| schema(Some(&s)))
        }
        "value.object" => {
            let value = opt_decode(a.opt("value"))?;
            result(shape::object(&value, a.str("location")?), |o| typed::dump(&Value::Array(o.into_owned())))
        }
        "value.list" => {
            let value = opt_decode(a.opt("value"))?;
            result(shape::list(&value, a.str("location")?), |l| typed::dump(&Value::Array(l.clone())))
        }
        "value.string_list" => {
            let value = opt_decode(a.opt("value"))?;
            result(shape::string_list(&value, a.str("location")?), |l| typed::dump(&Value::Array(l.clone())))
        }
        "value.required_string" => {
            let data = array(a.value("data")?)?;
            result(shape::required_string(&data, a.str("key")?, a.str("location")?), |s| bytes_value(s))
        }
        "value.optional_string" => {
            let data = array(a.value("data")?)?;
            result(shape::optional_string(&data, a.str("key")?), |s| s.map_or(Json::Null, |s| bytes_value(s)))
        }
        "value.nullable_int" => {
            let value = opt_decode(a.opt("value"))?;
            result(shape::nullable_int(&value, a.str("location")?), |i| json!(i))
        }
        "value.nullable_number" => {
            let value = opt_decode(a.opt("value"))?;
            result(shape::nullable_number(&value, a.str("location")?), number)
        }
        "value.extensions" => Outcome::Ok(extensions(&shape::extensions(&array(a.value("data")?)?))),
        "reference.is_local" => Outcome::ok(Reference::new(a.str("value")?).is_local()),
        "resolver.resolve" => {
            let document = array(a.value("document")?)?;
            let context = ResolutionContext {
                trail: strings(a.opt("trail"))?,
                base_uri: a.opt_str("baseUri")?.map(str::to_owned),
            };
            result(LocalResolver::new(&document).resolve(&Reference::new(a.str("reference")?), &context), |v| {
                typed::dump(&v)
            })
        }
        "resolver.resolve_object" => {
            let document = array(a.value("document")?)?;
            result(LocalResolver::new(&document).resolve_object(a.str("reference")?, strings(a.opt("trail"))?), |v| {
                typed::dump(&v)
            })
        }
        "operation.security_names" => {
            let mut operation = Operation::new("test", HttpMethod::Get, "/test");
            for schemes in a.array("security")? {
                let schemes = array(schemes)?;
                let mut requirement = SecurityRequirement::default();
                for (name, scopes) in schemes.iter() {
                    let scopes = match scopes {
                        Value::Array(list) => list
                            .values()
                            .map(|s| match s {
                                Value::Str(s) => Ok(String::from_utf8_lossy(s).into_owned()),
                                _ => Err(Fault::new("scopes must be strings")),
                            })
                            .collect::<Result<Vec<_>, _>>()?,
                        _ => return Err(Fault::new("scopes must be a list")),
                    };
                    requirement.schemes.insert(shape_key(name), scopes);
                }
                operation.security.push(requirement);
            }
            Outcome::Ok(json!({
                "accepted": operation.accepted_security_scheme_names(),
                "required": operation.required_security_scheme_names(),
                "security": operation.security.iter().map(requirement).collect::<Vec<_>>(),
            }))
        }
        "reader.read" => {
            let document = array(a.value("document")?)?;
            let declared = version(a.str("version")?)?;
            let schemas = SchemaReader::for_version(version(a.opt_str("dialect")?.unwrap_or(a.str("version")?))?);
            let source = a.str("sourceVersion")?;
            let read = match a.str("reader")? {
                "2.0" => OpenApi2::new(&document, declared, source, schemas).read(),
                "3.0" | "3.1" => OpenApi3::new(&document, declared, source, schemas).read(),
                other => return Err(Fault::new(format!("unknown reader {other}"))),
            };
            result(read, |s| spec(&s))
        }
        _ => return Err(Fault::new(format!("openapi: unknown operation `{op}`"))),
    })
}

fn shape_key(key: php_std::types::KeyRef<'_>) -> String {
    match key {
        php_std::types::KeyRef::Int(i) => i.to_string(),
        php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

fn strings(value: Option<&Json>) -> Result<Vec<String>, Fault> {
    match value {
        None | Some(Json::Null) => Ok(Vec::new()),
        Some(Json::Array(items)) => items
            .iter()
            .map(|i| i.as_str().map(str::to_owned).ok_or_else(|| Fault::new("trail items must be strings")))
            .collect(),
        Some(_) => Err(Fault::new("trail must be a list")),
    }
}
