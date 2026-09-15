//! Generate CORS response headers. Rust port of `Appwrite\Network\Cors`.

use crate::hostname::HostnameList;
use crate::platform::{LOOPBACK_ALIASES, LOOPBACK_HOSTNAME};
use crate::url::url_host;

pub const HEADER_ALLOW_ORIGIN: &str = "Access-Control-Allow-Origin";
pub const HEADER_ALLOW_METHODS: &str = "Access-Control-Allow-Methods";
pub const HEADER_ALLOW_HEADERS: &str = "Access-Control-Allow-Headers";
pub const HEADER_ALLOW_CREDENTIALS: &str = "Access-Control-Allow-Credentials";
pub const HEADER_EXPOSE_HEADERS: &str = "Access-Control-Expose-Headers";
pub const HEADER_MAX_AGE: &str = "Access-Control-Max-Age";

/// PHP `InvalidArgumentException` when `['*']` is combined with credentials.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CorsError {
    pub message: &'static str,
}

impl std::fmt::Display for CorsError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.message)
    }
}

impl std::error::Error for CorsError {}

/// Allowed origins are matched by hostname only, with loopback hostnames
/// accepted when `localhost` is on the allow list. Arrays passed to
/// [`Cors::new`] are formatted into comma-separated header strings.
#[derive(Debug, Clone)]
pub struct Cors {
    allowed_hosts: Vec<String>,
    allowed_methods: String,
    allowed_headers: String,
    exposed_headers: String,
    allow_credentials: bool,
    max_age: u64,
}

impl Cors {
    /// PHP `Cors::__construct()`.
    pub fn new(
        allowed_hosts: impl IntoIterator<Item = impl Into<String>>,
        allowed_methods: impl IntoIterator<Item = impl Into<String>>,
        allowed_headers: impl IntoIterator<Item = impl Into<String>>,
        exposed_headers: impl IntoIterator<Item = impl Into<String>>,
        allow_credentials: bool,
        max_age: u64,
    ) -> Result<Self, CorsError> {
        let allowed_hosts: Vec<String> = allowed_hosts
            .into_iter()
            .map(|h| h.into().to_ascii_lowercase())
            .collect();
        if allowed_hosts == ["*"] && allow_credentials {
            return Err(CorsError {
                message: "CORS invariant violated: cannot use wildcard origin \"*\" when credentials are enabled.",
            });
        }
        Ok(Self {
            allowed_hosts,
            allowed_methods: join_csv(allowed_methods),
            allowed_headers: join_csv(allowed_headers),
            exposed_headers: join_csv(exposed_headers),
            allow_credentials,
            max_age,
        })
    }

    /// Credentialed CORS using [`crate::config`] lists (PHP `cors` DI resource).
    pub fn from_allowed_hosts(
        allowed_hosts: impl IntoIterator<Item = impl Into<String>>,
    ) -> Result<Self, CorsError> {
        Self::new(
            allowed_hosts,
            crate::ALLOWED_METHODS.iter().copied(),
            crate::ALLOWED_HEADERS.iter().copied(),
            crate::EXPOSED_HEADERS.iter().copied(),
            true,
            crate::MAX_AGE,
        )
    }

