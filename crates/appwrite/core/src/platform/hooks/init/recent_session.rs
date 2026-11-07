//! Recent sign-in (PHP: `app/controllers/shared/api/auth.php`, the
//! `recentSession` group's init hook; hooks.json `recent-session`).

use crate::error::{Error, ErrorType};
use crate::platform::context::Context;
use crate::{Result, datetime};

/// `SESSION_RECENT_DURATION`: a sign-in (or MFA challenge) stays recent this long.
const SESSION_RECENT: i64 = 600;

/// The `recentSession` group: a session created, or MFA-verified, within
/// `SESSION_RECENT_DURATION`. Guests are left to the scope check.
pub(in crate::platform) fn recent_session(ctx: &Context) -> Result<()> {
    if !ctx.route.has_group("recentSession") {
        return Ok(());
    }
    let Some(session) = ctx.session.as_ref() else { return Ok(()) };
    let recent = datetime::add_seconds(datetime::now(), -SESSION_RECENT);
    let signed_in = session.created_at.is_some_and(|at| at >= recent);
    let verified = session.mfa_updated_at.is_some_and(|at| at >= recent);
    if !signed_in && !verified {
        return Err(Error::new(ErrorType::UserReauthenticationRequired));
    }
    Ok(())
}
