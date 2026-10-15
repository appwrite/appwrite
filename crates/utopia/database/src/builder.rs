//! The database's query builders: `utopia-query`'s builders compiling as
//! 7.x did, whose `from()` takes a collection id once they have a scope.
//!
//! PHP subclasses each builder of `utopia-php/query`; here each is the
//! `utopia-query` builder with the database's [`Flavor`] set (see
//! `utopia_query::builder::Flavor` for what each PHP trait became), and a
//! [`SqlBuilder`] holds one of them in its dialect.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Builder\MySQL` | [`mysql()`] |
//! | `Builder\MariaDB` | [`mariadb()`] |
//! | `Builder\Postgres` | [`postgres()`] |
//! | `Builder\SQLite` (`SQLite::COLLATION`) | [`sqlite()`] ([`SQLITE_COLLATION`]) |
//! | `Builder\Scope` | [`Scope`] |
//! | `Builder\Scoping`, `Builder\ScopesCollections`, `Builder\Filtering`, `Builder\CompilesFilters`, `Builder\RefusesJoinedWrites` | the builder's own `scope()`, `is_scoped()`, `from_table()`, `compile_filters()` and its refusals |
//! | `Builder\PreparesSearchTerms`, `Builder\MatchesContainsAllPatterns` | [`Flavor`] |
//!
//! ```
//! use utopia_database::builder;
//! use utopia_database::Query;
//!
//! let statement = builder::sqlite()
//!     .from("users", "")
//!     .filter([Query::starts_with("name", "a_").into_inner()])
//!     .build()?;
//! assert_eq!(statement.query, "SELECT * FROM `users` WHERE `name` LIKE ? ESCAPE '\\'");
//! # Ok::<(), utopia_query::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Database\Builder\SQLite;
//! use Utopia\Query\Query;
//!
//! echo (new SQLite())->from('users')->filter([Query::startsWith('name', 'a_')])->build()->query, "\n";
//! // SELECT * FROM `users` WHERE `name` LIKE ? ESCAPE '\'
//! ```

use std::sync::Arc;

pub use crate::hook::SqlBuilder;
use utopia_query::builder::{Condition, MariaDb, MySql, PostgreSql, Sqlite, Statement};
pub use utopia_query::builder::{Flavor, Scope, ScopeBinding, SqliteFlavor};

use crate::error::Error;
use crate::query::Query;
use crate::storage;
use crate::validator::object_path::ObjectPath;

/// The collation document ids compare in on SQLite (`SQLite::COLLATION`).
pub const SQLITE_COLLATION: &str = "NOCASE";

/// A database error carried through a `utopia-query` result (a hook's or a
/// scope's refusal, PHP's `Utopia\Database\Exception\*` thrown inside the
/// builder), converted back unchanged where the database reads the result.
pub fn query_error(error: Error) -> utopia_query::Error {
    let message = error.message().to_owned();
    let code = error.code();
    utopia_query::Error::with_previous(message, code, Arc::new(error))
}

/// The flavor of the MySQL, MariaDB and PostgreSQL builders.
fn sql_flavor() -> Flavor {
    Flavor { search_terms: true, contains_all_patterns: true, ..Flavor::default() }
}

/// A MySQL builder (`new Utopia\Database\Builder\MySQL()`).
pub fn mysql() -> MySql {
    let mut builder = MySql::new();
    builder.set_flavor(sql_flavor());
    builder
}

/// A MariaDB builder (`new Utopia\Database\Builder\MariaDB()`).
pub fn mariadb() -> MariaDb {
    let mut builder = MariaDb::new();
    builder.set_flavor(sql_flavor());
    builder
}

/// A PostgreSQL builder (`new Utopia\Database\Builder\Postgres()`): it also
/// refuses a filter on an invalid path into an object attribute, and lets
/// `distanceLessThan()` use the spatial index.
pub fn postgres() -> PostgreSql {
    let mut builder = PostgreSql::new();
    builder.set_flavor(Flavor {
        object_path: Some(Arc::new(|attribute: &str| {
            if ObjectPath::accepts(attribute.as_bytes()) {
                return Ok(());
            }
            Err(query_error(Error::query(format!("Invalid object path \"{attribute}\": {}", ObjectPath.description()))))
        })),
        indexed_distance: Some(crate::database::DEFAULT_SRID),
        ..sql_flavor()
    });
    builder
}

/// A SQLite builder (`new Utopia\Database\Builder\SQLite()`).
pub fn sqlite() -> Sqlite {
    let mut builder = Sqlite::new();
    builder.set_flavor(Flavor {
        sqlite: Some(SqliteFlavor {
            collation: SQLITE_COLLATION.to_owned(),
            collated_columns: vec![storage::UID.to_owned(), storage::PERMISSIONS_DOCUMENT.to_owned()],
        }),
        ..Flavor::default()
    });
    builder
}

/// Runs `$body` with `$b` bound to the builder a [`SqlBuilder`] holds,
/// whatever its dialect; every arm must have the same type.
///
/// ```
/// use utopia_database::builder::{self, SqlBuilder};
///
/// let mut builder = SqlBuilder::Sqlite(builder::sqlite());
/// let sql = utopia_database::each_builder!(&mut builder, b => b.from("t", "").build())?.query;
/// assert_eq!(sql, "SELECT * FROM `t`");
/// # Ok::<(), utopia_query::Error>(())
/// ```
#[macro_export]
macro_rules! each_builder {
    ($builder:expr, $b:ident => $body:expr) => {
        match $builder {
            $crate::builder::SqlBuilder::MySql($b) => $body,
            $crate::builder::SqlBuilder::MariaDb($b) => $body,
            $crate::builder::SqlBuilder::PostgreSql($b) => $body,
            $crate::builder::SqlBuilder::Sqlite($b) => $body,
        }
    };
}

