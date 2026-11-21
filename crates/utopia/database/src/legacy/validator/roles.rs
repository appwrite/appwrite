//! [`Roles`]: lists of role strings.
//!
//! PHP: `Utopia\Database\Validator\Roles`.

use crate::helpers::role::{Role, RoleName, falsy};
use crate::validator::Message;
use crate::validator::key::Key;
use crate::validator::label::Label;
use crate::value::{Str, Value};

/// `Roles::ROLES`: every role name, in PHP's order.
pub const ROLES: [&str; 7] = ["any", "guests", "users", "user", "team", "member", "label"];

/// `Roles::USER_DIMENSIONS`: the dimensions of `users` and `user` roles.
pub const USER_DIMENSIONS: [&str; 2] = ["verified", "unverified"];

/// Whether a role name takes an identifier and a dimension, and which
/// dimensions it allows (`Roles::CONFIG`).
struct Config {
    identifier_allowed: bool,
    identifier_required: bool,
    dimension_allowed: bool,
    dimension_options: Option<&'static [&'static str]>,
}

fn config(name: &RoleName) -> Option<Config> {
    let c = |identifier_allowed, identifier_required, dimension_allowed, dimension_options| Config {
        identifier_allowed,
        identifier_required,
        dimension_allowed,
        dimension_options,
    };
    Some(match name {
        RoleName::Any | RoleName::Guests => c(false, false, false, None),
        RoleName::Users => c(false, false, true, Some(&USER_DIMENSIONS)),
        RoleName::User => c(true, true, true, Some(&USER_DIMENSIONS)),
        RoleName::Team => c(true, true, true, None),
        RoleName::Member | RoleName::Label => c(true, true, false, None),
        RoleName::Other(_) => return None,
    })
}

/// Checks the identifier and dimension of a role against its name
/// (`Roles::isValidRole`); the failure message.
pub(crate) fn check_role(role: &Role) -> std::result::Result<(), String> {
    let name = role.name().as_str();
    let identifier = role.identifier();
    let dimension = role.dimension();
    let Some(config) = config(role.name()) else {
        return Err(format!("Role \"{name}\" is not allowed. Must be one of: {}.", ROLES.join(", ")));
    };

    if !config.identifier_allowed && !falsy(identifier) {
        return Err(format!("Role \"{name}\" can not have an ID value."));
    }
    if config.identifier_allowed && config.identifier_required && falsy(identifier) {
        return Err(format!("Role \"{name}\" must have an ID value."));
    }
    if config.identifier_allowed && !falsy(identifier) {
        let id = Value::Str(Str::from(identifier));
        let invalid = match role.name() {
            RoleName::Label => {
                let label = Label::default();
                (!label.is_valid(&id)).then(|| label.description())
            }
            _ => {
                let key = Key::default();
                (!key.is_valid(&id)).then(|| key.description())
            }
        };
        if let Some(description) = invalid {
            return Err(format!("Role \"{name}\" identifier value is invalid: {description}"));
        }
    }

    if !config.dimension_allowed && !falsy(dimension) {
        return Err(format!("Role \"{name}\" can not have a dimension value."));
    }
    if config.dimension_allowed && !falsy(dimension) {
        let options: Vec<&str> = config.dimension_options.map_or_else(|| vec![dimension], |o| o.to_vec());
        if !options.contains(&dimension) {
            return Err(format!("Role \"{name}\" dimension value is invalid. Must be one of: {}.", options.join(", ")));
        }
        // Project-specific team roles look like `project-<projectId>-<role>`: up to 81 characters.
        let key = Key::new(false, 81);
        if !key.accepts(dimension.as_bytes()) {
            return Err(format!("Role \"{name}\" dimension value is invalid: {}", key.description()));
        }
    }
    Ok(())
}

/// A list of role strings (`any`, `user:<id>`, `team:<id>/<role>`, ...),
/// at most `length` of them (0: no limit), each starting with one of the
/// `allowed` names and well formed for its role.
///
/// ```
/// use utopia_database::validator::roles::Roles;
/// use utopia_database::value::{list, string};
///
/// let roles = Roles::default();
/// assert!(roles.is_valid(&list([string("any"), string("user:123")])));
/// assert!(!roles.is_valid(&list([string("user")])));
/// assert_eq!(roles.description(), "Role \"user\" must have an ID value.");
/// ```
///
/// PHP: `Utopia\Database\Validator\Roles` (`new Roles($length, $allowed)`).
#[derive(Clone, Debug)]
pub struct Roles {
    length: i64,
    allowed: Vec<String>,
    message: Message,
}

impl Default for Roles {
    /// `new Roles()`: any number of roles, every role name allowed.
    fn default() -> Self {
        Self::new(0, ROLES.iter().map(|r| (*r).to_owned()).collect())
    }
}

impl Roles {
    /// `new Roles($length, $allowed)`.
    pub fn new(length: i64, allowed: Vec<String>) -> Self {
        Self { length, allowed, message: Message::new("Roles Error") }
    }

    /// `getDescription()`: the last failure, or `Roles Error`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `isValid($roles)`.
    pub fn is_valid(&self, roles: &Value) -> bool {
        match self.check(roles) {
            Ok(()) => true,
            Err(message) => {
                self.message.set(message);
                false
            }
        }
    }

    fn check(&self, roles: &Value) -> std::result::Result<(), String> {
        let Value::Array(roles) = roles else {
            return Err("Roles must be an array of strings.".to_owned());
        };
        if self.length != 0 && roles.len() as i64 > self.length {
            return Err(format!("You can only provide up to {} roles.", self.length));
        }
        for role in roles.values() {
            let Value::Str(role) = role else {
                return Err("Every role must be of type string.".to_owned());
            };
            let role = String::from_utf8_lossy(role.as_bytes());
            if role == "*" {
                return Err("Wildcard role \"*\" has been replaced. Use \"any\" instead.".to_owned());
            }
            if role.contains("role:") {
                return Err(
                    "Roles using the \"role:\" prefix have been removed. Use \"users\", \"guests\", or \"any\" instead."
                        .to_owned(),
                );
            }
            if !self.allowed.iter().any(|a| role.starts_with(a.as_str())) {
                return Err(format!("Role \"{role}\" is not allowed. Must be one of: {}.", self.allowed.join(", ")));
            }
            let role = Role::parse(&role).map_err(|e| e.message().to_owned())?;
            check_role(&role)?;
        }
        Ok(())
    }

    /// `getType()`: `array`.
    pub fn type_name(&self) -> &'static str {
        "array"
    }
}

super::validator!(Roles, utopia_validators::Type::Array, false);
