//! A document database: collections with typed attributes and indexes,
//! documents with permissions, queries, relationships, tenancy and caching,
//! over pluggable storage adapters.
//!
//! The crate reads and writes the same tables, rows and metadata as
//! `utopia-php/database`, so PHP and Rust services share one dataset.
//!
//! PHP: `utopia-php/database` 8.1.3, built on `utopia-php/query`
//! ([`utopia_query`]): a database [`Query`] is a query of that library, and
//! the SQL adapters compile statements with its builders.
//!
//! # Port status
//!
//! The 8.x crate is ported layer by layer. This layer is the foundation:
//! the model, the adapter contract, the validators. The `Database` type,
//! the hooks it implements (`Hook\Permissions`, `Hook\Relationships`), the
//! caches, the adapters and `Mirror` follow; the 7.4 port they start from
//! is kept, uncompiled, under `src/legacy/`.
//!
//! # PHP classes and their Rust items
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Database\Document` | [`Document`], [`document::SetType`] (`SetType`), the `Document::*` constants in [`document`] |
//! | `Utopia\Database\Collection`, `Collection\Update` | [`Collection`], [`collection::Update`] |
//! | `Utopia\Database\Attribute`, `Attribute\Update` | [`Attribute`], [`attribute::Update`] |
//! | `Utopia\Database\Format`, `NumericBounds`, `IntegerWidth`, `Unchanged` | [`attribute::Format`], [`attribute::NumericBounds`], [`attribute::IntegerWidth`], [`attribute::Patch`] |
//! | `Utopia\Database\Index` | [`Index`] |
//! | `Utopia\Database\Relationship`, `Relationship\Update` | [`Relationship`], [`relationship::Update`] |
//! | `RelationshipType`, `RelationshipSide`, `RelationshipDeleteAction` | [`relationship::RelationshipType`], [`relationship::RelationshipSide`], [`relationship::RelationshipDeleteAction`] |
//! | `Utopia\Database\Id` | [`id`] |
//! | `Utopia\Database\Role` | [`Role`] |
//! | `Utopia\Database\Permission`, `PermissionType` | [`Permission`], [`PermissionType`] |
//! | `Utopia\Database\Capability` | [`Capability`], [`capability::Capabilities`] |
//! | `Utopia\Database\Storage` | [`storage`] |
//! | `Utopia\Database\Change` | [`Change`] |
//! | `Utopia\Database\Connection` | [`connection`] |
//! | `Utopia\Database\DateTime` | [`datetime`] |
//! | `Utopia\Database\Query`, `ParsedQuery` | [`Query`], [`query::ParsedQuery`] |
//! | `Utopia\Database\Operator`, `OperatorType` | [`Operator`], [`operator`] |
//! | `Utopia\Database\Filter`, `Filter\{Codec, Signed, Callback, Registry}` | [`filter::Filter`], [`filter::Codec`] (`Signed` is [`filter::Codec::signature`]), [`filter::Callback`], [`filter::Registry`] |
//! | `Utopia\Database\Event`, `Event\Domain` and its subclasses, `Event\DispatcherHook` | [`Event`], [`event::Domain`], [`event::DispatcherHook`] |
//! | `Utopia\Database\Hook\*` (interfaces, `Tenancy`, `RowMetadata`) | [`hook`] |
//! | `Utopia\Database\Profiler`, `Profiler\Log` | [`Profiler`], [`profiler::Log`] |
//! | `Utopia\Database\State\{Value, Frame, Snapshot}` (`Group`: none) | [`state::Scoped`], [`state::Frame`], [`state::Snapshot`] |
//! | `Utopia\Database\Schema\{Column, Index}` | [`schema::Column`], [`schema::Index`] |
//! | `Utopia\Database\Adapter` | [`Adapter`], [`adapter::State`], [`adapter::with_transaction`] |
//! | `Adapter\{Limits, Profile, CursorBoundary, Timeout}` | [`adapter::Limits`], [`adapter::Profile`], [`adapter::CursorBoundary`], [`adapter::TimeoutState`] |
//! | `Adapter\Feature\*` | [`adapter::Feature`], [`adapter::Casting`], [`adapter::Connection`], [`adapter::QueryBuilder`], [`adapter::RawQuery`], [`adapter::Relationships`], [`adapter::Schemaless`], [`adapter::Spatial`], [`adapter::Timeouts`], [`adapter::Upserts`] |
//! | `Utopia\Database\Validator\*` | [`validator`] |
//! | `Utopia\Database\Database` (constants, `internalAttributesFor()`) | [`database`] |
//! | `Utopia\Database\Exception` and `Exception\*` | [`Error`], [`Kind`] |
//! | PHP values (`mixed`) | [`Value`] (`php_std::Value` with [`value::Ext`]); query values are [`utopia_query::Value`] (see [`value`]) |

#![warn(missing_docs)]

mod macros;

pub mod adapter;
pub mod attribute;
pub mod capability;
pub mod change;
pub mod collection;
pub mod connection;
pub mod database;
pub mod datetime;
pub mod document;
pub mod error;
pub mod event;
pub mod filter;
pub mod hook;
pub mod id;
pub mod index;
pub mod operator;
pub mod permission;
pub mod profiler;
pub mod query;
pub mod relationship;
pub mod role;
pub mod schema;
pub mod state;
pub mod storage;
pub mod validator;
pub mod value;

pub use adapter::Adapter;
pub use attribute::Attribute;
pub use capability::Capability;
pub use change::Change;
pub use collection::Collection;
pub use document::Document;
pub use error::{Error, Kind, Result};
pub use event::Event;
pub use index::Index;
pub use operator::Operator;
pub use permission::{Permission, PermissionType};
pub use profiler::Profiler;
pub use query::Query;
pub use relationship::Relationship;
pub use role::Role;
pub use validator::authorization::Authorization;
pub use value::{Array, Value};
