//! Shared test helpers. Mirrors the PHP `tests/MockResolver.php`.
//!
//! Each integration test file opts in with `mod common;` at the top.

#![allow(dead_code)]

pub mod mock_resolver;

pub use mock_resolver::MockResolver;
