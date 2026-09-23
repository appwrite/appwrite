# Utopia OpenAPI

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/openapi`](https://github.com/utopia-php/monorepo/tree/main/packages/openapi) — please open issues and pull requests there.

A framework-independent PHP library for parsing OpenAPI documents into one immutable, typed model.

OpenAPI 2.0, 3.0, and 3.1 documents all produce the same `Utopia\OpenAPI\Specification` object. Consumers can work with metadata, operations, schemas, requests, responses, and security without handling each source format separately.

## Supported versions

| Source format | Supported versions | Version enum |
| --- | --- | --- |
| OpenAPI (Swagger) 2 | `2.0` | `Version::V2` |
| OpenAPI 3.0 | `3.0` and all `3.0.x` patch releases | `Version::V3_0` |
| OpenAPI 3.1 | `3.1` and all `3.1.x` patch releases | `Version::V3_1` |

The complete source version remains available through `Specification::$sourceVersion`. For example, an `openapi: 3.1.1` document has `Version::V3_1` as its semantic version and `3.1.1` as its source version.

Swagger 1.x is not supported.

## Requirements

- PHP 8.5 or newer
- `ext-json`

Install the package with Composer:

```sh
composer require utopia-php/openapi
```

## Parsing a document

Pass JSON content directly to the parser:

```php
use Utopia\OpenAPI\Parser;

$json = file_get_contents('openapi.json');
$specification = Parser::parse($json);
```

Decoded associative arrays are also accepted:

```php
$specification = Parser::parse([
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'Example API',
        'version' => '1.0.0',
    ],
    'paths' => [],
]);
```

The parser detects the format from `swagger: 2.0` or `openapi: 3.x.x`.

### Requiring a specific version

Supply a `Version` when the caller expects a particular OpenAPI version:

```php
use Utopia\OpenAPI\Parser;
use Utopia\OpenAPI\Version;

$specification = Parser::parse(
    input: $json,
    version: Version::V3_1,
);
```

Parsing fails with `InvalidSpecification` if the expected version and declared document version differ.

An instance API is available as well:

```php
$parser = new Parser();
$specification = $parser->read($json);
```

## Working with the model

Every supported source format produces a `Utopia\OpenAPI\Specification` containing:

- OpenAPI and API metadata
- Servers and server variables
- Tags
- Paths and operations
- Component schemas
- Security schemes and requirements
- Vendor extensions

### Operations

```php
use Utopia\OpenAPI\Model\HttpMethod;

foreach ($specification->paths as $path => $pathItem) {
    foreach ($pathItem->operations as $operation) {
        echo strtoupper($operation->method->value);
        echo ' ' . $operation->path . PHP_EOL;
    }
}

$getPet = $specification
    ->paths['/pets/{id}']
    ->operation(HttpMethod::GET);
```

All operations can be retrieved as one ordered list:

```php
$operations = $specification->operations();
```

Operations can also be selected by an OpenAPI tag:

```php
$petOperations = $specification->operationsByTag('pets');
```

The library models OpenAPI tags as tags. It does not assign SDK service or platform semantics to them.

### Schemas

Schemas are represented by typed classes under `Utopia\OpenAPI\Model`, with `Schema` as their common base:

- `AnySchema` and `NeverSchema`
- `StringSchema`
- `IntegerSchema`
- `NumberSchema`
- `BooleanSchema`
- `ObjectSchema`
- `ArraySchema`
- `ReferenceSchema`
- `CompositeSchema`

The model preserves:

- Formats, defaults, enums, examples, and common constraints
- Object properties and required property names
- All three `additionalProperties` states: forbidden, unconstrained, or schema-constrained
- `oneOf`, `anyOf`, `allOf`, and `not`
- Discriminators and discriminator mappings
- OpenAPI 3.0 `nullable: true`
- OpenAPI 3.1 null types such as `type: [string, "null"]`
- OpenAPI 3.1 boolean schemas

Schema references remain references rather than being recursively expanded:

```php
use Utopia\OpenAPI\Model\ReferenceSchema;

