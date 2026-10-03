//! PHP `Utopia\Database\Helpers\Permission`.

use crate::constants::{PERMISSIONS, PERMISSION_WRITE};
use crate::error::{DatabaseError, Result};
use crate::helpers::Role;

/// PHP `Utopia\Database\Helpers\Permission`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Permission {
    permission: String,
    role: Role,
}

impl Permission {
    #[must_use]
    pub fn new(
        permission: impl Into<String>,
        role: impl Into<String>,
        identifier: impl Into<String>,
        dimension: impl Into<String>,
    ) -> Self {
        Self {
            permission: permission.into(),
            role: Role::new(role, identifier, dimension),
        }
    }

    #[must_use]
    pub fn to_string(&self) -> String {
        format!("{}(\"{}\")", self.permission, self.role.to_string())
    }

    #[must_use]
    pub fn get_permission(&self) -> &str {
        &self.permission
    }

    #[must_use]
    pub fn get_role(&self) -> &str {
        self.role.get_role()
    }

    #[must_use]
    pub fn get_identifier(&self) -> &str {
        self.role.get_identifier()
    }

    #[must_use]
    pub fn get_dimension(&self) -> &str {
        self.role.get_dimension()
    }

    pub fn parse(permission: &str) -> Result<Self> {
        let parts: Vec<&str> = permission.splitn(2, "(\"").collect();
        if parts.len() != 2 {
            return Err(DatabaseError::database(format!(
                "Invalid permission string format: \"{permission}\"."
            )));
        }
        let perm = parts[0];
        let allowed: Vec<&str> = PERMISSIONS
            .iter()
            .copied()
            .chain([PERMISSION_WRITE])
            .collect();
        if !allowed.contains(&perm) {
            return Err(DatabaseError::database(format!(
                "Invalid permission type: \"{perm}\"."
            )));
        }
        let full_role = parts[1].replace("\")", "");
        let role_parts: Vec<&str> = full_role.splitn(2, ':').collect();
        let role = role_parts[0];
        let has_identifier = role_parts.len() > 1;
        let has_dimension = full_role.contains('/');

        if !has_identifier && !has_dimension {
            return Ok(Self::new(perm, role, "", ""));
        }
        if has_identifier && !has_dimension {
            return Ok(Self::new(perm, role, role_parts[1], ""));
        }
        if !has_identifier {
            let dimension_parts: Vec<&str> = full_role.split('/').collect();
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
            return Ok(Self::new(perm, role_n, "", dimension));
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
        Ok(Self::new(perm, role, identifier, dimension))
    }

    pub fn aggregate(
        permissions: Option<&[String]>,
        allowed: &[&str],
    ) -> Result<Option<Vec<String>>> {
        let Some(permissions) = permissions else {
            return Ok(None);
        };
        let write_subs = ["create", "update", "delete"];
        let mut mutated = Vec::new();
        for permission in permissions {
            let parsed = Self::parse(permission)?;
            if parsed.get_permission() != "write" {
                mutated.push(parsed.to_string());
                continue;
            }
            for sub in write_subs {
                if !allowed.contains(&sub) {
                    continue;
                }
                mutated.push(
                    Self::new(
                        sub,
                        parsed.get_role(),
                        parsed.get_identifier(),
                        parsed.get_dimension(),
                    )
                    .to_string(),
                );
            }
        }
        let mut unique = Vec::new();
        for item in mutated {
            if !unique.contains(&item) {
                unique.push(item);
            }
        }
        Ok(Some(unique))
    }

    #[must_use]
    pub fn read(role: &Role) -> String {
        Self::new(
            "read",
            role.get_role(),
            role.get_identifier(),
            role.get_dimension(),
        )
        .to_string()
    }

    #[must_use]
    pub fn create(role: &Role) -> String {
        Self::new(
            "create",
            role.get_role(),
            role.get_identifier(),
            role.get_dimension(),
        )
        .to_string()
    }

    #[must_use]
    pub fn update(role: &Role) -> String {
        Self::new(
            "update",
            role.get_role(),
            role.get_identifier(),
            role.get_dimension(),
        )
        .to_string()
    }

    #[must_use]
    pub fn delete(role: &Role) -> String {
        Self::new(
            "delete",
            role.get_role(),
            role.get_identifier(),
            role.get_dimension(),
        )
        .to_string()
    }

    #[must_use]
    pub fn write(role: &Role) -> String {
        Self::new(
            "write",
            role.get_role(),
            role.get_identifier(),
            role.get_dimension(),
        )
        .to_string()
    }
}
