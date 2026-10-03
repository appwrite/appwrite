//! PHP `Utopia\Database\Validator\Roles`.

use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::helpers::Role;
use crate::validator::{Key, Label};

/// PHP `Roles::ROLE_*`.
pub const ROLE_ANY: &str = "any";
pub const ROLE_GUESTS: &str = "guests";
pub const ROLE_USERS: &str = "users";
pub const ROLE_USER: &str = "user";
pub const ROLE_TEAM: &str = "team";
pub const ROLE_MEMBER: &str = "member";
pub const ROLE_LABEL: &str = "label";
pub const ROLES: &[&str] = &[
    ROLE_ANY,
    ROLE_GUESTS,
    ROLE_USERS,
    ROLE_USER,
    ROLE_TEAM,
    ROLE_MEMBER,
    ROLE_LABEL,
];
pub const DIMENSION_VERIFIED: &str = "verified";
pub const DIMENSION_UNVERIFIED: &str = "unverified";
pub const USER_DIMENSIONS: &[&str] = &[DIMENSION_VERIFIED, DIMENSION_UNVERIFIED];

/// PHP `Utopia\Database\Validator\Roles`.
#[derive(Debug)]
pub struct Roles {
    allowed: Vec<String>,
    length: i64,
    message: Mutex<String>,
}

impl Clone for Roles {
    fn clone(&self) -> Self {
        Self {
            allowed: self.allowed.clone(),
            length: self.length,
            message: Mutex::new(self.message.lock().clone()),
        }
    }
}

impl Roles {
    #[must_use]
    pub fn new(length: i64, allowed: &[&str]) -> Self {
        Self {
            allowed: allowed.iter().map(|s| (*s).to_owned()).collect(),
            length,
            message: Mutex::new("Roles Error".into()),
        }
    }

    fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }

    pub(crate) fn is_valid_role(&self, role: &str, identifier: &str, dimension: &str) -> bool {
        let identifier_ok = if role == ROLE_LABEL {
            Label::default().is_valid(&Value::String(identifier.to_owned()))
        } else {
            Key::default().is_valid(&Value::String(identifier.to_owned()))
        };
        let dimension_ok = Key::new(false, 81).is_valid(&Value::String(dimension.to_owned()));

        let (id_allowed, id_required, dim_allowed, dim_required, dim_options): (
            bool,
            bool,
            bool,
            bool,
            Option<&[&str]>,
        ) = match role {
            ROLE_ANY | ROLE_GUESTS => (false, false, false, false, None),
            ROLE_USERS => (false, false, true, false, Some(USER_DIMENSIONS)),
            ROLE_USER => (true, true, true, false, Some(USER_DIMENSIONS)),
            ROLE_TEAM => (true, true, true, false, None),
            ROLE_MEMBER | ROLE_LABEL => (true, true, false, false, None),
            _ => {
                self.set_message(format!(
                    "Role \"{role}\" is not allowed. Must be one of: {}.",
                    ROLES.join(", ")
                ));
                return false;
            }
        };

        if !id_allowed && !identifier.is_empty() {
            self.set_message(format!("Role \"{role}\" can not have an ID value."));
            return false;
        }
        if id_allowed && id_required && identifier.is_empty() {
            self.set_message(format!("Role \"{role}\" must have an ID value."));
            return false;
        }
        if id_allowed && !identifier.is_empty() && !identifier_ok {
            self.set_message(format!(
                "Role \"{role}\" identifier value is invalid: {}",
                if role == ROLE_LABEL {
                    Label::default().description()
                } else {
                    Key::default().description()
                }
            ));
            return false;
        }

        if !dim_allowed && !dimension.is_empty() {
            self.set_message(format!("Role \"{role}\" can not have a dimension value."));
            return false;
        }
        if dim_allowed && dim_required && dimension.is_empty() {
            self.set_message(format!("Role \"{role}\" must have a dimension value."));
            return false;
        }
        if dim_allowed && !dimension.is_empty() {
            let options: Vec<&str> = dim_options.map_or_else(|| vec![dimension], |o| o.to_vec());
            if !options.contains(&dimension) {
                self.set_message(format!(
                    "Role \"{role}\" dimension value is invalid. Must be one of: {}.",
                    options.join(", ")
                ));
                return false;
            }
            if !dimension_ok {
                self.set_message(format!(
                    "Role \"{role}\" dimension value is invalid: {}",
                    Key::new(false, 81).description()
                ));
                return false;
            }
        }
        true
    }
}

impl Default for Roles {
    fn default() -> Self {
        Self::new(0, ROLES)
    }
}

impl Validator for Roles {
    fn description(&self) -> String {
        self.message.lock().clone()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Array
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(arr) = value.as_array() else {
            self.set_message("Roles must be an array of strings.");
            return false;
        };
        if self.length > 0 && arr.len() as i64 > self.length {
            self.set_message(format!("You can only provide up to {} roles.", self.length));
            return false;
        }
        for role in arr {
            let Some(s) = role.as_str() else {
                self.set_message("Every role must be of type string.");
                return false;
            };
            if s == "*" {
                self.set_message("Wildcard role \"*\" has been replaced. Use \"any\" instead.");
                return false;
            }
            if s.contains("role:") {
                self.set_message("Roles using the \"role:\" prefix have been removed. Use \"users\", \"guests\", or \"any\" instead.");
                return false;
            }
            let is_allowed = self.allowed.iter().any(|allowed| s.starts_with(allowed));
            if !is_allowed {
                self.set_message(format!(
                    "Role \"{s}\" is not allowed. Must be one of: {}.",
                    self.allowed.join(", ")
                ));
                return false;
            }
            let parsed = match Role::parse(s) {
                Ok(r) => r,
                Err(e) => {
                    self.set_message(e.message().to_owned());
                    return false;
                }
            };
            if !self.is_valid_role(
                parsed.get_role(),
                parsed.get_identifier(),
                parsed.get_dimension(),
            ) {
                return false;
            }
        }
        true
    }
}
