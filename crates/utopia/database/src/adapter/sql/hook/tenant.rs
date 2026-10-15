//! Tenant isolation for shared tables: every table a statement reads is kept
//! to the selected tenants.
//!
//! PHP: `Utopia\Database\Adapter\SQL\Hook\Tenant\*`.

use std::sync::{Arc, Mutex, MutexGuard};

use utopia_query::builder::{Condition, JoinType};
use utopia_query::hook::{Filter as FilterHook, Hook, JoinCondition, JoinFilter, Placement};
use utopia_query::{IntoValue, IntoValues, Value};

use super::column::AllowNull;
use super::hook_error;
use super::join::{Chain, OuterChain};
use crate::error::{Error, Result};
use crate::storage;

/// The tenant condition of the reads and writes on shared tables:
/// `table._tenant IN (?, ...)`.
///
/// PHP: `Utopia\Database\Adapter\SQL\Hook\Tenant\Filter`.
#[derive(Debug, Clone, PartialEq)]
pub struct Filter {
    tenants: Vec<Value>,
    metadata_collection: String,
    collection: String,
    allow_null_column: String,
    quote: String,
    unindexed: Vec<String>,
}

impl Filter {
    /// Keeps statements to `tenants`: the selected tenant, several for a
    /// query that spans tenants, or none (a `null` tenant), which matches no
    /// tenant's rows rather than every tenant's. The other arguments take
    /// PHP's defaults; set them with the `with_*` methods.
    pub fn new(tenants: impl IntoValues) -> Self {
        let mut tenants: Vec<Value> = tenants.into_values().values().cloned().collect();
        if tenants.is_empty() {
            tenants.push(Value::Null);
        }
        Filter {
            tenants,
            metadata_collection: String::new(),
            collection: String::new(),
            allow_null_column: String::new(),
            quote: "`".to_owned(),
            unindexed: Vec::new(),
        }
    }

    /// The metadata collection, whose rows (and permission rows) may be
    /// tenantless (`$metadataCollection`).
    pub fn with_metadata_collection(mut self, metadata_collection: impl Into<String>) -> Self {
        self.metadata_collection = metadata_collection.into();
        self
    }

    /// The collection or table actually queried, not its alias (`$collection`).
    pub fn with_collection(mut self, collection: impl Into<String>) -> Self {
        self.collection = collection.into();
        self
    }

    /// A column whose `NULL` also passes (`$allowNullColumn`): the rows an
    /// outer join produced without a main-table match. It must be a `NOT NULL`
    /// column such as `_uid`, never `_tenant`.
    pub fn with_allow_null_column(mut self, column: impl Into<String>) -> Self {
        self.allow_null_column = column.into();
        self
    }

    /// The adapter's identifier quote (`$quoteCharacter`, default `` ` ``).
    pub fn with_quote(mut self, quote: impl Into<String>) -> Self {
        self.quote = quote.into();
        self
    }

    /// The join aliases no index serves the join of (`$unindexed`): an inner
    /// or left join matches their tenant as a range.
    pub fn with_unindexed<S: Into<String>>(mut self, unindexed: impl IntoIterator<Item = S>) -> Self {
        self.unindexed = unindexed.into_iter().map(Into::into).collect();
        self
    }

    /// `?, ...`, one per tenant.
    fn placeholders(&self) -> String {
        vec!["?"; self.tenants.len()].join(", ")
    }

    /// The tenant condition of a joined table, before an outer join places
    /// or relaxes it.
    ///
    /// PHP: `joined()`.
    pub fn joined(&self, table: &str) -> Condition {
        let column = format!("{}.{}", AllowNull::quote(table, &self.quote), storage::TENANT);
        Condition::new(format!("{column} IN ({})", self.placeholders()), self.tenants.clone())
    }

