//! No admin mode on the console (PHP: `app/controllers/general.php`, the
//! init hook's "Admin mode is not allowed for console project"; hooks.json
//! `admin-console`).

use crate::Result;
use crate::error::{Error, ErrorType};
use crate::platform::context::{Context, Mode};
use crate::platform::hooks::resources::user::session_from_request;

/// Refuses admin mode for the console project.
pub(in crate::platform) fn admin_console(ctx: &mut Context) -> Result<()> {
    if ctx.mode == Mode::Admin && ctx.project_found && ctx.project.is_console() {
        session_from_request(ctx);
        return Err(Error::with_message(ErrorType::GeneralBadRequest, "Admin mode is not allowed for console project"));
    }
    Ok(())
}
