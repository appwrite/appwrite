//! SQL/Mongo query builder, AST, schema, and classifiers for Utopia.
//!
//! Rust port of [`utopia-php/query`](https://github.com/utopia-php/query) (`c334515035a2`).

pub mod ast;
pub mod builder;
pub mod classifier;
pub mod compiler;
pub mod enums;
pub mod error;
pub mod hook;
pub mod method;
pub mod query;
pub mod quotes;
pub mod schema;
pub mod tokenizer;
pub mod value;

pub mod prelude {
    pub use crate::ast::{Parser, Select, Serializer, Visitor, Walker};
    pub use crate::builder::{
        Builder, ClickHouse, MariaDb, MongoDb, MySql, PostgreSql, Sqlite, Statement,
    };
    pub use crate::classifier::Classifier;
    pub use crate::compiler::Compiler;
    pub use crate::enums::{CursorDirection, NullsPosition, OrderDirection, Type};
    pub use crate::error::QueryError;
    pub use crate::method::Method;
    pub use crate::query::Query;
    pub use crate::value::QueryValue;
}

pub use ast::{
    Aliased, Binary, Column as AstColumn, Expression, Func, Literal, Parser, Select, Serializer,
    Star, Table as AstTable, Visitor, Walker,
};
pub use builder::{
    Binding, Builder, CaseExpression, CaseKind, CaseOperator, ClickHouse, Condition, DialectKind,
    JoinBuilder, JoinType, LockMode, MariaDB, MariaDb, MongoDB, MongoDb, MySQL, MySql, PostgreSQL,
    PostgreSql, SQLite, Sqlite, Statement, UnionType, VectorMetric,
};
pub use classifier::{
    Classifier, MongodbClassifier, MysqlClassifier, PostgresClassifier, SqlClassifier,
};
pub use compiler::Compiler;
pub use enums::{CursorDirection, NullsPosition, OrderDirection, Type};
pub use error::{Exception, QueryError, UnsupportedException, ValidationException};
pub use hook::{
    AttributeHook, AttributeMap, FilterHook, Hook, JoinFilterHook, Placement, Tenant, WriteHook,
};
pub use method::Method;
pub use query::{FingerprintInput, Query};
pub use schema::{
    Column, ColumnType, ForeignKey, ForeignKeyAction, Index, IndexType, Schema, Table,
};
pub use tokenizer::{Token, TokenType, Tokenizer};
pub use value::{IntoValues, QueryValue};
