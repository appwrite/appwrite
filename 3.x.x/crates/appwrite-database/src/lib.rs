//! Appwrite database helpers on `utopia-database`.
//!
//! Ports the pieces of `Appwrite\Utopia\Database\*` the Users API
//! foundation needs: the [`CustomId`] validator
//! (`Appwrite\Utopia\Database\Validator\CustomId`) plus the
//! `unique()`-sentinel resolution pattern used throughout Users/Targets/
//! Sessions creation ([`resolve_id`]), and a handful of [`queries`] helpers
//! for the `Query::equal`/`Query::search` calls those endpoints repeat.
//!
//! ```
//! use appwrite_database::{resolve_id, CustomId, UNIQUE_SENTINEL};
//! use utopia_validators::Validator;
//! use serde_json::json;
//!
//! let validator = CustomId::default();
//! assert!(validator.is_valid(&json!(UNIQUE_SENTINEL)));
//! assert!(validator.is_valid(&json!("my-custom-id")));
//! assert!(!validator.is_valid(&json!(".starts-with-dot")));
//!
//! assert_eq!(resolve_id("my-custom-id"), "my-custom-id");
//! assert_ne!(resolve_id(UNIQUE_SENTINEL), UNIQUE_SENTINEL);
//! ```

mod custom_id;
pub mod filters;
pub mod queries;

pub use custom_id::{resolve_id, CustomId, UNIQUE_SENTINEL};

// Re-exported so callers building on the query helpers in `queries` don't
// need a direct `utopia-database` dependency of their own.
pub use utopia_database::{AttrValue, Query};
