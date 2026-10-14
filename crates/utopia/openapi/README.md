# Utopia OpenAPI

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/openapi`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/openapi) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

OpenAPI 2.0, 3.0 and 3.1 documents read into one version-independent, immutable model.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-openapi = { git = "https://github.com/utopia-rs/openapi" }
```

An OpenAPI document describes an HTTP API: its servers, paths, operations, parameters, request and response bodies, schemas and security. This library reads documents written in OpenAPI 2.0 (Swagger), 3.0 or 3.1 into one model, so code that generates SDKs, documentation or routes from a document works the same whichever version it was written in. Errors name the JSON Pointer location of the problem, so they can be traced back into the source.

## Read a document

Pass the JSON text. The version is detected from `swagger` or `openapi`; pass one to insist on it.

```rust
use utopia_openapi::{HttpMethod, Version, parse};

let spec = parse(br#"{
    "openapi": "3.0.3",
    "info": {"title": "Pets", "version": "1.0.0"},
    "paths": {"/pets": {"get": {
        "operationId": "listPets",
        "tags": ["pets"],
        "parameters": [{"name": "limit", "in": "query", "schema": {"type": "integer", "minimum": 1}}],
        "responses": {"200": {"description": "The pets"}}
    }}}
}"#, Some(Version::V3_0))?;

let list = spec.paths["/pets"].operation(HttpMethod::Get).unwrap();
assert_eq!(list.id, "listPets");
assert_eq!(list.parameters[0].name, "limit");
assert_eq!(spec.operations_by_tag("pets").count(), 1);
```

## One model for every version

A 2.0 document reads into the same shape as a 3.x one: `host`, `basePath` and `schemes` become servers, a `body` parameter becomes a request body with one media type per `consumes`, and a response `schema` gets one media type per `produces`.

```rust
use utopia_openapi::{HttpMethod, parse};

let spec = parse(br##"{
    "swagger": "2.0",
    "info": {"title": "Legacy", "version": "2"},
    "host": "api.example.com",
    "basePath": "/v1",
    "schemes": ["https"],
    "consumes": ["application/json"],
    "definitions": {"Pet": {"type": "object", "properties": {"name": {"type": "string"}}}},
    "paths": {"/pets": {"post": {
        "parameters": [{"name": "pet", "in": "body", "required": true, "schema": {"$ref": "#/definitions/Pet"}}],
        "responses": {"200": {"description": "OK"}}
    }}}
}"##, None)?;

assert_eq!(spec.servers[0].url, "https://api.example.com/v1");
let body = spec.paths["/pets"].operation(HttpMethod::Post).unwrap().request_body.as_ref().unwrap();
assert!(body.required);
assert_eq!(body.content["application/json"].schema.as_ref().unwrap().reference(), Some("#/definitions/Pet"));
```

## Follow schema references

Schema references stay references, since recursive schemas are legal. Resolve one when you need the schema it names: chains of references are followed, and a cycle or an unknown name stops at the last schema reached.

```rust
use utopia_openapi::{SchemaKind, parse};

let spec = parse(br##"{"openapi": "3.1.0", "info": {"title": "Refs", "version": "1"}, "components": {"schemas": {
    "Id": {"type": "string", "format": "uuid"},
    "UserId": {"$ref": "#/components/schemas/Id"},
    "User": {"type": "object", "properties": {"id": {"$ref": "#/components/schemas/UserId"}}}
}}}"##, None)?;

let id = &spec.schemas["User"].as_object().unwrap().properties["id"];
let resolved = spec.resolve_schema(id);
assert!(matches!(resolved.kind, SchemaKind::String(_)));
assert_eq!(resolved.format.as_deref(), Some("uuid"));
```

## Handle errors

Every failure is an [`Error`](crate::Error) whose message names where it happened.

```rust
use utopia_openapi::{Error, parse};

let error = parse(br#"{"openapi": "3.1.0", "info": {"version": "1"}}"#, None).unwrap_err();
assert!(matches!(error, Error::InvalidSpecification(_)));
assert_eq!(error.to_string(), "Expected string #/info/title");

let error = parse(br#"{"swagger": "1.2"}"#, None).unwrap_err();
assert_eq!(error.to_string(), "Unsupported OpenAPI version: 1.2");
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
