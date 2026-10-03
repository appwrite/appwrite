//! PHP `Utopia\Database\Validator\IndexedQueries`.

use crate::constants::{INDEX_FULLTEXT, INDEX_KEY, INDEX_UNIQUE};
use crate::document::Document;
use crate::query::{Query, TYPE_NOT_SEARCH, TYPE_SEARCH, VECTOR_TYPES};
use crate::validator::query::base::QueryMethodValidator;
use crate::validator::Queries;
use crate::value::AttrValue;
use utopia_validators::Validator;

/// PHP `Utopia\Database\Validator\IndexedQueries`.
pub struct IndexedQueries {
    inner: Queries,
    indexes: Vec<Document>,
}

impl std::fmt::Debug for IndexedQueries {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("IndexedQueries").finish_non_exhaustive()
    }
}

impl IndexedQueries {
    pub fn new(
        _attributes: Vec<Document>,
        indexes: Vec<Document>,
        validators: Vec<Box<dyn QueryMethodValidator>>,
    ) -> Self {
        let mut all = vec![
            Document::from_pairs([
                ("type", AttrValue::from(INDEX_UNIQUE)),
                ("attributes", AttrValue::from(vec!["$id"])),
            ])
            .unwrap_or_default(),
            Document::from_pairs([
                ("type", AttrValue::from(INDEX_KEY)),
                ("attributes", AttrValue::from(vec!["$createdAt"])),
            ])
            .unwrap_or_default(),
            Document::from_pairs([
                ("type", AttrValue::from(INDEX_KEY)),
                ("attributes", AttrValue::from(vec!["$updatedAt"])),
            ])
            .unwrap_or_default(),
        ];
        all.extend(indexes);
        Self {
            inner: Queries::new(validators, 0),
            indexes: all,
        }
    }

    fn count_vector_queries(queries: &[Query]) -> usize {
        let mut count = 0;
        for query in queries {
            if VECTOR_TYPES.contains(&query.get_method()) {
                count += 1;
            }
            if query.is_nested() {
                let nested: Vec<Query> = query
                    .get_values()
                    .iter()
                    .filter_map(AttrValue::as_query)
                    .cloned()
                    .collect();
                count += Self::count_vector_queries(&nested);
            }
        }
        count
    }

    pub fn is_valid_queries(&self, value: &[Query]) -> bool {
        if !self.inner.is_valid_queries(value) {
            return false;
        }
        if Self::count_vector_queries(value) > 1 {
            self.inner
                .set_message("Cannot use multiple vector queries in a single request");
            return false;
        }
        let grouped = Query::group_by_type(value);
        for filter in &grouped.filters {
            if filter.get_method() == TYPE_SEARCH || filter.get_method() == TYPE_NOT_SEARCH {
                let matched = self.indexes.iter().any(|index| {
                    index.get_attribute("type").as_str() == Some(INDEX_FULLTEXT)
                        && match index.get_attribute("attributes") {
                            AttrValue::Array(items) => {
                                items.len() == 1
                                    && items.values().next().and_then(AttrValue::as_str)
                                        == Some(filter.get_attribute())
                            }
                            _ => false,
                        }
                });
                if !matched {
                    self.inner.set_message(format!(
                        "Searching by attribute \"{}\" requires a fulltext index.",
                        filter.get_attribute()
                    ));
                    return false;
                }
            }
        }
        true
    }
}

impl Validator for IndexedQueries {
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
