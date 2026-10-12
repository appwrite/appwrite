//! Query builders: compile [`Query`](crate::Query) lists, and the builder's
//! own clauses, into statements.

mod types;

use std::any::Any;

pub use types::{
    Binding, ColumnPredicate, Condition, CteClause, Executor, JoinOn, JoinType, LockMode, MergeClause,
    SpatialDistanceFilter, Statement, UnionClause, UnionType, VectorMetric, WindowDefinition, WindowFrame,
    WindowSelect,
};

use crate::error::Result;

/// Any builder, as other builders and schemas take one (a subquery, a union
/// member, a view's query).
///
/// PHP: the abstract `Utopia\Query\Builder` as a parameter type.
pub trait AnyBuilder: Send + Sync {
    /// Compiles the builder's statement.
    fn build(&mut self) -> Result<Statement>;
    /// A boxed copy.
    fn clone_box(&self) -> Box<dyn AnyBuilder>;
    /// The PHP class of the builder (`Utopia\Query\Builder\MySQL`).
    fn php_class(&self) -> &'static str;
    /// The builder as [`Any`], to reach its concrete type.
    fn as_any(&self) -> &dyn Any;
}

impl Clone for Box<dyn AnyBuilder> {
    /// [`AnyBuilder::clone_box`].
    fn clone(&self) -> Self {
        self.clone_box()
    }
}
