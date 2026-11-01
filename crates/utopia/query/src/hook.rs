//! Hooks: code a builder runs while compiling, to scope every query
//! ([`Filter`], [`JoinFilter`]) or map attribute names to columns
//! ([`Attribute`]). [`Tenant`] and [`Map`] are the hooks the library ships.
//!
//! PHP: `Utopia\Query\Hook`, `Hook\Filter`, `Hook\Attribute`, `Hook\Join\Filter`, `Hook\Write`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Hook` (a marker interface) | [`Hook`] (what [`Builder::add_hook`](crate::builder::Builder::add_hook) takes) |
//! | `Hook\Filter` | [`Filter`] |
//! | `Hook\Attribute` | [`Attribute`] |
//! | `Hook\Join\Filter` | [`JoinFilter`] |
//! | `Hook\Join\Condition` | [`JoinCondition`] |
//! | `Hook\Join\Placement` | [`Placement`] |
//! | `Hook\Write` | [`Write`] |
//! | `Hook\Filter\Tenant` | [`Tenant`] |
//! | `Hook\Attribute\Map` | [`Map`] |

use std::collections::HashMap;
use std::sync::Arc;

use crate::builder::{Condition, JoinType};
use crate::error::{Error, Result};
use crate::value::{Array, IntoValues, Value};

/// Adds a condition to the `WHERE` of every query on a table (tenancy,
/// permissions).
///
/// PHP: `Utopia\Query\Hook\Filter`.
pub trait Filter: Send + Sync {
    /// The condition for `table` (the alias when the query has one).
    fn filter(&self, table: &str) -> Result<Condition>;
}

/// Maps an attribute name to the column it is stored in.
///
/// PHP: `Utopia\Query\Hook\Attribute`.
pub trait Attribute: Send + Sync {
    /// The column for `attribute`.
    fn resolve(&self, attribute: &str) -> Result<String>;
}

/// Scopes a joined table: a condition for the join, placed in its `ON` or in
/// the `WHERE`, or nothing.
///
/// PHP: `Utopia\Query\Hook\Join\Filter`.
pub trait JoinFilter: Send + Sync {
    /// The condition for joining `table` (its alias when it has one) with `join_type`.
    fn filter_join(&self, table: &str, join_type: JoinType) -> Result<Option<JoinCondition>>;
}

/// Decorates rows before they are written and observes writes, for the
/// database layer.
///
/// PHP: `Utopia\Query\Hook\Write`.
pub trait Write: Send + Sync {
    /// The row to write.
    fn decorate_row(&self, row: Array, metadata: &Array) -> Result<Array>;
    /// After rows were created.
    fn after_create(&self, table: &str, metadata: &Array, context: &Value) -> Result<()>;
    /// After a row was updated.
    fn after_update(&self, table: &str, metadata: &Array, context: &Value) -> Result<()>;
    /// After a batch update.
    fn after_batch_update(&self, table: &str, update_data: &Array, metadata: &Array, context: &Value) -> Result<()>;
    /// After rows were deleted.
    fn after_delete(&self, table: &str, ids: &Array, context: &Value) -> Result<()>;
}

/// A hook a builder accepts: one object may filter, resolve attributes and
/// filter joins at once (as [`Tenant`] filters both tables and joins).
///
/// PHP: `Utopia\Query\Hook`, whose implementations a builder sorts by the
/// interfaces they implement.
#[derive(Clone, Default)]
pub struct Hook {
    /// The filter part.
    pub filter: Option<Arc<dyn Filter>>,
    /// The attribute part.
    pub attribute: Option<Arc<dyn Attribute>>,
    /// The join filter part.
    pub join_filter: Option<Arc<dyn JoinFilter>>,
}

impl Hook {
    /// A hook that only filters.
    pub fn filter(filter: impl Filter + 'static) -> Self {
        Hook { filter: Some(Arc::new(filter)), ..Hook::default() }
    }

    /// A hook that only resolves attributes.
    pub fn attribute(attribute: impl Attribute + 'static) -> Self {
        Hook { attribute: Some(Arc::new(attribute)), ..Hook::default() }
    }

    /// A hook that only filters joins.
    pub fn join_filter(join_filter: impl JoinFilter + 'static) -> Self {
        Hook { join_filter: Some(Arc::new(join_filter)), ..Hook::default() }
    }
}

impl From<Tenant> for Hook {
    /// Tenant filters both tables and joins.
    fn from(tenant: Tenant) -> Self {
        let tenant = Arc::new(tenant);
        Hook { filter: Some(tenant.clone()), attribute: None, join_filter: Some(tenant) }
    }
}

impl From<Map> for Hook {
    /// Map resolves attributes.
    fn from(map: Map) -> Self {
        Hook::attribute(map)
    }
}

/// Where a join filter's condition goes.
///
/// PHP: `Utopia\Query\Hook\Join\Placement`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Placement {
    /// In the join's `ON`.
    On,
    /// In the query's `WHERE`.
    Where,
}

