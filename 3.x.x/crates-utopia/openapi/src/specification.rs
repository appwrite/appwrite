//! Canonical OpenAPI specification (PHP `Utopia\OpenAPI\Specification`).

use crate::json::Json;
use crate::model::{
    ExternalDocumentation, Info, Operation, PathItem, Schema, SecurityRequirement, SecurityScheme,
    Tag,
};
use crate::version::Version;
use indexmap::IndexMap;

/// Parsed OpenAPI document in the canonical model.
#[derive(Clone, Debug, PartialEq)]
pub struct Specification {
    pub version: Version,
    pub info: Info,
    pub servers: Vec<crate::model::Server>,
    pub tags: IndexMap<String, Tag>,
    pub paths: IndexMap<String, PathItem>,
    pub schemas: IndexMap<String, Schema>,
    pub security_schemes: IndexMap<String, SecurityScheme>,
    pub security: Vec<SecurityRequirement>,
    pub extensions: IndexMap<String, Json>,
    pub source_version: String,
    pub json_schema_dialect: Option<String>,
    pub external_documentation: Option<ExternalDocumentation>,
}

impl Specification {
    /// Flatten every operation across all paths (PHP `operations()`).
    pub fn operations(&self) -> Vec<&Operation> {
        let mut operations = Vec::new();
        for path in self.paths.values() {
            operations.extend(path.operations.values());
        }
        operations
    }

    /// Operations whose `tags` list contains `tag` (PHP `operationsByTag`).
    pub fn operations_by_tag(&self, tag: &str) -> Vec<&Operation> {
        self.operations()
            .into_iter()
            .filter(|op| op.tags.iter().any(|t| t == tag))
            .collect()
    }
}