/// Forwards builder methods returning the builder.
macro_rules! forward {
    ($( $(#[$doc:meta])* fn $name:ident(&mut self $(, $arg:ident: $ty:ty)*); )*) => {
        $(
            $(#[$doc])*
            pub fn $name(&mut self $(, $arg: $ty)*) -> &mut Self {
                each_builder!(&mut *self, b => { b.$name($($arg),*); });
                self
            }
        )*
    };
}

/// Forwards builder methods returning a result of the builder.
macro_rules! forward_result {
    ($( $(#[$doc:meta])* fn $name:ident(&mut self $(, $arg:ident: $ty:ty)*); )*) => {
        $(
            $(#[$doc])*
            pub fn $name(&mut self $(, $arg: $ty)*) -> utopia_query::Result<&mut Self> {
                each_builder!(&mut *self, b => { b.$name($($arg),*)?; });
                Ok(self)
            }
        )*
    };
}

/// Forwards builder methods compiling a statement.
macro_rules! forward_statement {
    ($( $(#[$doc:meta])* fn $name:ident(&mut self); )*) => {
        $(
            $(#[$doc])*
            pub fn $name(&mut self) -> utopia_query::Result<Statement> {
                each_builder!(&mut *self, b => b.$name())
            }
        )*
    };
}

impl SqlBuilder {
    forward! {
        /// `from($table, $alias)`: on a scoped builder, a collection id.
        fn from(&mut self, table: &str, alias: &str);
        /// `fromNone()`.
        fn from_none(&mut self);
        /// `into($table)`.
        fn into(&mut self, table: &str);
        /// `insertAs($alias)`.
        fn insert_as(&mut self, alias: &str);
        /// `filter($queries)`.
        fn filter(&mut self, queries: Vec<utopia_query::Query>);
        /// `queries($queries)`.
        fn queries(&mut self, queries: Vec<utopia_query::Query>);
        /// `select($columns)`.
        fn select(&mut self, columns: Vec<String>);
        /// `selectRaw($expression, $bindings)`.
        fn select_raw(&mut self, expression: &str, bindings: Vec<utopia_query::Value>);
        /// `distinct()`.
        fn distinct(&mut self);
        /// `limit($value)`.
        fn limit(&mut self, value: i64);
        /// `offset($value)`.
        fn offset(&mut self, value: i64);
        /// `sortRandom()`.
        fn sort_random(&mut self);
        /// `groupBy($columns)`.
        fn group_by(&mut self, columns: Vec<String>);
        /// `having($queries)`.
        fn having(&mut self, queries: Vec<utopia_query::Query>);
        /// `onConflict($keys, $updateColumns)`.
        fn on_conflict(&mut self, keys: Vec<String>, update_columns: Vec<String>);
        /// `reset()`.
        fn reset(&mut self);
    }

    forward_result! {
        /// `set($row)`.
        fn set(&mut self, row: utopia_query::Array);
        /// `scope($scope)`: fails once the builder read a collection.
        fn scope(&mut self, scope: Arc<dyn Scope>);
        /// `fromTable($table, $alias)`: fails once the builder read a collection.
        fn from_table(&mut self, table: &str, alias: &str);
        /// `addHook($hook)`.
        fn add_hook(&mut self, hook: utopia_query::hook::Hook);
    }

    forward_statement! {
        /// `build()`.
        fn build(&mut self);
        /// `insert()`.
        fn insert(&mut self);
        /// `update()`.
        fn update(&mut self);
        /// `delete()`.
        fn delete(&mut self);
        /// `insertDefaultValues()`.
        fn insert_default_values(&mut self);
    }

    /// `sortAsc($attribute)`.
    pub fn sort_asc(&mut self, attribute: &str) -> &mut Self {
        each_builder!(&mut *self, b => { b.sort_asc(attribute, None); });
        self
    }

    /// `sortDesc($attribute)`.
    pub fn sort_desc(&mut self, attribute: &str) -> &mut Self {
        each_builder!(&mut *self, b => { b.sort_desc(attribute, None); });
        self
    }

    /// `isScoped()`.
    pub fn is_scoped(&self) -> bool {
        each_builder!(self, b => b.is_scoped())
    }

    /// `compileFilters($filters)` (`Builder\Filtering`).
    pub fn compile_filters(&mut self, filters: &[Query]) -> utopia_query::Result<Condition> {
        let filters: Vec<utopia_query::Query> = filters.iter().map(|q| (**q).clone()).collect();
        each_builder!(&mut *self, b => b.compile_filters(&filters))
    }

    /// The bindings of the last statement built (`getBindings()`).
    pub fn bindings(&self) -> Vec<utopia_query::Value> {
        each_builder!(self, b => b.bindings())
    }

    /// The queries added so far.
    pub fn pending(&self) -> &[utopia_query::Query] {
        each_builder!(self, b => b.pending())
    }

    /// The identifier quote of the dialect.
    pub fn quote_char(&self) -> char {
        match self {
            SqlBuilder::PostgreSql(_) => '"',
            _ => '`',
        }
    }
}
