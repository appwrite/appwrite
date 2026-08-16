//! Shared HTTP-layer helpers for user property updates. Not a PHP action class.

use serde_json::json;
use utopia_platform::Action;
use utopia_validators::Text;

/// Common `userId` route param wired on most `PATCH`/`PUT` user property endpoints.
pub(crate) fn user_id_param(action: Action) -> Action {
    action.param("userId", json!(""), Text::new(36), "User ID.", false)
}