    /// For a single tenant, `(column >= ? AND column <= ?)`, which MySQL reads
    /// once for a join no index serves; otherwise [`Filter::joined`].
    fn range(&self, table: &str) -> Condition {
        if self.tenants.len() != 1 {
            return self.joined(table);
        }
        let column = format!("{}.{}", AllowNull::quote(table, &self.quote), storage::TENANT);
        Condition::new(
            format!("({column} >= ? AND {column} <= ?)"),
            vec![self.tenants[0].clone(), self.tenants[0].clone()],
        )
    }
}

impl FilterHook for Filter {
    /// The tenant condition of `table`, qualified with it unless it is a raw
    /// (dotted or quoted) name; a metadata table also matches tenantless rows,
    /// and the allow-null column relaxes it.
    fn filter(&self, table: &str) -> utopia_query::Result<Condition> {
        let prefix = if !table.contains('.') && !table.contains(self.quote.as_str()) {
            format!("{}.", AllowNull::quote(table, &self.quote))
        } else {
            String::new()
        };

        let name = if self.collection.is_empty() { table } else { self.collection.as_str() };

        // A shared pool creates its system collections once, with no tenant,
        // and their permission rows carry that tenant too.
        let metadata = &self.metadata_collection;
        let is_metadata = !metadata.is_empty()
            && metadata != "0"
            && (name == metadata || name == storage::permissions_table(metadata));

        let placeholders = self.placeholders();
        let tenant = storage::TENANT;
        let condition = if is_metadata {
            Condition::new(
                format!("({prefix}{tenant} IN ({placeholders}) OR {prefix}{tenant} IS NULL)"),
                self.tenants.clone(),
            )
        } else {
            Condition::new(format!("{prefix}{tenant} IN ({placeholders})"), self.tenants.clone())
        };

        if self.allow_null_column.is_empty() {
            return Ok(condition);
        }

        AllowNull::wrap(condition, &self.allow_null_column, &self.quote).map_err(hook_error)
    }
}

impl JoinFilter for Filter {
    /// Inner and left joins in `ON` (a range for an unindexed join), the
    /// others in `WHERE`, where a full outer join, or any join once the main
    /// table is relaxed, also lets through a missing row (`_uid IS NULL`).
    fn filter_join(&self, table: &str, join_type: JoinType) -> utopia_query::Result<Option<JoinCondition>> {
        let placement = match join_type {
            JoinType::Left | JoinType::Inner => Placement::On,
            _ => Placement::Where,
        };

        let mut condition = if placement == Placement::On && self.unindexed.iter().any(|u| u == table) {
            self.range(table)
        } else {
            self.joined(table)
        };

        if placement == Placement::Where && (join_type == JoinType::FullOuter || !self.allow_null_column.is_empty()) {
            condition =
                AllowNull::wrap(condition, &format!("{table}.{}", storage::UID), &self.quote).map_err(hook_error)?;
        }

        Ok(Some(JoinCondition::new(condition, placement)))
    }
}

impl From<Filter> for Hook {
    /// The filter and join filter.
    fn from(filter: Filter) -> Self {
        let filter = Arc::new(filter);
        Hook { filter: Some(filter.clone()), attribute: None, join_filter: Some(filter) }
    }
}

/// The tenant conditions a right or full outer join needs inside `ON`: the
/// main table's and the joined table's own. [`OuterChain`] adds those of the
/// tables joined before it.
///
/// These joins pair rows before the `WHERE` tenant filters run, so a row
/// whose only match lies outside the tenant would be paired with it and then
/// dropped by `WHERE`.
///
/// PHP: `Utopia\Database\Adapter\SQL\Hook\Tenant\OuterJoin`.
#[derive(Debug, Clone, PartialEq)]
pub struct OuterJoin {
    filter: Filter,
    source: String,
}

impl OuterJoin {
    /// `filter`'s conditions for the main table aliased `source` (the
    /// reference the builder hands [`Filter`]'s `filter()`).
    pub fn new(filter: Filter, source: impl Into<String>) -> Self {
        OuterJoin { filter, source: source.into() }
    }
}

