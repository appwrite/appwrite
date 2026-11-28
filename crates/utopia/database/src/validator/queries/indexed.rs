//! [`Indexed`]: query lists checked against the collection's indexes too.
//!
//! PHP: `Utopia\Database\Validator\Queries\Indexed`.

use std::borrow::Cow;
use std::collections::HashMap;
use std::ops::Deref;
use std::sync::{Arc, OnceLock};

use utopia_query::Method;
use utopia_query::schema::IndexType;

use super::base::{Queries, QueryEntry, children};
use crate::attribute::Attribute;
use crate::document;
use crate::index::Index;
use crate::validator::query::base::QueryValidator;
use crate::value::Value;

/// The indexes every collection holds: `_uid_` on `$id`, `_created_at_`
/// and `_updated_at_`.
fn internal_indexes() -> &'static [Index] {
    static INDEXES: OnceLock<Vec<Index>> = OnceLock::new();
    INDEXES.get_or_init(|| {
        vec![
            Index::unique("_uid_", [document::ID], Vec::new(), Vec::new()).expect("a valid internal index"),
            Index::key("_created_at_", [document::CREATED_AT], Vec::new(), Vec::new()).expect("a valid internal index"),
            Index::key("_updated_at_", [document::UPDATED_AT], Vec::new(), Vec::new()).expect("a valid internal index"),
        ]
    })
}

/// A [`Queries`] list that also has at most one vector query (nested ones
/// included), and searches only attributes with a fulltext index of their
/// own (of the joined collection, for an attribute under a join alias).
///
/// ```
/// use std::sync::Arc;
/// use utopia_database::Query;
/// use utopia_database::attribute::Attribute;
/// use utopia_database::validator::queries::indexed::Indexed;
/// use utopia_database::validator::query::filter::Filter;
///
/// let attributes = [Attribute::string("name", 64)];
/// let indexed = Indexed::new(&attributes, &[], vec![Arc::new(Filter::new(&attributes, "integer"))]);
/// assert!(!indexed.check(&[Query::search("name", "phrase")]));
/// assert_eq!(indexed.description(), "Searching by attribute \"name\" requires a fulltext index.");
/// ```
///
/// PHP: `Utopia\Database\Validator\Queries\Indexed` (`new
/// Indexed($attributes, $indexes, $validators)`); it derefs to its
/// [`Queries`].
#[derive(Debug)]
pub struct Indexed {
    base: Queries,
    attributes: Vec<Attribute>,
    indexes: Vec<Index>,
}

impl Deref for Indexed {
    type Target = Queries;

    fn deref(&self) -> &Queries {
        &self.base
    }
}

impl Default for Indexed {
    /// `new Indexed()`.
    fn default() -> Self {
        Indexed::new(&[], &[], Vec::new())
    }
}

impl Indexed {
    /// `new Indexed($attributes, $indexes, $validators)`.
    pub fn new(attributes: &[Attribute], indexes: &[Index], validators: Vec<Arc<dyn QueryValidator>>) -> Self {
        let mut all = internal_indexes().to_vec();
        all.extend_from_slice(indexes);
        Indexed { base: Queries::new(validators, 0), attributes: attributes.to_vec(), indexes: all }
    }

    /// The attributes the validator was given.
    pub fn attributes(&self) -> &[Attribute] {
        &self.attributes
    }

    /// The indexes: the internal ones, then those given.
    pub fn indexes(&self) -> &[Index] {
        &self.indexes
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.base.description()
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::Array(queries) => self.check(&queries.values().collect::<Vec<_>>()),
            _ => self.base.is_valid(value),
        }
    }

    /// [`Indexed::is_valid`] of a list.
    pub fn check<T: QueryEntry>(&self, queries: &[T]) -> bool {
        self.run(queries).is_some()
    }

    pub(crate) fn run<'a, T: QueryEntry>(&self, queries: &'a [T]) -> Option<Vec<Cow<'a, utopia_query::Query>>> {
        let parsed = self.base.run(queries)?;
        let queries: Vec<&utopia_query::Query> = parsed.iter().map(|q| &**q).collect();
        if count_vector_queries(&queries) > 1 {
            self.base.message.set("Cannot use multiple vector queries in a single request");
            return None;
        }
        let join_indexes = self.join_indexes(&queries);
        if !self.validate_search_indexes(&queries, &join_indexes) {
            return None;
        }
        Some(parsed)
    }

    /// The indexes of the collection each join alias names (`joinIndexes()`).
    fn join_indexes(&self, queries: &[&utopia_query::Query]) -> HashMap<String, Arc<[Index]>> {
        let mut indexes = HashMap::new();
        for query in queries {
            if !query.method().is_join() {
                continue;
            }
            let joined = self.base.joinable(query.attribute()).map_or_else(|| Arc::from(Vec::new()), |j| j.indexes);
            indexes.insert(query.alias().to_owned(), joined);
        }
        indexes
    }

    fn validate_search_indexes(
        &self,
        queries: &[&utopia_query::Query],
        join_indexes: &HashMap<String, Arc<[Index]>>,
    ) -> bool {
        for query in queries {
            if matches!(query.method(), Method::Search | Method::NotSearch) {
                let attribute = query.attribute();
                let mut column = attribute;
                let mut indexes: &[Index] = &self.indexes;
                if let Some((alias, rest)) = attribute.split_once('.')
                    && let Some(joined) = join_indexes.get(alias)
                {
                    column = rest;
                    indexes = joined;
                }
                let matched = indexes
                    .iter()
                    .any(|i| i.kind == IndexType::Fulltext && i.attributes.len() == 1 && i.attributes[0] == column);
                if !matched {
                    self.base.message.set(format!("Searching by attribute \"{attribute}\" requires a fulltext index."));
                    return false;
                }
            }
            let nested: Vec<&utopia_query::Query> = if query.is_nested_join() {
                query.join_on_queries()
            } else if query.is_nested() {
                children(query).collect()
            } else {
                continue;
            };
            if !self.validate_search_indexes(&nested, join_indexes) {
                return false;
            }
        }
        true
    }
}

/// Counts vector queries across the whole query tree
/// (`countVectorQueries()`).
fn count_vector_queries(queries: &[&utopia_query::Query]) -> usize {
    let mut count = 0;
    for query in queries {
        if query.method().is_vector() {
            count += 1;
        }
        if query.is_nested_join() {
            count += count_vector_queries(&query.join_on_queries());
        } else if query.is_nested() {
            count += count_vector_queries(&children(query).collect::<Vec<_>>());
        }
    }
    count
}

crate::validator::validator!(Indexed, utopia_validators::Type::Object, true);
