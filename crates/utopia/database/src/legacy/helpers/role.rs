//! [`Role`]: who a permission is granted to (`any`, `users`, `user:<id>`,
//! `team:<id>/<dimension>`, ...).
//!
//! PHP: `Utopia\Database\Helpers\Role`.

use std::fmt;

use crate::error::{Error, Result};

/// The name of a role: one of the roles the library knows, or any other
/// name (PHP parses and stores roles it does not know).
///
/// PHP: the `$role` string of `Helpers\Role` (`Validator\Roles::ROLE_*`
/// for the known ones).
#[derive(Clone, PartialEq, Eq, Hash, Debug)]
pub enum RoleName {
    /// `any`: everyone.
    Any,
    /// `guests`: visitors without a session.
    Guests,
    /// `users`: every signed-in user, optionally by status dimension.
    Users,
    /// `user`: one user.
    User,
    /// `team`: the members of a team, optionally of one team role.
    Team,
    /// `member`: one team membership.
    Member,
    /// `label`: users carrying a label.
    Label,
    /// A role name the library does not know.
    Other(String),
}

impl RoleName {
    /// The known role names, in PHP's `Roles::ROLES` order.
    pub const KNOWN: [RoleName; 7] = [
        RoleName::Any,
        RoleName::Guests,
        RoleName::Users,
        RoleName::User,
        RoleName::Team,
        RoleName::Member,
        RoleName::Label,
    ];

    /// The name as PHP spells it.
    pub fn as_str(&self) -> &str {
        match self {
            RoleName::Any => "any",
            RoleName::Guests => "guests",
            RoleName::Users => "users",
            RoleName::User => "user",
            RoleName::Team => "team",
            RoleName::Member => "member",
            RoleName::Label => "label",
            RoleName::Other(s) => s,
        }
    }

    /// The role a name names; unknown names are [`RoleName::Other`].
    pub fn from_name(name: &str) -> RoleName {
        match name {
            "any" => RoleName::Any,
            "guests" => RoleName::Guests,
            "users" => RoleName::Users,
            "user" => RoleName::User,
            "team" => RoleName::Team,
            "member" => RoleName::Member,
            "label" => RoleName::Label,
            other => RoleName::Other(other.to_owned()),
        }
    }
}

/// A role: a name, an optional identifier and an optional dimension,
/// written `name:identifier/dimension`.
///
/// ```
/// use utopia_database::helpers::role::Role;
///
/// let role = Role::parse("team:123/owner").unwrap();
/// assert_eq!(role.name().as_str(), "team");
/// assert_eq!(role.identifier(), "123");
/// assert_eq!(role.dimension(), "owner");
/// assert_eq!(Role::user("123", "verified").to_string(), "user:123/verified");
/// assert_eq!(Role::parse("users/a/b").unwrap_err().message(), "Only one dimension can be provided");
/// ```
///
/// PHP: `Utopia\Database\Helpers\Role`. As in PHP, an identifier or a
/// dimension of `"0"` is falsy: [`Role::to_string`] leaves it out, and
/// [`Role::parse`] rejects a `"0"` dimension as empty.
#[derive(Clone, PartialEq, Eq, Hash, Debug)]
pub struct Role {
    name: RoleName,
    identifier: String,
    dimension: String,
}

/// PHP `empty($string)` / falsy string: `""` or `"0"`.
pub(crate) fn falsy(s: &str) -> bool {
    s.is_empty() || s == "0"
}

impl Role {
    /// `new Role($role, $identifier, $dimension)`.
    pub fn new(name: RoleName, identifier: impl Into<String>, dimension: impl Into<String>) -> Self {
        Self { name, identifier: identifier.into(), dimension: dimension.into() }
    }

    /// A role from its name as text (`new Role('team', ...)`).
    pub fn named(name: &str, identifier: impl Into<String>, dimension: impl Into<String>) -> Self {
        Self::new(RoleName::from_name(name), identifier, dimension)
    }

    /// The name (`getRole()`).
    pub fn name(&self) -> &RoleName {
        &self.name
    }

    /// The identifier, empty when none (`getIdentifier()`).
    pub fn identifier(&self) -> &str {
        &self.identifier
    }

    /// The dimension, empty when none (`getDimension()`).
    pub fn dimension(&self) -> &str {
        &self.dimension
    }

    /// `Role::parse($role)`: `name`, `name:identifier`, `name/dimension` or
    /// `name:identifier/dimension`. Only the first `:` separates the
    /// identifier; text after a second `:` is dropped, as PHP does.
    ///
    /// Fails ([`crate::Kind::Exception`], PHP's `\Exception`) with `Only one
    /// dimension can be provided` or `Dimension must not be empty`.
    pub fn parse(role: &str) -> Result<Self> {
        let parts: Vec<&str> = role.split(':').collect();
        let has_identifier = parts.len() > 1;
        let has_dimension = role.contains('/');
        let name = parts[0];

        if !has_identifier && !has_dimension {
            return Ok(Self::named(name, "", ""));
        }
        if has_identifier && !has_dimension {
            return Ok(Self::named(name, parts[1], ""));
        }
        let (name, identifier, dimension) = if !has_identifier {
            let (name, dimension) = split_dimension(name)?;
            (name, "", dimension)
        } else {
            let (identifier, dimension) = split_dimension(parts[1])?;
            (name, identifier, dimension)
        };
        Ok(Self::named(name, identifier, dimension))
    }

    /// `Role::user($identifier, $status)`.
    pub fn user(identifier: impl Into<String>, status: impl Into<String>) -> Self {
        Self::new(RoleName::User, identifier, status)
    }

    /// `Role::users($status)`.
    pub fn users(status: impl Into<String>) -> Self {
        Self::new(RoleName::Users, "", status)
    }

    /// `Role::team($identifier, $dimension)`.
    pub fn team(identifier: impl Into<String>, dimension: impl Into<String>) -> Self {
        Self::new(RoleName::Team, identifier, dimension)
    }

    /// `Role::label($identifier)`.
    pub fn label(identifier: impl Into<String>) -> Self {
        Self::new(RoleName::Label, identifier, "")
    }

    /// `Role::any()`.
    pub fn any() -> Self {
        Self::new(RoleName::Any, "", "")
    }

    /// `Role::guests()`.
    pub fn guests() -> Self {
        Self::new(RoleName::Guests, "", "")
    }

    /// `Role::member($identifier)`.
    pub fn member(identifier: impl Into<String>) -> Self {
        Self::new(RoleName::Member, identifier, "")
    }
}

/// `explode('/', $s)` into exactly two parts, the second not empty.
fn split_dimension(s: &str) -> Result<(&str, &str)> {
    let mut parts = s.split('/');
    let (Some(first), Some(second), None) = (parts.next(), parts.next(), parts.next()) else {
        return Err(Error::new(crate::Kind::Exception, "Only one dimension can be provided"));
    };
    if falsy(second) {
        return Err(Error::new(crate::Kind::Exception, "Dimension must not be empty"));
    }
    Ok((first, second))
}

impl fmt::Display for Role {
    /// `toString()`: `name`, then `:identifier` and `/dimension` when they
    /// are truthy.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.name.as_str())?;
        if !falsy(&self.identifier) {
            write!(f, ":{}", self.identifier)?;
        }
        if !falsy(&self.dimension) {
            write!(f, "/{}", self.dimension)?;
        }
        Ok(())
    }
}
