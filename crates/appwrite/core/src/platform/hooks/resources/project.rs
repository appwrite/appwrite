//! The `project` resource (PHP: `app/init/resources/request.php`,
//! `$context->set('project'`; hooks.json `resource-project`).

use std::sync::Arc;

use serde_json::Value;
use utopia_database::Database;

use crate::Result;
use crate::database::Project;
use crate::platform::context::Context;

/// The `project` resource for the `api` group: the project the request
/// names (`project` param, `X-Appwrite-Project`, then the query), the
/// console's when it names none. Returns the project the request supplied
/// (the mode compares it with the one resolved).
pub(in crate::platform) async fn resolve(
    ctx: &mut Context,
    platform_db: &Database,
    header_project: &str,
) -> Result<String> {
    let state = ctx.state.clone();
    // -- project -----------------------------------------------------------
    let supplied_project = match ctx.request.params().get("project") {
        Some(Value::String(s)) => s.clone(),
        _ => header_project.to_owned(),
    };
    let mut project_id = supplied_project.clone();
    if project_id.is_empty() {
        project_id = ctx.request.query_value("project").unwrap_or("").to_owned();
    }
    if project_id.is_empty() || project_id == Project::CONSOLE {
        ctx.project = state.projects.console();
    } else {
        match state.projects.get(platform_db, &project_id).await? {
            Some(p) => ctx.project = p,
            None => {
                ctx.project = Arc::new(Project::empty());
                ctx.project_found = false;
            }
        }
    }
    Ok(supplied_project)
}

/// The `project` resource alone (for routes outside the `api` group).
pub(in crate::platform) async fn resolve_alone(ctx: &mut Context) {
    let Ok(platform_db) = ctx.platform_db() else { return };
    let project_id = match ctx.request.params().get("project") {
        Some(Value::String(s)) => s.clone(),
        _ => ctx.request.header_or_empty("x-appwrite-project").to_owned(),
    };
    let project_id =
        if project_id.is_empty() { ctx.request.query_value("project").unwrap_or("").to_owned() } else { project_id };
    if project_id.is_empty() || project_id == Project::CONSOLE {
        return;
    }
    match ctx.state.projects.get(&platform_db, &project_id).await {
        Ok(Some(p)) => ctx.project = p,
        _ => {
            ctx.project = Arc::new(Project::empty());
            ctx.project_found = false;
        }
    }
}
