//! [`Aggregate`]: aggregate queries (`count`, `sum`, `avg`, `min`, ...).
//!
//! PHP: `Utopia\Database\Validator\Query\Aggregate`.

use std::collections::{HashMap, HashSet};
use std::sync::{Arc, Mutex, MutexGuard};

use utopia_query::schema::ColumnType;
use utopia_query::{Method, Value as QValue};

use crate::attribute::Attribute;
use crate::storage;
use crate::validator::Message;
use crate::validator::query::base::{
    INVALID_QUERY, MethodType, base_is_valid, is_alias_identifier, query_validator, split_dot,
};
use crate::validator::query::joined::attributes::{Joins, internal_columns, is_joined_internal_attribute};
use crate::validator::query::joined::collection::Collection;

/// The longest alias an aggregate may take.
///
/// PHP: `Aggregate::MAX_ALIAS_LENGTH`.
pub const MAX_ALIAS_LENGTH: usize = 63;

const NUMERIC_METHODS: [Method; 8] = [
    Method::Sum,
    Method::Avg,
    Method::Stddev,
    Method::StddevPop,
    Method::StddevSamp,
    Method::Variance,
    Method::VarPop,
    Method::VarSamp,
];

const BITWISE_METHODS: [Method; 3] = [Method::BitAnd, Method::BitOr, Method::BitXor];

const EXTREMUM_METHODS: [Method; 2] = [Method::Min, Method::Max];

/// Types whose values some engine cannot compare for `min()` and `max()`.
const UNORDERED_TYPES: [ColumnType; 7] = [
    ColumnType::Boolean,
    ColumnType::Json,
    ColumnType::Object,
    ColumnType::Point,
    ColumnType::Linestring,
    ColumnType::Polygon,
    ColumnType::Vector,
];

#[derive(Debug, Clone, Default)]
struct State {
    joins: Joins,
    /// How many aggregates of the query set carry each alias.
    aliases: HashMap<String, usize>,
    /// The attribute each group is returned under, keyed by the name it takes.
    groups: HashMap<String, String>,
}

/// An aggregate of an attribute of the collection (or of a join, or `*` for
/// `count`) that holds a column: arithmetic aggregates of a number,
/// bitwise ones of an integer, extrema of ordered values, with an alias
/// that is an identifier no other aggregate or group takes.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::attribute::Attribute;
/// use utopia_database::validator::query::aggregate::Aggregate;
///
/// let aggregate = Aggregate::new(&[Attribute::double("price"), Attribute::string("name", 64)], true, false);
/// assert!(aggregate.check(&Query::avg("price", "average")));
/// assert!(!aggregate.check(&Query::sum("name", "")));
/// assert_eq!(aggregate.description(), "Aggregate sum requires a numeric attribute that is not an array: name");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Aggregate` (`new
/// Aggregate($attributes, $supportForAttributes, $sharedTables)`).
#[derive(Debug)]
pub struct Aggregate {
    schema: HashSet<String>,
    numeric: HashMap<String, ColumnType>,
    unordered: HashSet<String>,
    columns: HashMap<String, bool>,
    support_for_attributes: bool,
    state: Mutex<State>,
    message: Message,
}

impl Default for Aggregate {
    /// `new Aggregate()`.
    fn default() -> Self {
        Aggregate::new(&[], true, false)
    }
}

impl Aggregate {
    /// `new Aggregate($attributes, $supportForAttributes, $sharedTables)`.
    pub fn new(attributes: &[Attribute], support_for_attributes: bool, shared_tables: bool) -> Self {
        let mut schema = HashSet::new();
        let mut numeric = HashMap::new();
        let mut unordered = HashSet::new();
        for attribute in attributes {
            schema.insert(attribute.key.clone());
            if !attribute.array && attribute.is_numeric() {
                numeric.insert(attribute.key.clone(), attribute.kind);
            }
            if !is_ordered(Some(attribute.kind), attribute.array) {
                unordered.insert(attribute.key.clone());
            }
        }
        schema.extend(internal_columns(shared_tables).map(str::to_owned));
        Aggregate {
            schema,
            numeric,
            unordered,
            columns: Collection::columns(attributes),
            support_for_attributes,
            state: Mutex::new(State::default()),
            message: Message::new(INVALID_QUERY),
        }
    }

    fn state(&self) -> MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn fail(&self, message: impl Into<String>) -> bool {
        self.message.set(message);
        false
    }

    /// `acceptsMainAttribute()`.
    fn accepts_main(&self, attribute: &str) -> bool {
        self.schema.contains(attribute)
    }

    /// The aggregates of the query set: an alias names one column of the
    /// result (`setAggregations()`).
    pub fn set_aggregations(&self, aggregations: &[&utopia_query::Query]) {
        let mut state = self.state();
        state.aliases.clear();
        for aggregation in aggregations {
            let alias = aggregation.alias();
            if !alias.is_empty() {
                *state.aliases.entry(alias.to_owned()).or_insert(0) += 1;
            }
        }
    }