$schema = $specification->schemas['Pet'];

if ($schema instanceof ReferenceSchema) {
    echo $schema->reference;
}
```

This makes valid recursive schemas safe to parse. Resolve the local component reference of a schema explicitly when its concrete type is needed:

```php
$resolved = $specification->resolveSchema($schema);
```

`resolveSchema()` follows chained local schema references and leaves missing, external, or cyclic references unresolved.

OpenAPI 3.1 annotated enumerations (`oneOf` or `anyOf` of `const` + `title`)
are mapped onto `StringSchema` fields. The type name is the composite `title`.
Value names are each branch’s `title`.

```yaml
title: WebhookEvent
oneOf:
  - const: user.created
    title: UserCreated
  - const: user.updated
    title: UserUpdated
```

```php
use Utopia\OpenAPI\Model\CompositeSchema;
use Utopia\OpenAPI\Model\StringSchema;

if ($schema instanceof CompositeSchema) {
    $enum = $schema->stringEnum();
    $values = $enum?->enum ?? [];
    $keys = $enum?->enumKeys ?? [];
    $name = $enum?->enumName;
}
```

The composite tree is preserved. `stringEnum()` returns a synthesized
`StringSchema` (`enum`, `enumName`, `enumKeys`, `open`) so consumers do not
walk the union.

An `anyOf` that adds an unconstrained `type: string` branch documents
suggested values without closing the set (`open: true`). That includes a
legacy multi-value string enum next to `type: string`, a flattened list of
`const` values plus `type: string`, and a nested annotated `oneOf` plus `type: string`.
`openStringEnumBranch()` returns the same `StringSchema` only when `open` is
true.

`allOf`, unions with `$ref` members, and unions with multiple multi-value
enum branches are not treated as string enums. Mixed `const` and object, numeric
`const`, or multi-value enum branches throw `InvalidSpecification`.

### Conditional references

`CompositeSchema::conditionalReferences()` returns references with required scalar
literals in `oneOf`/`anyOf` branches composed through `allOf` (including nested
`allOf` and OpenAPI 3.1 `const`):

```yaml
anyOf:
  - allOf:
      - $ref: '#/components/schemas/Email'
      - type: object
        required: [type, format]
        properties:
          type: {enum: [string]}
          format: {enum: [email]}
```

The result is a list of references with a list of conditions for each:

```json
[{"reference":"#/components/schemas/Email","conditions":[
  {"propertyName":"type","value":"string"},
  {"propertyName":"format","value":"email"}
]}]
```

Property names and references are values, not array keys, so numeric strings such
as `"0"` remain strings when iterated or encoded as JSON.

Scalar types, unresolved references, and the schema tree are preserved. Unsupported
members, optional/nullable conditions, extra scalar constraints, conflicting
literals (including `const`/`enum`), or repeated references return `[]` for the
whole union. These are selection hints, not validation or exclusivity guarantees;
callers choose a selection policy. Vendor extensions and discriminators are unchanged.

### Parameters and request bodies

Path-level parameters are inherited by operations. An operation-level parameter with the same case-sensitive `name` and `in` value replaces the inherited parameter.

OpenAPI 3.x request bodies and OpenAPI 2.0 body parameters both produce `RequestBody` objects. OpenAPI 2.0 form-data parameters are normalized into request content with object properties and encoding metadata.

### Responses and media types

Responses remain indexed by their original status code or `default` key. Response order is preserved.

OpenAPI 2.0 `produces` values and OpenAPI 3.x response `content` both produce `MediaType` objects. The same normalization applies to OpenAPI 2.0 `consumes` and request content.

### Security

Security alternatives retain OpenAPI's AND/OR semantics. This document:

```yaml
security:
  - Project: []
    Session: []
  - Project: []
    JWT: []
