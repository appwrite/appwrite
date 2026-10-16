//! PHP `Utopia\Query\Builder\Statement`.

use std::sync::Arc;

use crate::error::QueryError;
use crate::value::QueryValue;

pub type Executor = Arc<dyn Fn(&Statement) -> Result<ExecuteResult, QueryError> + Send + Sync>;

#[derive(Debug, Clone, PartialEq)]
pub enum ExecuteResult {
    Rows(Vec<QueryValue>),
    Count(i64),
}

#[derive(Clone)]
pub struct Statement {
    pub query: String,
    pub bindings: Vec<QueryValue>,
    pub read_only: bool,
    pub named_bindings: Option<serde_json::Map<String, serde_json::Value>>,
    executor: Option<Executor>,
}

impl std::fmt::Debug for Statement {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Statement")
            .field("query", &self.query)
            .field("bindings", &self.bindings)
            .field("read_only", &self.read_only)
            .field("named_bindings", &self.named_bindings)
            .finish_non_exhaustive()
    }
}

impl PartialEq for Statement {
    fn eq(&self, other: &Self) -> bool {
        self.query == other.query
            && self.bindings == other.bindings
            && self.read_only == other.read_only
            && self.named_bindings == other.named_bindings
    }
}

impl Statement {
    pub fn new(query: impl Into<String>, bindings: Vec<QueryValue>) -> Self {
        Self {
            query: query.into(),
            bindings,
            read_only: false,
            named_bindings: None,
            executor: None,
        }
    }

    pub fn with_read_only(mut self, read_only: bool) -> Self {
        self.read_only = read_only;
        self
    }

    pub fn with_executor(self, executor: Executor) -> Self {
        Self {
            query: self.query,
            bindings: self.bindings,
            read_only: self.read_only,
            named_bindings: self.named_bindings,
            executor: Some(executor),
        }
    }

    pub fn execute(&self) -> Result<ExecuteResult, QueryError> {
        match &self.executor {
            Some(exec) => exec(self),
            None => Err(QueryError::exception("No executor configured on this plan")),
        }
    }
}
