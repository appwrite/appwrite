//! PHP `Utopia\Database\Validator\Queries\Documents`.

use chrono::NaiveDateTime;

use crate::constants::{VAR_DATETIME, VAR_ID, VAR_STRING};
use crate::document::Document;
use crate::validator::indexed_queries::IndexedQueries;
use crate::validator::query::base::QueryMethodValidator;
use crate::validator::query::{Cursor, Filter, Limit, Offset, Order, Select};
use crate::value::AttrValue;

/// PHP `Utopia\Database\Validator\Queries\Documents`.
pub struct DocumentsQueries {
    inner: IndexedQueries,
}

impl std::fmt::Debug for DocumentsQueries {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DocumentsQueries").finish_non_exhaustive()
    }
}

impl DocumentsQueries {
    pub fn new(
        mut attributes: Vec<Document>,
        indexes: Vec<Document>,
        id_attribute_type: impl Into<String>,
        max_values_count: i64,
        max_uid_length: i64,
        min_allowed_date: NaiveDateTime,
        max_allowed_date: NaiveDateTime,
        support_for_attributes: bool,
        support_unsigned_big_int: bool,
    ) -> Self {
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
                ("$id", AttrValue::from("$sequence")),
                ("key", AttrValue::from("$sequence")),
                ("type", AttrValue::from(VAR_ID)),
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
        let validators: Vec<Box<dyn QueryMethodValidator>> = vec![
            Box::new(Limit::default()),
            Box::new(Offset::default()),
            Box::new(Cursor::new(max_uid_length)),
            Box::new(Filter::new(
                &attributes,
                id_attribute_type,
                max_values_count,
                min_allowed_date,
                max_allowed_date,
                support_for_attributes,
                support_unsigned_big_int,
            )),
            Box::new(Order::new(&attributes, support_for_attributes)),
            Box::new(Select::new(&attributes, support_for_attributes)),
        ];
        Self {
            inner: IndexedQueries::new(attributes, indexes, validators),
        }
    }

    pub fn is_valid_queries(&self, queries: &[crate::query::Query]) -> bool {
        self.inner.is_valid_queries(queries)
    }
}

impl utopia_validators::Validator for DocumentsQueries {
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
