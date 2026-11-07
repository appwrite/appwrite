//! [`Order`]: `orderAsc`, `orderDesc` and `orderRandom` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Order`.

use std::collections::HashSet;
use std::sync::{Arc, Mutex, MutexGuard};

use utopia_query::{Method, Value as QValue};

use crate::attribute::Attribute;
use crate::validator::Message;
use crate::validator::query::base::{INVALID_QUERY, MethodType, query_of, query_validator, split_dot};
use crate::validator::query::joined::attributes::Joins;
use crate::validator::query::joined::collection::Collection;
use crate::value::Value;

#[derive(Debug, Clone, Default)]
struct State {
    joins: Joins,
    aggregation_aliases: HashSet<String>,
    aggregates: bool,
    group_by: Vec<String>,
}

/// An order on an attribute of the collection, an aggregate alias, or a
/// column of a join; in an aggregation query only on its groups and
/// aggregates. A random order needs the adapter's support.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::attribute::Attribute;
/// use utopia_database::validator::query::order::Order;
///
/// let order = Order::new(&[Attribute::string("title", 64)], true, true);
/// assert!(order.check(&Query::order_asc("title", None)));
/// assert!(!order.check(&Query::order_desc("missing", None)));
/// assert_eq!(order.description(), "Attribute not found in schema: missing");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Order` (`new Order($attributes,
/// $supportForAttributes, $supportForOrderRandom)`).
#[derive(Debug)]
pub struct Order {
    schema: HashSet<String>,
    /// The relationships whose related ids live on the other collection or
    /// in a junction table, so this collection holds no column to order by.
    columnless: HashSet<String>,
    support_for_attributes: bool,
    support_for_order_random: bool,
    state: Mutex<State>,
    message: Message,
}

impl Default for Order {
    /// `new Order()`.
    fn default() -> Self {
        Order::new(&[], true, true)
    }
}

impl Order {
    /// `new Order($attributes, $supportForAttributes, $supportForOrderRandom)`.
    pub fn new(attributes: &[Attribute], support_for_attributes: bool, support_for_order_random: bool) -> Self {
        Order {
            schema: attributes.iter().map(|a| a.key.clone()).collect(),
            columnless: Collection::columns(attributes)
                .into_iter()
                .filter_map(|(key, holds_column)| (!holds_column).then_some(key))
                .collect(),
            support_for_attributes,
            support_for_order_random,
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

    /// `acceptsMainAttribute()`.
    fn accepts_main(&self, attribute: &str) -> bool {
        self.schema.contains(attribute)
    }

    /// `isValid($value)`: anything but a query is refused.
    pub fn is_valid(&self, value: &Value) -> bool {
        query_of(value).is_some_and(|q| self.check(q))
    }

    /// `isValid($query)`.
    pub fn check(&self, query: &utopia_query::Query) -> bool {
        let attribute = query.attribute();
        match query.method() {
            Method::OrderAsc | Method::OrderDesc => {
                if self.columnless.contains(attribute) {
                    return self.fail(format!("Cannot order by relationship attribute: {attribute}"));
                }
                let state = self.state();
                self.is_valid_attribute(&state, attribute) && self.is_grouped_order(&state, attribute)
            }
            Method::OrderRandom => {
                if !self.support_for_order_random {
                    return self.fail("Random order is not supported by this adapter");
                }
                true
            }
            _ => false,
        }
    }

    fn is_valid_attribute(&self, state: &State, attribute: &str) -> bool {
        let mut attribute = attribute;
        let dotted = split_dot(attribute);
        if let Some((alias, column)) = dotted {
            if self.schema.contains(attribute) {
                return true;
            }
            if state.joins.is_join_column_reference(alias, column) {
                let join = state.joins.by_alias(alias);
                if !self.support_for_attributes || join.is_some_and(|j| j.holds_column(column)) {
                    return true;
                }
                return match state.joins.is_joined_column(alias, column, |a| self.accepts_main(a)) {
                    Ok(()) => true,
                    Err(message) => self.fail(message),
                };
            }
            attribute = alias;
            if self.schema.contains(attribute) {
                return self.fail(format!("Cannot order by nested attribute: {attribute}"));
            }
        }
        if state.aggregation_aliases.contains(attribute) {
            return true;
        }
        if self.support_for_attributes && !self.schema.contains(attribute) {
            if dotted.is_none() && !state.joins.is_empty() {
                return match state.joins.is_joined_attribute(attribute, |a| self.accepts_main(a)) {
                    Ok(()) => true,
                    Err(message) => self.fail(message),
                };
            }
            return self.fail(format!("Attribute not found in schema: {attribute}"));
        }
        true
    }

    fn is_grouped_order(&self, state: &State, attribute: &str) -> bool {
        if (!state.aggregates && state.group_by.is_empty()) || state.aggregation_aliases.contains(attribute) {
            return true;
        }
        let column = state.joins.column(attribute, |a| self.accepts_main(a));
        if state.group_by.iter().any(|g| state.joins.column(g, |a| self.accepts_main(a)) == column) {
            return true;
        }
        self.fail(format!(
            "Cannot order by \"{attribute}\": an aggregation query can only order by its groups and aggregates"
        ))
    }

    /// Register aggregation aliases that become valid order targets for the
    /// current validation pass (`addAggregationAliases()`).
    pub fn add_aggregation_aliases<S: AsRef<str>>(&self, aliases: &[S]) {
        let mut state = self.state();
        for alias in aliases {
            state.aggregation_aliases.insert(alias.as_ref().to_owned());
        }
    }

    /// Clear the aggregation aliases of a previous pass
    /// (`resetAggregationAliases()`).
    pub fn reset_aggregation_aliases(&self) {
        self.state().aggregation_aliases.clear();
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

/// The non-empty strings among groupBy values.
pub(crate) fn group_names(attributes: &[QValue]) -> Vec<String> {
    attributes
        .iter()
        .filter_map(|a| match a {
            QValue::Str(s) if !s.is_empty() => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
            _ => None,
        })
        .collect()
}

query_validator!(Order, MethodType::Order);
