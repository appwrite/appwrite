//! The session limit (PHP: `app/controllers/shared/api.php`, the `session`
//! group's shutdown hook reading `maxSessions`; hooks.json `session-limit`).

use serde_json::Value;

use super::Job;
use crate::database::Databases;

impl Job {
    /// The `session` group's limit: the user's oldest sessions beyond the
    /// project's `maxSessions` are deleted.
    pub(super) async fn session_limit(&self) {
        let Some(user_id) = &self.session_user else { return };
        let limit = self.project.auths.get("maxSessions").and_then(Value::as_i64).unwrap_or(10);
        if limit == 0 {
            return;
        }
        let Ok(db) = self.state.databases.project(&self.project, Databases::unchecked()) else { return };
        let Ok(Some(user)) = crate::database::documents::load_user(&db, user_id).await else { return };
        let excess = user.sessions.len() as i64 - limit;
        if excess <= 0 {
            return;
        }
        for session in user.sessions.iter().take(excess as usize) {
            if let Err(e) = db.delete_document("sessions", &session.id).await {
                tracing::warn!(error = %e, "session limit delete failed");
            }
        }
        let _ = db.purge_cached_document("users", user_id).await;
    }
}
