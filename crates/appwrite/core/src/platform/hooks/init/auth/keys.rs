//! The auth hook's API key usage tracking (PHP:
//! `app/controllers/shared/api.php`, the `api` group's auth hook: a key's
//! `accessedAt` and `sdks`).

use utopia_database::Document;
use utopia_database::value::list;

use crate::database::Databases;
use crate::datetime;
use crate::platform::context::Context;

/// Who owns a key: the project (standard keys), a console user (account
/// keys) or the project's organization (organization keys), with its
/// internal id for the lock.
pub(super) enum KeyOwner {
    Project,
    User { id: String, sequence: String },
    Team { id: String, sequence: String },
}

/// Updates `accessedAt` / `sdks` of a stored key (once per 24h or new SDK),
/// before the action as in PHP: the update and its cache purges are done
/// by the time the response is sent.
pub(super) async fn track_key_usage(ctx: &Context, owner: KeyOwner, key: crate::database::project::Key) {
    const SERVERS: [&str; 13] = [
        "node.js",
        "php",
        "python",
        "ruby",
        "go",
        ".net",
        "dart",
        "kotlin",
        "swift",
        "rust",
        "graphql",
        "rest",
        "terraform",
    ];
    let now = datetime::now();
    let stale = key.accessed_at.map(|a| a < datetime::add_seconds(now, -86_400)).unwrap_or(true);
    let sdk = ctx.request.header_or_empty("x-sdk-name").to_lowercase();
    let new_sdk = !sdk.is_empty() && SERVERS.contains(&sdk.as_str()) && !key.sdks.contains(&sdk);
    if !stale && !new_sdk {
        return;
    }
    let state = ctx.state.clone();
    let project = ctx.project.clone();
    let Ok(db) = state.databases.platform(Databases::unchecked()) else { return };
    let owner_sequence = match &owner {
        KeyOwner::Project => project.sequence.clone(),
        KeyOwner::User { sequence, .. } | KeyOwner::Team { sequence, .. } => sequence.clone(),
    };
    let lock_key = format!("lock:platform:{owner_sequence}:keys:{}", key.id);
    let state2 = state.clone();
    let _ = state
        .lock
        .try_with_key(&lock_key, async move {
            let mut updates = Document::default();
            updates.put("accessedAt", datetime::value(&now));
            if new_sdk {
                let mut sdks = key.sdks.clone();
                sdks.push(sdk);
                updates.put("sdks", list(sdks.iter().map(|s| utopia_database::Value::from(s.as_str()))));
            }
            match db.update_document("keys", &key.id, updates).await {
                Ok(_) => {
                    // The document whose `keys` sub-query holds the key.
                    let (collection, id) = match &owner {
                        KeyOwner::Project => ("projects", project.id.as_str()),
                        KeyOwner::User { id, .. } => ("users", id.as_str()),
                        KeyOwner::Team { id, .. } => ("teams", id.as_str()),
                    };
                    let purged = db.authorization().skip(db.purge_cached_document(collection, id)).await;
                    if let Err(e) = purged {
                        tracing::warn!(error = %e, collection, "key owner cache purge failed");
                    }
                    if matches!(owner, KeyOwner::Project) {
                        state2.projects.forget(&project.id);
                    }
                }
                Err(e) => tracing::warn!(error = %e, "key usage update failed"),
            }
        })
        .await;
}
