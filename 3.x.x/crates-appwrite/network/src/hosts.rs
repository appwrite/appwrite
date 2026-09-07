//! Per-request CORS/CSRF hostname allow list.
//! Rust port of the `allowedHostnames` DI resource in `app/init/resources/request.php`.

use serde_json::Value;

use crate::platform::{get_hostnames, push_unique};
use crate::url::url_host;

/// Inputs for [`allowed_hostnames`], matching the PHP `allowedHostnames`
/// resource's dependencies (`platform`, `project`, `rule`, `devKey`, `request`).
#[derive(Debug, Clone, Copy)]
pub struct AllowedHostnames<'a> {
    pub platform_hostnames: &'a [String],
    pub project_id: Option<&'a str>,
    pub project_platforms: &'a [Value],
    pub has_dev_key: bool,
    pub request_hostname: &'a str,
    pub origin: &'a str,
    pub referer: &'a str,
    pub is_options: bool,
    pub rule_domain: Option<&'a str>,
}

/// PHP `app/config/platform.php` `hostnames`.
#[must_use]
pub fn platform_hostnames() -> Vec<String> {
    let mut hosts = Vec::new();
    for key in ["_APP_DOMAIN", "_APP_CONSOLE_DOMAIN"] {
        let value = utopia_system::get_env(key, "localhost");
        if !value.is_empty() {
            push_unique(&mut hosts, value);
        }
    }
    let migration = utopia_system::get_env("_APP_MIGRATION_HOST", "");
    if !migration.is_empty() {
        push_unique(&mut hosts, migration);
    }
    hosts
}

/// PHP `app/config/platform.php` `schemas` (`_APP_CONSOLE_SCHEMA`).
#[must_use]
pub fn platform_schemes() -> Vec<String> {
    utopia_system::get_env("_APP_CONSOLE_SCHEMA", "")
        .split(',')
        .map(str::trim)
        .filter(|s| !s.is_empty())
        .map(ToString::to_string)
        .collect()
}

/// PHP `allowedHostnames` resource.
#[must_use]
pub fn allowed_hostnames(input: AllowedHostnames<'_>) -> Vec<String> {
    let mut allowed = Vec::new();
    for host in input.platform_hostnames {
        if !host.is_empty() {
            push_unique(&mut allowed, host.clone());
        }
    }

    if let Some(project_id) = input.project_id {
        if !project_id.is_empty() && project_id != "console" {
            for host in get_hostnames(input.project_platforms) {
                push_unique(&mut allowed, host);
            }
        }
    }

    if input.has_dev_key && !input.request_hostname.is_empty() {
        push_unique(&mut allowed, input.request_hostname.to_string());
    }

    let origin_hostname = url_host(input.origin).unwrap_or_default();
    let referer_hostname = url_host(input.referer).unwrap_or_default();
    let hostname = if origin_hostname.is_empty() {
        referer_hostname
    } else {
        origin_hostname
    };

    if input.is_options && !hostname.is_empty() {
        push_unique(&mut allowed, hostname.clone());
    }

    if let Some(domain) = input.rule_domain {
        if !domain.is_empty() {
            push_unique(&mut allowed, domain.to_string());
        }
    }

    if input.has_dev_key && !hostname.is_empty() {
        push_unique(&mut allowed, hostname);
    }

    allowed
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn options_adds_origin_hostname() {
        let platform = vec!["localhost".to_string()];
        let hosts = allowed_hostnames(AllowedHostnames {
            platform_hostnames: &platform,
            project_id: Some("proj1"),
            project_platforms: &[],
            has_dev_key: false,
            request_hostname: "api.example.com",
            origin: "https://evil.com",
            referer: "",
            is_options: true,
            rule_domain: None,
        });
        assert!(hosts.contains(&"localhost".to_string()));
        assert!(hosts.contains(&"evil.com".to_string()));
    }

    #[test]
    fn project_web_platforms_are_included() {
        let platform = vec!["localhost".to_string()];
        let platforms = [json!({ "type": "web", "hostname": "app.example.com" })];
        let hosts = allowed_hostnames(AllowedHostnames {
            platform_hostnames: &platform,
            project_id: Some("proj1"),
            project_platforms: &platforms,
            has_dev_key: false,
            request_hostname: "",
            origin: "",
            referer: "",
            is_options: false,
            rule_domain: None,
        });
        assert!(hosts.contains(&"app.example.com".to_string()));
    }

    #[test]
    fn console_project_skips_platform_documents() {
        let platform = vec!["localhost".to_string()];
        let platforms = [json!({ "type": "web", "hostname": "app.example.com" })];
        let hosts = allowed_hostnames(AllowedHostnames {
            platform_hostnames: &platform,
            project_id: Some("console"),
            project_platforms: &platforms,
            has_dev_key: false,
            request_hostname: "",
            origin: "",
            referer: "",
            is_options: false,
            rule_domain: None,
        });
        assert!(!hosts.contains(&"app.example.com".to_string()));
    }
}
