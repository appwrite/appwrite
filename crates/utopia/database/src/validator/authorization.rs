//! [`Authorization`]: the roles of the current request and whether a
//! permission list grants an action to them.
//!
//! PHP: `Utopia\Database\Validator\Authorization` and
//! `Validator\Authorization\Input` ([`Input`]).

use std::future::Future;
use std::sync::Mutex;

use crate::permission::PermissionType;
use crate::state::Scoped;
use crate::value::{Array, ArrayKey, KeyRef, Str, Value};

/// The current roles, and whether authorization is enforced.
///
/// The status and the roles are [`Scoped`]: [`Authorization::skip`] and
/// [`Authorization::with_roles`] change them for the future they run and
/// nothing else, and a setter inside such a scope changes them only there.
///
/// ```
/// use utopia_database::validator::authorization::{Authorization, Input};
/// use utopia_database::PermissionType;
///
/// let auth = Authorization::new(true);
/// auth.add_role("user:1");
/// assert!(auth.is_valid(&Input::new(PermissionType::Read, vec!["user:1".into()])));
/// assert!(!auth.is_valid(&Input::new(PermissionType::Read, vec!["user:2".into()])));
/// assert_eq!(auth.description(), "Missing \"read\" permission for role \"user:2\". Only \"[\"any\",\"user:1\"]\" scopes are allowed and \"[\"user:2\"]\" was given.");
/// ```
///
/// PHP: roles are the keys of an array, so a numeric role (`"123"`) is an
/// integer key and comes back from [`Authorization::roles`] as one.
pub struct Authorization {
    default_status: bool,
    status: Scoped<bool>,
    roles: Scoped<Array>,
    message: Mutex<String>,
}

impl std::fmt::Debug for Authorization {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Authorization").field("status", &self.status()).field("roles", &self.role_names()).finish()
    }
}

/// The action and permissions an [`Authorization`] checks.
///
/// PHP: `Utopia\Database\Validator\Authorization\Input`.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Input {
    /// The action: a [`PermissionType`]'s value, or an action of an
    /// application's own such as `execute`.
    pub action: String,
    /// The roles the permission list grants the action to.
    pub permissions: Vec<String>,
}

impl Input {
    /// `new Input($action, $permissions)`.
    pub fn new(action: impl Into<Action>, permissions: Vec<String>) -> Self {
        Self { action: action.into().0, permissions }
    }

    /// Replaces the action (`setAction()`).
    pub fn set_action(&mut self, action: impl Into<Action>) -> &mut Self {
        self.action = action.into().0;
        self
    }

    /// Replaces the permissions (`setPermissions()`).
    pub fn set_permissions(&mut self, permissions: Vec<String>) -> &mut Self {
        self.permissions = permissions;
        self
    }
}

/// An action of an [`Input`]: a [`PermissionType`] or a name.
#[derive(Debug, Clone)]
pub struct Action(String);

impl From<PermissionType> for Action {
    fn from(p: PermissionType) -> Self {
        Action(p.as_str().to_owned())
    }
}

impl From<&str> for Action {
    fn from(s: &str) -> Self {
        Action(s.to_owned())
    }
}

impl From<String> for Action {
    fn from(s: String) -> Self {
        Action(s)
    }
}

impl Default for Authorization {
    fn default() -> Self {
        Self::new(true)
    }
}

impl Clone for Authorization {
    /// A copy with the status and roles the caller sees, scoped
    /// independently of this one (`__clone`).
    fn clone(&self) -> Self {
        Authorization {
            default_status: self.default_status,
            status: Scoped::new(self.status.get()),
            roles: Scoped::new(self.roles.get()),
            message: Mutex::new(self.description()),
        }
    }
}

fn key(role: &str) -> ArrayKey {
    ArrayKey::normalize(Str::from(role))
}

impl Authorization {
    /// `new Authorization($defaultStatus)`: with the role `any`, enforced
    /// unless `default_status` is false.
    pub fn new(default_status: bool) -> Self {
        let mut roles = Array::new();
        roles.set(ArrayKey::from("any"), Value::Bool(true));
        Self {
            default_status,
            status: Scoped::new(default_status),
            roles: Scoped::new(roles),
            message: Mutex::new("Authorization Error".to_owned()),
        }
    }

    fn set_message(&self, message: String) {
        *self.message.lock().unwrap_or_else(|e| e.into_inner()) = message;
    }

    /// The description of the last failure (`getDescription()`).
    pub fn description(&self) -> String {
        self.message.lock().unwrap_or_else(|e| e.into_inner()).clone()
    }

