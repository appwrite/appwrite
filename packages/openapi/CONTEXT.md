# Domain language

Terms used across this library. Use these words in code, tests, and commits.

## Document

**Document** — the raw decoded OpenAPI input: a nested `array<string, mixed>`, before any interpretation. JSON objects are normalized to arrays during decoding; callers that provide decoded input should provide an associative array.

**Source version** — the version string the document declares (`"3.1.1"`, `"2.0"`). Preserved verbatim on `Specification::$sourceVersion`.

**Version** — the three versions this library reads: `2.0`, `3.0`, `3.1`. Coarser than the source version — `3.0.3` and `3.0.0` are both `Version::V3_0`.

## Reading

**Reader** — reads a document of one version into a `Specification`. `OpenAPI2` handles 2.0. `OpenAPI3` contains shared 3.x behavior, with `OpenAPI30` and `OpenAPI31` selecting the supported minor version.

**Specification** — the canonical model. Version-independent: a 2.0 document and a 3.1 document that describe the same service produce the same shape. This is the library's product; everything else exists to build it.

**Location** — a JSON Pointer (`#/paths/~1pets/get/responses/200`) naming where in the document a value came from. Threaded through reading so errors can name their position.

**Dialect** — the JSON Schema rules a given OpenAPI version permits, including boolean schemas, type arrays, and `const`. It is not the same as **Version**. The reader currently selects supported schema behavior from `Version`; an OpenAPI 3.1 `jsonSchemaDialect` value is preserved as source metadata but does not enable custom dialect processing.

## Schema

**Schema** — a node in the canonical schema tree. One class per kind: object, array, string, integer, number, boolean, composite, reference, any, never.

**Reference schema** — a `$ref` left deliberately unexpanded. Recursive schema graphs are legal, so schema references are never followed during reading; only object references (parameters, responses, examples, security schemes) are resolved.

**Annotations** — the fields every schema kind shares: `title`, `description`, `nullable`, `default`, `enum`, `format`, `readOnly`, `writeOnly`, `deprecated`, `example`, `extensions`.

**Annotated enumeration** — an OAS 3.1 `oneOf` or `anyOf` whose members are string `const` (or one-element `enum`) schemas, optionally with `title`/`description`. Mapped onto `StringSchema` (`enum`, `enumName` from the composite title, `enumKeys` from branch titles, `open` when composed with an unconstrained string). Exposed on `CompositeSchema` as `stringEnum()` without collapsing the union tree.

**Extension** — any `x-`-prefixed key. Captured on every model that can carry one; never interpreted. Enum type and value names come from `title`.
