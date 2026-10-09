//! Permission strings (`read("user:abc")`) and roles.

/// Permission types stored in `_permissions` / `_perms`.
pub const TYPES: [&str; 4] = ["create", "read", "update", "delete"];

/// `Permission::toString()`: `type("role")`.
pub fn format(kind: &str, role: &str) -> String {
    format!("{kind}(\"{role}\")")
}

pub fn read(role: &str) -> String {
    format("read", role)
}

pub fn update(role: &str) -> String {
    format("update", role)
}

pub fn delete(role: &str) -> String {
    format("delete", role)
}

/// Roles granted `kind` by a permission list (`Document::getPermissionsByType`).
pub fn roles_for<'a>(permissions: &'a [String], kind: &'a str) -> impl Iterator<Item = String> + 'a {
    permissions.iter().filter(move |p| p.starts_with(kind)).filter_map(move |p| {
        let rest = p.strip_prefix(kind)?.strip_prefix('(')?;
        let role: String = rest.chars().filter(|c| *c != ')' && *c != '"' && *c != ' ').collect();
        Some(role)
    })
}

/// Splits permissions into `(type, role)` pairs for the `_perms` table.
pub fn pairs(permissions: &[String]) -> (Vec<String>, Vec<String>) {
    let mut types = Vec::new();
    let mut roles = Vec::new();
    for kind in TYPES {
        let mut seen: Vec<String> = Vec::new();
        for role in roles_for(permissions, kind) {
            if !seen.contains(&role) {
                seen.push(role.clone());
                types.push(kind.to_owned());
                roles.push(role);
            }
        }
    }
    (types, roles)
}

/// Role helpers (`Role::user()`, ...).
pub mod role {
    pub fn user(id: &str) -> String {
        format!("user:{id}")
    }
    pub fn team(id: &str, dimension: Option<&str>) -> String {
        match dimension {
            Some(d) => format!("team:{id}/{d}"),
            None => format!("team:{id}"),
        }
    }
    pub fn member(id: &str) -> String {
        format!("member:{id}")
    }
    pub fn label(id: &str) -> String {
        format!("label:{id}")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn splits_permissions() {
        let perms = vec![read("any"), update("user:abc"), delete("user:abc"), read("any")];
        let (types, roles) = pairs(&perms);
        assert_eq!(types, vec!["read", "update", "delete"]);
        assert_eq!(roles, vec!["any", "user:abc", "user:abc"]);
    }
}