    /// Build CORS headers for a given request origin. PHP `Cors::headers()`.
    #[must_use]
    pub fn headers(&self, origin: &str) -> Vec<(String, String)> {
        let mut headers = vec![
            (
                HEADER_ALLOW_METHODS.to_string(),
                self.allowed_methods.clone(),
            ),
            (
                HEADER_ALLOW_HEADERS.to_string(),
                self.allowed_headers.clone(),
            ),
            (
                HEADER_EXPOSE_HEADERS.to_string(),
                self.exposed_headers.clone(),
            ),
            (
                HEADER_ALLOW_CREDENTIALS.to_string(),
                if self.allow_credentials {
                    "true".to_string()
                } else {
                    "false".to_string()
                },
            ),
            (HEADER_MAX_AGE.to_string(), self.max_age.to_string()),
        ];

        if self.allowed_hosts == ["*"] {
            headers.push((HEADER_ALLOW_ORIGIN.to_string(), origin.to_string()));
            return headers;
        }

        let origin = origin.trim().to_ascii_lowercase();
        if origin.is_empty() {
            return headers;
        }

        let Some(host) = url_host(&origin) else {
            return headers;
        };
        if host.is_empty() {
            return headers;
        }

        let validator = HostnameList::new(self.allowed_hosts.clone());
        if !validator.is_valid(&host) {
            let alias =
                LOOPBACK_ALIASES.contains(&host.as_str()) && validator.is_valid(LOOPBACK_HOSTNAME);
            if !alias {
                return headers;
            }
        }

        headers.push((HEADER_ALLOW_ORIGIN.to_string(), origin));
        headers
    }
}

fn join_csv(items: impl IntoIterator<Item = impl Into<String>>) -> String {
    items
        .into_iter()
        .map(Into::into)
        .collect::<Vec<_>>()
        .join(", ")
}

#[cfg(test)]
mod tests {
    use super::*;

