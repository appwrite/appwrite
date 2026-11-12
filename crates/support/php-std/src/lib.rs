//! PHP engine semantics for crates that must behave exactly like PHP.
//!
//! Only what utopia-image uses, copied unchanged from the full crate on
//! `feat/rust-utopia-conversion`; that crate replaces this one when it lands.
#![warn(missing_docs)]

pub mod format;
pub mod path;
pub mod value;
