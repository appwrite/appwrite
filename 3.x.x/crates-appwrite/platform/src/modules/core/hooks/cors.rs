//! CORS + security-header hooks. Rust port of the CORS half of
//! `app/controllers/general.php`'s `Http::init()` (groups `api`/`web`/`graphql`),
//! `Http::options()`, and the error-handler CORS re-apply.
//!
//! Utopia skips init/shutdown on OPTIONS, so preflight CORS lives here rather
//! than on the `api` init hook -- matching PHP.

use std::sync::Arc;

use appwrite_exception::Exception;
use appwrite_network::{
    allowed_hostnames, get_schemes, platform_hostnames, platform_schemes, AllowedHostnames, Cors,
    Origin,
};
use serde_json::Value;
use utopia_http::ActionContext;
use utopia_platform::{Action, ActionType};

use crate::state::AppwriteState;

use super::{project_id_from_request, send_error};

/// PHP `Http::init()` CORS / security headers (`groups(['api', 'web', 'graphql'])`).
#[must_use]
pub fn init() -> Action {
    Action::new()
        .set_type(ActionType::Init)
        .groups(["api", "web", "graphql"])
        .inject("appwriteState")
        .expect("appwriteState is a single, non-duplicate injection")
        .http_action(|ctx| async move {
            apply_cors(&ctx);

            ctx.response()
                .add_header("Server", "Appwrite")
                .add_header("X-Content-Type-Options", "nosniff");
            if ctx.request().protocol() == "https" {
                ctx.response()
                    .add_header("Strict-Transport-Security", "max-age=10886400");
            }

            enforce_origin(&ctx)
        })
}

/// PHP `Http::options()`: CORS headers + `204 No Content`.
#[must_use]
pub fn options() -> Action {
    Action::new()
        .set_type(ActionType::Options)
        .groups(["*"])
        .inject("appwriteState")
        .expect("appwriteState is a single, non-duplicate injection")
        .http_action(|ctx| async move {
            apply_cors(&ctx);
            ctx.response().add_header("Server", "Appwrite");
            ctx.response().no_content()
        })
}

/// PHP error handler CORS re-apply so browsers can read the error body.
pub(crate) fn apply_cors(ctx: &ActionContext) {
    let origin = ctx.request().origin();
    let (hosts, _) = hosts_and_project(ctx);
    let Ok(cors) = Cors::from_allowed_hosts(hosts) else {
        return;
    };
    for (name, value) in cors.headers(&origin) {
        ctx.response().remove_header(&name).add_header(&name, value);
    }
}

/// Resolve allow-list + optional project so init, OPTIONS, and error share
/// the PHP `allowedHostnames` resource's inputs.
fn hosts_and_project(ctx: &ActionContext) -> (Vec<String>, Option<Value>) {
    let platform = platform_hostnames();
    let project_id = project_id_from_request(ctx);
    let project = if project_id.is_empty() {
        None
    } else {
        ctx.container.get_as::<Value>("project").ok().or_else(|| {
            ctx.container
                .get_as::<Arc<AppwriteState>>("appwriteState")
                .ok()
                .and_then(|state| state.resolve_project(&project_id))
        })
    };
    let platforms = project
        .as_ref()
        .and_then(|p| p.get("platforms"))
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default();
    let origin = ctx.request().origin();
    let referer = ctx.request().referer();
    let request_hostname = ctx.request().hostname();
    let hosts = allowed_hostnames(AllowedHostnames {
        platform_hostnames: &platform,
        project_id: if project_id.is_empty() {
            None
        } else {
            Some(project_id.as_str())
        },
        project_platforms: &platforms,
        has_dev_key: !ctx.request().header_line("x-appwrite-dev-key").is_empty(),
        request_hostname: &request_hostname,
        origin: &origin,
        referer: &referer,
        is_options: ctx.request().method() == "OPTIONS",
        rule_domain: None,
    });
    (hosts, project)
}

/// PHP application-level CSRF after CORS headers are set.
fn enforce_origin(ctx: &ActionContext) -> utopia_http::Result<()> {
    let origin = ctx.request().origin();
    if origin.is_empty()
        || !ctx.request().header_line("x-appwrite-dev-key").is_empty()
        || !ctx.request().header_line("x-appwrite-key").is_empty()
    {
        return Ok(());
    }
    if ctx.route.as_ref().is_some_and(|route| {
        route
            .hook_meta()
            .get_label("origin", Value::Bool(false))
            .as_str()
            == Some("*")
    }) {
        return Ok(());
    }

    let (hosts, project) = hosts_and_project(ctx);
    let mut schemes = platform_schemes();
    if let Some(project) = &project {
        let id = project.get("$id").and_then(Value::as_str).unwrap_or("");
        if !id.is_empty() && id != "console" {
            let platforms = project
                .get("platforms")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            for scheme in get_schemes(&platforms) {
                if !schemes.iter().any(|s| s == &scheme) {
                    schemes.push(scheme);
                }
            }
        }
    }
    let mut validator = Origin::new(hosts, schemes);
    if validator.is_valid(&origin) {
        return Ok(());
    }
    send_error(
        ctx,
        &Exception::with_message(Exception::GENERAL_UNKNOWN_ORIGIN, validator.description()),
    )
}
