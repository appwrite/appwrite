//! [`Specification`]: the canonical model of a document.

use std::borrow::Cow;

use php_std::Array;
use php_std::encoding::rawurldecode;

use crate::model::{
    ExternalDocumentation, Info, Map, Operation, PathItem, SecurityRequirement, SecurityScheme, Server, Tag,
};
use crate::{Schema, SchemaKind, Version};

/// What a document describes, the same shape whichever OpenAPI version it
/// was written in: a 2.0 and a 3.1 document describing the same service
/// read into equal specifications.
///
/// PHP: `Utopia\OpenAPI\Specification`.
#[derive(Debug, Clone, PartialEq)]
pub struct Specification {
    /// The version the document was read as.
    pub version: Version,
    /// `info`.
    pub info: Info,
    /// The servers (OpenAPI 2.0: from `host`, `basePath` and `schemes`).
    pub servers: Vec<Server>,
    /// The tags by name.
    pub tags: Map<Tag>,
    /// The path items by path.
    pub paths: Map<PathItem>,
    /// The named schemas (`components.schemas`, or 2.0's `definitions`).
    pub schemas: Map<Schema>,
    /// The security schemes (`components.securitySchemes`, or 2.0's `securityDefinitions`).
    pub security_schemes: Map<SecurityScheme>,
    /// The document's security alternatives.
    pub security: Vec<SecurityRequirement>,
    /// `x-` keys of the document.
    pub extensions: Array,
    /// The version string the document declares (`3.1.1`), as written.
    pub source_version: String,
    /// `jsonSchemaDialect` (OpenAPI 3.1), kept as written; it does not
    /// change how schemas are read.
    pub json_schema_dialect: Option<String>,
    /// `externalDocs`.
    pub external_documentation: Option<ExternalDocumentation>,
}

impl Specification {
    /// Follows a chain of local component references
    /// (`#/components/schemas/...` or `#/definitions/...`, percent-decoded,
    /// `~1` and `~0` unescaped) to the named schema they end at, without
    /// expanding anything inside it. Stops at the last schema it can reach:
    /// an external or unknown reference, or one seen before (a cycle), is
    /// returned as it is.
    ///
    /// PHP: `Specification::resolveSchema($schema)`; it returns the same
    /// instance, here a reference into `self.schemas` or `schema` itself.
    ///
    /// ```
    /// use utopia_openapi::{SchemaKind, parse};
    ///
    /// let spec = parse(br##"{"openapi": "3.1.0", "info": {"title": "Refs", "version": "1"}, "components": {"schemas": {
    ///     "Binary": {"type": "string", "format": "binary"},
    ///     "Alias": {"$ref": "#/components/schemas/Binary"},
    ///     "Chained": {"$ref": "#/components/schemas/Alias"}
    /// }}}"##, None)?;
    /// let resolved = spec.resolve_schema(&spec.schemas["Chained"]);
    /// assert!(std::ptr::eq(resolved, &spec.schemas["Binary"]));
    /// assert!(matches!(resolved.kind, SchemaKind::String(_)));
    /// # Ok::<(), utopia_openapi::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\OpenAPI\Parser;
    ///
    /// $spec = Parser::parse(['openapi' => '3.1.0', 'info' => ['title' => 'Refs', 'version' => '1'], 'components' => ['schemas' => [
    ///     'Binary' => ['type' => 'string', 'format' => 'binary'],
    ///     'Alias' => ['$ref' => '#/components/schemas/Binary'],
    ///     'Chained' => ['$ref' => '#/components/schemas/Alias'],
    /// ]]]);
    /// var_dump($spec->resolveSchema($spec->schemas['Chained']) === $spec->schemas['Binary']); // bool(true)
    /// ```
    pub fn resolve_schema<'s>(&'s self, schema: &'s Schema) -> &'s Schema {
        let mut schema = schema;
        let mut visited: Vec<&str> = Vec::new();
        while let SchemaKind::Reference(reference) = &schema.kind {
            if !reference.starts_with('#') {
                break;
            }
            let decoded: Cow<'_, [u8]> = if reference.contains('%') {
                Cow::Owned(rawurldecode(reference.as_bytes()))
            } else {
                Cow::Borrowed(reference.as_bytes())
            };
            let name = [b"#/components/schemas/".as_slice(), b"#/definitions/".as_slice()]
                .into_iter()
                .find_map(|prefix| decoded.strip_prefix(prefix));
            let Some(name) = name else { break };
            let name = crate::reference::unescape(name);
            let Ok(name) = std::str::from_utf8(&name) else { break };
            let Some((key, target)) = self.schemas.get_key_value(name) else { break };
            if visited.contains(&key.as_str()) {
                break;
            }
            visited.push(key);
            schema = target;
        }
        schema
    }

    /// Every operation, path by path, each path's in method order.
    ///
    /// PHP: `Specification::operations()`.
    pub fn operations(&self) -> impl Iterator<Item = &Operation> {
        self.paths.values().flat_map(|path| path.operations.iter())
    }

    /// The operations tagged `tag`, in [`Specification::operations`] order.
    ///
    /// PHP: `Specification::operationsByTag($tag)`.
    ///
    /// ```
    /// use utopia_openapi::parse;
    ///
    /// let spec = parse(br#"{"swagger": "2.0", "info": {"title": "Pets", "version": "1"}, "paths": {
    ///     "/pets": {"get": {"operationId": "list", "tags": ["pets"], "responses": {"200": {"description": "OK"}}}},
    ///     "/users": {"get": {"operationId": "users", "tags": ["users"], "responses": {"200": {"description": "OK"}}}}
    /// }}"#, None)?;
    /// let ids: Vec<&str> = spec.operations_by_tag("pets").map(|o| o.id.as_str()).collect();
    /// assert_eq!(ids, ["list"]);
    /// # Ok::<(), utopia_openapi::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\OpenAPI\Parser;
    ///
    /// $spec = Parser::parse(['swagger' => '2.0', 'info' => ['title' => 'Pets', 'version' => '1'], 'paths' => [
    ///     '/pets' => ['get' => ['operationId' => 'list', 'tags' => ['pets'], 'responses' => ['200' => ['description' => 'OK']]]],
    ///     '/users' => ['get' => ['operationId' => 'users', 'tags' => ['users'], 'responses' => ['200' => ['description' => 'OK']]]],
    /// ]]);
    /// echo $spec->operationsByTag('pets')[0]->id, "\n"; // list
    /// ```
    pub fn operations_by_tag<'s>(&'s self, tag: &'s str) -> impl Iterator<Item = &'s Operation> {
        self.operations().filter(move |operation| operation.tags.iter().any(|t| t == tag))
    }
}
