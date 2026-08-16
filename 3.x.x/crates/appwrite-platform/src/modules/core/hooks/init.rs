//! `api` group `Init` hook. Rust port of the project/API-key resolution half
//! of `app/controllers/shared/api.php`'s `Http::init()`.
//!
//! Simplifications versus PHP (documented, not silently dropped):
//! - Only the `standard` API key type is supported (`Appwrite\Auth\Key`'s
//!   `dynamic`/`jwt` cases -- session cookies, session headers, and JWTs --
//!   are not wired into this hook yet; see `crates/appwrite-platform/README.md`
//!   TODOs). Every `/v1/users*` route requires a `users.read`/`users.write`
//!   scope, so an unauthenticated (guest) key always fails the scope check
//!   below, which is the correct behavior for this module even without the
//!   other key types.
//! - No abuse/rate-limiting, mode (`X-Appwrite-Mode`) resolution, or usage
//!   stats gate -- out of scope for the Users-API v1 milestone.

use std::sync::Arc;

use appwrite_auth::Key;
use appwrite_exception::Exception;
use serde_json::Value;
use utopia_di::Resource;
use utopia_platform::{Action, ActionType};

use crate::state::AppwriteState;

use super::send_error;

#[must_use]
pub fn action() -> Action {
    Action::new()
        .set_type(ActionType::Init)
        .groups(["api"])
        .inject("appwriteState")
        .expect("appwriteState is a single, non-duplicate injection")
        .http_action(|ctx| async move {
            let state = match ctx.container.get_as::<Arc<AppwriteState>>("appwriteState") {
                Ok(state) => state,
                Err(_) => {
                    return send_error(&ctx, &Exception::new(Exception::GENERAL_SERVER_ERROR));
                }
            };

            let project_id = project_id_from_request(&ctx);
            if project_id.is_empty() {
                return send_error(&ctx, &Exception::new(Exception::PROJECT_NOT_FOUND));
            }

            let Some(project) = state.projects.get(&project_id) else {
                return send_error(&ctx, &Exception::new(Exception::PROJECT_NOT_FOUND));
            };

            let key_secret = ctx.request().header_line("x-appwrite-key");
            let key = if key_secret.is_empty() {
                Key::guest(project_id.clone())
            } else {
                Key::decode_standard(&project, &key_secret)
            };

            if key.expired {
                return send_error(&ctx, &Exception::new(Exception::PROJECT_KEY_EXPIRED));
            }

            if let Some(required) = required_scope(&ctx) {
                if !key_satisfies_scope(&key, &required) {
                    return send_error(
                        &ctx,
                        &Exception::new(Exception::GENERAL_UNAUTHORIZED_SCOPE),
                    );
                }
            }

            let db = state.databases.get_or_create(&project_id);

            ctx.container.set_cached("project", Resource::new(project));
            ctx.container.set_cached("apiKey", Resource::new(key));
            ctx.container.set_cached("dbForProject", Resource::new(db));

            Ok(())
        })
}

/// PHP: `$request->getHeader('x-appwrite-project', $request->getParam('project', ''))`.
fn project_id_from_request(ctx: &utopia_http::ActionContext) -> String {
    let header = ctx.request().header_line("x-appwrite-project");
    if !header.is_empty() {
        return header;
    }
    ctx.request()
        .param_ref("project")
        .and_then(Value::as_str)
        .unwrap_or_default()
        .to_string()
}

/// The matched route's `scope` label, PHP `$route->getLabel('scope', '')`.
/// Users endpoints set either a single scope (`"users.write"`) or a list
/// (`Sessions` endpoints: `["users.write", "sessions.write"]`, matching PHP's
/// `in_array($scope, $roles)` check against whichever the route declares).
fn required_scope(ctx: &utopia_http::ActionContext) -> Option<Value> {
    let route = ctx.route.as_ref()?;
    let label = route.hook_meta().get_label("scope", Value::Null);
    if label.is_null() {
        None
    } else {
        Some(label)
    }
}

fn key_satisfies_scope(key: &Key, required: &Value) -> bool {
    match required {
        Value::String(scope) => key.scopes.iter().any(|s| s == scope),
        Value::Array(scopes) => scopes.iter().any(|scope| {
            scope
                .as_str()
                .is_some_and(|scope| key.scopes.iter().any(|s| s == scope))
        }),
        _ => false,
    }
}
