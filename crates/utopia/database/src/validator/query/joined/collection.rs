//! [`Collection`]: a collection one join of a query set reads, as the query
//! validators check it.
//!
//! PHP: `Utopia\Database\Validator\Query\Joined\Collection`.

use std::collections::{HashMap, HashSet};
use std::ops::Deref;
use std::sync::Arc;

use utopia_query::schema::ColumnType;

use crate::attribute::Attribute;
use crate::error::Result;
use crate::relationship::{RelationshipSide, RelationshipType};

const ENCRYPT: &str = "encrypt";

/// What a joined collection declares, whatever alias joins it.
///
/// PHP: the properties of `Joined\Collection` but `alias`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Definition {
    /// The attributes the collection declares, relationships left out.
    pub attributes: HashSet<String>,
    /// The type of each attribute that holds a single number.
    pub numeric: HashMap<String, ColumnType>,
    /// The attributes whose values are stored encrypted.
    pub encrypted: HashSet<String>,
    /// Every attribute the collection declares, and whether it holds a
    /// column a join condition can compare.
    pub columns: HashMap<String, bool>,
    /// The id of the collection the join reads.
    pub collection: String,
    /// The definition of each attribute in [`Definition::attributes`].
    pub schema: HashMap<String, Attribute>,
}

impl Definition {
    /// What collection `id` declares with `attributes`.
    pub fn new(id: impl Into<String>, attributes: &[Attribute]) -> Self {
        let mut definition = Definition { collection: id.into(), ..Definition::default() };
        for attribute in attributes {
            let key = &attribute.key;
            if key.is_empty() {
                continue;
            }
            definition.columns.insert(key.clone(), stores_column(attribute));
            if attribute.relationship.is_none() {
                definition.attributes.insert(key.clone());
                definition.schema.insert(key.clone(), attribute.clone());
            }
            if !attribute.array && attribute.is_numeric() {
                definition.numeric.insert(key.clone(), attribute.kind);
            }
            if attribute.filters.iter().any(|f| f == ENCRYPT) {
                definition.encrypted.insert(key.clone());
            }
        }
        definition
    }

    /// What a stored collection declares.
    ///
    /// Fails as [`crate::Collection::attributes`] does.
    pub fn of(collection: &crate::Collection) -> Result<Self> {
        Ok(Definition::new(collection.document().id(), collection.attributes()?))
    }
}

/// A collection one join of a query set reads: the alias its columns are
/// referenced by and what the collection declares ([`Definition`], which it
/// derefs to).
///
/// ```
/// use utopia_database::attribute::Attribute;
/// use utopia_database::validator::query::joined::collection::{Collection, Definition};
///
/// let notes = Definition::new("notes", &[Attribute::string("body", 64), Attribute::integer("score")]);
/// let join = Collection::new("note", notes.into());
/// assert!(join.holds_column("body"));
/// assert!(join.numeric.contains_key("score"));
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Joined\Collection` (`final
/// readonly`).
#[derive(Debug, Clone, PartialEq)]
pub struct Collection {
    /// The alias the join declares.
    pub alias: String,
    definition: Arc<Definition>,
}

impl Deref for Collection {
    type Target = Definition;

    fn deref(&self) -> &Definition {
        &self.definition
    }
}

impl Collection {
    /// The collection `definition` describes, joined under `alias`.
    pub fn new(alias: impl Into<String>, definition: Arc<Definition>) -> Self {
        Collection { alias: alias.into(), definition }
    }

    /// The collection a join reads, under the alias the join declares
    /// (`Collection::of()`).
    ///
    /// Fails as [`crate::Collection::attributes`] does.
    pub fn of(alias: impl Into<String>, collection: &crate::Collection) -> Result<Self> {
        Ok(Collection::new(alias, Arc::new(Definition::of(collection)?)))
    }

    /// What the collection declares.
    pub fn definition(&self) -> &Arc<Definition> {
        &self.definition
    }

    /// Whether the collection's table holds a column for the attribute:
    /// every attribute but a relationship does, and a relationship does on
    /// the side that stores the related document's id (`holdsColumn()`).
    pub fn holds_column(&self, attribute: &str) -> bool {
        self.columns.get(attribute).copied().unwrap_or(false)
    }

    /// Every attribute of a collection, and whether its table holds a
    /// column for it (`Collection::columns()`).
    pub fn columns(attributes: &[Attribute]) -> HashMap<String, bool> {
        attributes.iter().filter(|a| !a.key.is_empty()).map(|a| (a.key.clone(), stores_column(a))).collect()
    }
}

/// Whether a table holds a column for the attribute (`storesColumn()`).
pub(crate) fn stores_column(attribute: &Attribute) -> bool {
    let Some(relationship) = &attribute.relationship else { return true };
    let parent = attribute.side == Some(RelationshipSide::Parent);
    match relationship.kind {
        RelationshipType::OneToOne => parent || relationship.two_way,
        RelationshipType::OneToMany => !parent,
        RelationshipType::ManyToOne => parent,
        RelationshipType::ManyToMany => false,
    }
}
