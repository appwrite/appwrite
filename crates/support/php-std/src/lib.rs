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
//! PHP values are [`Value`] ([`types`]): byte strings, ordered arrays with
//! integer and string keys, `stdClass`, and crate-defined objects. The
//! codecs ([`json`], [`serialize`], [`igbinary`]), comparisons and
//! [`sort`] work on it. Older string-level helpers ([`value`], [`string`],
//! [`format`], [`filter`]) take request-model `serde_json::Value`s, decoded
//! the way `Utopia\Http\Request` decodes bodies (objects are associative
//! arrays, except empty objects, which stay `stdClass`; [`Value::from_json`]
//! is the same mapping).

pub mod datetime;
pub mod encoding;
pub mod filter;
pub mod format;
pub mod igbinary;
pub mod json;
pub mod mb;
pub mod net;
pub mod number;
pub mod path;
pub mod pcre;
pub mod serialize;
pub mod sort;
pub mod string;
pub mod system;
pub mod types;
pub mod url;
pub mod value;

pub use types::{Array, ArrayKey, EngineError, Extension, Never, Str, Value};
