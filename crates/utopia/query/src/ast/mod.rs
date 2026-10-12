//! The SQL syntax tree: a parser from tokens, serializers per dialect, a
//! walker and visitors that rewrite trees.
//!
//! [`Parser`] reads the tokens of a [`Tokenizer`](crate::tokenizer::Tokenizer)
//! into a [`Select`]; [`Walker`] applies a [`Visitor`] to every node
//! (validating columns, injecting a filter, renaming tables); [`Serializer`]
//! writes the tree back as SQL. Nodes are plain values with public fields:
//! [`Expression`] is the enum of every expression node.
//!
//! PHP: `Utopia\Query\AST\*`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `AST\Expression` (interface) | [`Expression`] |
//! | `AST\Call\Func` | [`Func`] |
//! | `AST\Expression\Aliased`, `Between`, `Binary`, `CaseWhen`, `Cast`, `Conditional`, `Exists`, `In`, `Subquery`, `Unary`, `Window` | [`Aliased`], [`Between`], [`Binary`], [`CaseWhen`], [`Cast`], [`Conditional`], [`Exists`], [`In`] (with [`InList`]), [`Subquery`], [`Unary`], [`Window`] |
//! | `AST\Literal`, `AST\Placeholder`, `AST\Raw`, `AST\Star` | [`Literal`], [`Placeholder`], [`Raw`], [`Star`] |
//! | `AST\Reference\Column`, `AST\Reference\Table` | [`Column`], [`Table`] |
//! | `AST\SubquerySource`, `Table\|SubquerySource` | [`SubquerySource`], [`TableSource`] |
//! | `AST\JoinClause`, `AST\OrderByItem` | [`JoinClause`], [`OrderByItem`] |
//! | `AST\Definition\Cte`, `AST\Definition\Window` | [`Cte`], [`WindowDefinition`] |
//! | `AST\Specification\Window` | [`WindowSpecification`] |
//! | `AST\Statement\Select` (`with()`: struct update syntax) | [`Select`] |
//! | `AST\Parser` | [`Parser`] |
//! | `AST\Serializer`, `Serializer\MySQL`, `MariaDB`, `ClickHouse`, `PostgreSQL`, `SQLite` | [`Serializer::new`], [`Serializer::mysql`], [`Serializer::mariadb`], [`Serializer::clickhouse`], [`Serializer::postgresql`], [`Serializer::sqlite`] |
//! | `AST\Walker` | [`Walker`] |
//! | `AST\Visitor` (interface) | [`Visitor`] |
//! | `AST\Visitor\ColumnValidator`, `FilterInjector`, `TableRenamer` | [`ColumnValidator`], [`FilterInjector`], [`TableRenamer`] |
//!
//! ```
//! use utopia_query::ast::{Binary, Column, FilterInjector, Literal, Parser, Serializer, Visitor};
//!
//! let select = Parser::new().parse_sql("SELECT * FROM orders WHERE total > 100")?;
//! let mut tenant = FilterInjector::new(Binary::new(Column::new("tenant_id"), "=", Literal::new(42)));
//! let select = tenant.visit_select(select)?;
//! assert_eq!(
//!     Serializer::new().serialize(&select)?,
//!     "SELECT * FROM `orders` WHERE `total` > 100 AND `tenant_id` = 42"
//! );
//! # Ok::<(), utopia_query::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Query\AST\Expression\Binary;
//! use Utopia\Query\AST\Literal;
//! use Utopia\Query\AST\Parser;
//! use Utopia\Query\AST\Reference\Column;
//! use Utopia\Query\AST\Serializer;
//! use Utopia\Query\AST\Visitor\FilterInjector;
//! use Utopia\Query\Tokenizer\Tokenizer;
//!
//! $select = (new Parser())->parse(Tokenizer::filter((new Tokenizer())->tokenize('SELECT * FROM orders WHERE total > 100')));
//! $select = (new FilterInjector(new Binary(new Column('tenant_id'), '=', new Literal(42))))->visitSelect($select);
//! echo (new Serializer())->serialize($select), "\n"; // SELECT * FROM `orders` WHERE `total` > 100 AND `tenant_id` = 42
//! ```

mod node;
mod parser;
mod serializer;
mod walker;

pub use node::{
    Aliased, Between, Binary, CaseWhen, Cast, Column, Conditional, Cte, Exists, Expression, Func, In, InList,
    JoinClause, Literal, OrderByItem, Placeholder, Raw, Select, Star, Subquery, SubquerySource, Table, TableSource,
    Unary, Window, WindowDefinition, WindowSpecification,
};
pub use parser::Parser;
pub use serializer::Serializer;
pub use walker::{ColumnValidator, FilterInjector, TableRenamer, Visitor, Walker};
