//! Shared domain steps of the Teams module.

use utopia_database::helpers::permission::Permission;
use utopia_database::helpers::role::Role;
use utopia_database::query::Method;
use utopia_database::validator::query::cursor::Cursor as CursorValidator;
use utopia_database::value::{list, to_text};
use utopia_database::{Database, Document, Query, Value};

use appwrite_core::database::documents::{self, Membership, Team, User};
use appwrite_core::platform::Context;
use appwrite_core::response::{MembershipModel, Render};
use appwrite_core::{Error, ErrorType, Result};

/// `getDocument('teams', $teamId)`, or `team_not_found`.
pub async fn team_or_404(db: &Database, id: &str) -> Result<(Document, Team)> {
    let document = db.get_document("teams", id, Vec::new(), false).await?;
    if document.is_empty() {
        return Err(Error::new(ErrorType::TeamNotFound));
    }
    let team = Team::from(&document);
    Ok((document, team))
}

/// Permissions built from `(type, role)` pairs.
pub fn permissions(items: &[(&str, Role)]) -> Value {
    list(items.iter().map(|(kind, role)| {
        let permission = match *kind {
            "read" => Permission::read(role),
            "update" => Permission::update(role),
            _ => Permission::delete(role),
        };
        Value::from(permission.as_str())
    }))
}

/// Resolves the cursor of a list to the document it names in
/// `collection`, or fails with `general_cursor_not_found` naming the
/// cursor as a `label`.
pub async fn resolve_cursor(db: &Database, collection: &str, queries: &mut [Query], label: &str) -> Result<()> {
    let Some(cursor) = queries.iter().position(|q| matches!(q.method(), Method::CursorAfter | Method::CursorBefore))
    else {
        return Ok(());
    };
    let validator = CursorValidator::default();
    if !validator.check(&queries[cursor]) {
        return Err(Error::with_message(ErrorType::GeneralQueryInvalid, validator.description()));
    }
    let id = queries[cursor].value().map(to_text).unwrap_or_default();
    let document = db.get_document(collection, &id, Vec::new(), false).await?;
    if document.is_empty() {
        return Err(Error::with_message(
            ErrorType::GeneralCursorNotFound,
            format!("{label} '{id}' for the 'cursor' value not found."),
        ));
    }
    queries[cursor].set_value(Value::from(document));
    Ok(())
}

/// Which member details a caller sees (`auths.memberships*`; the policy
/// hides other members only from clients).
#[derive(Debug, Clone, Copy)]
pub struct Privacy {
    pub user_name: bool,
    pub user_email: bool,
    pub mfa: bool,
    pub user_id: bool,
    pub user_phone: bool,
    pub user_accessed_at: bool,
}

impl Privacy {
    pub const ALL: Privacy = Privacy {
        user_name: true,
        user_email: true,
        mfa: true,
        user_id: true,
        user_phone: true,
        user_accessed_at: true,
    };

    /// The project's policy, all shown to keys and privileged users.
    pub fn of(ctx: &Context) -> Privacy {
        if ctx.is_privileged() || ctx.is_key() {
            return Privacy::ALL;
        }
        let project = &ctx.project;
        Privacy {
            user_name: project.auth_bool("membershipsUserName", true),
            user_email: project.auth_bool("membershipsUserEmail", true),
            mfa: project.auth_bool("membershipsMfa", true),
            user_id: project.auth_bool("membershipsUserId", true),
            user_phone: project.auth_bool("membershipsUserPhone", true),
            user_accessed_at: project.auth_bool("membershipsUserAccessedAt", false),
        }
    }

    /// Whether any detail is shown (the member is read only then).
    pub fn any(&self) -> bool {
        self.user_name || self.user_email || self.mfa || self.user_id || self.user_phone || self.user_accessed_at
    }
}

/// Whether `membership` is the caller's own (`$user->getSequence()`).
pub fn is_self(ctx: &Context, membership: &Membership) -> bool {
    ctx.user.as_ref().is_some_and(|u| membership.user_internal_id == u.sequence.to_string())
}

/// A member as the membership models show them under a privacy policy.
#[derive(Debug, Default)]
pub struct Member {
    pub id: Option<String>,
    pub name: String,
    pub email: String,
    pub phone: String,
    pub mfa: bool,
    pub accessed_at: Option<chrono::NaiveDateTime>,
}

impl Member {
    /// The details of `user` (`None`: not read) that `privacy` shows.
    pub fn of(user: Option<&User>, privacy: Privacy) -> Member {
        let empty = User::default();
        let user = user.unwrap_or(&empty);
        let mfa = privacy.mfa && user.mfa == Some(true) && {
            let totp =
                user.authenticators.iter().any(|a| a.kind.as_deref() == Some("totp") && a.verified == Some(true));
            let email = user.email.as_deref().is_some_and(|e| !e.is_empty()) && user.email_verification == Some(true);
            let phone = user.phone.as_deref().is_some_and(|p| !p.is_empty()) && user.phone_verification == Some(true);
            totp || email || phone
        };
        Member {
            id: privacy.user_id.then(|| user.id.clone()),
            name: if privacy.user_name { user.name.clone().unwrap_or_default() } else { String::new() },
            email: if privacy.user_email { user.email.clone().unwrap_or_default() } else { String::new() },
            phone: if privacy.user_phone { user.phone.clone().unwrap_or_default() } else { String::new() },
            mfa,
            accessed_at: if privacy.user_accessed_at { user.accessed_at } else { None },
        }
    }

    pub fn model<'a>(&'a self, membership: &'a Membership, team_name: &'a str, render: Render) -> MembershipModel<'a> {
        MembershipModel {
            membership,
            user_id: self.id.as_deref(),
            user_name: &self.name,
            user_email: &self.email,
            user_phone: &self.phone,
            team_name,
            mfa: self.mfa,
            user_accessed_at: self.accessed_at,
            render,
        }
    }
}

/// `getDocument('users', $userId)` with its sub-queries, `None` when absent.
pub async fn user(db: &Database, id: &str) -> Result<Option<User>> {
    if id.is_empty() {
        return Ok(None);
    }
    documents::load_user(db, id).await
}

/// `ID::unique()` for `unique()`, else the id given.
pub fn id_or_unique(id: String) -> String {
    if id == "unique()" { appwrite_core::database::unique_id() } else { id }
}
