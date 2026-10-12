//! Validators: documents against their collection ([`structure`]), queries
//! ([`queries`], [`query`]), attribute and index definitions, keys,
//! permissions and roles, and the [`authorization`] check of a permission
//! list against the current roles.
//!
//! PHP: `Utopia\Database\Validator\*`. Each validator implements
//! [`utopia_validators::Validator`] (description, `is_valid`, `is_array`,
//! type) the way PHP's does; the database also calls their typed methods.

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
pub mod object;
pub mod operator;
pub mod partial_structure;
pub mod permissions;
pub mod queries;
pub mod query;
pub mod roles;
pub mod sequence;
pub mod spatial;
pub mod structure;
pub mod uid;
pub mod vector;
