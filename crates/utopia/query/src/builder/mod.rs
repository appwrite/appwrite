//! Query builders: compile [`Query`](crate::Query) lists, and the
//! builder's own clauses, into statements for each dialect.
//!
//! A [`Builder`] is generic over its [`Dialect`]; the dialects are type
//! aliases ([`MySql`], ...). Features only some dialects have are traits in
//! [`feature`]: a builder has their methods when its dialect implements them.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Builder` (abstract), `Builder\SQL` (abstract) | [`Builder`], [`Dialect`], [`SqlDialect`] |
//! | `Builder\MySQL` | [`MySql`] ([`MySqlDialect`]) |
//! | `Builder\MariaDB` | [`MariaDb`] ([`MariaDbDialect`]) |
//! | `Builder\PostgreSQL`, `PostgreSQL\UpdateFrom`, `PostgreSQL\DeleteUsing`, `PostgreSQL\MergeTarget` | [`PostgreSql`] ([`PostgreSqlDialect`]), [`UpdateFrom`], [`DeleteUsing`], [`MergeTarget`] |
//! | `Builder\SQLite` | [`Sqlite`] ([`SqliteDialect`]) |
//! | `Builder\ClickHouse`, `ClickHouse\AsofOperator`, `ClickHouse\Format`, `ClickHouse\FormattedInsertStatement`, `ClickHouse::DELETE_MODE_*` | [`ClickHouse`] ([`ClickHouseDialect`]), [`AsofOperator`], [`Format`], [`FormattedInsertStatement`], [`DeleteMode`] |
//! | `Builder\MongoDB`, `MongoDB\Operation`, `MongoDB\PipelineStage`, `MongoDB\UpdateOperation`, `MongoDB\UpdateOperator`, `hint()`'s `string\|array` | [`MongoDb`] ([`MongoDbDialect`]), [`Operation`], [`PipelineStage`], [`UpdateOperation`], [`UpdateOperator`], [`IndexHint`] |
//! | `Builder\Statement` | [`Statement`] |
//! | `Builder\Condition`, `Builder\Binding` | [`Condition`], [`Binding`] |
//! | `Builder\JoinBuilder`, `Builder\JoinOn`, `Builder\JoinType` | [`JoinBuilder`], [`JoinOn`], [`JoinType`] |
//! | `Builder\Case\Expression`, `Case\Kind`, `Case\Operator`, `Case\WhenClause` | [`CaseExpression`], [`Kind`], [`Operator`], [`WhenClause`] |
//! | `Builder\CteClause`, `Builder\UnionClause`, `Builder\UnionType` | [`CteClause`], [`UnionClause`], [`UnionType`] |
//! | `Builder\WindowFrame`, `Builder\WindowDefinition`, `Builder\WindowSelect` | [`WindowFrame`], [`WindowDefinition`], [`WindowSelect`] |
//! | `Builder\LockMode`, `Builder\VectorMetric` | [`LockMode`], [`VectorMetric`] |
//! | `Builder\SubSelect`, `Builder\WhereInSubquery`, `Builder\ExistsSubquery`, `Builder\LateralJoin` | [`SubSelect`], [`WhereInSubquery`], [`ExistsSubquery`], [`LateralJoin`] |
//! | `Builder\ColumnPredicate`, `Builder\MergeClause`, `Builder\SpatialDistanceFilter` | [`ColumnPredicate`], [`MergeClause`], [`SpatialDistanceFilter`] |
//! | `Builder\Feature\*` | [`feature`] |
//! | `Builder` as a parameter type | [`AnyBuilder`] |

mod api;
mod ast;
mod case;
mod clickhouse;
mod core;
pub mod feature;
mod join;
mod mariadb;
mod mongodb;
mod mysql;
mod postgresql;
mod sql;
mod sqlite;
mod types;

use std::any::Any;

pub use case::{CaseExpression, Kind, Operator, WhenClause};
pub use clickhouse::{AsofOperator, ClickHouse, ClickHouseDialect, DeleteMode, Format, FormattedInsertStatement};
pub use core::{
    AfterBuild, BeforeBuild, Builder, Core, Dialect, ExistsSubquery, ExplainOptions, LateralJoin, SubSelect,
    WhereInSubquery,
};
pub use join::JoinBuilder;
pub use mariadb::{MariaDb, MariaDbDialect};
pub use mongodb::{IndexHint, MongoDb, MongoDbDialect, Operation, PipelineStage, UpdateOperation, UpdateOperator};
pub use mysql::{MySql, MySqlDialect, MySqlFamily, MySqlState};
pub use postgresql::{DeleteUsing, MergeTarget, PostgreSql, PostgreSqlDialect, UpdateFrom};
pub use sql::{SqlDialect, geometry_to_wkt};
pub use sqlite::{Sqlite, SqliteDialect};
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

/// Which AST serializer a builder converts with (`toAst`/`fromAst`).
///
/// PHP: `Builder::createAstSerializer()`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum AstDialect {
    /// The standard serializer.
    Standard,
    /// MySQL's.
    MySql,
    /// MariaDB's.
    MariaDb,
    /// PostgreSQL's.
    PostgreSql,
    /// SQLite's.
    Sqlite,
    /// ClickHouse's.
    ClickHouse,
}
