//! `api` group `Shutdown` hook. Rust port of the audit-logging slice of
//! `app/controllers/shared/api.php`'s `Http::shutdown()`.
//!
//! Simplifications versus PHP (documented, not silently dropped): session
//! limiting, Realtime/webhook/function event dispatch, abuse counter resets,
//! and response caching are not implemented -- only the `audits.event`
//! enqueue, since it is the one piece test/observability of the Users API
//! depends on. `publisherForAudits` is resolved from the *global* container
//! (bound once in `apps/server`'s `main()`), not per-request state; only
//! `project`/`apiKey` need the per-request container the `Init` hook wrote.

use std::sync::Arc;

use appwrite_event::{AuditMessage, AuditPublisher};
use serde_json::Value;
use utopia_platform::{Action, ActionType};

#[must_use]
pub fn action() -> Action {
    Action::new()
        .set_type(ActionType::Shutdown)
        .groups(["api"])
        // Any injection forces `utopia-http` to hand this hook the
        // request-scoped container the `Init` hook wrote `project`/`apiKey`
        // into (see `utopia-http/src/http.rs` `build_context`), rather than
        // the global one shared by every concurrent request.
        .inject("appwriteState")
        .expect("appwriteState is a single, non-duplicate injection")
        .http_action(|ctx| async move {
            let Some(route) = ctx.route.clone() else {
                return Ok(());
            };
            let event = route.hook_meta().get_label("audits.event", Value::Null);
            let Some(event) = event.as_str().filter(|s| !s.is_empty()) else {
                return Ok(());
            };

            let Ok(publisher) = ctx
                .container
                .get_as::<Arc<dyn AuditPublisher>>("publisherForAudits")
            else {
                return Ok(());
            };
            let project = ctx.container.get_as::<Value>("project").ok();

            let resource_template = route
                .hook_meta()
                .get_label("audits.resource", Value::Null)
                .as_str()
                .unwrap_or_default()
                .to_string();
            let resource = substitute_template(&resource_template, &ctx);

            let message = AuditMessage::new(event.to_string(), Value::Null)
                .with_resource(resource)
                .with_ip(ctx.request().ip())
                .with_user_agent(ctx.request().header_line("user-agent"));
            let message = if let Some(project) = project {
                message.with_project(project)
            } else {
                message
            };

            let _ = publisher.enqueue(message);
            Ok(())
        })
}

/// Expands `user/{request.userId}` / `user/{response.$id}` style templates
/// (PHP `Audit::getResource()`'s `{request.*}`/`{response.*}` substitution)
/// using the matched route's path params. Response-field substitution
/// (`{response.$id}`) is not available here -- the body has already been
/// serialized to bytes by the time `Shutdown` hooks run -- so those
/// placeholders resolve to an empty string; this is a documented gap versus
/// PHP (which reads the still-live `Document` response object).
fn substitute_template(template: &str, ctx: &utopia_http::ActionContext) -> String {
    let mut result = template.to_string();
    for (key, value) in &ctx.params {
        let text = value.as_str().map(str::to_string).unwrap_or_default();
        result = result.replace(&format!("{{request.{key}}}"), &text);
    }
    if result.contains("{response.$id}") {
        result = result.replace("{response.$id}", "");
    }
    result
}
