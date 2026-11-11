//! Authentication methods (PHP: `app/controllers/shared/api/auth.php`, the
//! `auth` group's init hook; hooks.json `auth-methods`).

use serde_json::Value;

use crate::Result;
use crate::auth::roles;
use crate::error::{Error, ErrorType};
use crate::platform::context::Context;

/// The `auth` group: the console refuses denied countries, and the project's
/// disabled authentication methods are refused to all but keys and
/// privileged users.
pub(in crate::platform) async fn auth_methods(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("auth") {
        return Ok(());
    }
    let denylist = &ctx.state.config.console_countries_denylist;
    if !denylist.is_empty() && ctx.project.is_console() {
        let country = ctx.state.geo.country_code(&ctx.ip).await.to_uppercase();
        if denylist.contains(&country) {
            return Err(Error::new(ErrorType::GeneralRegionAccessDenied));
        }
    }
    if roles::is_key(&ctx.roles) || roles::is_privileged(&ctx.roles) {
        return Ok(());
    }
    // `app/config/auth.php`: the method, its `auths` key, the default and
    // the message when it is off.
    let (key, default, name) = match ctx.route.labels.auth_type {
        "email-password" => ("emailPassword", true, "Email / Password"),
        "magic-url" => ("usersAuthMagicURL", true, "Magic URL"),
        "anonymous" => ("anonymous", true, "Anonymous"),
        "phone" => ("phone", true, "Phone"),
        "invites" => ("invites", true, "Invites"),
        "jwt" => ("JWT", true, "JWT"),
        "email-otp" => ("emailOtp", true, "Email OTP"),
        "passkey" => ("passkey", false, "Passkey"),
        _ => {
            return Err(Error::with_message(ErrorType::UserAuthMethodUnsupported, "Unsupported authentication route"));
        }
    };
    // PHP refuses `=== false`, and a passkey `!== true`.
    let value = ctx.project.auths.get(key);
    let refused = if default { value == Some(&Value::Bool(false)) } else { value != Some(&Value::Bool(true)) };
    if refused {
        return Err(Error::with_message(
            ErrorType::UserAuthMethodUnsupported,
            format!("{name} authentication is disabled for this project"),
        ));
    }
    Ok(())
}
