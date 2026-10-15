//! [`Permissions`]: lists of permission strings.
//!
//! PHP: `Utopia\Database\Validator\Permissions`.

use crate::permission::{Permission, PermissionType};
use crate::validator::Message;
use crate::validator::roles::check_role;
use crate::value::Value;

/// The actions a permission may grant, by default.
pub const PERMISSIONS: [PermissionType; 5] = [
    PermissionType::Create,
    PermissionType::Read,
    PermissionType::Update,
    PermissionType::Delete,
    PermissionType::Write,
];

/// A list of permission strings (`read("any")`, `update("user:123")`, ...),
/// at most `length` of them (0: no limit), each starting with one of the
/// `allowed` actions and granted to a well-formed role.
///
/// ```
/// use utopia_database::validator::permissions::Permissions;
/// use utopia_database::value::{list, string};
///
/// let permissions = Permissions::default();
/// assert!(permissions.is_valid(&list([string("read(\"any\")")])));
/// assert!(!permissions.is_valid(&list([string("read(\"user\")")])));
/// assert_eq!(permissions.description(), "Role \"user\" must have an ID value.");
/// ```
///
/// PHP: `Utopia\Database\Validator\Permissions` (`new Permissions($length, $allowed)`).
#[derive(Clone, Debug)]
pub struct Permissions {
    length: i64,
    allowed: Vec<String>,
    message: Message,
}

impl Default for Permissions {
    /// `new Permissions()`: any number of permissions, every action allowed.
    fn default() -> Self {
        Self::new(0, &PERMISSIONS)
    }
}

impl Permissions {
    /// `new Permissions($length, $allowed)`.
    pub fn new(length: i64, allowed: &[PermissionType]) -> Self {
        let allowed = allowed.iter().map(|p| p.as_str().to_owned()).collect();
        Self { length, allowed, message: Message::new("Permissions Error") }
    }

    /// `getDescription()`: the last failure, or `Permissions Error`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `isValid($permissions)`.
    pub fn is_valid(&self, permissions: &Value) -> bool {
        match self.check(permissions) {
            Ok(()) => true,
            Err(message) => {
                self.message.set(message);
                false
            }
        }
    }

    fn check(&self, permissions: &Value) -> std::result::Result<(), String> {
        let Value::Array(permissions) = permissions else {
            return Err("Permissions must be an array of strings.".to_owned());
        };
        if self.length != 0 && permissions.len() as i64 > self.length {
            return Err(format!("You can only provide up to {} permissions.", self.length));
        }
        for permission in permissions.values() {
            let Value::Str(permission) = permission else {
                return Err("Every permission must be of type string.".to_owned());
            };
            let permission = String::from_utf8_lossy(permission.as_bytes());
            if permission == "*" {
                return Err("Wildcard permission \"*\" has been replaced. Use \"any\" instead.".to_owned());
            }
            if permission.contains("role:") {
                return Err("Permissions using the \"role:\" prefix have been replaced. Use \"users\", \"guests\", or \"any\" instead.".to_owned());
            }
            if !self.allowed.iter().any(|a| permission.starts_with(a.as_str())) {
                return Err(format!(
                    "Permission \"{permission}\" is not allowed. Must be one of: {}.",
                    self.allowed.join(", ")
                ));
            }
            let permission = Permission::parse(&permission).map_err(|e| e.message().to_owned())?;
            check_role(permission.role())?;
        }
        Ok(())
    }

    /// `getType()`: `array`.
    pub fn type_name(&self) -> &'static str {
        "array"
    }
}

super::validator!(Permissions, utopia_validators::Type::Array, false);
