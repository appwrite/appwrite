//! [`Authorization`]: the roles of the current request and whether a
//! permission list grants an action to them.
//!
//! PHP: `Utopia\Database\Validator\Authorization` and
//! `Validator\Authorization\Input` ([`Input`]).

use std::future::Future;
use std::sync::{Mutex, MutexGuard};

use crate::value::{Array, ArrayKey, KeyRef, Str, Value};

/// The current roles, and whether authorization is enforced.
///
/// ```
/// use utopia_database::validator::authorization::{Authorization, Input};
///
/// let auth = Authorization::new();
/// auth.add_role("user:1");
/// assert!(auth.is_valid(&Input::new("read", vec!["user:1".into()])));
/// assert!(!auth.is_valid(&Input::new("read", vec!["user:2".into()])));
/// assert_eq!(auth.description(), "Missing \"read\" permission for role \"user:2\". Only \"[\"any\",\"user:1\"]\" scopes are allowed and \"[\"user:2\"]\" was given.");
/// ```
///
/// PHP: roles are the keys of an array, so a numeric role (`"123"`) is an
/// integer key and comes back from [`Authorization::roles`] as one.
pub struct Authorization {
    state: Mutex<State>,
}

struct State {
    status: bool,
    status_default: bool,
    roles: Array,
    message: String,
}

/// The action and permissions an [`Authorization`] checks.
///
/// PHP: `Utopia\Database\Validator\Authorization\Input`.
#[derive(Clone, Debug, PartialEq)]
pub struct Input {
    /// The action (`read`, `create`, `update`, `delete`).
    pub action: String,
    /// The roles the permission list grants the action to.
    pub permissions: Vec<String>,
}

impl Input {
    /// `new Input($action, $permissions)`.
    pub fn new(action: impl Into<String>, permissions: Vec<String>) -> Self {
        Self { action: action.into(), permissions }
    }
}

impl Default for Authorization {
    fn default() -> Self {
        Self::new()
    }
}

impl Authorization {
    /// `new Authorization()`: enforced, with the role `any`.
    pub fn new() -> Self {
        let mut roles = Array::new();
        roles.set(ArrayKey::from("any"), Value::Bool(true));
        Self {
            state: Mutex::new(State {
                status: true,
                status_default: true,
                roles,
                message: "Authorization Error".to_owned(),
            }),
        }
    }

    fn lock(&self) -> MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// The description of the last failure (`getDescription()`).
    pub fn description(&self) -> String {
        self.lock().message.clone()
    }

    /// `isValid($input)`: whether one of the permissions is a current role
    /// (always, when disabled). Sets the description on failure.
    pub fn is_valid(&self, input: &Input) -> bool {
        let mut s = self.lock();
        if !s.status {
            return true;
        }
        if input.permissions.is_empty() {
            s.message = format!("No permissions provided for action '{}'", input.action);
            return false;
        }
        let mut last = "-";
        for permission in &input.permissions {
            last = permission;
            if s.roles.contains_key(&ArrayKey::normalize(Str::from(permission.as_str()))) {
                return true;
            }
        }
        let roles = php_std::json::encode(&Value::Array(role_list(&s.roles)), php_std::json::Flags::NONE, 512)
            .unwrap_or_default();
        let given = php_std::json::encode(
            &Value::Array(input.permissions.iter().map(|p| Value::Str(Str::from(p.as_str()))).collect()),
            php_std::json::Flags::NONE,
            512,
        )
        .unwrap_or_default();
        s.message = format!(
            "Missing \"{}\" permission for role \"{}\". Only \"{}\" scopes are allowed and \"{}\" was given.",
            input.action, last, roles, given
        );
        false
    }

    /// `addRole()`.
    pub fn add_role(&self, role: &str) {
        self.lock().roles.set(ArrayKey::normalize(Str::from(role)), Value::Bool(true));
    }

    /// `removeRole()`.
    pub fn remove_role(&self, role: &str) {
        self.lock().roles.remove(&ArrayKey::normalize(Str::from(role)));
    }

    /// `getRoles()`: in the order they were added (numeric roles as integers).
    pub fn roles(&self) -> Vec<Value> {
        role_list(&self.lock().roles).into_iter().map(|(_, v)| v).collect()
    }

    /// The roles as strings, for permission checks against stored permissions.
    pub fn role_names(&self) -> Vec<String> {
        self.lock()
            .roles
            .keys()
            .map(|k| match k {
                KeyRef::Int(i) => i.to_string(),
                KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
            })
            .collect()
    }

    /// `cleanRoles()`.
    pub fn clean_roles(&self) {
        self.lock().roles = Array::new();
    }

    /// `hasRole()`.
    pub fn has_role(&self, role: &str) -> bool {
        self.lock().roles.contains_key(&ArrayKey::normalize(Str::from(role)))
    }

    /// `setDefaultStatus()`: the status [`Authorization::reset`] returns to,
    /// and the current status.
    pub fn set_default_status(&self, status: bool) {
        let mut s = self.lock();
        s.status_default = status;
        s.status = status;
    }

    /// `setStatus()`.
    pub fn set_status(&self, status: bool) {
        self.lock().status = status;
    }

    /// `getStatus()`: whether authorization is enforced.
    pub fn status(&self) -> bool {
        self.lock().status
    }

    /// `skip($callback)`: runs `f` with authorization disabled, then restores
    /// the status it had.
    pub async fn skip<T, F, Fut>(&self, f: F) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        let initial = {
            let mut s = self.lock();
            std::mem::replace(&mut s.status, false)
        };
        let out = f().await;
        self.lock().status = initial;
        out
    }

    /// `enable()`.
    pub fn enable(&self) {
        self.lock().status = true;
    }

    /// `disable()`.
    pub fn disable(&self) {
        self.lock().status = false;
    }

    /// `reset()`: back to the default status.
    pub fn reset(&self) {
        let mut s = self.lock();
        s.status = s.status_default;
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
        self.lock().message = "Invalid input provided".to_owned();
        false
    }

    fn is_array(&self) -> bool {
        false
    }

    fn kind(&self) -> utopia_validators::Type {
        utopia_validators::Type::Array
    }
}
