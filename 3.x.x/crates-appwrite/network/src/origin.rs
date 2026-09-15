//! CSRF origin allow-list. Rust port of `Appwrite\Network\Validator\Origin`.

use crate::hostname::HostnameList;
use crate::platform::{
    get_name_by_scheme, LOOPBACK_ALIASES, LOOPBACK_HOSTNAME, SCHEME_CHROME_EXTENSION,
    SCHEME_EDGE_EXTENSION, SCHEME_FIREFOX_EXTENSION, SCHEME_HTTP, SCHEME_HTTPS,
    SCHEME_SAFARI_EXTENSION, SCHEME_TAURI,
};
use crate::url::url_host;

const WEB_PLATFORMS: &[&str] = &[
    SCHEME_HTTP,
    SCHEME_HTTPS,
    SCHEME_CHROME_EXTENSION,
    SCHEME_FIREFOX_EXTENSION,
    SCHEME_SAFARI_EXTENSION,
    SCHEME_EDGE_EXTENSION,
    SCHEME_TAURI,
];

/// PHP `Appwrite\Network\Validator\Origin`.
#[derive(Debug, Clone)]
pub struct Origin {
    allowed_hostnames: Vec<String>,
    allowed_schemes: Vec<String>,
    scheme: Option<String>,
    host: Option<String>,
    origin: String,
}

impl Origin {
    #[must_use]
    pub fn new(
        allowed_hostnames: impl IntoIterator<Item = impl Into<String>>,
        allowed_schemes: impl IntoIterator<Item = impl Into<String>>,
    ) -> Self {
        Self {
            allowed_hostnames: allowed_hostnames.into_iter().map(Into::into).collect(),
            allowed_schemes: allowed_schemes.into_iter().map(Into::into).collect(),
            scheme: None,
            host: None,
            origin: String::new(),
        }
    }

    pub fn set_allowed_hostnames(
        &mut self,
        allowed_hostnames: impl IntoIterator<Item = impl Into<String>>,
    ) -> &mut Self {
        self.allowed_hostnames = allowed_hostnames.into_iter().map(Into::into).collect();
        self
    }

    pub fn set_allowed_schemes(
        &mut self,
        allowed_schemes: impl IntoIterator<Item = impl Into<String>>,
    ) -> &mut Self {
        self.allowed_schemes = allowed_schemes.into_iter().map(Into::into).collect();
        self
    }

    #[must_use]
    pub fn allowed_hostnames(&self) -> &[String] {
        &self.allowed_hostnames
    }

    #[must_use]
    pub fn allowed_schemes(&self) -> &[String] {
        &self.allowed_schemes
    }

    /// PHP `Origin::isValid()`.
    #[must_use]
    pub fn is_valid(&mut self, origin: &str) -> bool {
        if origin.is_empty() {
            return false;
        }
        self.origin = origin.to_string();
        self.scheme = parse_scheme(origin);
        self.host = url_host(origin).map(|h| h.to_ascii_lowercase());

        if let Some(scheme) = &self.scheme {
            if WEB_PLATFORMS.contains(&scheme.as_str()) {
                let host = self.host.clone().unwrap_or_default();
                let validator = HostnameList::new(self.allowed_hostnames.clone());
                if validator.is_valid(&host) {
                    return true;
                }
                return LOOPBACK_ALIASES.contains(&host.as_str())
                    && validator.is_valid(LOOPBACK_HOSTNAME);
            }
        }

        if let Some(scheme) = &self.scheme {
            if !scheme.is_empty() && self.allowed_schemes.iter().any(|s| s == scheme) {
                return true;
            }
        }
        false
    }

