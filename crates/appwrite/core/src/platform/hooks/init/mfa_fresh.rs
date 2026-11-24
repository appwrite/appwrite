//! Recent MFA challenge (PHP: `app/controllers/shared/api/auth.php`, the
//! `mfaProtected` group's init hook; hooks.json `mfa-fresh`).

use crate::Result;
use crate::error::{Error, ErrorType};
use crate::platform::context::Context;

/// The `mfaProtected` group: an MFA challenge completed within
/// `MFA_RECENT_DURATION`.
pub(in crate::platform) fn mfa_fresh(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("mfaProtected") {
        return Ok(());
    }
    if !ctx.session.as_ref().is_some_and(|s| s.challenge_recent()) {
        return Err(Error::new(ErrorType::UserChallengeRequired));
    }
    Ok(())
}
