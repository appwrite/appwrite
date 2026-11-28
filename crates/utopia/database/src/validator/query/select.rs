//! [`Select`]: `select` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Select`.

use std::collections::HashSet;
use std::sync::{Arc, Mutex, MutexGuard};

use utopia_query::schema::ColumnType;
use utopia_query::{Method, Value as QValue};

use crate::attribute::Attribute;
use crate::database;
use crate::document;
use crate::validator::Message;
use crate::validator::query::base::{INVALID_QUERY, MethodType, query_of, query_validator, split_dot};
use crate::validator::query::joined::attributes::Joins;
use crate::validator::query::joined::collection::Collection;
use crate::validator::query::order::group_names;
use crate::value::Value;

#[derive(Debug, Clone, Default)]
struct State {
    joins: Joins,
    aggregates: bool,
    group_by: Vec<String>,
}

/// A selection of attributes of the collection (or of a relationship, or
/// of a join), each a string, none twice; in an aggregation query only the
/// attributes it groups by.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::attribute::Attribute;
/// use utopia_database::validator::query::select::Select;
///
/// let select = Select::new(&[Attribute::string("title", 64)], true, false);
/// assert!(select.check(&Query::select(["*", "title", "$id"])));
/// assert!(!select.check(&Query::select(["title", "title"])));
/// assert_eq!(select.description(), "Duplicate attributes selected");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Select` (`new Select($attributes,
/// $supportForAttributes, $sharedTables)`).
#[derive(Debug)]
pub struct Select {
    schema: HashSet<String>,
    relationships: HashSet<String>,
    /// The object attributes, which a path can go into.
    objects: HashSet<String>,
    support_for_attributes: bool,
    shared_tables: bool,
    state: Mutex<State>,
    message: Message,
}

impl Default for Select {
    /// `new Select()`.
    fn default() -> Self {
        Select::new(&[], true, false)
    }
}

impl Select {
    /// `new Select($attributes, $supportForAttributes, $sharedTables)`.
    pub fn new(attributes: &[Attribute], support_for_attributes: bool, shared_tables: bool) -> Self {
        Select {
            schema: attributes.iter().map(|a| a.key.clone()).collect(),
            relationships: attributes.iter().filter(|a| a.relationship.is_some()).map(|a| a.key.clone()).collect(),
            objects: attributes.iter().filter(|a| a.kind == ColumnType::Object).map(|a| a.key.clone()).collect(),
            support_for_attributes,
            shared_tables,
            state: Mutex::new(State::default()),
            message: Message::new(INVALID_QUERY),
        }
    }

    fn state(&self) -> MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    fn fail(&self, message: impl Into<String>) -> bool {
        self.message.set(message);
        false
    }

    /// The internal attributes a read can select (`internalKeys()`).
    fn is_internal_key(&self, attribute: &str) -> bool {
        database::internal_attributes(self.shared_tables).iter().any(|a| a.key == attribute)
    }

    /// `acceptsMainAttribute()`.
    fn accepts_main(&self, attribute: &str) -> bool {
        self.schema.contains(attribute) || self.is_internal_key(attribute)
    }

    /// `isValid($value)`: anything but a query is refused.
    pub fn is_valid(&self, value: &Value) -> bool {
        query_of(value).is_some_and(|q| self.check(q))
    }

    /// `isValid($query)`.
    pub fn check(&self, query: &utopia_query::Query) -> bool {
        if query.method() != Method::Select {
            return false;
        }
        if query.values().is_empty() {
            return self.fail("No attributes selected");
        }
        let mut attributes: Vec<String> = Vec::with_capacity(query.values().len());
        for value in query.values().values() {
            let QValue::Str(s) = value else {
                return self.fail(format!("Attribute selection must be a string, got {}", value.debug_type()));
            };
            attributes.push(String::from_utf8_lossy(s.as_bytes()).into_owned());
        }
        let unique: HashSet<&str> = attributes.iter().map(String::as_str).collect();
        if unique.len() != attributes.len() {
            return self.fail("Duplicate attributes selected");
        }

        let state = self.state();
        let aggregation = state.aggregates || !state.group_by.is_empty();
        for attribute in &attributes {
            let mut attribute = attribute.as_str();
            if let Some((alias, column)) = split_dot(attribute) {
                if self.schema.contains(attribute) {
                    continue;
                }
                if column == "*" && state.joins.has_alias(alias) {
                    if aggregation {
                        return self.reject_ungrouped(attribute);
                    }
                    continue;
                }
                if state.joins.is_join_column_reference(alias, column) {
                    let join = state.joins.by_alias(alias);
                    if self.support_for_attributes
                        && !join.is_some_and(|j| j.holds_column(column))
                        && let Err(message) = state.joins.is_joined_column(alias, column, |a| self.accepts_main(a))
                    {
                        return self.fail(message);
                    }
                    continue;
                }
                if aggregation && state.joins.has_alias(alias) {
                    return self.reject_ungrouped(attribute);
                }
                if !aggregation
                    && self.support_for_attributes
                    && self.schema.contains(alias)
                    && !self.relationships.contains(alias)
                    && !self.objects.contains(alias)
                {
                    return self.fail(format!("Cannot select nested attribute on: {alias}"));
                }
                attribute = alias;
            }
            if self.is_internal_key(attribute) || attribute == document::TENANT {
                continue;
            }
            if self.support_for_attributes && !self.schema.contains(attribute) && attribute != "*" {
                return self.fail(format!("Attribute not found in schema: {attribute}"));
            }
        }
        !aggregation || self.is_grouped_selection(&state, &attributes)
    }

    fn is_grouped_selection(&self, state: &State, attributes: &[String]) -> bool {
        let groups: HashSet<String> =
            state.group_by.iter().map(|g| state.joins.column(g, |a| self.accepts_main(a))).collect();
        for attribute in attributes {
            if attribute != "*"
                && !self.is_relationship_wildcard(state, attribute)
                && !groups.contains(&state.joins.column(attribute, |a| self.accepts_main(a)))
            {
                return self.reject_ungrouped(attribute);
            }
        }
        true
    }

    fn reject_ungrouped(&self, attribute: &str) -> bool {
        self.fail(format!(
            "Cannot select \"{attribute}\": an aggregation query can only select the attributes it groups by"
        ))
    }

    /// A wildcard under a relationship of the collection
    /// (`isRelationshipWildcard()`).
    fn is_relationship_wildcard(&self, state: &State, attribute: &str) -> bool {
        match split_dot(attribute) {
            Some((key, _)) => {
                attribute.ends_with(".*") && self.relationships.contains(key) && !state.joins.has_alias(key)
            }
            None => false,
        }
    }

    /// The aggregates of the query set (`setAggregations()`).
    pub fn set_aggregations(&self, aggregations: &[&utopia_query::Query]) {
        self.state().aggregates = !aggregations.is_empty();
    }

    /// The groupBy attributes of the query set (`setGroupBy()`).
    pub fn set_group_by(&self, attributes: &[QValue]) {
        self.state().group_by = group_names(attributes);
    }

    /// `allowJoins()`.
    pub fn allow_joins(&self, joins: &[Arc<Collection>]) {
        self.state().joins.allow_joins(joins);
    }

    /// `allowJoinAliases()`.
    pub fn allow_join_aliases<S: AsRef<str>>(&self, aliases: &[S]) {
        self.state().joins.allow_join_aliases(aliases);
    }

    /// `resetJoinAliases()`.
    pub fn reset_join_aliases(&self) {
        self.state().joins.reset();
    }
}

query_validator!(Select, MethodType::Select);
