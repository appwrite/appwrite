//! [`GroupBy`]: `groupBy` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\GroupBy`.

use std::collections::{HashMap, HashSet};
use std::sync::{Arc, Mutex, MutexGuard};

use utopia_query::Value as QValue;

use crate::attribute::Attribute;
use crate::validator::Message;
use crate::validator::query::base::{INVALID_QUERY, MethodType, base_is_valid, query_validator};
use crate::validator::query::joined::attributes::{Joins, internal_columns};
use crate::validator::query::joined::collection::Collection;

/// A `groupBy` of at least one attribute, each a non-empty string naming an
/// attribute of the collection that holds a column, an internal column, or
/// a column of a join.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::attribute::Attribute;
/// use utopia_database::validator::query::group_by::GroupBy;
///
/// let group_by = GroupBy::new(&[Attribute::string("status", 16)], true, false);
/// assert!(group_by.check(&Query::group_by(["status", "$id"])));
/// assert!(!group_by.check(&Query::group_by(["missing"])));
/// assert_eq!(group_by.description(), "Attribute not found in schema: missing");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\GroupBy` (`new GroupBy($attributes,
/// $supportForAttributes, $sharedTables)`).
#[derive(Debug)]
pub struct GroupBy {
    schema: HashSet<String>,
    columns: HashMap<String, bool>,
    support_for_attributes: bool,
    joins: Mutex<Joins>,
    message: Message,
}

impl Default for GroupBy {
    /// `new GroupBy()`.
    fn default() -> Self {
        GroupBy::new(&[], true, false)
    }
}

impl GroupBy {
    /// `new GroupBy($attributes, $supportForAttributes, $sharedTables)`.
    pub fn new(attributes: &[Attribute], support_for_attributes: bool, shared_tables: bool) -> Self {
        let mut schema: HashSet<String> = attributes.iter().map(|a| a.key.clone()).collect();
        schema.extend(internal_columns(shared_tables).map(str::to_owned));
        GroupBy {
            schema,
            columns: Collection::columns(attributes),
            support_for_attributes,
            joins: Mutex::new(Joins::default()),
            message: Message::new(INVALID_QUERY),
        }
    }

    fn joins(&self) -> MutexGuard<'_, Joins> {
        self.joins.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn fail(&self, message: impl Into<String>) -> bool {
        self.message.set(message);
        false
    }

    /// `isValidQuery($query)`.
    pub fn check(&self, query: &utopia_query::Query) -> bool {
        let columns = query.values();
        if columns.is_empty() {
            return self.fail("GroupBy requires at least one attribute");
        }
        let joins = self.joins();
        for column in columns.values() {
            let column = match column {
                QValue::Str(s) if !s.is_empty() => String::from_utf8_lossy(s.as_bytes()),
                _ => return self.fail("GroupBy attributes must be non-empty strings"),
            };
            if self.support_for_attributes
                && !self.schema.contains(column.as_ref())
                && let Err(message) = joins.is_joined_attribute(&column, |a| self.schema.contains(a))
            {
                return self.fail(message);
            }
            if self.columns.get(column.as_ref()) == Some(&false) {
                return self.fail(format!("Cannot group by virtual relationship attribute: {column}"));
            }
        }
        true
    }

    /// `allowJoins()`.
    pub fn allow_joins(&self, joins: &[Arc<Collection>]) {
        self.joins().allow_joins(joins);
    }

    /// `allowJoinAliases()`.
    pub fn allow_join_aliases<S: AsRef<str>>(&self, aliases: &[S]) {
        self.joins().allow_join_aliases(aliases);
    }

    /// `resetJoinAliases()`.
    pub fn reset_join_aliases(&self) {
        self.joins().reset();
    }
}

base_is_valid!(GroupBy);
query_validator!(GroupBy, MethodType::GroupBy);