impl JoinFilter for OuterJoin {
    /// For a right or full outer join, the main table's condition and the
    /// joined table's in `ON`; nothing otherwise.
    fn filter_join(&self, table: &str, join_type: JoinType) -> utopia_query::Result<Option<JoinCondition>> {
        if join_type != JoinType::Right && join_type != JoinType::FullOuter {
            return Ok(None);
        }

        let source = self.filter.filter(&self.source)?;
        let joined = self.filter.joined(table);

        let mut bindings = source.bindings;
        bindings.extend(joined.bindings);
        Ok(Some(JoinCondition::new(
            Condition::new(format!("{} AND {}", source.expression, joined.expression), bindings),
            Placement::On,
        )))
    }
}

/// The tenant conditions of a builder `Database::from()` hands out under
/// shared tables. Its caller adds the joins after the hook is made, so the
/// hook learns them as the builder compiles each statement: the builder
/// hands every join to the join filters in order, then the main table to
/// the filters (which forgets them for the next statement).
///
/// Each joined table meets its own condition in `ON` for inner and left
/// joins, in `WHERE` otherwise, letting through a row an outer join left
/// without the table. The main table's condition runs in `WHERE`, relaxed
/// the same way only when the statement has a right or full outer join.
/// [`RawOuterJoin`] adds what such a join needs in its own `ON`.
///
/// The learned joins sit behind a mutex, since builders share hooks.
///
/// PHP: `Utopia\Database\Adapter\SQL\Hook\Tenant\Raw`.
#[derive(Debug)]
pub struct Raw {
    tenant: Value,
    table: String,
    metadata: bool,
    quote: String,
    joins: Mutex<Chain>,
}

impl Raw {
    /// Keeps statements to `tenant`, for the main table `table` as the
    /// builder names it; `metadata` says whether it holds definitions a
    /// shared pool keeps once, with no tenant, for every tenant to read.
    pub fn new(tenant: impl IntoValue, table: impl Into<String>, metadata: bool, quote: impl Into<String>) -> Self {
        Raw {
            tenant: tenant.into_value(),
            table: table.into(),
            metadata,
            quote: quote.into(),
            joins: Mutex::new(Chain::default()),
        }
    }

    /// The hook to add to a builder: this filter as both its filter and its
    /// join filter, shared.
    pub fn hook(self: &Arc<Self>) -> Hook {
        Hook { filter: Some(self.clone()), attribute: None, join_filter: Some(self.clone()) }
    }

    /// Forgets the joins learned so far.
    ///
    /// PHP: `reset()`.
    pub fn reset(&self) {
        *self.joins() = Chain::default();
    }

    /// The learned joins.
    fn joins(&self) -> MutexGuard<'_, Chain> {
        self.joins.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// What a right or full outer join needs in its `ON`: the main table's
    /// condition (relaxed for a missing row), its own, and those of the
    /// tables joined before it whose conditions sit in `WHERE`, each relaxed
    /// the same way.
    ///
    /// PHP: `outerJoin()`.
    pub fn outer_join(&self, table: &str, join_type: JoinType) -> Result<Condition> {
        let chain = self.joins().clone();
        let earlier: Vec<(String, Condition)> =
            chain.preceding(table).into_iter().map(|alias| (alias.clone(), self.joined(&alias))).collect();

        let mut conditions = vec![self.allow_missing(self.main(&self.table), &self.table)?, self.joined(table)];
        let preceding = OuterChain::new(chain, earlier, self.quote.as_str())
            .filter_join(table, join_type)
            .map_err(crate::query::query_error)?;
        if let Some(preceding) = preceding {
            conditions.push(preceding.condition);
        }

        let mut expressions = Vec::new();
        let mut bindings = Vec::new();
        for condition in conditions {
            expressions.push(condition.expression);
            bindings.extend(condition.bindings);
        }

        Ok(Condition::new(expressions.join(" AND "), bindings))
    }

