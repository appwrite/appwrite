//! Validators of one query by kind (`Validator\Query\*`): filters, cursors,
//! limits, offsets, orders, selections, joins, aggregates, groupings,
//! `having`, `distinct` and relationship queries; and the joined collections they resolve
//! attributes against ([`joined`]).
//!
//! Each validates a query (`check`, PHP's `isValid($query)`), and through
//! `is_valid` any value as PHP's `isValid(mixed)` does. The query list
//! validators ([`crate::validator::queries`]) dispatch to them through the
//! [`base::QueryValidator`] trait.

pub mod aggregate;
pub mod base;
pub mod cursor;
pub mod distinct;
pub mod filter;
pub mod group_by;
pub mod having;
pub mod join;
pub mod joined;
pub mod limit;
pub mod offset;
pub mod order;
pub mod relationship;
pub mod select;
