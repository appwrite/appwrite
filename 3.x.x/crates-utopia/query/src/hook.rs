//! PHP `Utopia\Query\Hook` and implementations.

use crate::builder::types::{Condition, JoinType};
use crate::value::QueryValue;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Placement {
    On,
    Where,
}

#[derive(Debug, Clone, PartialEq)]
pub struct JoinCondition {
    pub condition: Condition,
    pub placement: Placement,
}

impl JoinCondition {
    pub fn new(condition: Condition, placement: Placement) -> Self {
        Self {
            condition,
            placement,
        }
    }
}

pub trait Hook: Send + Sync {}

pub trait FilterHook: Hook {
    fn filter(&self, table: &str) -> Condition;
}

pub trait AttributeHook: Hook {
    fn resolve(&self, attribute: &str) -> String;
}

pub trait JoinFilterHook: Hook {
    fn filter_join(&self, table: &str, join_type: JoinType) -> Option<JoinCondition>;
}

#[derive(Debug, Clone)]
pub struct AttributeMap {
    map: std::collections::HashMap<String, String>,
}

impl AttributeMap {
    pub fn new(map: std::collections::HashMap<String, String>) -> Self {
        Self { map }
    }
}

impl Hook for AttributeMap {}

impl AttributeHook for AttributeMap {
    fn resolve(&self, attribute: &str) -> String {
        self.map
            .get(attribute)
            .cloned()
            .unwrap_or_else(|| attribute.to_owned())
    }
}

#[derive(Debug, Clone)]
pub struct Tenant {
    tenant_ids: Vec<QueryValue>,
    column: String,
}

impl Tenant {
    pub fn new(
        tenant_ids: Vec<QueryValue>,
        column: impl Into<String>,
    ) -> Result<Self, crate::error::QueryError> {
        let column = column.into();
        let ok = column.starts_with(|c: char| c.is_ascii_alphabetic() || c == '_')
            && column
                .chars()
                .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '.');
        if !ok {
            return Err(crate::error::QueryError::exception(format!(
                "Invalid column name: {column}"
            )));
        }
        Ok(Self { tenant_ids, column })
    }

    pub fn with_ids<I, T>(ids: I) -> Result<Self, crate::error::QueryError>
    where
        I: IntoIterator<Item = T>,
        T: Into<QueryValue>,
    {
        Self::new(ids.into_iter().map(Into::into).collect(), "tenant_id")
    }
}

impl Hook for Tenant {}

impl FilterHook for Tenant {
    fn filter(&self, _table: &str) -> Condition {
        if self.tenant_ids.is_empty() {
            return Condition::expr("1 = 0");
        }
        let placeholders = vec!["?"; self.tenant_ids.len()].join(", ");
        Condition::new(
            format!("{} IN ({placeholders})", self.column),
            self.tenant_ids.clone(),
        )
    }
}

impl JoinFilterHook for Tenant {
    fn filter_join(&self, table: &str, join_type: JoinType) -> Option<JoinCondition> {
        let condition = FilterHook::filter(self, table);
        let placement = match join_type {
            JoinType::Left | JoinType::Right => Placement::On,
            _ => Placement::Where,
        };
        Some(JoinCondition::new(condition, placement))
    }
}

/// PHP `Utopia\Query\Hook\Write`.
pub trait WriteHook: Hook {
    fn decorate_row(
        &self,
        row: serde_json::Map<String, serde_json::Value>,
        metadata: serde_json::Map<String, serde_json::Value>,
    ) -> serde_json::Map<String, serde_json::Value>;

    fn after_create(
        &self,
        table: &str,
        metadata: &[serde_json::Map<String, serde_json::Value>],
        context: &QueryValue,
    );

    fn after_update(
        &self,
        table: &str,
        metadata: &serde_json::Map<String, serde_json::Value>,
        context: &QueryValue,
    );

    fn after_batch_update(
        &self,
        table: &str,
        update_data: &serde_json::Map<String, serde_json::Value>,
        metadata: &[serde_json::Map<String, serde_json::Value>],
        context: &QueryValue,
    );

    fn after_delete(&self, table: &str, ids: &[String], context: &QueryValue);
}
