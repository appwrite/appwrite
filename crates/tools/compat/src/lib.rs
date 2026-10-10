//! Differential compatibility testing between the PHP Utopia libraries and
//! their Rust crates. See `tests/compat/README.md` for how to use it and
//! `crates/CONVERSION.md` for the standard it enforces.
//!
//! - [`adapter`] and [`libs`]: the Rust side of every library's operations.
//! - [`driver`]: the Rust driver process (`compat-driver`).
//! - [`runner`]: the `compat` command, which drives the PHP and Rust drivers
//!   with the same cases and generated inputs and compares everything.

pub mod adapter;
pub mod driver;
pub mod libs;
pub mod runner;
