//! Recent MFA challenge (PHP: `app/controllers/shared/api/auth.php`, the
//! `mfaProtected` group's init hook; hooks.json `mfa-fresh`).

use crate::error::{Error, ErrorType};
use crate::platform::context::Context;
use crate::{Result, datetime};

/// `MFA_RECENT_DURATION`: an MFA challenge stays fresh this long.
const MFA_RECENT: i64 = 1800;

/// The `mfaProtected` group: an MFA challenge completed within
/// `MFA_RECENT_DURATION`.
pub(in crate::platform) fn mfa_fresh(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("mfaProtected") {
        return Ok(());
    }
    let now = datetime::now();
    let fresh = ctx
        .session
        .as_ref()
        .and_then(|s| s.mfa_updated_at)
        .is_some_and(|at| datetime::add_seconds(at, MFA_RECENT) >= now);
    if !fresh {
        return Err(Error::new(ErrorType::UserChallengeRequired));
    }
    Ok(())
}
