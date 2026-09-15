//! PHP `Utopia\Database\Validator\Authorization` and `Authorization\Input`.

use serde_json::Value;
use utopia_validators::{Validator, ValueType};

/// PHP `Utopia\Database\Validator\Authorization\Input`.
#[derive(Debug, Clone)]
pub struct Input {
    action: String,
    permissions: Vec<String>,
}

impl Input {
    #[must_use]
    pub fn new(action: impl Into<String>, permissions: Vec<String>) -> Self {
        Self {
            action: action.into(),
            permissions,
        }
    }

    pub fn set_permissions(&mut self, permissions: Vec<String>) -> &mut Self {
        self.permissions = permissions;
        self
    }

    pub fn set_action(&mut self, action: impl Into<String>) -> &mut Self {
        self.action = action.into();
        self
    }

    #[must_use]
    pub fn get_permissions(&self) -> &[String] {
        &self.permissions
    }

    #[must_use]
    pub fn get_action(&self) -> &str {
        &self.action
    }
}

/// PHP `Utopia\Database\Validator\Authorization`.
#[derive(Debug, Clone)]
pub struct Authorization {
    status: bool,
    status_default: bool,
    roles: indexmap::IndexMap<String, bool>,
    message: String,
}

impl Default for Authorization {
    fn default() -> Self {
        let mut roles = indexmap::IndexMap::new();
        roles.insert("any".into(), true);
        Self {
            status: true,
            status_default: true,
            roles,
            message: "Authorization Error".into(),
        }
    }
}

impl Authorization {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    pub fn add_role(&mut self, role: impl Into<String>) {
        self.roles.insert(role.into(), true);
    }

    pub fn remove_role(&mut self, role: &str) {
        self.roles.shift_remove(role);
    }

    #[must_use]
    pub fn get_roles(&self) -> Vec<String> {
        self.roles.keys().cloned().collect()
    }

    pub fn clean_roles(&mut self) {
        self.roles.clear();
    }

    #[must_use]
    pub fn has_role(&self, role: &str) -> bool {
        self.roles.contains_key(role)
    }

    pub fn set_default_status(&mut self, status: bool) {
        self.status_default = status;
        self.status = status;
    }

    pub fn set_status(&mut self, status: bool) {
        self.status = status;
    }

    #[must_use]
    pub fn get_status(&self) -> bool {
        self.status
    }

    pub fn skip<T, F: FnOnce() -> T>(&mut self, callback: F) -> T {
        let initial = self.status;
        self.disable();
        let result = callback();
        self.status = initial;
        result
    }

    pub fn enable(&mut self) {
        self.status = true;
    }

    pub fn disable(&mut self) {
        self.status = false;
    }

    pub fn reset(&mut self) {
        self.status = self.status_default;
    }

    pub fn is_valid_input(&mut self, input: &Input) -> bool {
        if !self.status {
            return true;
        }
        if input.permissions.is_empty() {
            self.message = format!("No permissions provided for action '{}'", input.action);
            return false;
        }
        let mut last = "-";
        for permission in &input.permissions {
            last = permission;
            if self.roles.contains_key(permission) {
                return true;
            }
        }
        self.message = format!(
            "Missing \"{}\" permission for role \"{}\". Only \"{}\" scopes are allowed and \"{}\" was given.",
            input.action,
            last,
            serde_json::to_string(&self.get_roles()).unwrap_or_else(|_| "[]".into()),
            serde_json::to_string(&input.permissions).unwrap_or_else(|_| "[]".into()),
        );
        false
    }
}

impl Validator for Authorization {
    fn description(&self) -> String {
        self.message.clone()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Array
    }

    fn is_valid(&self, _value: &Value) -> bool {
        true
    }
}
