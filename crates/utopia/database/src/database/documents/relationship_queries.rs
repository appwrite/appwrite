//! `validateRelationshipSelects()` and `withoutRelationshipQueries()`: what
//! the reads and the bulk writes do with relationship queries.

use utopia_query::Method;

use super::super::Database;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::error::{Error, Result};
use crate::query::Query;
use crate::validator::queries::documents::internal_attributes;
use crate::validator::query::base::nested;
use crate::validator::query::select::Select;

impl Database {
    /// A relationship query's selects name attributes of the collection it
    /// relates to, which the documents validator does not know, so they are
    /// checked against that collection (`validateRelationshipSelects()`).
    ///
    /// Fails ([`crate::Kind::Query`]) with the select validator's
    /// description (`Invalid query: ...`).
    pub(crate) async fn validate_relationship_selects(&self, collection: &Collection, queries: &[Query]) -> Result<()> {
        let relationship_queries: Vec<&Query> =
            queries.iter().filter(|q| q.method() == Method::Relationship).collect();
        if relationship_queries.is_empty() {
            return Ok(());
        }

        let relationships = Database::relationship_attributes(collection)?;
        for query in relationship_queries {
            // The last attribute of a key, as PHP's map keyed by it keeps.
            let related_collection = relationships
                .iter()
                .rev()
                .find(|a| a.key == query.attribute())
                .and_then(|a| a.relationship.as_ref())
                .map(|r| r.related_collection.clone());
            let selects: Vec<&utopia_query::Query> =
                query.values().values().filter_map(nested).filter(|q| q.method() == Method::Select).collect();

            let Some(related_collection) = related_collection else { continue };
            if selects.is_empty() {
                continue;
            }

            let related = self.silent(self.get_collection(&related_collection)).await?;
            let mut attributes = related.attributes()?.to_vec();
            attributes.extend_from_slice(internal_attributes());
            let validator = Select::new(
                &attributes,
                self.inner.adapter.supports(Capability::DefinedAttributes),
                self.profile().shared_tables,
            );

            for select in selects {
                if !validator.check(select) {
                    return Err(Error::query(format!("Invalid query: {}", validator.description())));
                }
            }
        }
        Ok(())
    }

    /// A bulk write changes the documents its filters match, which a
    /// relationship query does not narrow, so one is refused there when
    /// queries are validated, and dropped when not
    /// (`withoutRelationshipQueries()`).
    ///
    /// Fails ([`crate::Kind::Query`]) with `Invalid query: Invalid query
    /// method: relationship`.
    pub(crate) fn without_relationship_queries(&self, queries: Vec<Query>) -> Result<Vec<Query>> {
        let mut kept = Vec::with_capacity(queries.len());
        for query in queries {
            if query.method() != Method::Relationship {
                kept.push(query);
                continue;
            }
            if self.is_validating() {
                return Err(Error::query(format!(
                    "Invalid query: Invalid query method: {}",
                    Method::Relationship.as_str()
                )));
            }
        }
        Ok(kept)
    }
}