    /// `isValid($input)`: whether one of the permissions is a current role
    /// (always, while disabled). Sets the description on failure.
    pub fn is_valid(&self, input: &Input) -> bool {
        if !self.status.get() {
            return true;
        }
        if input.permissions.is_empty() {
            self.set_message(format!("No permissions provided for action '{}'", input.action));
            return false;
        }
        let roles = self.roles.get();
        let mut last = "-";
        for permission in &input.permissions {
            last = permission;
            if roles.contains_key(&key(permission)) {
                return true;
            }
        }
        let encode = |v: Value| php_std::json::encode(&v, php_std::json::Flags::NONE, 512).unwrap_or_default();
        let given = encode(Value::Array(input.permissions.iter().map(|p| Value::Str(Str::from(p.as_str()))).collect()));
        self.set_message(format!(
            "Missing \"{}\" permission for role \"{}\". Only \"{}\" scopes are allowed and \"{}\" was given.",
            input.action,
            last,
            encode(Value::Array(role_list(&roles))),
            given
        ));
        false
    }

    /// `addRole()`.
    pub fn add_role(&self, role: &str) -> &Self {
        let mut roles = self.roles.get();
        roles.set(key(role), Value::Bool(true));
        self.roles.set(roles);
        self
    }

    /// `removeRole()`.
    pub fn remove_role(&self, role: &str) -> &Self {
        let mut roles = self.roles.get();
        roles.remove(&key(role));
        self.roles.set(roles);
        self
    }

    /// `getRoles()`: in the order they were added (numeric roles as
    /// integers).
    pub fn roles(&self) -> Vec<Value> {
        role_list(&self.roles.get()).into_iter().map(|(_, v)| v).collect()
    }

    /// The roles as strings.
    pub fn role_names(&self) -> Vec<String> {
        self.roles
            .get()
            .keys()
            .map(|k| match k {
                KeyRef::Int(i) => i.to_string(),
                KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
            })
            .collect()
    }

    /// Runs `future` with exactly `roles` (`withRoles()`).
    pub async fn with_roles<F: Future>(&self, roles: &[String], future: F) -> F::Output {
        let set: Array = roles.iter().map(|r| (key(r), Value::Bool(true))).collect();
        self.roles.with(set, future).await
    }

    /// `cleanRoles()`.
    pub fn clean_roles(&self) -> &Self {
        self.roles.set(Array::new());
        self
    }

    /// `hasRole()`.
    pub fn has_role(&self, role: &str) -> bool {
        self.roles.get().contains_key(&key(role))
    }

    /// `setStatus()`.
    pub fn set_status(&self, status: bool) -> &Self {
        self.status.set(status);
        self
    }

    /// `getStatus()`: whether authorization is enforced.
    pub fn status(&self) -> bool {
        self.status.get()
    }

    /// Runs `future` with authorization disabled (`skip()`).
    pub async fn skip<F: Future>(&self, future: F) -> F::Output {
        self.status.with(false, future).await
    }

    /// [`Authorization::skip`] for work that does not await.
    pub fn skip_sync<R>(&self, callback: impl FnOnce() -> R) -> R {
        self.status.with_sync(false, callback)
    }

    /// Runs `future` with `status` and exactly `roles` (`restore()`,
    /// internal: applies a snapshot).
    pub async fn restore<F: Future>(&self, status: bool, roles: &[String], future: F) -> F::Output {
        self.status.with(status, self.with_roles(roles, future)).await
    }

    /// `enable()`.
    pub fn enable(&self) -> &Self {
        self.status.set(true);
        self
    }

    /// `disable()`.
    pub fn disable(&self) -> &Self {
        self.status.set(false);
        self
    }

    /// `reset()`: back to the default status.
    pub fn reset(&self) {
        self.status.set(self.default_status);
    }
}

/// `array_keys($roles)` as a list of values.
fn role_list(roles: &Array) -> Array {
    roles
        .keys()
        .map(|k| match k {
            KeyRef::Int(i) => Value::Int(i),
            KeyRef::Str(s) => Value::Str(Str::from(s)),
        })
        .collect()
}

impl utopia_validators::Validator for Authorization {
    fn description(&self) -> String {
        Authorization::description(self)
    }

    /// An [`Authorization`] validates an [`Input`], not a JSON value: through
    /// this trait every value is `Invalid input provided`, as PHP answers
    /// for anything that is not an `Input`.
    fn is_valid(&self, _value: &serde_json::Value) -> bool {
        self.set_message("Invalid input provided".to_owned());
        false
    }

    fn is_array(&self) -> bool {
        false
    }

    fn kind(&self) -> utopia_validators::Type {
        utopia_validators::Type::Array
    }
}
