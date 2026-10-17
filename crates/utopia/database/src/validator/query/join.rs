//! [`Join`]: join queries (`join`, `leftJoin`, `rightJoin`, `crossJoin`,
//! `fullOuterJoin`; `naturalJoin` is refused).
//!
//! PHP: `Utopia\Database\Validator\Query\Join`.

use std::collections::{HashMap, HashSet};
use std::sync::{Arc, Mutex, MutexGuard};

use utopia_query::{Method, Value as QValue};

use crate::attribute::Attribute;
use crate::document;
use crate::query::DEFAULT_ALIAS;
use crate::validator::Message;
use crate::validator::query::base::{INVALID_QUERY, MethodType, base_is_valid, is_alias_identifier, query_validator};
use crate::validator::query::joined::collection::Collection;

/// The most joins one query set may declare.
///
/// PHP: `Join::MAX_PER_QUERY`.
pub const MAX_PER_QUERY: usize = 8;

const OPERATORS: [&str; 7] = ["=", "!=", "<", ">", "<=", ">=", "<>"];

/// The internal attributes every table holds a column for that a join
/// condition can compare.
const INTERNAL_COLUMNS: [&str; 4] = [document::ID, document::SEQUENCE, document::CREATED_AT, document::UPDATED_AT];

#[derive(Debug, Clone, Default)]
struct State {
    /// The joins of the query set whose collection is known.
    joins: Vec<Arc<Collection>>,
    /// The aliases the joins validated so far declared, with the collection
    /// each joins when known.
    declared: HashMap<String, Option<Arc<Collection>>>,
}

