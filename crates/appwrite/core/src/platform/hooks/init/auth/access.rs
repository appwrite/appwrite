//! The auth hook's access times (PHP: `app/controllers/shared/api.php`, the
//! `api` group's auth hook: the project's and the user's `accessedAt`).

use utopia_database::Document;

use crate::database::{Databases, filters};
use crate::datetime;
use crate::platform::context::{Context, Mode};

/// Updates project and user `accessedAt` (once per 24h), without the
/// sub-queries (`updateDocument` returns the document decoded, and nothing
/// reads it).
pub(super) async fn track_access(ctx: &mut Context) {
    let now = datetime::now();
    let threshold = datetime::add_seconds(now, -86_400);
    let state = ctx.state.clone();
    if !ctx.project.is_console() && ctx.project.accessed_at.map(|a| a < threshold).unwrap_or(true) {
        let project = ctx.project.clone();
        let state = state.clone();
        utopia_async::spawn(async move {
            let Ok(db) = state.databases.platform(Databases::unchecked()) else { return };
            let key = format!("lock:platform:projects:{}:accessedAt", project.id);
            let state2 = state.clone();
            let _ = state
                .lock
                .try_with_key(&key, async move {
                    let mut updates = Document::default();
                    updates.put("accessedAt", datetime::value(&now));
                    let skipped = filters::names(filters::PROJECTS_SUBQUERIES);
                    let updated = db
                        .skip_filters(Some(skipped.as_slice()), db.update_document("projects", &project.id, updates))
                        .await;
                    match updated {
                        Ok(_) => state2.projects.forget(&project.id),
                        Err(e) => tracing::warn!(error = %e, "project access update failed"),
                    }
                })
                .await;
        });
    }
    let Some(user) = &ctx.user else { return };
    if ctx.target_user.is_some() || user.accessed_at.map(|a| a >= threshold).unwrap_or(false) {
        return;
    }
    let user_id = user.id.clone();
    let user_seq = user.sequence;
    let (db, lock_key) = if !ctx.project.is_console() && ctx.mode != Mode::Admin {
        match state.databases.project(&ctx.project, Databases::unchecked()) {
            Ok(db) => (db, format!("lock:project:{}:users:{}:accessedAt", ctx.project.sequence, user_seq)),
            Err(_) => return,
        }
    } else {
        match state.databases.platform(Databases::unchecked()) {
            Ok(db) => (db, format!("lock:platform:{user_seq}:users:{user_id}:accessedAt")),
            Err(_) => return,
        }
    };
    // Before the action, as in PHP: an action that writes the user returns
    // it with its access time, and the action reads it too.
    let _ = state
        .lock
        .try_with_key(&lock_key, async move {
            let mut updates = Document::default();
            updates.put("accessedAt", datetime::value(&now));
            let skipped = filters::names(filters::USERS_SUBQUERIES);
            let updated =
                db.skip_filters(Some(skipped.as_slice()), db.update_document("users", &user_id, updates)).await;
            if let Err(e) = updated {
                tracing::warn!(error = %e, "user access update failed");
            }
        })
        .await;
    if let Some(user) = ctx.user.as_mut() {
        user.accessed_at = Some(datetime::truncate(now));
    }
}
