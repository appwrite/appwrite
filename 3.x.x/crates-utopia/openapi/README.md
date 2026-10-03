# utopia-openapi

OpenAPI 2.0 / 3.0 / 3.1 parser and canonical model. Rust port of [utopia-php/openapi](https://github.com/utopia-php/openapi) (`5962baf44f33`).

Parses a JSON document (string or decoded object) into a version-independent model: paths, operations, schemas, security, servers, and extensions.

## Install

```toml
utopia-openapi = { path = "../utopia-openapi" }
```

## Usage

```rust
use utopia_openapi::{Parser, Version};

let spec = Parser::parse(
    r#"{"openapi":"3.1.0","info":{"title":"Pets","version":"1"},"paths":{}}"#,
    None,
).unwrap();
assert_eq!(spec.version, Version::V31);
assert_eq!(spec.info.title, "Pets");
```

## API Reference

### `Parser`

| Method | Description |
|--------|-------------|
| `parse(input, version)` | PHP `Parser::parse`. `input` is JSON text, `serde_json::Value`, `Json`, or an object map. Optional `version` asserts the document family. |
| `read(&self, input, version)` | Instance form of `parse`. |

### `Version`

| Variant | PHP | Document strings |
|---------|-----|------------------|
| `V2` | `Version::V2` | `2.0` |
| `V30` | `Version::V3_0` | `3.0`, `3.0.x` |
| `V31` | `Version::V3_1` | `3.1`, `3.1.x` |
| `from_document_version` | `fromDocumentVersion` | Throws `UnsupportedVersion` otherwise. |

### `Specification`

Public fields match PHP: `version`, `info`, `servers`, `tags`, `paths`, `schemas`, `security_schemes` (`securitySchemes`), `security`, `extensions`, `source_version`, `json_schema_dialect`, `external_documentation`.

| Method | Description |
|--------|-------------|
| `operations()` | Flatten every path operation. |
| `operations_by_tag(tag)` | PHP `operationsByTag`. |

### Model types

`Info`, `Contact`, `License`, `Server`, `ServerVariable`, `Tag`, `ExternalDocumentation`, `PathItem`, `Operation`, `Parameter`, `ParameterLocation`, `RequestBody`, `Response`, `Header`, `MediaType`, `Encoding`, `Example`, `SecurityScheme`, `SecuritySchemeType`, `SecurityRequirement`, `OAuthFlow`, `HttpMethod`, `Discriminator`.

Schema tree (`Schema` enum): `Any`, `Never`, `String`, `Integer`, `Number`, `Boolean`, `Array`, `Object`, `Composite`, `Reference`. Common fields live on `SchemaMeta` (`enum_values` is PHP `enum`). `ObjectSchema::additional_properties` is `Option<AdditionalProperties>` (`Boolean` or nested `Schema`).

### Parser helpers

`Value` (object/list/string/int/number/extensions), `SchemaReader` + `Dialect`, `LocalResolver` / `Reference` / `ResolutionContext`.

### Errors

`ParseException`, `InvalidSpecification`, `UnsupportedVersion`, `CircularReference`, `ReferenceNotFound`, unified as `OpenApiError`.

## Deviations

- PHP `enum` schema field → `enum_values` (`enum` is a Rust keyword).
- PHP `stdClass` is `Json::Object`.
- Schema subclasses are `Schema` enum variants rather than inheritance.

## Tests

```bash
cargo test -p utopia-openapi
```

Ports `ParserTest`, `ValueTest`, `Schema/ReaderTest`, and `CrossVersionFixtureTest` (fixtures under `tests/fixtures`).

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/openapi/Cargo.toml
```
