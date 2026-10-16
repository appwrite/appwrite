//! PHP `Utopia\Database\Validator\*`.

pub mod attribute;
pub mod authorization;
pub mod big_int;
pub mod byte_length;
pub mod datetime;
pub mod index;
pub mod index_dependency;
pub mod indexed_queries;
pub mod key;
pub mod label;
pub mod object_validator;
pub mod operator;
pub mod partial_structure;
pub mod permissions;
pub mod queries;
pub mod roles;
pub mod sequence;
pub mod spatial;
pub mod structure;
pub mod uid;
pub mod vector;

pub mod query {
    pub mod base;
    pub mod cursor;
    pub mod filter;
    pub mod limit;
    pub mod offset;
    pub mod order;
    pub mod select;
    pub use base::{Base, QueryMethodValidator};
    pub use cursor::Cursor;
    pub use filter::Filter;
    pub use limit::Limit;
    pub use offset::Offset;
    pub use order::Order;
    pub use select::Select;
}

pub mod queries_doc {
    pub mod document;
    pub mod documents;
    pub use document::DocumentQueries;
    pub use documents::DocumentsQueries;
}

pub use attribute::Attribute;
pub use authorization::{Authorization, Input};
pub use big_int::BigInt;
pub use byte_length::ByteLength;
pub use datetime::Datetime;
pub use index::Index;
pub use index_dependency::IndexDependency;
pub use indexed_queries::IndexedQueries;
pub use key::Key;
pub use label::Label;
pub use object_validator::ObjectValidator;
pub use operator::OperatorValidator;
pub use partial_structure::PartialStructure;
pub use permissions::Permissions;
pub use queries::Queries;
pub use roles::Roles;
pub use sequence::Sequence;
pub use spatial::Spatial;
pub use structure::Structure;
pub use uid::Uid;
pub use vector::Vector;
