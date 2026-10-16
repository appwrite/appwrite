//! Global `Error` hook. Rust port of `app/http.php`'s catch-all exception
//! handler: maps whatever failed (missing/invalid param, unmatched route,
//! an action's own `Err`) into the same `Response::MODEL_ERROR` JSON shape
//! `send_error` uses for hand-written [`appwrite_exception::Exception`]s.
//!
//! Runs for every request regardless of route group (`groups(["*"])`,
//! `include_global` is always `true` for error hooks in `utopia-http`) --
//! PHP's handler is likewise registered without a `groups()` filter.

use appwrite_exception::Exception;
use utopia_http::HttpError;
use utopia_platform::{Action, ActionType};

use super::send_error;

#[must_use]
pub fn action() -> Action {
    Action::new()
        .set_type(ActionType::Error)
        .groups(["*"])
        .http_action(|ctx| async move {
            let exception = match ctx.error() {
                Some(HttpError::MissingParam(key)) => Exception::with_message(
                    Exception::GENERAL_ARGUMENT_INVALID,
                    format!("Param \"{key}\" is not optional."),
                ),
                Some(HttpError::InvalidParam { key, description }) => Exception::with_message(
                    Exception::GENERAL_ARGUMENT_INVALID,
                    format!("Invalid `{key}` param: {description}"),
                ),
                Some(HttpError::App {
                    status: 404,
                    message,
                }) => Exception::with_message(Exception::GENERAL_ROUTE_NOT_FOUND, message.clone())
                    .with_code(404),
                Some(other) => {
                    Exception::with_message(Exception::GENERAL_SERVER_ERROR, other.to_string())
                        .with_code(other.status())
                }
                None => Exception::new(Exception::GENERAL_UNKNOWN),
            };

            send_error(&ctx, &exception)
        })
}