```

is represented as two `SecurityRequirement` objects and means:

```text
(Project AND Session) OR (Project AND JWT)
```

Security inheritance is also preserved:

- A missing operation `security` inherits root security.
- An explicit `security: []` disables inherited security.
- An empty requirement object represents anonymous access as an alternative.

Supported security scheme types include API keys, HTTP basic and bearer authentication, OAuth 2 flows, OpenID Connect, and OpenAPI 3.1 mutual TLS.

### Vendor extensions

Fields whose names begin with `x-` are retained in each extensible model's `extensions` map:

```php
$owner = $specification->info->extensions['x-owner'] ?? null;
```

Extensions are opaque. The parser does not interpret `x-appwrite` or any other vendor-specific behavior.

## OpenAPI 2.0 normalization

OpenAPI 2.0 documents are read directly; they are not converted into an intermediate OpenAPI 3 document.

| OpenAPI 2.0 field | Canonical model |
| --- | --- |
| `host`, `basePath`, `schemes` | `Server` objects |
| `definitions` | `Specification::$schemas` |
| Body parameters | `RequestBody` |
| Form-data parameters | Request content and encodings |
| `consumes` | Request media types |
| `produces` | Response media types |
| Response `schema` | Response media schema |
| `securityDefinitions` | Security schemes |
| Root and operation `security` | Security requirements |

Global and operation-level `consumes`, `produces`, parameters, and security follow their standard inheritance and override rules.

## References

Local references use RFC 6901 JSON Pointer syntax:

```text
#/components/schemas/Pet
#/definitions/Pet
```

Escaped pointer tokens are supported:

- `~1` represents `/`
- `~0` represents `~`

Local non-schema references needed to build typed objects are resolved with cycle detection. Schema references intentionally remain `ReferenceSchema` objects so recursive schema graphs are not expanded.

External file and URL references are not resolved. The parser never performs implicit filesystem or network access.

## Error handling

All public exceptions implement `Utopia\OpenAPI\Exception\OpenAPIException`:

- `ParseException` — malformed JSON or another basic parsing failure
- `InvalidSpecification` — a document cannot produce a coherent typed model
- `UnsupportedVersion` — the declared OpenAPI version is unsupported
- `ReferenceNotFound` — a required local reference does not exist
- `CircularReference` — an invalid non-schema reference cycle was encountered

```php
use Utopia\OpenAPI\Exception\OpenAPIException;
use Utopia\OpenAPI\Parser;

try {
    $specification = Parser::parse($json);
} catch (OpenAPIException $exception) {
    echo $exception->getMessage();
}
```

The parser validates structure required to construct the model. It is not a complete OpenAPI conformance validator.

## Current scope

The core parser supports JSON strings and decoded PHP arrays. The following capabilities are intentionally outside its current scope:

- YAML parsing
- External reference resolution
- Implicit file or network access
- Complete OpenAPI conformance validation
- Serialization or textual round trips
- Callbacks, links, and OpenAPI 3.1 webhooks
- Interpretation of vendor extensions
- Swagger 1.x

A missing `operationId` is currently accepted and represented as an empty string.

## Development

Development takes place in the [utopia-php monorepo](https://github.com/utopia-php/monorepo). From its root, run:

```sh
bin/monorepo test openapi
bin/monorepo check openapi
bin/monorepo validate
```

Run `bin/monorepo check openapi --fix` to apply Pint formatting and Rector refactoring. PHPStan findings require manual fixes. The monorepo provides the shared QA tools; this package keeps its Rector configuration.

`composer test` is the unit tier and runs on the host against local fixtures. No services or containers are required. Monorepo CI runs tests and QA checks for changed packages, validates package conventions, and lints Markdown with Vale.

The test suite covers version handling, metadata, operations, parameter inheritance, requests, responses, schemas, recursive references, security semantics, OpenAPI 2.0 normalization, and JSON Pointer escaping.

## License

MIT. See [LICENSE](LICENSE).
