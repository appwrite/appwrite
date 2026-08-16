pub mod console;
pub mod error;
pub mod init;
pub mod shutdown;

use appwrite_exception::Exception;
use utopia_http::ActionContext;

/// Shared error-response writer: PHP's `Response::dynamic($error, Response::MODEL_ERROR)`
/// send in `app/http.php`'s error handler. Falls back to `500` if `error.code()`
/// is not one of [`utopia_http::response::StatusCode`]'s known values (e.g. a
/// catalog entry using `426`/`451`, which `Utopia\Http`'s Swoole/Hyper response
/// never had to special-case because PHP sets the status without an allow-list).
pub(crate) fn send_error(ctx: &ActionContext, error: &Exception) -> utopia_http::Result<()> {
    if ctx.response().set_status(error.code()).is_err() {
        let _ = ctx.response().set_status(500);
    }
    ctx.response().json(&error.to_json())
}