    fn header<'a>(headers: &'a [(String, String)], name: &str) -> Option<&'a str> {
        headers
            .iter()
            .find(|(k, _)| k == name)
            .map(|(_, v)| v.as_str())
    }

    #[test]
    fn wildcard_with_credentials_throws() {
        let err = Cors::new(
            ["*"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            true,
            86_400,
        )
        .unwrap_err();
        assert!(err.message.contains("wildcard origin"));
    }

    #[test]
    fn wildcard_allows_any_origin() {
        let cors = Cors::new(
            ["*"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            false,
            86_400,
        )
        .unwrap();
        let result = cors.headers("https://foo.com");
        assert_eq!(
            header(&result, HEADER_ALLOW_ORIGIN),
            Some("https://foo.com")
        );
    }

    #[test]
    fn subdomain_wildcard_allows_any_subdomain() {
        let cors = Cors::new(
            ["*.example.com"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            false,
            86_400,
        )
        .unwrap();
        let result = cors.headers("https://foo.example.com");
        assert_eq!(
            header(&result, HEADER_ALLOW_ORIGIN),
            Some("https://foo.example.com")
        );
    }

    #[test]
    fn empty_origin_returns_static_headers_only() {
        let cors = Cors::new(
            ["example.com"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            false,
            86_400,
        )
        .unwrap();
        let result = cors.headers("");
        assert_eq!(header(&result, HEADER_ALLOW_ORIGIN), None);
        assert_eq!(header(&result, HEADER_ALLOW_CREDENTIALS), Some("false"));
        assert_eq!(header(&result, HEADER_ALLOW_METHODS), Some("GET"));
    }

    #[test]
    fn invalid_origin_returns_static_headers_only() {
        let cors = Cors::new(
            ["example.com"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            false,
            86_400,
        )
        .unwrap();
        let result = cors.headers("%%%not-a-url%%%");
        assert_eq!(header(&result, HEADER_ALLOW_ORIGIN), None);
    }

    #[test]
    fn unlisted_origin_returns_static_headers_only() {
        let cors = Cors::new(
            ["allowed.com"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            false,
            86_400,
        )
        .unwrap();
        let result = cors.headers("https://forbidden.com");
        assert_eq!(header(&result, HEADER_ALLOW_ORIGIN), None);
    }

    #[test]
    fn allowed_origin_is_returned() {
        let cors = Cors::new(
            ["example.com"],
            ["POST"],
            ["X-Test"],
            Vec::<String>::new(),
            true,
            86_400,
        )
        .unwrap();
        let result = cors.headers("https://example.com");
        assert_eq!(
            header(&result, HEADER_ALLOW_ORIGIN),
            Some("https://example.com")
        );
    }

    #[test]
    fn origin_is_lowercased_for_matching() {
        let cors = Cors::new(
            ["example.com"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            false,
            86_400,
        )
        .unwrap();
        let result = cors.headers("HTTPS://EXAMPLE.COM");
        assert_eq!(
            header(&result, HEADER_ALLOW_ORIGIN),
            Some("https://example.com")
        );
    }

    #[test]
    fn loopback_origin_is_allowed() {
        let cors = Cors::new(
            ["example.com", "localhost"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            true,
            86_400,
        )
        .unwrap();
        for origin in [
            "http://localhost",
            "http://localhost:3000",
            "http://127.0.0.1",
            "https://127.0.0.1:5173",
            "http://[::1]",
            "http://[::1]:3000",
        ] {
            assert_eq!(
                header(&cors.headers(origin), HEADER_ALLOW_ORIGIN),
                Some(origin),
                "origin {origin}"
            );
        }
    }

    #[test]
    fn loopback_origin_is_echoed_exactly() {
        let cors = Cors::new(
            ["example.com", "localhost"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            true,
            86_400,
        )
        .unwrap();
        let result = cors.headers("http://127.0.0.1:5173");
        assert_eq!(
            header(&result, HEADER_ALLOW_ORIGIN),
            Some("http://127.0.0.1:5173")
        );
        assert_ne!(header(&result, HEADER_ALLOW_ORIGIN), Some("*"));
        assert_eq!(header(&result, HEADER_ALLOW_CREDENTIALS), Some("true"));
    }

    #[test]
    fn loopback_lookalike_origin_is_rejected() {
        let cors = Cors::new(
            ["example.com", "localhost"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            true,
            86_400,
        )
        .unwrap();
        for origin in [
            "http://127.0.0.1.evil.com",
            "http://localhost.evil.com",
            "http://128.0.0.1",
            "http://[2001:db8::1]",
            "http://xlocalhost",
            "http://127.0.0.1x.example.com",
            "http://[::1].evil.com",
            "http://[::1]evil.com",
            "http://127.0.0.2",
            "http://[0:0:0:0:0:0:0:1]",
            "http://localhost.",
        ] {
            assert_eq!(
                header(&cors.headers(origin), HEADER_ALLOW_ORIGIN),
                None,
                "origin {origin} was unexpectedly allowed"
            );
        }
    }

    #[test]
    fn loopback_requires_localhost_to_be_allowed() {
        let cors = Cors::new(
            ["example.com"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            true,
            86_400,
        )
        .unwrap();
        for origin in [
            "http://localhost",
            "http://localhost:3000",
            "http://127.0.0.1",
            "https://127.0.0.1:5173",
            "http://[::1]",
            "http://[::1]:3000",
        ] {
            assert_eq!(
                header(&cors.headers(origin), HEADER_ALLOW_ORIGIN),
                None,
                "origin {origin} was unexpectedly allowed"
            );
        }
    }

    #[test]
    fn header_formatting() {
        let cors = Cors::new(
            ["example.com"],
            ["GET", "POST"],
            ["X-A", "X-B"],
            ["E1", "E2"],
            true,
            86_400,
        )
        .unwrap();
        let result = cors.headers("https://example.com");
        assert_eq!(header(&result, HEADER_ALLOW_METHODS), Some("GET, POST"));
        assert_eq!(header(&result, HEADER_ALLOW_HEADERS), Some("X-A, X-B"));
        assert_eq!(header(&result, HEADER_EXPOSE_HEADERS), Some("E1, E2"));
        assert_eq!(header(&result, HEADER_ALLOW_CREDENTIALS), Some("true"));
    }

    #[test]
    fn max_age_included() {
        let cors = Cors::new(
            ["example.com"],
            ["GET"],
            ["X-Test"],
            Vec::<String>::new(),
            false,
            999,
        )
        .unwrap();
        let result = cors.headers("https://example.com");
        assert_eq!(header(&result, HEADER_MAX_AGE), Some("999"));
    }
}
