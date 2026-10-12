//! [`IndexedQueries`]: query lists that also need indexes (full-text
//! searches) and use at most one vector query.
//!
//! PHP: `Utopia\Database\Validator\IndexedQueries`.

use crate::database::Database;
use crate::document::Document;
use crate::query::{Method, Query};
use crate::validator::queries::{Queries, query_of};
use crate::validator::query::base::QueryValidator;
use crate::validator::query::filter::is_filter;
use crate::value::{Array, ArrayKey, Value};

/// A [`Queries`] list whose `search` and `notSearch` filters each have a
/// full-text index on exactly their attribute, with at most one vector
/// query (nested ones included).
///
/// PHP: `Utopia\Database\Validator\IndexedQueries` (`new IndexedQueries($attributes,
/// $indexes, $validators)`). The indexes on `$id`, `$createdAt` and
/// `$updatedAt` are always there.
pub struct IndexedQueries {
    queries: Queries,
    attributes: Vec<Document>,
    indexes: Vec<Document>,
}

fn index(kind: &str, attribute: &str) -> Document {
    Document::from_array_unchecked(
        [
            (ArrayKey::from("type"), Value::from(kind)),
            (ArrayKey::from("attributes"), Value::Array([Value::from(attribute)].into_iter().collect())),
        ]
        .into_iter()
        .collect(),
    )
}

impl IndexedQueries {
    /// `new IndexedQueries($attributes, $indexes, $validators)`.
    pub fn new(attributes: Vec<Document>, indexes: Vec<Document>, validators: Vec<Box<dyn QueryValidator>>) -> Self {
        let mut all = vec![
            index(Database::INDEX_UNIQUE, "$id"),
            index(Database::INDEX_KEY, "$createdAt"),
            index(Database::INDEX_KEY, "$updatedAt"),
        ];
        all.extend(indexes);
        Self { queries: Queries::new(validators, 0), attributes, indexes: all }
    }

    /// The attributes it was given.
    pub fn attributes(&self) -> &[Document] {
        &self.attributes
    }

    /// The indexes, the three internal ones first.
    pub fn indexes(&self) -> &[Document] {
        &self.indexes
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.queries.description()
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        if !self.queries.is_valid(value) {
            return false;
        }
        let Value::Array(queries) = value else { return false };
        self.check_array(queries)
    }

    /// [`IndexedQueries::is_valid`] of a list of queries.
    pub fn check(&self, queries: &[Query]) -> bool {
        self.is_valid(&Value::Array(queries.iter().cloned().map(Value::from).collect()))
    }

    fn check_array(&self, value: &Array) -> bool {
        let mut queries: Vec<Query> = Vec::with_capacity(value.len());
        for item in value.values() {
            let query = match query_of(item) {
                Ok(q) => q.into_owned(),
                Err(message) => {
                    self.queries.set_message(format!("Invalid query: {message}"));
                    return false;
                }
            };
            if query.is_nested() && !self.is_valid(&Value::Array(query.values().iter().cloned().collect())) {
                return false;
            }
            queries.push(query);
        }

        if count_vector_queries(queries.iter()) > 1 {
            self.queries.set_message("Cannot use multiple vector queries in a single request");
            return false;
        }

        let filters = queries.iter().filter(|q| is_filter(q.method()));
        for filter in filters {
            if !matches!(filter.method(), Method::Search | Method::NotSearch) {
                continue;
            }
            let wanted = Value::Array([Value::from(filter.attribute())].into_iter().collect());
            let matched = self.indexes.iter().any(|index| {
                index.get("type").is_some_and(|t| t.strict_eq(&Value::from(Database::INDEX_FULLTEXT)))
                    && index.get("attributes").is_some_and(|a| a.strict_eq(&wanted))
            });
            if !matched {
                self.queries.set_message(format!(
                    "Searching by attribute \"{}\" requires a fulltext index.",
                    filter.attribute()
                ));
                return false;
            }
        }
        true
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }
}

/// `countVectorQueries($queries)`, nested queries included.
fn count_vector_queries<'a>(queries: impl Iterator<Item = &'a Query>) -> usize {
    queries
        .map(|q| {
            usize::from(q.method().is_vector()) + if q.is_nested() { count_vector_queries(q.queries()) } else { 0 }
        })
        .sum()
}

crate::validator::validator!(IndexedQueries, utopia_validators::Type::Object, true);
