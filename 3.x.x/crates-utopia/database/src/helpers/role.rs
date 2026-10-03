//! PHP `Utopia\Database\Helpers\Role`.

use crate::error::{DatabaseError, Result};

/// PHP `Utopia\Database\Helpers\Role`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Role {
    role: String,
    identifier: String,
    dimension: String,
}

impl Role {
    #[must_use]
    pub fn new(
        role: impl Into<String>,
        identifier: impl Into<String>,
        dimension: impl Into<String>,
    ) -> Self {
        Self {
            role: role.into(),
            identifier: identifier.into(),
            dimension: dimension.into(),
        }
    }

    #[must_use]
    pub fn to_string(&self) -> String {
        let mut s = self.role.clone();
        if !self.identifier.is_empty() {
            s.push(':');
            s.push_str(&self.identifier);
        }
        if !self.dimension.is_empty() {
            s.push('/');
            s.push_str(&self.dimension);
        }
        s
    }

    #[must_use]
    pub fn get_role(&self) -> &str {
        &self.role
    }

    #[must_use]
    pub fn get_identifier(&self) -> &str {
        &self.identifier
    }

    #[must_use]
    pub fn get_dimension(&self) -> &str {
        &self.dimension
    }

    pub fn parse(role: &str) -> Result<Self> {
        let role_parts: Vec<&str> = role.splitn(2, ':').collect();
        let has_identifier = role_parts.len() > 1;
        let has_dimension = role.contains('/');
        let role_name = role_parts[0];

        if !has_identifier && !has_dimension {
            return Ok(Self::new(role_name, "", ""));
        }

        if has_identifier && !has_dimension {
            return Ok(Self::new(role_name, role_parts[1], ""));
        }

        if !has_identifier {
            let dimension_parts: Vec<&str> = role.split('/').collect();
            if dimension_parts.len() != 2 {
                return Err(DatabaseError::database(
                    "Only one dimension can be provided",
                ));
            }
            let role_n = dimension_parts[0];
            let dimension = dimension_parts[1];
            if dimension.is_empty() {
                return Err(DatabaseError::database("Dimension must not be empty"));
            }
            return Ok(Self::new(role_n, "", dimension));
        }

        let dimension_parts: Vec<&str> = role_parts[1].split('/').collect();
        if dimension_parts.len() != 2 {
            return Err(DatabaseError::database(
                "Only one dimension can be provided",
            ));
        }
        let identifier = dimension_parts[0];
        let dimension = dimension_parts[1];
        if dimension.is_empty() {
            return Err(DatabaseError::database("Dimension must not be empty"));
        }
        Ok(Self::new(role_name, identifier, dimension))
    }

    #[must_use]
    pub fn user(identifier: impl Into<String>, status: impl Into<String>) -> Self {
        Self::new("user", identifier, status)
    }

    #[must_use]
    pub fn users(status: impl Into<String>) -> Self {
        Self::new("users", "", status)
    }

    #[must_use]
    pub fn team(identifier: impl Into<String>, dimension: impl Into<String>) -> Self {
        Self::new("team", identifier, dimension)
    }

    #[must_use]
    pub fn label(identifier: impl Into<String>) -> Self {
        Self::new("label", identifier, "")
    }

    #[must_use]
    pub fn any() -> Self {
        Self::new("any", "", "")
    }

    #[must_use]
    pub fn guests() -> Self {
        Self::new("guests", "", "")
    }

    #[must_use]
    pub fn member(identifier: impl Into<String>) -> Self {
        Self::new("member", identifier, "")
    }
}