    /// The main table's condition.
    fn main(&self, table: &str) -> Condition {
        let column = self.column(table);
        if self.metadata {
            return Condition::new(format!("({column} IN (?) OR {column} IS NULL)"), vec![self.tenant.clone()]);
        }
        Condition::new(format!("{column} IN (?)"), vec![self.tenant.clone()])
    }

    /// A joined table's condition.
    fn joined(&self, table: &str) -> Condition {
        Condition::new(format!("{} IN (?)", self.column(table)), vec![self.tenant.clone()])
    }

    /// `condition`, also passing a row `table` is missing from.
    fn allow_missing(&self, condition: Condition, table: &str) -> Result<Condition> {
        AllowNull::wrap(condition, &format!("{table}.{}", storage::UID), &self.quote)
    }

    /// `table`'s tenant column.
    fn column(&self, table: &str) -> String {
        format!("{}.{}", AllowNull::quote(table, &self.quote), storage::TENANT)
    }
}

impl JoinFilter for Raw {
    /// Learns the join; inner and left joins in `ON`, the others in `WHERE`
    /// letting through a row the table is missing from.
    fn filter_join(&self, table: &str, join_type: JoinType) -> utopia_query::Result<Option<JoinCondition>> {
        self.joins().insert(table.to_owned(), join_type);

        if join_type == JoinType::Inner || join_type == JoinType::Left {
            return Ok(Some(JoinCondition::new(self.joined(table), Placement::On)));
        }

        let condition = self.allow_missing(self.joined(table), table).map_err(hook_error)?;
        Ok(Some(JoinCondition::new(condition, Placement::Where)))
    }
}

impl FilterHook for Raw {
    /// The main table's condition, relaxed for a missing row when the
    /// statement has a right or full outer join; forgets the joins. Fails
    /// with `Utopia\Database\Exception\Query` for a statement without a
    /// table, or one whose right or full outer join renames the main table.
    fn filter(&self, table: &str) -> utopia_query::Result<Condition> {
        let preserving = self.joins().has_preserving_outer_join();
        self.reset();

        if table.is_empty() {
            return Err(hook_error(Error::query(
                "A query builder statement without a table cannot be kept to the selected tenant under shared tables",
            )));
        }

        if preserving && table != self.table {
            return Err(hook_error(Error::query(format!(
                "A right or full outer join needs the main table named '{}', as Database::from() names it, under shared tables",
                self.table
            ))));
        }

        let condition = self.main(table);
        if preserving { self.allow_missing(condition, table).map_err(hook_error) } else { Ok(condition) }
    }
}

/// The tenant conditions a right or full outer join of a `Database::from()`
/// builder needs in its `ON` ([`Raw::outer_join`]). Add it after the [`Raw`]
/// it reads, so that filter has learned the join.
///
/// PHP: `Utopia\Database\Adapter\SQL\Hook\Tenant\RawOuterJoin`.
#[derive(Debug, Clone)]
pub struct RawOuterJoin {
    filter: Arc<Raw>,
}

impl RawOuterJoin {
    /// The outer joins of `filter`'s statements.
    pub fn new(filter: Arc<Raw>) -> Self {
        RawOuterJoin { filter }
    }
}

impl JoinFilter for RawOuterJoin {
    /// For a right or full outer join, [`Raw::outer_join`] in `ON`; nothing
    /// otherwise.
    fn filter_join(&self, table: &str, join_type: JoinType) -> utopia_query::Result<Option<JoinCondition>> {
        if join_type != JoinType::Right && join_type != JoinType::FullOuter {
            return Ok(None);
        }
        let condition = self.filter.outer_join(table, join_type).map_err(hook_error)?;
        Ok(Some(JoinCondition::new(condition, Placement::On)))
    }
}

impl From<RawOuterJoin> for Hook {
    /// The join filter.
    fn from(hook: RawOuterJoin) -> Self {
        Hook::join_filter(hook)
    }
}
