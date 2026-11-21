//! Error responses (PHP: `app/controllers/general.php`, `Http::error()`;
//! hooks.json `error`): the HTML error page of routes labelled `error`
//! ([`page`]), else the error JSON. The CORS and no-cache headers are added
//! when the response is sent ([`crate::platform::lifecycle`]'s `finalize`).

mod page;

use http::StatusCode;
use serde_json::Value;
use utopia_http::Response;

use crate::VERSION;
use crate::error::Error;
use crate::platform::State;
use crate::platform::context::Context;
use crate::response::ErrorModel;

/// The response of an error the request failed with.
pub(in crate::platform) fn error(ctx: &Context, error: Error) -> Response {
    let state = &ctx.state;
    // Routes labelled `error` show browsers the error page; SDKs send
    // `accept: text/html` too, so only requests without `x-sdk-name`.
    if ctx.route.labels.error_page && ctx.request.header_or_empty("x-sdk-name").is_empty() {
        if error.status() >= 500 {
            tracing::error!(error = %error, location = %error.location, "request failed");
        }
        let url = if state.config.development {
            format!("{}://localhost", state.config.link_scheme)
        } else {
            let host = state.config.platform.get("consoleHostname").and_then(Value::as_str).unwrap_or("");
            format!("{}://{host}", state.config.link_scheme)
        };
        let html = page::render(
            &ctx.project.name,
            error.public_message(),
            error.kind.name(),
            error.status(),
            state.config.development,
            &url,
        );
        Response::with_body(
            StatusCode::from_u16(error.status()).unwrap_or(StatusCode::INTERNAL_SERVER_ERROR),
            "text/html; charset=UTF-8",
            html,
        )
    } else {
        error_response(state, &error)
    }
}

/// Error JSON (`general.php` error hook).
pub(in crate::platform) fn error_response(state: &State, error: &Error) -> Response {
    let status = error.status();
    if status >= 500 {
        tracing::error!(error = %error, location = %error.location, "request failed");
    }
    let model = ErrorModel {
        message: error.public_message(),
        code: status,
        kind: error.kind.name(),
        version: VERSION,
        dev: if state.config.development { Some((error.location.file(), error.location.line())) } else { None },
    };
    Response::json_bytes(
        StatusCode::from_u16(status).unwrap_or(StatusCode::INTERNAL_SERVER_ERROR),
        crate::json::to_vec(&model),
    )
}
