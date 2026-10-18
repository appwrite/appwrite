//! CORS preflight (PHP: `app/controllers/general.php`, `Http::options()`;
//! hooks.json `preflight`).

use std::sync::Arc;

use utopia_http::{Request, Response};

use crate::database::Project;
use crate::network;
use crate::platform::State;
use crate::platform::hooks::resources::hostnames::allowed_hostnames;
use crate::platform::hooks::resources::user::session_store;

/// Answers `OPTIONS` with the CORS headers and 204.
pub(in crate::platform) fn preflight(state: &Arc<State>, request: &Request) -> Response {
    let mut response = Response::no_content_response();
    let console = state.projects.console();
    let allowed = allowed_hostnames(state, &console, true, request, None);
    for (k, v) in network::cors_headers(request.header_or_empty("origin"), &allowed) {
        response.add(k, &v);
    }
    response.set("server", "Appwrite");
    // `noContent()` sends the default content type.
    response.set("content-type", "text/html");
    // The user resource the handler's injections resolve: a session from the
    // project's cookie (the console's without one), else the fallback.
    let project = request.query_value("project").filter(|p| !p.is_empty()).unwrap_or(Project::CONSOLE);
    let cookie = format!("a_session_{project}");
    let session = request
        .cookie(&cookie)
        .or_else(|| request.cookie(&format!("{cookie}_legacy")))
        .or_else(|| request.header("x-appwrite-session"))
        .map(session_store)
        .is_some_and(|(id, secret)| !id.is_empty() || !secret.is_empty());
    response.add("x-debug-fallback", "false");
    if !session {
        response.add("x-debug-fallback", "true");
    }
    response
}
