//! `Builder\MariaDB` on [`MariaDb`]: MySQL's methods plus `RETURNING` and sequences.

use utopia_query::builder::MariaDb;

use super::builder::{
    base_call, bitwise, conditional_aggregates, cross_joins, full_text_search, hints, insert_or_ignore, json,
    lateral_joins, locking, mysql_family, negated_full_text_search, raw_sql, returning, rollup, sequences, spatial,
    statistical, string_aggregates, transactions, upsert, upsert_select,
};
use super::engine::{Args, Object, Outcome};
use crate::query_object_boilerplate;

/// `class_implements(new MariaDB())`.
const INTERFACES: &[&str] = &[
    "Builder\\Feature\\Aggregates",
    "Builder\\Feature\\BitwiseAggregates",
    "Builder\\Feature\\CTEs",
    "Builder\\Feature\\ConditionalAggregates",
    "Builder\\Feature\\CrossJoins",
    "Builder\\Feature\\Deletes",
    "Builder\\Feature\\FullTextSearch",
    "Builder\\Feature\\Hints",
    "Builder\\Feature\\Hooks",
    "Builder\\Feature\\InsertOrIgnore",
    "Builder\\Feature\\Inserts",
    "Builder\\Feature\\Joins",
    "Builder\\Feature\\Json",
    "Builder\\Feature\\LateralJoins",
    "Builder\\Feature\\Locking",
    "Builder\\Feature\\MariaDB\\Returning",
    "Builder\\Feature\\NegatedFullTextSearch",
    "Builder\\Feature\\RawSql",
    "Builder\\Feature\\Rollup",
    "Builder\\Feature\\Selects",
    "Builder\\Feature\\Sequences",
    "Builder\\Feature\\Spatial",
    "Builder\\Feature\\StatisticalAggregates",
    "Builder\\Feature\\StringAggregates",
    "Builder\\Feature\\Transactions",
    "Builder\\Feature\\Unions",
    "Builder\\Feature\\Updates",
    "Builder\\Feature\\Upsert",
    "Builder\\Feature\\UpsertSelect",
    "Builder\\Feature\\Windows",
    "Compiler",
];

impl Object for MariaDb {
    fn class(&self) -> String {
        "Builder\\MariaDB".into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        base_call(self, method, a)
            .or_else(|| mysql_family(self, method, a))
            .or_else(|| locking(self, method, a))
            .or_else(|| transactions(self, method, a))
            .or_else(|| statistical(self, method, a))
            .or_else(|| bitwise(self, method, a))
            .or_else(|| raw_sql(self, method, a))
            .or_else(|| cross_joins(self, method, a))
            .or_else(|| lateral_joins(self, method, a))
            .or_else(|| rollup(self, method, a))
            .or_else(|| hints(self, method, a))
            .or_else(|| conditional_aggregates(self, method, a))
            .or_else(|| string_aggregates(self, method, a))
            .or_else(|| json(self, method, a))
            .or_else(|| spatial(self, method, a))
            .or_else(|| full_text_search(self, method, a))
            .or_else(|| negated_full_text_search(self, method, a))
            .or_else(|| upsert(self, method, a))
            .or_else(|| upsert_select(self, method, a))
            .or_else(|| insert_or_ignore(self, method, a))
            .or_else(|| returning(self, method, a))
            .or_else(|| sequences(self, method, a))
    }
    fn parents(&self) -> &'static [&'static str] {
        &["Builder\\MySQL", "Builder\\SQL", "Builder"]
    }
    fn interfaces(&self) -> &'static [&'static str] {
        INTERFACES
    }
    query_object_boilerplate!();
}
