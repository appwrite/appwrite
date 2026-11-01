//! Project services refuse the console (PHP: `app/controllers/general.php`,
//! the init hook of the `database`, `functions`, `sites` and `messaging`
//! groups; hooks.json `console-forbidden`).

use crate::Result;
use crate::database::Project;
use crate::error::{Error, ErrorType};
use crate::platform::context::Context;

/// The groups of the project services the console has none of.
pub(in crate::platform) const CONSOLE_FORBIDDEN: [&str; 4] = ["database", "functions", "sites", "messaging"];

/// `general.php`'s init hook for the `database`, `functions`, `sites` and
/// `messaging` groups: the console project has no databases, functions,
/// sites or messaging, and a request that names no project is told so.
pub(in crate::platform) fn console_forbidden(ctx: &Context) -> Result<()> {
    refuse_console(ctx.route.groups, &ctx.project.id, ctx.request.header_or_empty("x-appwrite-project"))
}

/// [`console_forbidden`] for a route's groups, the project and the
/// `X-Appwrite-Project` header.
fn refuse_console(groups: &[&str], project_id: &str, header: &str) -> Result<()> {
    if !groups.iter().any(|g| CONSOLE_FORBIDDEN.contains(g)) || project_id != Project::CONSOLE {
        return Ok(());
    }
    let message = if header.is_empty() {
        "No Appwrite project was specified. Please specify your project ID when initializing your Appwrite SDK."
    } else {
        "This endpoint is not available for the console project. The Appwrite Console is a reserved project ID and cannot be used with the Appwrite SDKs and APIs. Please check if your project ID is correct."
    };
    Err(Error::with_message(ErrorType::GeneralAccessForbidden, message))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn console_is_refused_by_project_services() {
        assert!(refuse_console(&["api", "users"], Project::CONSOLE, "").is_ok());
        assert!(refuse_console(&["api", "database"], "project1", "project1").is_ok());
        let missing = refuse_console(&["api", "database"], Project::CONSOLE, "").unwrap_err();
        assert_eq!(missing.kind, ErrorType::GeneralAccessForbidden);
        assert!(missing.public_message().starts_with("No Appwrite project was specified."));
        let console = refuse_console(&["api", "messaging"], Project::CONSOLE, "console").unwrap_err();
        assert!(console.public_message().starts_with("This endpoint is not available for the console project."));
        for group in CONSOLE_FORBIDDEN {
            assert!(refuse_console(&[group], Project::CONSOLE, "").is_err());
        }
    }
}
