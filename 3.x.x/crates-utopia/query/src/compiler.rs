//! PHP `Utopia\Query\Compiler`.

use crate::error::QueryError;
use crate::query::Query;

/// PHP `Utopia\Query\Compiler`.
pub trait Compiler {
    fn compile_filter(&mut self, query: &Query) -> Result<String, QueryError>;
    fn compile_order(&mut self, query: &Query) -> Result<String, QueryError>;
    fn compile_limit(&mut self, query: &Query) -> Result<String, QueryError>;
    fn compile_offset(&mut self, query: &Query) -> Result<String, QueryError>;
    fn compile_select(&mut self, query: &Query) -> Result<String, QueryError>;
    fn compile_cursor(&mut self, query: &Query) -> Result<String, QueryError>;
    fn compile_aggregate(&mut self, query: &Query) -> Result<String, QueryError>;
    fn compile_group_by(&mut self, query: &Query) -> Result<String, QueryError>;
    fn compile_join(&mut self, query: &Query) -> Result<String, QueryError>;
}