    /// The attributes the query set groups by, each under its own name and
    /// its column's (`setGroupBy()`).
    pub fn set_group_by(&self, attributes: &[QValue]) {
        let mut state = self.state();
        state.groups.clear();
        for attribute in attributes {
            let QValue::Str(s) = attribute else { continue };
            if s.is_empty() {
                continue;
            }
            let attribute = String::from_utf8_lossy(s.as_bytes()).into_owned();
            let name = split_dot(&attribute).map_or(attribute.as_str(), |(_, column)| column).to_owned();
            let column = storage::column(&name).to_owned();
            state.groups.entry(name).or_insert_with(|| attribute.clone());
            state.groups.entry(column).or_insert(attribute);
        }
    }

    /// `isValidQuery($query)`.
    pub fn check(&self, query: &utopia_query::Query) -> bool {
        let attribute = query.attribute();
        if attribute == "*" && query.method() != Method::Count {
            return self.fail("Only count can aggregate \"*\"");
        }
        let state = self.state();
        if attribute != "*"
            && self.support_for_attributes
            && !self.schema.contains(attribute)
            && let Err(message) = state.joins.is_joined_attribute(attribute, |a| self.accepts_main(a))
        {
            return self.fail(message);
        }
        if self.columns.get(attribute) == Some(&false) {
            return self.fail(format!("Cannot aggregate virtual relationship attribute: {attribute}"));
        }
        if !self.is_valid_operand(&state, query) || !self.is_valid_extremum(&state, query) {
            return false;
        }
        let alias = query.alias();
        if alias.is_empty() {
            return true;
        }
        if !is_alias_identifier(alias) {
            return self.fail("Invalid aggregate alias");
        }
        if alias.len() > MAX_ALIAS_LENGTH {
            return self
                .fail(format!("Aggregate alias is too long: at most {MAX_ALIAS_LENGTH} characters are allowed"));
        }
        if state.aliases.get(alias).copied().unwrap_or(0) > 1 {
            return self.fail(format!("Aggregate alias \"{alias}\" is given to more than one aggregate"));
        }
        if let Some(group) = state.groups.get(alias) {
            return self.fail(format!(
                "Aggregate alias \"{alias}\" is the name the groupBy attribute \"{group}\" is returned under"
            ));
        }
        true
    }

    fn is_valid_operand(&self, state: &State, query: &utopia_query::Query) -> bool {
        let method = query.method();
        let attribute = query.attribute();
        let bitwise = BITWISE_METHODS.contains(&method);
        if !bitwise && !NUMERIC_METHODS.contains(&method) {
            return true;
        }
        let kind = if self.schema.contains(attribute) {
            self.numeric.get(attribute).copied()
        } else {
            let join = state.joins.join_of(attribute);
            let column = split_dot(attribute).map_or(attribute, |(_, c)| c);
            match join {
                Some(join) if join.attributes.contains(column) => join.numeric.get(column).copied(),
                Some(_) if is_joined_internal_attribute(column, |a| self.accepts_main(a)) => {
                    self.numeric.get(column).copied()
                }
                _ => return true,
            }
        };
        let Some(kind) = kind else {
            return self.fail(format!(
                "Aggregate {} requires a numeric attribute that is not an array: {attribute}",
                method.as_str()
            ));
        };
        if bitwise && kind != ColumnType::Integer && kind != ColumnType::BigInteger {
            return self.fail(format!(
                "Aggregate {} requires an integer attribute that is not an array: {attribute}",
                method.as_str()
            ));
        }
        true
    }

    fn is_valid_extremum(&self, state: &State, query: &utopia_query::Query) -> bool {
        let method = query.method();
        let attribute = query.attribute();
        if !EXTREMUM_METHODS.contains(&method) {
            return true;
        }
        let ordered = if self.schema.contains(attribute) {
            !self.unordered.contains(attribute)
        } else {
            let column = split_dot(attribute).map_or(attribute, |(_, c)| c);
            let Some(definition) = state.joins.join_of(attribute).and_then(|j| j.schema.get(column)) else {
                return true;
            };
            is_ordered(Some(definition.kind), definition.array)
        };
        if !ordered {
            return self.fail(format!(
                "Aggregate {} requires an attribute whose values are ordered, not an array, object, boolean, spatial or vector one: {attribute}",
                method.as_str()
            ));
        }
        true
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

/// `isOrdered()`.
fn is_ordered(kind: Option<ColumnType>, array: bool) -> bool {
    !array && !kind.is_some_and(|k| UNORDERED_TYPES.contains(&k))
}

base_is_valid!(Aggregate);
query_validator!(Aggregate, MethodType::Aggregate);
