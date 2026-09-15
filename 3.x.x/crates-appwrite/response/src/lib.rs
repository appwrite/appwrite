//! Appwrite API response models.
//!
//! Rust port of the Users-API subset of `Appwrite\Utopia\Response` /
//! `Appwrite\Utopia\Response\Model\*` (`src/Appwrite/Utopia/Response.php`,
//! `src/Appwrite/Utopia/Response/Model/*.php`).
//!
//! ```
//! use appwrite_response::{dynamic, MODEL_USER};
//! use serde_json::json;
//!
//! let doc = json!({
//!     "$id": "u1",
//!     "$createdAt": "2024-01-01T00:00:00.000+00:00",
//!     "$updatedAt": "2024-01-01T00:00:00.000+00:00",
//!     "name": "Ada",
//!     "email": "ada@appwrite.io",
//!     "extraInternalField": "not part of the model",
//! });
//!
//! let filtered = dynamic(&doc, MODEL_USER);
//! assert_eq!(filtered["name"], "Ada");
//! assert!(filtered.get("extraInternalField").is_none());
//! ```

mod dynamic;
mod model;

pub use dynamic::dynamic;
pub use model::{list_spec, spec, ListSpec, ModelDef, ModelSpec, Rule, RuleType};
pub use model::{
    MODEL_ERROR, MODEL_IDENTITY, MODEL_IDENTITY_LIST, MODEL_JWT, MODEL_MEMBERSHIP,
    MODEL_MEMBERSHIP_LIST, MODEL_MFA_CHALLENGE_SECRET, MODEL_MFA_FACTORS, MODEL_MFA_RECOVERY_CODES,
    MODEL_NONE, MODEL_PREFERENCES, MODEL_SESSION, MODEL_SESSION_LIST, MODEL_TARGET,
    MODEL_TARGET_LIST, MODEL_TOKEN, MODEL_USER, MODEL_USER_LIST,
};
