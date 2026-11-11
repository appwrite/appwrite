//! `Builder\SQLite` on [`Sqlite`]: the base methods and its features.

use utopia_query::builder::Sqlite;

use super::builder::{
    base_call, bitwise, conditional_aggregates, cross_joins, insert_or_ignore, json, locking, raw_sql, statistical,
    string_aggregates, transactions, upsert, upsert_select,
};
use super::engine::{Args, Object, Outcome};
use crate::query_object_boilerplate;

/// `class_implements(new SQLite())`.
const INTERFACES: &[&str] = &[
    "Builder\\Feature\\Aggregates",
    "Builder\\Feature\\BitwiseAggregates",
    "Builder\\Feature\\CTEs",
    "Builder\\Feature\\ConditionalAggregates",
    "Builder\\Feature\\CrossJoins",
    "Builder\\Feature\\Deletes",
    "Builder\\Feature\\Hooks",
    "Builder\\Feature\\InsertOrIgnore",
    "Builder\\Feature\\Inserts",
    "Builder\\Feature\\Joins",
    "Builder\\Feature\\Json",
    "Builder\\Feature\\Locking",
    "Builder\\Feature\\RawSql",
    "Builder\\Feature\\Selects",
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

impl Object for Sqlite {
    fn class(&self) -> String {
        "Builder\\SQLite".into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        base_call(self, method, a)
            .or_else(|| locking(self, method, a))
            .or_else(|| transactions(self, method, a))
            .or_else(|| statistical(self, method, a))
            .or_else(|| bitwise(self, method, a))
            .or_else(|| raw_sql(self, method, a))
            .or_else(|| cross_joins(self, method, a))
            .or_else(|| conditional_aggregates(self, method, a))
            .or_else(|| string_aggregates(self, method, a))
            .or_else(|| json(self, method, a))
            .or_else(|| upsert(self, method, a))
            .or_else(|| upsert_select(self, method, a))
            .or_else(|| insert_or_ignore(self, method, a))
    }
    fn parents(&self) -> &'static [&'static str] {
        &["Builder\\SQL", "Builder"]
    }
    fn interfaces(&self) -> &'static [&'static str] {
        INTERFACES
    }
    query_object_boilerplate!();
}
