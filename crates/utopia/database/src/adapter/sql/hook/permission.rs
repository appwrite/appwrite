//! Document permission checks: a subquery against a permissions side table
//! (or, on PostgreSQL, the JSONB copy on each row), placed for every join.
//!
//! PHP: `Utopia\Database\Adapter\SQL\Hook\Permission\*`.

use std::collections::HashMap;
use std::fmt;
use std::sync::Arc;

use utopia_query::builder::{Condition, JoinType};
use utopia_query::hook::{Filter as FilterHook, Hook, JoinCondition, JoinFilter, Placement};
use utopia_query::{Value, list, string};

use super::column::AllowNull;
use super::{hook_error, is_name_byte, is_name_start, is_word_or_dash, matches_identifier};
use crate::error::{Error, Kind, Result};
use crate::permission::PermissionType;
use crate::storage;

/// `/*+ NO_SEMIJOIN() */ `: MySQL's hint against merging the subquery into
/// the outer query.
const NO_SEMIJOIN: &str = "/*+ NO_SEMIJOIN() */ ";

/// The permissions table of a base table (PHP's `Closure(string): string`).
pub type PermissionsTable = Arc<dyn Fn(&str) -> String + Send + Sync>;

/// The optional arguments of [`Filter::with_options`], with PHP's defaults.
///
/// PHP: `Permission\Filter::__construct()`'s parameters after `$permissionsTable`.
#[derive(Clone)]
pub struct Options {
    /// The permission type checked (`$type`, default `read`).
    pub r#type: String,
    /// The columns checked (`$columns`): `None` checks none; rows scoped to
    /// no column (`NULL`, a wildcard) always match.
    pub columns: Option<Vec<String>>,
    /// The document column of the read table (`$documentColumn`, default `id`).
    pub document_column: String,
    /// The permissions table's document column (default `document_id`).
    pub permission_document_column: String,
    /// The permissions table's role column (default `role`).
    pub permission_role_column: String,
    /// The permissions table's type column (default `type`).
    pub permission_type_column: String,
    /// The permissions table's column scope column (default `column`).
    pub scope_column: String,
    /// A filter applied inside the subquery, such as tenancy (`$subqueryFilter`).
    pub subquery_filter: Option<Arc<dyn FilterHook>>,
    /// The identifier quote (default `` ` ``).
    pub quote: String,
    /// Whether the engine may merge the subquery as a semi-join; when not, it
    /// carries MySQL's `NO_SEMIJOIN` hint (default `true`).
    pub semi_join: bool,
}

impl Default for Options {
    fn default() -> Self {
        Options {
            r#type: PermissionType::Read.as_str().to_owned(),
            columns: None,
            document_column: "id".to_owned(),
            permission_document_column: "document_id".to_owned(),
            permission_role_column: "role".to_owned(),
            permission_type_column: "type".to_owned(),
            scope_column: "column".to_owned(),
            subquery_filter: None,
            quote: "`".to_owned(),
            semi_join: true,
        }
    }
}

impl fmt::Debug for Options {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Options")
            .field("type", &self.r#type)
            .field("columns", &self.columns)
            .field("document_column", &self.document_column)
            .field("permission_document_column", &self.permission_document_column)
            .field("permission_role_column", &self.permission_role_column)
            .field("permission_type_column", &self.permission_type_column)
            .field("scope_column", &self.scope_column)
            .field("subquery_filter", &self.subquery_filter.is_some())
            .field("quote", &self.quote)
            .field("semi_join", &self.semi_join)
            .finish()
    }
}

/// How a [`Filter`] matches the roles.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Strategy {
    /// An `IN` subquery against the permissions table.
    Subquery,
    /// `_permissions @> ?::jsonb` per role (PostgreSQL).
    Jsonb,
}

/// The permission condition of a read: the document column `IN` a subquery
/// against the permissions side table, filtered by the reader's roles, the
/// permission type and optionally columns.
///
/// PHP: `Utopia\Database\Adapter\SQL\Hook\Permission\Filter`, and the
/// anonymous subclass `Postgres::newPermissionHook()` returns ([`Filter::jsonb`]).
#[derive(Clone)]
pub struct Filter {
    roles: Vec<String>,
    permissions_table: PermissionsTable,
    options: Options,
    document_collation: String,
    strategy: Strategy,
}