    /// PHP `Origin::getDescription()`.
    #[must_use]
    pub fn description(&self) -> String {
        let platform = get_name_by_scheme(self.scheme.as_deref());
        let host = self
            .host
            .as_deref()
            .filter(|h| !h.is_empty())
            .map_or(String::new(), |h| format!("({h})"));

        if self.host.as_deref().unwrap_or("").is_empty() && self.scheme.is_none() {
            return "Invalid Origin.".into();
        }

        if platform.is_empty() {
            let scheme = self.scheme.as_deref().unwrap_or("");
            return format!(
                "Invalid Scheme. The scheme used ({scheme}) in the Origin ({origin}) is not supported. If you are using a custom scheme, please change it to `appwrite-callback-<PROJECT_ID>`",
                origin = self.origin
            );
        }

        format!(
            "Invalid Origin. Register your new client {host} as a new {platform} platform on your project console dashboard"
        )
    }
}

/// PHP `Origin::parseScheme()`.
#[must_use]
pub fn parse_scheme(uri: &str) -> Option<String> {
    let uri = uri.trim();
    if uri.is_empty() {
        return None;
    }
    if let Some((scheme, _)) = uri.split_once(':') {
        if is_scheme_token(scheme) {
            return Some(scheme.to_ascii_lowercase());
        }
    }
    None
}

fn is_scheme_token(scheme: &str) -> bool {
    let mut chars = scheme.chars();
    let Some(first) = chars.next() else {
        return false;
    };
    if !first.is_ascii_alphabetic() {
        return false;
    }
    chars.all(|c| c.is_ascii_alphanumeric() || matches!(c, '+' | '.' | '-'))
}

#[cfg(test)]
mod tests {
    use super::Origin;

    #[test]
    fn values() {
        let mut validator = Origin::new(
            [
                "appwrite.io",
                "appwrite.test",
                "localhost",
                "appwrite.flutter",
            ],
            ["exp", "appwrite-callback-123"],
        );
        assert!(!validator.is_valid(""));
        assert!(!validator.is_valid("/"));
        assert!(validator.is_valid("https://localhost"));
        assert!(validator.is_valid("http://localhost:80"));
        assert!(validator.is_valid("https://appwrite.io"));
        assert!(!validator.is_valid("https://example.com"));
        assert!(validator.is_valid("exp://"));
        assert!(validator.is_valid("appwrite-callback-123://"));
        assert!(!validator.is_valid("appwrite-callback-456://"));
        assert!(!validator.is_valid("appwrite-ios://com.company.appname"));
        assert_eq!(
            validator.description(),
            "Invalid Origin. Register your new client (com.company.appname) as a new iOS platform on your project console dashboard"
        );
        assert!(validator.is_valid("tauri://localhost"));
        assert!(!validator.is_valid("tauri://example.com"));
        assert!(!validator.is_valid("random-scheme://localhost"));
        assert!(validator.description().contains("Invalid Scheme"));
    }

    #[test]
    fn loopback() {
        let mut validator = Origin::new(["appwrite.io", "localhost"], ["exp"]);
        assert!(validator.is_valid("http://localhost"));
        assert!(validator.is_valid("http://localhost:3000"));
        assert!(validator.is_valid("http://127.0.0.1"));
        assert!(validator.is_valid("https://127.0.0.1:5173"));
        assert!(validator.is_valid("http://[::1]"));
        assert!(validator.is_valid("http://[::1]:3000"));
        assert!(!validator.is_valid("http://127.0.0.1.evil.com"));
        assert!(!validator.is_valid("http://localhost.evil.com"));
        assert!(!validator.is_valid("http://128.0.0.1"));
        assert!(!validator.is_valid("http://[2001:db8::1]"));
        assert!(!validator.is_valid("http://xlocalhost"));
        assert!(!validator.is_valid("http://127.0.0.2"));
        assert!(!validator.is_valid("http://[0:0:0:0:0:0:0:1]"));
        assert!(!validator.is_valid("http://localhost."));
        assert!(!validator.is_valid("random-scheme://127.0.0.1"));
    }

    #[test]
    fn loopback_requires_localhost() {
        let mut validator = Origin::new(["appwrite.io"], ["exp"]);
        assert!(!validator.is_valid("http://localhost"));
        assert!(!validator.is_valid("http://127.0.0.1"));
        assert!(!validator.is_valid("http://[::1]"));
    }
}
