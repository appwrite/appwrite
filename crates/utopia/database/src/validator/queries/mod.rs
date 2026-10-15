//! Validators of query lists (`Validator\Queries\*`): any list
//! ([`base::Queries`]), lists checked against the indexes too
//! ([`indexed::Indexed`]), the queries of a document listing
//! ([`documents::Documents`]) and of a single document read
//! ([`document::Document`]), and plain lists checked with only what they
//! need ([`narrow::Narrow`]).

pub mod base;
pub mod document;
pub mod documents;
pub mod indexed;
pub mod narrow;
