//! Shared helpers for pre-hashed password user creation endpoints. Not a PHP
//! action class - mirrors the common param wiring in
//! `Http/Users/{Argon2,Bcrypt,...}/Create.php` that all call
//! [`crate::modules::users::base::create_user`] with a concrete hasher.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::Action;

use crate::modules::users::base::{self, inject};
use crate::modules::users::validators::Email;

pub(crate) fn common_params(action: Action) -> Action {
    action
        .param(
            "userId",
            json!(""),
            appwrite_database::CustomId::default(),
            "User ID.",
            false,
        )
        .param("email", json!(""), Email::new(false), "User email.", false)
}

pub(crate) fn create_action(path: &'static str, desc: &'static str) -> Action {
    inject(
        common_params(
            Action::new()
                .set_http_method(utopia_platform::HttpMethod::Post)
                .set_http_path(path)
                .desc(desc)
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.create")
                .label("audits.resource", "user/{response.$id}"),
        ),
        &["response", "project", "dbForProject", "hooks"],
    )
}

pub(crate) fn run_create(
    ctx: &utopia_http::ActionContext,
    hasher: Result<std::sync::Arc<dyn utopia_auth::Hash>, utopia_auth::AuthError>,
) -> Result<Value, Exception> {
    let hasher = hasher.map_err(base::hash_error)?;
    let db_handle = base::get_db(ctx)?;
    let hooks = base::get_hooks(ctx)?;
    let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
    base::create_user(
        &mut db,
        &hooks,
        hasher,
        base::CreateUserParams {
            user_id: base::param_str(ctx, "userId")?,
            email: Some(base::param_str(ctx, "email")?),
            password: Some(base::param_str(ctx, "password")?),
            phone: None,
            name: ctx
                .param_value("name")
                .and_then(Value::as_str)
                .map(str::to_string),
        },
    )
}
