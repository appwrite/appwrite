//! The auth hook's console membership (PHP: `app/controllers/shared/api.php`,
//! the `api` group's auth hook: the admin roles of the `team` resource).

use utopia_database::Database;

use crate::Result;
use crate::platform::context::{Context, Mode};
use crate::platform::hooks::resources::team::load_team;

/// The team whose membership roles an admin request takes: on the console,
/// the organization the route acts on (the `team` resource, when there is
/// one); elsewhere, in admin mode, the project's organization (`""` when it
/// has none, which no membership matches). `None` when no admin roles
/// apply.
pub(super) async fn admin_team(ctx: &Context, platform_db: &Database) -> Result<Option<String>> {
    if ctx.user.is_none() {
        return Ok(None);
    }
    if ctx.project.is_console() {
        return Ok(load_team(platform_db, ctx).await?.map(|(id, _)| id));
    }
    if ctx.mode != Mode::Admin {
        return Ok(None);
    }
    Ok(Some(load_team(platform_db, ctx).await?.map(|(id, _)| id).unwrap_or_default()))
}
