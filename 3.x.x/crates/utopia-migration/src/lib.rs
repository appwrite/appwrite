//! Project migration sources and destinations for Utopia.
//!
//! Rust port of [`utopia-php/migration`](https://github.com/utopia-php/migration) (PHP SHA `7e371c8f59bf`).

#![deny(unsafe_code)]
#![allow(missing_debug_implementations)]

pub mod cache;
pub mod destination;
pub mod destinations;
pub mod exception;
pub mod on_duplicate;
pub mod resource;
pub mod resource_selector;
pub mod resources;
pub mod source;
pub mod sources;
pub mod target;
pub mod transfer;
pub mod warning;

pub use cache::Cache;
pub use destination::Destination;
pub use destinations::appwrite::{
    Appwrite as AppwriteDestination, CollectionStructure, ATTRIBUTE_IMMUTABLE_FIELDS,
    RELATIONSHIP_IMMUTABLE_FIELDS,
};
pub use destinations::csv::CsvDestination;
pub use destinations::json::JsonDestination;
pub use destinations::local::LocalDestination;
pub use destinations::mock::MockDestination;
pub use exception::Exception;
pub use on_duplicate::{OnDuplicate, SchemaAction};
pub use resource::{AnyResource, Resource, ResourceBase, ALL_RESOURCES};
pub use resource_selector::ResourceSelector;
pub use source::Source;
pub use sources::appwrite::Appwrite as AppwriteSource;
pub use sources::csv::CsvSource;
pub use sources::firebase::Firebase;
pub use sources::json::JsonSource;
pub use sources::mock::MockSource;
pub use sources::nhost::NHost;
pub use sources::supabase::Supabase;
pub use target::Target;
pub use transfer::Transfer;
pub use warning::Warning;

pub mod prelude {
    pub use crate::resources::auth::OAuth2Provider;
    pub use crate::resources::database::{
        Attribute, Collection, Column, ColumnKind, Database, Document, Index, Row, Table,
    };
    pub use crate::resources::functions::Func;
    pub use crate::resources::sites::Site;
    pub use crate::{
        AnyResource, AppwriteDestination, AppwriteSource, Cache, CollectionStructure,
        CsvDestination, CsvSource, Destination, Exception, Firebase, JsonDestination, JsonSource,
        LocalDestination, MockDestination, MockSource, NHost, OnDuplicate, Resource, SchemaAction,
        Source, Supabase, Target, Transfer, Warning, ATTRIBUTE_IMMUTABLE_FIELDS,
        RELATIONSHIP_IMMUTABLE_FIELDS,
    };
}
