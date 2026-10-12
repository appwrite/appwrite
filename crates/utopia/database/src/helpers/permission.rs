//! [`Permission`]: an action granted to a [`Role`], written
//! `action("role")` (`read("any")`, `update("team:123/owner")`).
//!
//! PHP: `Utopia\Database\Helpers\Permission`.

use std::fmt;

use crate::error::{Error, Result};
use crate::helpers::role::{Role, falsy};

/// The action a permission grants.
///
/// PHP: the `$permission` string of `Helpers\Permission`
/// (`Database::PERMISSION_*`). PHP's constructor also stores other
/// strings, which [`Permission::parse`] never produces; Rust cannot hold
/// them (a recorded deviation).
#[derive(Clone, Copy, PartialEq, Eq, Hash, Debug)]
pub enum PermissionType {
    /// `create`
    Create,
    /// `read`
    Read,
    /// `update`
    Update,
    /// `delete`
    Delete,
    /// `write`: an aggregate of create, update and delete.
    Write,
}

impl PermissionType {
    /// `Database::PERMISSIONS`: the individual actions, in PHP's order.
    pub const ALL: [PermissionType; 4] =
        [PermissionType::Create, PermissionType::Read, PermissionType::Update, PermissionType::Delete];

    /// The action as PHP spells it.
    pub fn as_str(self) -> &'static str {
        match self {
            PermissionType::Create => "create",
            PermissionType::Read => "read",
            PermissionType::Update => "update",
            PermissionType::Delete => "delete",
            PermissionType::Write => "write",
        }
    }

    /// The action a name names, if any.
    pub fn from_name(name: &str) -> Option<PermissionType> {
        Some(match name {
            "create" => PermissionType::Create,
            "read" => PermissionType::Read,
            "update" => PermissionType::Update,
            "delete" => PermissionType::Delete,
            "write" => PermissionType::Write,
            _ => return None,
        })
    }

    /// The individual actions an aggregate stands for (`write`: create,
    /// update, delete); `None` for an individual action.
    fn subtypes(self) -> Option<[PermissionType; 3]> {
        match self {
            PermissionType::Write => Some([PermissionType::Create, PermissionType::Update, PermissionType::Delete]),
            _ => None,
        }
    }
}

/// A permission: an action and the role it is granted to.
///
/// ```
/// use utopia_database::helpers::permission::{Permission, PermissionType};
/// use utopia_database::helpers::role::Role;
///
/// let p = Permission::parse("read(\"user:123\")").unwrap();
/// assert_eq!(p.permission(), PermissionType::Read);
/// assert_eq!(p.role().identifier(), "123");
/// assert_eq!(Permission::read(&Role::any()), "read(\"any\")");
/// assert_eq!(
///     Permission::aggregate(&["write(\"any\")"], &PermissionType::ALL).unwrap(),
///     ["create(\"any\")", "update(\"any\")", "delete(\"any\")"]
/// );
/// ```
///
/// PHP: `Utopia\Database\Helpers\Permission`; `getRole()`,
/// `getIdentifier()` and `getDimension()` are read through [`Permission::role`].
#[derive(Clone, PartialEq, Eq, Hash, Debug)]
pub struct Permission {
    permission: PermissionType,
    role: Role,
}

impl Permission {
    /// `new Permission($permission, $role, $identifier, $dimension)`.
    pub fn new(permission: PermissionType, role: Role) -> Self {
        Self { permission, role }
    }

    /// The action (`getPermission()`).
    pub fn permission(&self) -> PermissionType {
        self.permission
    }

    /// The role (`getRole()`, `getIdentifier()`, `getDimension()`).
    pub fn role(&self) -> &Role {
        &self.role
    }

    /// `Permission::parse($permission)`.
    ///
    /// Fails ([`crate::Kind::Database`]) with `Invalid permission string
    /// format: "..."`, `Invalid permission type: "..."`, `Only one dimension
    /// can be provided` or `Dimension must not be empty`.
    pub fn parse(permission: &str) -> Result<Self> {
        let mut parts = permission.split("(\"");
        let (Some(kind), Some(rest), None) = (parts.next(), parts.next(), parts.next()) else {
            return Err(Error::database(format!("Invalid permission string format: \"{permission}\".")));
        };
        let Some(kind) = PermissionType::from_name(kind) else {
            return Err(Error::database(format!("Invalid permission type: \"{kind}\".")));
        };
        let full_role = rest.replace("\")", "");
        let role_parts: Vec<&str> = full_role.split(':').collect();
        let name = role_parts[0];
        let has_identifier = role_parts.len() > 1;
        let has_dimension = full_role.contains('/');

        let role = if !has_identifier && !has_dimension {
            Role::named(name, "", "")
        } else if has_identifier && !has_dimension {
            Role::named(name, role_parts[1], "")
        } else if !has_identifier {
            let (name, dimension) = split_dimension(&full_role)?;
            Role::named(name, "", dimension)
        } else {
            let (identifier, dimension) = split_dimension(role_parts[1])?;
            Role::named(name, identifier, dimension)
        };
        Ok(Self::new(kind, role))
    }

    /// `Permission::aggregate($permissions, $allowed)`: each permission
    /// string parsed and written back, aggregates (`write`) expanded into
    /// the individual actions in `allowed`, without duplicates, in order.
    ///
    /// PHP: `null` permissions give `null`; a Rust caller without
    /// permissions has nothing to call.
    pub fn aggregate<S: AsRef<str>>(permissions: &[S], allowed: &[PermissionType]) -> Result<Vec<String>> {
        let mut mutated: Vec<String> = Vec::new();
        for permission in permissions {
            let permission = Self::parse(permission.as_ref())?;
            match permission.permission.subtypes() {
                None => mutated.push(permission.to_string()),
                Some(subtypes) => {
                    for subtype in subtypes {
                        if allowed.contains(&subtype) {
                            mutated.push(Self::new(subtype, permission.role.clone()).to_string());
                        }
                    }
                }
            }
        }
        let mut unique: Vec<String> = Vec::with_capacity(mutated.len());
        for p in mutated {
            if !unique.contains(&p) {
                unique.push(p);
            }
        }
        Ok(unique)
    }

    /// `Permission::read($role)`.
    pub fn read(role: &Role) -> String {
        Self::new(PermissionType::Read, role.clone()).to_string()
    }

    /// `Permission::create($role)`.
    pub fn create(role: &Role) -> String {
        Self::new(PermissionType::Create, role.clone()).to_string()
    }

    /// `Permission::update($role)`.
    pub fn update(role: &Role) -> String {
        Self::new(PermissionType::Update, role.clone()).to_string()
    }

    /// `Permission::delete($role)`.
    pub fn delete(role: &Role) -> String {
        Self::new(PermissionType::Delete, role.clone()).to_string()
    }

    /// `Permission::write($role)`.
    pub fn write(role: &Role) -> String {
        Self::new(PermissionType::Write, role.clone()).to_string()
    }
}

fn split_dimension(s: &str) -> Result<(&str, &str)> {
    let mut parts = s.split('/');
    let (Some(first), Some(second), None) = (parts.next(), parts.next(), parts.next()) else {
        return Err(Error::database("Only one dimension can be provided"));
    };
    if falsy(second) {
        return Err(Error::database("Dimension must not be empty"));
    }
    Ok((first, second))
}

impl fmt::Display for Permission {
    /// `toString()`: `action("role")`.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}(\"{}\")", self.permission.as_str(), self.role)
    }
}
