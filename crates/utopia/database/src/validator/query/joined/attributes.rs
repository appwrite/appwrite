//! [`Joins`]: resolution of attributes a collection does not declare, for
//! the validators that may name an attribute of a joined collection.
//!
//! PHP: `Utopia\Database\Validator\Query\Joined\Attributes` (a trait).

use std::collections::{HashMap, HashSet};
use std::sync::Arc;

use super::collection::Collection;
use crate::database;
use crate::document;
use crate::validator::query::base::{is_allowed_join_column, split_dot};

/// The joins and join aliases of the query set a validator checks.
///
/// The PHP trait's abstract `acceptsMainAttribute()` is the
/// `accepts_main` argument of the methods that need it; the message the
/// trait sets is their `Err`.
///
/// PHP: the state and methods of `Joined\Attributes`.
#[derive(Debug, Clone, Default)]
pub struct Joins {
    /// The joins of the query set whose collection is known, in query order.
    joins: Vec<Arc<Collection>>,
    /// The known join of each alias.
    by_alias: HashMap<String, Arc<Collection>>,
    /// The join aliases of the query set.
    aliases: HashSet<String>,
}

impl Joins {
    /// Declare the joins of the query set whose collection is known
    /// (`allowJoins()`).
    pub fn allow_joins(&mut self, joins: &[Arc<Collection>]) {
        self.joins = joins.to_vec();
        self.by_alias.clear();
        for join in joins {
            if !join.alias.is_empty() {
                self.by_alias.entry(join.alias.clone()).or_insert_with(|| join.clone());
                self.aliases.insert(join.alias.clone());
            }
        }
    }

    /// Declare join aliases of the query set (`allowJoinAliases()`).
    pub fn allow_join_aliases<S: AsRef<str>>(&mut self, aliases: &[S]) {
        for alias in aliases {
            let alias = alias.as_ref();
            if !alias.is_empty() {
                self.aliases.insert(alias.to_owned());
            }
        }
    }

    /// Forget the joins and aliases (`resetJoinAliases()`).
    pub fn reset(&mut self) {
        self.joins.clear();
        self.by_alias.clear();
        self.aliases.clear();
    }

    /// The known joins, in query order.
    pub fn joins(&self) -> &[Arc<Collection>] {
        &self.joins
    }

    /// Whether no join of the query set is known (`$this->joins === []`).
    pub fn is_empty(&self) -> bool {
        self.joins.is_empty()
    }

    /// The known join of an alias.
    pub fn by_alias(&self, alias: &str) -> Option<&Arc<Collection>> {
        self.by_alias.get(alias)
    }

    /// Whether the query set declares the alias.
    pub fn has_alias(&self, alias: &str) -> bool {
        self.aliases.contains(alias)
    }

    /// Whether `alias.column` refers to a join (`isJoinColumnReference()`).
    pub fn is_join_column_reference(&self, alias: &str, column: &str) -> bool {
        self.aliases.contains(alias) && is_allowed_join_column(column)
    }

    /// Whether a column under a join alias is valid: one of the joined
    /// collection's attributes, or an internal attribute accepted on the
    /// main collection (`isJoinedColumn()`).
    pub fn is_joined_column(
        &self,
        alias: &str,
        column: &str,
        accepts_main: impl Fn(&str) -> bool,
    ) -> Result<(), String> {
        match self.by_alias.get(alias) {
            None => Ok(()),
            Some(join) if join.attributes.contains(column) => Ok(()),
            Some(_) if is_joined_internal_attribute(column, accepts_main) => Ok(()),
            Some(_) => Err(format!("Attribute not found in schema: {alias}.{column}")),
        }
    }

    /// Whether an attribute the collection does not declare resolves to a
    /// join: `alias.column` of a declared alias, or a bare name exactly one
    /// join declares (`isJoinedAttribute()`).
    pub fn is_joined_attribute(&self, attribute: &str, accepts_main: impl Fn(&str) -> bool) -> Result<(), String> {
        match split_dot(attribute) {
            None => {
                let joins = self.joins.iter().filter(|j| j.attributes.contains(attribute)).count();
                if joins == 1 {
                    return Ok(());
                }
                if joins > 1 {
                    return Err(format!(
                        "Attribute \"{attribute}\" is ambiguous across joins; qualify it with a join alias"
                    ));
                }
            }
            Some((alias, column)) => {
                if self.is_join_column_reference(alias, column) {
                    return self.is_joined_column(alias, column, accepts_main);
                }
            }
        }
        Err(format!("Attribute not found in schema: {attribute}"))
    }

    /// The known join an attribute the collection does not declare resolves
    /// to (`joinOf()`).
    pub fn join_of(&self, attribute: &str) -> Option<&Arc<Collection>> {
        match split_dot(attribute) {
            Some((alias, _)) => self.by_alias.get(alias),
            None => self.joins.iter().find(|j| j.attributes.contains(attribute)),
        }
    }

    /// The column an attribute names: a bare name the collection does not
    /// declare is the column of the one join that declares it (`column()`).
    pub fn column(&self, attribute: &str, accepts_main: impl Fn(&str) -> bool) -> String {
        if attribute.contains('.') || accepts_main(attribute) {
            return attribute.to_owned();
        }
        let mut declaring = self.joins.iter().filter(|j| j.attributes.contains(attribute));
        match (declaring.next(), declaring.next()) {
            (Some(join), None) => format!("{}.{attribute}", join.alias),
            _ => attribute.to_owned(),
        }
    }
}

/// Whether an internal attribute is a column of a joined row: every one but
/// `$collection`, when accepted on the main collection
/// (`isJoinedInternalAttribute()`).
pub fn is_joined_internal_attribute(column: &str, accepts_main: impl Fn(&str) -> bool) -> bool {
    column.starts_with('$') && column != document::COLLECTION && accepts_main(column)
}

/// The internal attributes a table holds a column for: every one but
/// `$collection`, and `$tenant` only under shared tables
/// (`internalColumns()`).
pub fn internal_columns(shared_tables: bool) -> impl Iterator<Item = &'static str> {
    database::internal_attributes(shared_tables).iter().map(|a| a.key.as_str()).filter(|k| *k != document::COLLECTION)
}