impl fmt::Debug for Filter {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Filter")
            .field("roles", &self.roles)
            .field("options", &self.options)
            .field("document_collation", &self.document_collation)
            .field("strategy", &self.strategy)
            .finish_non_exhaustive()
    }
}

impl Filter {
    /// A filter for `roles` against the table `permissions_table` names for
    /// each base table, with every other argument at its default.
    pub fn new(roles: Vec<String>, permissions_table: impl Fn(&str) -> String + Send + Sync + 'static) -> Self {
        Filter {
            roles,
            permissions_table: Arc::new(permissions_table),
            options: Options::default(),
            document_collation: String::new(),
            strategy: Strategy::Subquery,
        }
    }

    /// A filter with `options`. Fails with `InvalidArgumentException` for a
    /// document, permission document, role, type or scope column outside
    /// `/^[a-zA-Z_][a-zA-Z0-9_.\-]*$/`.
    pub fn with_options(
        roles: Vec<String>,
        permissions_table: impl Fn(&str) -> String + Send + Sync + 'static,
        options: Options,
    ) -> Result<Self> {
        for column in [
            &options.document_column,
            &options.permission_document_column,
            &options.permission_role_column,
            &options.permission_type_column,
            &options.scope_column,
        ] {
            if !matches_identifier(column, is_name_start, is_name_byte) {
                return Err(Error::new(Kind::InvalidArgument, format!("Invalid column name: {column}")));
            }
        }
        Ok(Filter { options, ..Filter::new(roles, permissions_table) })
    }

    /// PostgreSQL's filter: matches the roles against the JSONB copy of the
    /// permissions stored on each row (the `_permissions` column beside
    /// `document_column`), so PostgreSQL can combine the GIN permission index
    /// with ordering indexes: `("t"."_permissions" @> ?::jsonb OR ...)`, one
    /// `["type(\"role\")"]` binding per role.
    ///
    /// PHP: the anonymous `Permission\Filter` of `Postgres::newPermissionHook()`.
    pub fn jsonb(roles: Vec<String>, r#type: impl Into<String>, document_column: impl Into<String>) -> Self {
        let options = Options {
            r#type: r#type.into(),
            document_column: document_column.into(),
            quote: "\"".to_owned(),
            ..Options::default()
        };
        Filter { options, strategy: Strategy::Jsonb, ..Filter::new(roles, |table: &str| table.to_owned()) }
    }

    /// The filter comparing the document column in `collation`, the one of
    /// the index that serves it. Fails with `InvalidArgumentException` for a
    /// collation outside `/^[a-zA-Z_][a-zA-Z0-9_]*$/`.
    ///
    /// PHP: `collate()`.
    pub fn collate(&self, collation: &str) -> Result<Self> {
        if !matches_identifier(collation, is_name_start, |b| b.is_ascii_alphanumeric() || b == b'_') {
            return Err(Error::new(Kind::InvalidArgument, format!("Invalid collation name: {collation}")));
        }
        Ok(Filter { document_collation: format!(" COLLATE {collation}"), ..self.clone() })
    }

    /// The filter with a subquery the engine may not merge as a semi-join.
    ///
    /// PHP: `withoutSemiJoin()`.
    pub fn without_semi_join(&self) -> Self {
        let mut filter = self.clone();
        filter.options.semi_join = false;
        filter
    }

