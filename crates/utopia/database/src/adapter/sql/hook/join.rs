//! The joins of a read, and the conditions a right or full outer join
//! repeats from the tables joined before it.
//!
//! PHP: `Utopia\Database\Adapter\SQL\Hook\Join\*`.

use std::collections::HashMap;
use std::ops::Deref;

use utopia_query::Method;
use utopia_query::builder::{Condition, JoinType};
use utopia_query::hook::{JoinCondition, JoinFilter, Placement};

use super::column::AllowNull;
use super::hook_error;
use crate::storage;

/// The joins of one read, in the order the builder pairs them.
///
/// Inner and left joins carry a table's conditions in their own `ON`. Right,
/// full outer and cross joins leave them in `WHERE`, which runs only after
/// every join has paired its rows, and right and full outer joins can leave
/// the tables before them missing from a row.
///
/// PHP: `Utopia\Database\Adapter\SQL\Hook\Join\Chain`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Chain {
    /// Each joined table's alias and how it is joined, in order (a PHP array:
    /// a repeated alias keeps its first position and takes the last type).
    joins: Vec<(String, JoinType)>,
}

impl Chain {
    /// The joins, each alias with how it is joined, in order.
    pub fn new<K: Into<String>>(joins: impl IntoIterator<Item = (K, JoinType)>) -> Self {
        let mut chain = Chain::default();
        for (alias, join_type) in joins {
            chain.insert(alias.into(), join_type);
        }
        chain
    }

    /// The joins of a read's queries, keyed by alias, as the builder hands
    /// them to join filters; the queries that are not joins are skipped.
    ///
    /// PHP: `Chain::fromQueries()`.
    pub fn from_queries<Q: Deref<Target = utopia_query::Query>>(queries: &[Q]) -> Self {
        let mut chain = Chain::default();
        for query in queries {
            let join_type = match query.method() {
                Method::Join => JoinType::Inner,
                Method::LeftJoin => JoinType::Left,
                Method::RightJoin => JoinType::Right,
                Method::FullOuterJoin => JoinType::FullOuter,
                Method::CrossJoin => JoinType::Cross,
                Method::NaturalJoin => JoinType::Natural,
                _ => continue,
            };
            chain.insert(query.alias().to_owned(), join_type);
        }
        chain
    }

    /// Sets `alias`'s join type, in place when it is already joined.
    pub(crate) fn insert(&mut self, alias: String, join_type: JoinType) {
        match self.joins.iter_mut().find(|(joined, _)| *joined == alias) {
            Some(entry) => entry.1 = join_type,
            None => self.joins.push((alias, join_type)),
        }
    }

    /// How `alias` is joined; a table the read does not join counts as
    /// joined inner.
    ///
    /// PHP: `type()`.
    pub fn join_type(&self, alias: &str) -> JoinType {
        self.joins.iter().find(|(joined, _)| joined == alias).map_or(JoinType::Inner, |(_, t)| *t)
    }

    /// Whether some table is joined with `join_type`.
    ///
    /// PHP: `has()`.
    pub fn has(&self, join_type: JoinType) -> bool {
        self.joins.iter().any(|(_, t)| *t == join_type)
    }

    /// Whether a right or full outer join can leave a table missing from a
    /// row, so that a condition in `WHERE` has to let such rows through.
    ///
    /// PHP: `hasPreservingOuterJoin()`.
    pub fn has_preserving_outer_join(&self) -> bool {
        self.has(JoinType::Right) || self.has(JoinType::FullOuter)
    }

    /// The tables joined before `alias` whose conditions sit in `WHERE`: the
    /// ones joined right, full outer or cross. Empty for an alias the chain
    /// does not join (and, as in PHP, where an integer-like alias becomes an
    /// integer array key that never equals the string, for such an alias).
    ///
    /// PHP: `preceding()`.
    pub fn preceding(&self, alias: &str) -> Vec<String> {
        if php_std::types::numeric_key(alias.as_bytes()).is_some() {
            return Vec::new();
        }
        let mut preceding = Vec::new();
        for (joined, join_type) in &self.joins {
            if joined == alias {
                return preceding;
            }
            if *join_type != JoinType::Inner && *join_type != JoinType::Left {
                preceding.push(joined.clone());
            }
        }
        Vec::new()
    }
}

/// The conditions of earlier joined tables that a right or full outer join
/// needs inside `ON`.
///
/// A table joined right, full outer or cross keeps its condition in `WHERE`,
/// which runs after every join. A later right or full outer join would pair
/// its rows with that table's rows outside the condition first, and `WHERE`
/// would then drop the pairs. Each repeated condition lets through the rows
/// an outer join left without its table, recognised by the `NOT NULL` `_uid`.
///
/// PHP: `Utopia\Database\Adapter\SQL\Hook\Join\OuterChain`.
#[derive(Debug, Clone, PartialEq)]
pub struct OuterChain {
    chain: Chain,
    conditions: HashMap<String, Condition>,
    quote: String,
}

impl OuterChain {
    /// The condition each joined table's rows must meet, by alias, for the
    /// joins of `chain`; tables are named quoted with `quote` (PHP's default
    /// is `` ` ``).
    pub fn new<K: Into<String>>(
        chain: Chain,
        conditions: impl IntoIterator<Item = (K, Condition)>,
        quote: impl Into<String>,
    ) -> Self {
        OuterChain {
            chain,
            conditions: conditions.into_iter().map(|(k, v)| (k.into(), v)).collect(),
            quote: quote.into(),
        }
    }
}

impl JoinFilter for OuterChain {
    /// For a right or full outer join, the conditions of the tables before
    /// it whose conditions sit in `WHERE`, each relaxed for a missing row,
    /// in `ON`; nothing otherwise, or when there are none.
    fn filter_join(&self, table: &str, join_type: JoinType) -> utopia_query::Result<Option<JoinCondition>> {
        if join_type != JoinType::Right && join_type != JoinType::FullOuter {
            return Ok(None);
        }

        let mut expressions = Vec::new();
        let mut bindings = Vec::new();
        for alias in self.chain.preceding(table) {
            let Some(condition) = self.conditions.get(&alias) else {
                continue;
            };
            let condition = AllowNull::wrap(condition.clone(), &format!("{alias}.{}", storage::UID), &self.quote)
                .map_err(hook_error)?;
            expressions.push(condition.expression);
            bindings.extend(condition.bindings);
        }

        if expressions.is_empty() {
            return Ok(None);
        }

        Ok(Some(JoinCondition::new(Condition::new(expressions.join(" AND "), bindings), Placement::On)))
    }
}
