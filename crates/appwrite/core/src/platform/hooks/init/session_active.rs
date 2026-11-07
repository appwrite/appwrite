//! One session at a time (PHP: `app/controllers/shared/api.php`, the
//! `session` group's init hook; hooks.json `session-active`).

use crate::Result;
use crate::error::{Error, ErrorType};
use crate::platform::context::Context;

/// The `session` group: a session is not created for a caller who has one,
/// unless the route is labelled `session.allowActive`.
pub(in crate::platform) fn session_active(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("session") || ctx.route.labels.session_allow_active {
        return Ok(());
    }
    if ctx.user.is_some() {
        return Err(Error::new(ErrorType::UserSessionAlreadyExists));
    }
    Ok(())
}
