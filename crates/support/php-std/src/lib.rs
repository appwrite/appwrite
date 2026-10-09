//! PHP engine semantics in Rust.
//!
//! Utopia crates must behave exactly like their PHP libraries, and much of
//! that behaviour comes from the PHP engine rather than the library: type
//! juggling, `is_numeric`, `(string)` casts, loose comparison, `strtolower`,
//! `mb_*`, `number_format`, `json_encode`/`json_decode`, `filter_var`,
//! `parse_url`. Each is reimplemented here once, and verified against the
//! real PHP function by `bin/compat` (library `php-std`, see
//! `tests/compat/php-std`). Utopia crates use these functions instead of
//! their own approximations (crates/CONVERSION.md §3).
//!
//! PHP values are carried as `serde_json::Value`, decoded the way
//! `Utopia\Http\Request` decodes request bodies: objects are associative
//! arrays, except empty objects, which stay `stdClass`.

pub mod number;
pub mod path;
pub mod system;
pub mod value;
