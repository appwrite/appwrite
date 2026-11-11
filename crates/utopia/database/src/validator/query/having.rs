//! [`Having`]: `having` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Having`.

use std::collections::{HashMap, HashSet};
use std::sync::{Arc, Mutex, MutexGuard};

use crate::validator::finite::finite_query_value;
use utopia_query::{Method, Value as QValue};

use crate::validator::Message;
use crate::validator::query::base::{
    INVALID_QUERY, MethodType, QueryValidator, base_is_valid, nested, query_validator, ucfirst,
};
use crate::validator::query::filter::Filter;

/// Operators the builder compiles against an aggregate expression.
const ALIAS_METHODS: [Method; 10] = [
    Method::Equal,
    Method::NotEqual,
    Method::LessThan,
    Method::LessThanEqual,
    Method::GreaterThan,
    Method::GreaterThanEqual,
    Method::Between,
    Method::NotBetween,
    Method::IsNull,
    Method::IsNotNull,
];

/// Aggregates whose result has the type of the aggregated attribute.
const EXTREMA: [Method; 2] = [Method::Min, Method::Max];

#[derive(Default)]
struct State {
    /// The filter rules every condition has to meet: a [`Filter`].
    filter: Option<Arc<dyn QueryValidator>>,
    /// The method and attribute of each aliased aggregate, by alias.
    aggregations: HashMap<String, (Method, String)>,
    group_by: HashSet<String>,
}

/// A `having` query of at least one condition, each a query; with the
/// filter rules of the query set ([`Having::set_filter`]), each condition
/// compares an aggregate alias of the set or one of its groupBy attributes
/// under those rules.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::validator::query::having::Having;
///
/// let having = Having::default();
/// assert!(having.check(&Query::having([Query::greater_than("total", 1)])));
/// assert!(!having.check(&Query::having([])));
/// assert_eq!(having.description(), "Having requires at least one condition");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Having`.
pub struct Having {
    state: Mutex<State>,
    message: Message,
}

impl std::fmt::Debug for Having {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Having").field("message", &self.message).finish_non_exhaustive()
    }
}

impl Default for Having {
    fn default() -> Self {
        Having { state: Mutex::new(State::default()), message: Message::new(INVALID_QUERY) }
    }
}

impl Having {
    fn state(&self) -> MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn fail(&self, message: impl Into<String>) -> bool {
        self.message.set(message);
        false
    }

    /// The filter rules every condition has to meet (`setFilter()`): a
    /// validator that is a [`Filter`]; any other is ignored. Without one,
    /// only the shape of the having query is checked.
    pub fn set_filter(&self, filter: Option<Arc<dyn QueryValidator>>) {
        self.state().filter = filter.filter(|f| f.as_any().is::<Filter>());
    }

    /// The aggregate queries of the query set (`setAggregations()`).
    pub fn set_aggregations(&self, aggregations: &[&utopia_query::Query]) {
        let mut state = self.state();
        state.aggregations.clear();
        for aggregation in aggregations {
            let alias = aggregation.alias();
            if !alias.is_empty() {
                state.aggregations.insert(alias.to_owned(), (aggregation.method(), aggregation.attribute().to_owned()));
            }
        }
    }

    /// The groupBy attributes of the query set (`setGroupBy()`).
    pub fn set_group_by(&self, attributes: &[QValue]) {
        let mut state = self.state();
        state.group_by.clear();
        for attribute in attributes {
            if let QValue::Str(s) = attribute
                && !s.is_empty()
            {
                state.group_by.insert(String::from_utf8_lossy(s.as_bytes()).into_owned());
            }
        }
    }

    /// `isValidQuery($query)`.
    pub fn check(&self, query: &utopia_query::Query) -> bool {
        let conditions = query.values();
        if conditions.is_empty() {
            return self.fail("Having requires at least one condition");
        }
        let mut queries = Vec::with_capacity(conditions.len());
        for condition in conditions.values() {
            let Some(condition) = nested(condition) else {
                return self.fail("Having conditions must be Query instances");
            };
            queries.push(condition);
        }
        let state = self.state();
        let Some(filter) = state.filter.as_ref().and_then(|f| f.as_any().downcast_ref::<Filter>()) else {
            return true;
        };
        queries.into_iter().all(|condition| self.is_valid_condition(&state, condition, filter, false))
    }

    fn is_valid_condition(&self, state: &State, condition: &utopia_query::Query, filter: &Filter, inner: bool) -> bool {
        let method = condition.method();
        if !is_filter_method(method) {
            return self.fail("Having conditions must be filter queries");
        }
        if method == Method::And || method == Method::Or {
            if !condition.nested().all(|child| self.is_valid_condition(state, child, filter, true)) {
                return false;
            }
            return self.is_valid_filter(condition, filter);
        }
        let attribute = condition.attribute();
        if let Some(aggregation) = state.aggregations.get(attribute) {
            if inner {
                return self
                    .fail(format!("Aggregate alias \"{attribute}\" can only be compared at the top level of having"));
            }
            return self.is_valid_alias_condition(condition, aggregation, filter);
        }
        if !state.group_by.contains(attribute) {
            return self
                .fail(format!("Having can only compare an aggregate alias or a groupBy attribute: {attribute}"));
        }
        self.is_valid_filter(condition, filter)
    }

    fn is_valid_alias_condition(
        &self,
        condition: &utopia_query::Query,
        (aggregate, attribute): &(Method, String),
        filter: &Filter,
    ) -> bool {
        let method = condition.method();
        let alias = condition.attribute();
        if !ALIAS_METHODS.contains(&method) {
            return self.fail(format!("Aggregate alias \"{alias}\" cannot be compared with {}", method.as_str()));
        }
        let values = condition.values();
        if EXTREMA.contains(aggregate) {
            let compared = utopia_query::Query::new(method, attribute.clone(), values.clone(), "");
            return self.is_valid_filter(&compared, filter);
        }
        let count = values.len();
        let message = match method {
            Method::Equal if count == 0 => Some("require at least one value."),
            Method::Between | Method::NotBetween if count != 2 => Some("require exactly two values."),
            Method::Equal | Method::Between | Method::NotBetween | Method::IsNull | Method::IsNotNull => None,
            _ if count != 1 => Some("require exactly one value."),
            _ => None,
        };
        if let Some(message) = message {
            return self.fail(format!("{} queries {message}", ucfirst(method.as_str())));
        }
        if count as i64 > filter.max_values_count() {
            return self.fail(format!(
                "Query on aggregate alias has greater than {} values: {alias}",
                filter.max_values_count()
            ));
        }
        if !values.values().all(finite_query_value) {
            return self.fail(format!("Query value is invalid for aggregate alias \"{alias}\""));
        }
        true
    }

    fn is_valid_filter(&self, condition: &utopia_query::Query, filter: &Filter) -> bool {
        if !filter.check(condition) {
            return self.fail(filter.description());
        }
        true
    }
}

fn is_filter_method(method: Method) -> bool {
    method.is_filter()
        || method.is_spatial()
        || method.is_vector()
        || matches!(method, Method::And | Method::Or | Method::ContainsAll | Method::ElemMatch)
}

base_is_valid!(Having);
query_validator!(Having, MethodType::Having);
