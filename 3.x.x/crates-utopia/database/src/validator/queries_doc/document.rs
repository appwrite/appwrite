//! PHP `Utopia\Database\Validator\Queries\Document`.

use crate::constants::{VAR_DATETIME, VAR_STRING};
use crate::document::Document;
use crate::validator::query::base::QueryMethodValidator;
use crate::validator::query::Select;
use crate::validator::Queries;
use crate::value::AttrValue;

/// PHP `Utopia\Database\Validator\Queries\Document`.
pub struct DocumentQueries {
    inner: Queries,
}

impl std::fmt::Debug for DocumentQueries {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DocumentQueries").finish_non_exhaustive()
    }
}

impl DocumentQueries {
    pub fn new(mut attributes: Vec<Document>, support_for_attributes: bool) -> Self {
        attributes.push(
            Document::from_pairs([
                ("$id", AttrValue::from("$id")),
                ("key", AttrValue::from("$id")),
                ("type", AttrValue::from(VAR_STRING)),
                ("array", AttrValue::from(false)),
            ])
            .unwrap_or_default(),
        );
        attributes.push(
            Document::from_pairs([
                ("$id", AttrValue::from("$createdAt")),
                ("key", AttrValue::from("$createdAt")),
                ("type", AttrValue::from(VAR_DATETIME)),
                ("array", AttrValue::from(false)),
            ])
            .unwrap_or_default(),
        );
        attributes.push(
            Document::from_pairs([
                ("$id", AttrValue::from("$updatedAt")),
                ("key", AttrValue::from("$updatedAt")),
                ("type", AttrValue::from(VAR_DATETIME)),
                ("array", AttrValue::from(false)),
            ])
            .unwrap_or_default(),
        );
        let validators: Vec<Box<dyn QueryMethodValidator>> =
            vec![Box::new(Select::new(&attributes, support_for_attributes))];
        Self {
            inner: Queries::new(validators, 0),
        }
    }

    pub fn is_valid_queries(&self, queries: &[crate::query::Query]) -> bool {
        self.inner.is_valid_queries(queries)
    }
}

impl utopia_validators::Validator for DocumentQueries {
    fn description(&self) -> String {
        self.inner.description()
    }
    fn value_type(&self) -> utopia_validators::ValueType {
        utopia_validators::ValueType::Array
    }
    fn is_valid(&self, value: &serde_json::Value) -> bool {
        self.inner.is_valid(value)
    }
}