/// A join of a table under an alias (an identifier, not the main
/// collection's, not a relationship key), whose conditions compare a column
/// of the main collection or of an earlier join with a column of the
/// joined collection.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::validator::query::join::Join;
///
/// let join = Join::default();
/// assert!(join.check(&Query::join("orders", "ord", vec![Query::on("$id", "customerId", "=")]).unwrap()));
/// assert!(!join.check(&Query::cross_join("orders", "my-alias").unwrap()));
/// assert_eq!(
///     join.description(),
///     "Join alias must start with a letter or an underscore and contain only letters, digits and underscores"
/// );
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Join` (`new Join($attributes,
/// $supportForAttributes)`; `null` attributes are `None`).
#[derive(Debug)]
pub struct Join {
    columns: Option<HashMap<String, bool>>,
    relationships: HashSet<String>,
    support_for_attributes: bool,
    state: Mutex<State>,
    message: Message,
}

impl Default for Join {
    /// `new Join()`: the main collection's attributes unknown.
    fn default() -> Self {
        Join::new(None, true)
    }
}

impl Join {
    /// `new Join($attributes, $supportForAttributes)`.
    pub fn new(attributes: Option<&[Attribute]>, support_for_attributes: bool) -> Self {
        Join {
            columns: attributes.map(Collection::columns),
            relationships: attributes
                .unwrap_or_default()
                .iter()
                .filter(|a| !a.key.is_empty() && a.relationship.is_some())
                .map(|a| a.key.clone())
                .collect(),
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

    /// Declare the joins of the query set whose collection is known
    /// (`allowJoins()`).
    pub fn allow_joins(&self, joins: &[Arc<Collection>]) {
        self.state().joins = joins.to_vec();
    }

    /// Start a new query set (`resetJoinAliases()`).
    pub fn reset_join_aliases(&self) {
        let mut state = self.state();
        state.joins.clear();
        state.declared.clear();
    }

    /// Why a join alias cannot be used, or `None` when it can
    /// (`describeInvalidAlias()`). A defaulted alias is the table ID the join
    /// names ([`utopia_query::Query::is_alias_defaulted`]), so the reason says
    /// that ID cannot serve as the alias and asks for one.
    pub fn describe_invalid_alias(alias: &str, defaulted: bool) -> Option<String> {
        if alias.is_empty() {
            return Some("Join alias is required".to_owned());
        }
        if !is_alias_identifier(alias) {
            return Some(if defaulted {
                unusable_table(
                    alias,
                    "an alias must start with a letter or an underscore and contain only letters, digits and underscores",
                )
            } else {
                "Join alias must start with a letter or an underscore and contain only letters, digits and underscores"
                    .to_owned()
            });
        }
        if alias.eq_ignore_ascii_case(DEFAULT_ALIAS) {
            return Some(if defaulted {
                unusable_table(alias, "it is reserved for the main collection")
            } else {
                format!("Join alias \"{alias}\" is reserved for the main collection")
            });
        }
        None
    }

    /// Why a join alias another join of the same query set already declared
    /// cannot be used (`describeDuplicateAlias()`).
    pub fn describe_duplicate_alias(alias: &str, defaulted: bool) -> String {
        if defaulted {
            unusable_table(alias, "another join of the query already uses it as its alias")
        } else {
            format!("Join alias \"{alias}\" is declared more than once")
        }
    }

    /// Whether one query set may declare `count` joins (`isValidCount()`).
    pub fn is_valid_count(&self, count: usize) -> bool {
        if count > MAX_PER_QUERY {
            return self.fail(format!("Too many joins: at most {MAX_PER_QUERY} are allowed"));
        }
        true
    }

    /// `isValidQuery($query)`.
    pub fn check(&self, query: &utopia_query::Query) -> bool {
        if query.method() == Method::NaturalJoin {
            return self.fail("Natural joins are not supported");
        }
        let table = query.attribute();
        // `empty($table)`: `""` and `"0"`.
        if table.is_empty() || table == "0" {
            return self.fail("Join requires a table name");
        }
        let alias = query.alias();
        let defaulted = query.is_alias_defaulted();
        if let Some(message) = Join::describe_invalid_alias(alias, defaulted) {
            return self.fail(message);
        }
        if self.relationships.contains(alias) {
            return self.fail(if defaulted {
                unusable_table(alias, &format!("it is the key of the relationship attribute \"{alias}\""))
            } else {
                format!(
                    "Join alias \"{alias}\" is the key of the relationship attribute \"{alias}\": give the join another alias"
                )
            });
        }
        let mut state = self.state();
        let join = state.joins.iter().find(|j| j.collection == table && j.alias == alias).cloned();
        if query.method() != Method::CrossJoin && !self.is_valid_conditions(&state, query, alias, join.as_deref()) {
            return false;
        }
        state.declared.insert(alias.to_owned(), join);
        true
    }

    fn is_valid_conditions(
        &self,
        state: &State,
        query: &utopia_query::Query,
        alias: &str,
        join: Option<&Collection>,
    ) -> bool {
        let conditions = query.join_on_queries();
        if conditions.is_empty() {
            return self.fail("Join ON requires at least one condition");
        }
        for condition in conditions {
            if !self.is_condition(condition) {
                return false;
            }
            if condition.method() != Method::On {
                continue;
            }
            let values = condition.values();
            let at = |i: i64| values.get(&php_std::ArrayKey::Int(i)).filter(|v| !matches!(v, QValue::Null));
            let equals = utopia_query::string("=");
            if !self.is_valid_condition(state, at(0), at(1).unwrap_or(&equals), at(2), alias, join) {
                return false;
            }
        }
        true
    }

    fn is_condition(&self, query: &utopia_query::Query) -> bool {
        let method = query.method();
        if !method.is_join_condition() {
            return self.fail(format!("Unsupported join ON condition: {}", method.as_str()));
        }
        if method != Method::And && method != Method::Or {
            return true;
        }
        query.nested().all(|child| self.is_condition(child))
    }

    fn is_valid_condition(
        &self,
        state: &State,
        left: Option<&QValue>,
        operator: &QValue,
        right: Option<&QValue>,
        alias: &str,
        join: Option<&Collection>,
    ) -> bool {
        let column = |v: Option<&QValue>| match v {
            Some(QValue::Str(s)) if !s.is_empty() => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
            _ => None,
        };
        let (Some(left), Some(right)) = (column(left), column(right)) else {
            return self.fail("Join ON requires left and right columns");
        };
        match operator {
            QValue::Str(s) if OPERATORS.iter().any(|o| o.as_bytes() == s.as_bytes()) => {}
            QValue::Str(s) => {
                return self.fail(format!("Invalid join operator: {}", String::from_utf8_lossy(s.as_bytes())));
            }
            other => return self.fail(format!("Invalid join operator: {}", other.type_name())),
        }
        self.is_valid_left_column(state, &left) && self.is_valid_right_column(&right, alias, join)
    }

    /// The left column belongs to the main collection, or under its alias to
    /// a join declared before this one.
    fn is_valid_left_column(&self, state: &State, column: &str) -> bool {
        let Some((alias, name)) = column.split_once('.') else {
            return self.is_column(self.columns.as_ref().map(Columns::Main), column, column);
        };
        let Some(declared) = state.declared.get(alias) else {
            return self.fail(format!(
                "The left column of a join condition must belong to the main collection or to a join declared before it: {column}"
            ));
        };
        self.is_column(declared.as_deref().map(Columns::Joined), name, column)
    }

    /// The right column belongs to the collection the join reads, bare or
    /// under the join's alias.
    fn is_valid_right_column(&self, column: &str, alias: &str, join: Option<&Collection>) -> bool {
        let mut name = column;
        if let Some((prefix, rest)) = column.split_once('.') {
            if prefix != alias {
                return self.fail(format!(
                    "The right column of a join condition must belong to the joined collection: {column}"
                ));
            }
            name = rest;
        }
        self.is_column(join.map(Columns::Joined), name, column)
    }

    fn is_column(&self, columns: Option<Columns<'_>>, name: &str, column: &str) -> bool {
        if !name.is_empty() && (!self.support_for_attributes || columns.is_none() || INTERNAL_COLUMNS.contains(&name)) {
            return true;
        }
        match columns.and_then(|c| c.holds(name)) {
            Some(true) => true,
            Some(false) => self.fail(format!("Cannot join on virtual relationship attribute: {column}")),
            None => self.fail(format!("Attribute not found in schema: {column}")),
        }
    }
}

/// The columns of one side of a condition: the main collection's, or a
/// joined collection's (`columnsOf()`: its attributes, then its columns).
#[derive(Clone, Copy)]
enum Columns<'a> {
    Main(&'a HashMap<String, bool>),
    Joined(&'a Collection),
}

impl Columns<'_> {
    fn holds(self, name: &str) -> Option<bool> {
        match self {
            Columns::Main(columns) => columns.get(name).copied(),
            Columns::Joined(join) if join.attributes.contains(name) => Some(true),
            Columns::Joined(join) => join.columns.get(name).copied(),
        }
    }
}

base_is_valid!(Join);
query_validator!(Join, MethodType::Join);

/// `describeUnusableTable()`: a join read under its table ID that cannot be.
fn unusable_table(table: &str, reason: &str) -> String {
    format!("Table ID \"{table}\" cannot be used as the join alias: {reason}. Pass an alias for the join")
}