impl Placement {
    /// The case name.
    pub fn name(self) -> &'static str {
        match self {
            Placement::On => "On",
            Placement::Where => "Where",
        }
    }
}

/// A join filter's condition and where it goes.
///
/// PHP: `Utopia\Query\Hook\Join\Condition`.
#[derive(Debug, Clone, PartialEq)]
pub struct JoinCondition {
    /// The condition.
    pub condition: Condition,
    /// Where it goes (PHP's default is `Where`).
    pub placement: Placement,
}

impl JoinCondition {
    /// A condition placed as given.
    pub fn new(condition: Condition, placement: Placement) -> Self {
        JoinCondition { condition, placement }
    }
}

/// Scopes every query, and every joined table, to tenants: `column IN (?, ...)`
/// (in a left or right join's `ON`, elsewhere in the `WHERE`).
///
/// PHP: `Utopia\Query\Hook\Filter\Tenant`.
///
/// ```
/// use utopia_query::builder::MySql;
/// use utopia_query::hook::Tenant;
///
/// let statement = MySql::new().from("docs", "").add_hook(Tenant::new(["7"], "tenant_id")?)?.build()?;
/// assert_eq!(statement.query, "SELECT * FROM `docs` WHERE tenant_id IN (?)");
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Builder\MySQL;
/// use Utopia\Query\Hook\Filter\Tenant;
///
/// echo (new MySQL())->from('docs')->addHook(new Tenant(['7']))->build()->query, "\n"; // SELECT * FROM `docs` WHERE tenant_id IN (?)
/// ```
#[derive(Debug, Clone, PartialEq)]
pub struct Tenant {
    tenant_ids: Vec<Value>,
    column: String,
}

impl Tenant {
    /// Scopes to `tenant_ids` on `column` (PHP's default is `tenant_id`),
    /// which must be a plain identifier (letters, digits, `_` and `.`, not
    /// starting with a digit).
    pub fn new(tenant_ids: impl IntoValues, column: impl Into<String>) -> Result<Self> {
        let column = column.into();
        if !is_column_name(&column) {
            return Err(Error::InvalidArgument(format!("Invalid column name: {column}")));
        }
        Ok(Tenant { tenant_ids: tenant_ids.into_values().values().cloned().collect(), column })
    }
}

/// `/^[a-zA-Z_][a-zA-Z0-9_.]*$/` (where `$` also matches before a final newline).
pub(crate) fn is_column_name(name: &str) -> bool {
    let bytes = name.strip_suffix('\n').unwrap_or(name).as_bytes();
    match bytes.split_first() {
        Some((first, rest)) => {
            (first.is_ascii_alphabetic() || *first == b'_')
                && rest.iter().all(|b| b.is_ascii_alphanumeric() || *b == b'_' || *b == b'.')
        }
        None => false,
    }
}

impl Filter for Tenant {
    /// `column IN (?, ...)` with the tenant ids, or `1 = 0` for none.
    fn filter(&self, _table: &str) -> Result<Condition> {
        if self.tenant_ids.is_empty() {
            return Ok(Condition::new("1 = 0", Vec::new()));
        }
        let placeholders = vec!["?"; self.tenant_ids.len()].join(", ");
        Ok(Condition::new(format!("{} IN ({placeholders})", self.column), self.tenant_ids.clone()))
    }
}

impl JoinFilter for Tenant {
    /// The same condition: in the `ON` of left and right joins (so unmatched
    /// rows survive), in the `WHERE` otherwise.
    fn filter_join(&self, table: &str, join_type: JoinType) -> Result<Option<JoinCondition>> {
        let condition = self.filter(table)?;
        let placement = match join_type {
            JoinType::Left | JoinType::Right => Placement::On,
            _ => Placement::Where,
        };
        Ok(Some(JoinCondition::new(condition, placement)))
    }
}

/// Maps attribute names to columns through a table; other names stay as they are.
///
/// PHP: `Utopia\Query\Hook\Attribute\Map`.
///
/// ```
/// use utopia_query::hook::{Attribute, Map};
///
/// let map = Map::new([("$id", "_uid")]);
/// assert_eq!(map.resolve("$id")?, "_uid");
/// assert_eq!(map.resolve("name")?, "name");
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Hook\Attribute\Map;
///
/// $map = new Map(['$id' => '_uid']);
/// echo $map->resolve('$id'), "\n";  // _uid
/// echo $map->resolve('name'), "\n"; // name
/// ```
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Map {
    map: HashMap<String, String>,
}

impl Map {
    /// A map from attribute names to columns.
    pub fn new<K: Into<String>, V: Into<String>>(map: impl IntoIterator<Item = (K, V)>) -> Self {
        Map { map: map.into_iter().map(|(k, v)| (k.into(), v.into())).collect() }
    }
}

impl Attribute for Map {
    /// The mapped column, or the attribute itself.
    fn resolve(&self, attribute: &str) -> Result<String> {
        Ok(self.map.get(attribute).cloned().unwrap_or_else(|| attribute.to_owned()))
    }
}
