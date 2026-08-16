//! Application persistence adapters for Utopia.
//!
//! Rust port of [`utopia-php/database`](https://github.com/utopia-php/database)
//! (PHP SHA `761050b576d1`).
//!
//! ```
//! use utopia_cache::adapter::Memory as CacheMemory;
//! use utopia_cache::Cache;
//! use utopia_database::adapter::Memory;
//! use utopia_database::helpers::{Id, Permission, Role};
//! use utopia_database::{Database, Document};
//! use utopia_database::query::Query;
//!
//! let cache = Cache::new(CacheMemory::new());
//! let mut db = Database::new(Memory::new(), cache);
//! db.set_namespace("myapp").unwrap();
//! db.set_database("myapp").unwrap();
//! db.create(None).unwrap();
//!
//! db.create_collection(
//!     "movies",
//!     vec![Document::from_pairs([
//!         ("$id", "name".into()),
//!         ("type", "string".into()),
//!         ("size", 128.into()),
//!         ("required", true.into()),
//!     ]).unwrap()],
//!     vec![],
//!     Some(vec![
//!         Permission::create(&Role::any()),
//!         Permission::read(&Role::any()),
//!     ]),
//!     true,
//! ).unwrap();
//!
//! let doc = db.create_document(
//!     "movies",
//!     Document::from_pairs([
//!         ("$id", Id::custom("tt2654620").into()),
//!         ("name", "Linux in Action".into()),
//!         ("$permissions", vec![Permission::read(&Role::any())].into()),
//!     ]).unwrap(),
//! ).unwrap();
//! assert_eq!(doc.get_id(), "tt2654620");
//!
//! let found = db.find("movies", &[Query::equal("name", vec!["Linux in Action".into()])], "read").unwrap();
//! assert_eq!(found.len(), 1);
//! ```

#![allow(clippy::too_many_arguments)]
#![allow(clippy::too_many_lines)]
#![allow(clippy::fn_params_excessive_bools)]
#![allow(clippy::missing_errors_doc)]
#![allow(clippy::missing_panics_doc)]
#![allow(clippy::module_name_repetitions)]
#![allow(clippy::redundant_pub_crate)]
#![allow(clippy::match_same_arms)]
#![allow(clippy::match_wildcard_for_single_variants)]
#![allow(clippy::nonminimal_bool)]
#![allow(clippy::format_push_string)]
#![allow(clippy::unreadable_literal)]
#![allow(clippy::default_trait_access)]
#![allow(clippy::inherent_to_string)]
#![allow(clippy::iter_without_into_iter)]
#![allow(clippy::ref_option)]
#![allow(clippy::needless_continue)]
#![allow(clippy::only_used_in_recursion)]
#![allow(clippy::incompatible_msrv)]
#![allow(clippy::question_mark)]
#![allow(clippy::unnecessary_wraps)]
#![allow(clippy::if_same_then_else)]
#![allow(clippy::manual_strip)]
#![allow(clippy::assigning_clones)]
#![allow(clippy::used_underscore_binding)]
#![allow(clippy::should_implement_trait)]
#![allow(clippy::map_unwrap_or)]
#![allow(clippy::single_match_else)]
#![allow(clippy::cloned_instead_of_copied)]
#![allow(clippy::doc_markdown)]
#![allow(clippy::struct_field_names)]

pub mod adapter;
pub mod change;
pub mod connection;
pub mod constants;
pub mod database;
pub mod datetime;
pub mod document;
pub mod error;
pub mod helpers;
pub mod mirror;
pub mod operator;
pub mod pdo;
pub mod query;
pub mod validator;
pub mod value;

pub use adapter::{Adapter, AdapterState, Memory, PoolAdapter};
pub use change::Change;
pub use connection::Connection;
pub use constants::*;
pub use database::Database;
pub use datetime::DateTime;
pub use document::{Document, SET_TYPE_APPEND, SET_TYPE_ASSIGN, SET_TYPE_PREPEND};
pub use error::{DatabaseError, Result};
pub use helpers::{Id, Permission, Role};
pub use mirror::{AllowAllFilter, Mirror, MirrorFilter};
pub use operator::Operator;
pub use pdo::{
    Dialect, Pdo, PdoStatement, SqlParam, ATTR_TIMEOUT, PARAM_BOOL, PARAM_INT, PARAM_LOB,
    PARAM_NULL, PARAM_STR,
};
pub use query::{GroupedQueries, Query};
pub use validator::authorization::{Authorization, Input};
pub use value::AttrValue;

/// Prelude matching PHP `use Utopia\Database\*` plus helpers.
pub mod prelude {
    pub use crate::adapter::Memory;
    pub use crate::helpers::{Id, Permission, Role};
    pub use crate::query::Query;
    pub use crate::{
        Adapter, AttrValue, Authorization, Change, Connection, Database, DatabaseError, DateTime,
        Document, Operator, Pdo,
    };
}