    /// The subquery condition.
    fn subquery(&self, table: &str) -> utopia_query::Result<Condition> {
        let options = &self.options;
        let permissions_table = (self.permissions_table)(table);

        if !matches_identifier(&permissions_table, is_word_or_dash, is_name_byte) {
            return Err(hook_error(Error::database(format!("Invalid permissions table name: {permissions_table}"))));
        }

        let quoted_table = AllowNull::quote(&permissions_table, &options.quote);
        let quoted_document = AllowNull::quote(&options.document_column, &options.quote);
        let roles = vec!["?"; self.roles.len()].join(", ");

        let mut column_clause = String::new();
        let mut column_bindings: Vec<Value> = Vec::new();
        if let Some(columns) = &options.columns {
            let scope = &options.scope_column;
            if columns.is_empty() {
                column_clause = format!(" AND {scope} IS NULL");
            } else {
                let placeholders = vec!["?"; columns.len()].join(", ");
                column_clause = format!(" AND ({scope} IS NULL OR {scope} IN ({placeholders}))");
                column_bindings = columns.iter().map(string).collect();
            }
        }

        let mut sub_clause = String::new();
        let mut sub_bindings = Vec::new();
        if let Some(filter) = &options.subquery_filter {
            let condition = filter.filter(&permissions_table)?;
            sub_clause = format!(" AND {}", condition.expression);
            sub_bindings = condition.bindings;
        }

        let hint = if options.semi_join { "" } else { NO_SEMIJOIN };

        let mut bindings: Vec<Value> = self.roles.iter().map(string).collect();
        bindings.push(string(&options.r#type));
        bindings.extend(column_bindings);
        bindings.extend(sub_bindings);

        Ok(Condition::new(
            format!(
                "{quoted_document}{} IN (SELECT {hint}{} FROM {quoted_table} WHERE {} IN ({roles}) AND {} = ?{column_clause}{sub_clause})",
                self.document_collation,
                options.permission_document_column,
                options.permission_role_column,
                options.permission_type_column,
            ),
            bindings,
        ))
    }

    /// The JSONB condition.
    fn jsonb_condition(&self) -> Condition {
        let mut parts: Vec<&str> = self.options.document_column.split('.').collect();
        if let Some(last) = parts.last_mut() {
            *last = storage::PERMISSIONS;
        }
        let column =
            parts.iter().map(|part| format!("\"{}\"", part.replace('"', "\"\""))).collect::<Vec<_>>().join(".");

        let mut conditions = Vec::with_capacity(self.roles.len());
        let mut bindings = Vec::with_capacity(self.roles.len());
        for role in &self.roles {
            conditions.push(format!("{column} @> ?::jsonb"));
            let permission = Value::Array(list([string(format!("{}(\"{role}\")", self.options.r#type))]));
            let encoded = php_std::json::encode(&permission, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH)
                .unwrap_or_else(|_| "[]".to_owned());
            bindings.push(string(encoded));
        }

        Condition::new(format!("({})", conditions.join(" OR ")), bindings)
    }
}

impl FilterHook for Filter {
    /// `1 = 0` without roles; otherwise the permission condition. Fails with
    /// `Utopia\Database\Exception` for a permissions table name outside
    /// `/^[a-zA-Z0-9_\-][a-zA-Z0-9_.\-]*$/`.
    fn filter(&self, table: &str) -> utopia_query::Result<Condition> {
        if self.roles.is_empty() {
            return Ok(Condition::new("1 = 0", Vec::new()));
        }
        match self.strategy {
            Strategy::Subquery => self.subquery(table),
            Strategy::Jsonb => Ok(self.jsonb_condition()),
        }
    }
}

impl JoinFilter for Filter {
    /// Nothing: each joined table is checked by its own [`Join`].
    fn filter_join(&self, _table: &str, _join_type: JoinType) -> utopia_query::Result<Option<JoinCondition>> {
        Ok(None)
    }
}

impl From<Filter> for Hook {
    /// The filter and (empty) join filter.
    fn from(filter: Filter) -> Self {
        let filter = Arc::new(filter);
        Hook { filter: Some(filter.clone()), attribute: None, join_filter: Some(filter) }
    }
}

/// A permission check bound to one join alias, placed the way
/// [`tenant::Filter`](super::tenant::Filter) places tenant conditions.
///
/// Inner and left joins check it in `ON`; right, full outer and cross joins
/// in `WHERE`, since `ON` cannot drop the rows they keep. In `WHERE`, after a
/// full outer join or in a read with a right or full outer join, it also
/// lets through the rows an outer join left without this table, recognised
/// by the `NOT NULL` `_uid`.
///
/// PHP: `Utopia\Database\Adapter\SQL\Hook\Permission\Join`.
#[derive(Debug, Clone)]
pub struct Join {
    filter: Filter,
    alias: String,
    quote: String,
    preserving_outer_join: bool,
}

impl Join {
    /// `filter` checked for the join aliased `alias`, quoting with `quote`
    /// (PHP's default is `` ` ``); `preserving_outer_join` says whether the
    /// read has a right or full outer join (PHP's default is `false`).
    pub fn new(
        filter: Filter,
        alias: impl Into<String>,
        quote: impl Into<String>,
        preserving_outer_join: bool,
    ) -> Self {
        Join { filter, alias: alias.into(), quote: quote.into(), preserving_outer_join }
    }
}

impl JoinFilter for Join {
    /// The condition for the bound alias, placed by join type; nothing for
    /// another table.
    fn filter_join(&self, table: &str, join_type: JoinType) -> utopia_query::Result<Option<JoinCondition>> {
        if table != self.alias {
            return Ok(None);
        }

        let placement = match join_type {
            JoinType::Left | JoinType::Inner => Placement::On,
            _ => Placement::Where,
        };

        let mut condition = self.filter.filter(table)?;
        if placement == Placement::Where && (join_type == JoinType::FullOuter || self.preserving_outer_join) {
            condition = AllowNull::wrap(condition, &format!("{}.{}", self.alias, storage::UID), &self.quote)
                .map_err(hook_error)?;
        }

        Ok(Some(JoinCondition::new(condition, placement)))
    }
}

/// The permission conditions a right or full outer join needs inside `ON`:
/// the main table's (relaxed for a missing row) and the joined table's own.
///
/// These joins pair rows before the `WHERE` permission checks run, so a row
/// whose only match is unreadable would be paired with it and then dropped.
/// It mirrors [`tenant::OuterJoin`](super::tenant::OuterJoin).
///
/// PHP: `Utopia\Database\Adapter\SQL\Hook\Permission\OuterJoin`.
#[derive(Debug, Clone, PartialEq)]
pub struct OuterJoin {
    source: String,
    conditions: HashMap<String, Condition>,
    quote: String,
}

impl OuterJoin {
    /// For the main table aliased `source`, with the permission condition of
    /// each table read per document, by alias (the main table's included),
    /// quoting with `quote` (PHP's default is `` ` ``).
    pub fn new<K: Into<String>>(
        source: impl Into<String>,
        conditions: impl IntoIterator<Item = (K, Condition)>,
        quote: impl Into<String>,
    ) -> Self {
        OuterJoin {
            source: source.into(),
            conditions: conditions.into_iter().map(|(k, v)| (k.into(), v)).collect(),
            quote: quote.into(),
        }
    }
}

impl JoinFilter for OuterJoin {
    /// For a right or full outer join, the main table's and the joined
    /// table's conditions in `ON`; nothing otherwise, or when neither has one.
    fn filter_join(&self, table: &str, join_type: JoinType) -> utopia_query::Result<Option<JoinCondition>> {
        if join_type != JoinType::Right && join_type != JoinType::FullOuter {
            return Ok(None);
        }

        let mut conditions = Vec::new();
        if let Some(source) = self.conditions.get(&self.source) {
            conditions.push(
                AllowNull::wrap(source.clone(), &format!("{}.{}", self.source, storage::UID), &self.quote)
                    .map_err(hook_error)?,
            );
        }
        if let Some(joined) = self.conditions.get(table) {
            conditions.push(joined.clone());
        }

        if conditions.is_empty() {
            return Ok(None);
        }

        let mut expressions = Vec::new();
        let mut bindings = Vec::new();
        for condition in conditions {
            expressions.push(condition.expression);
            bindings.extend(condition.bindings);
        }

        Ok(Some(JoinCondition::new(Condition::new(expressions.join(" AND "), bindings), Placement::On)))
    }
}
