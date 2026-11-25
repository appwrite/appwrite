//! A document database: collections with typed attributes and indexes,
//! documents with permissions, queries, relationships, tenancy and caching,
//! over pluggable storage adapters.
//!
//! The crate reads and writes the same tables, rows and metadata as
//! `utopia-php/database`, so PHP and Rust services share one dataset.
//!
//! # Port status
//!
//! Only the modules without I/O are here so far: identifiers, roles,
//! permissions, dates and the errors they fail with. The rest of the crate
//! (the model, the adapters, the validators and the [`Error`] marks they
//! set) comes from appwrite/appwrite#14308.
//!
//! # PHP classes and their Rust items
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Database\Id` | [`id`] |
//! | `Utopia\Database\Role` | [`Role`] |
//! | `Utopia\Database\Permission`, `PermissionType` | [`Permission`], [`PermissionType`] |
//! | `Utopia\Database\DateTime` | [`datetime`] |
//! | `Utopia\Database\Exception` and `Exception\*` | [`Error`], [`Kind`] |

#![warn(missing_docs)]

pub mod datetime;
// The marks are set and read by the modules that are not here yet.
#[allow(dead_code)]
pub mod error;
pub mod id;
pub mod permission;
pub mod role;

pub use error::{Error, Kind, Result};
pub use permission::{Permission, PermissionType};
pub use role::Role;
