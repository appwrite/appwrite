use std::collections::HashMap;
use std::sync::OnceLock;

use crate::error::DsnError;
use crate::parse::{parse_url, php_empty, php_parse_str, php_urldecode};

/// Parsed Data Source Name.
///
/// Rust port of PHP `Utopia\DSN\DSN`. Construct with [`Dsn::new`].
#[derive(Debug)]
pub struct Dsn {
    scheme: String,
    user: Option<String>,
    password: Option<String>,
    host: String,
    port: Option<String>,
    path: String,
    query: Option<String>,
    params: OnceLock<HashMap<String, String>>,
}

impl Clone for Dsn {
    fn clone(&self) -> Self {
        let cloned = Self {
            scheme: self.scheme.clone(),
            user: self.user.clone(),
            password: self.password.clone(),
            host: self.host.clone(),
            port: self.port.clone(),
            path: self.path.clone(),
            query: self.query.clone(),
            params: OnceLock::new(),
        };
        if let Some(params) = self.params.get() {
            let _ = cloned.params.set(params.clone());
        }
        cloned
    }
}

impl Dsn {
    /// Parse `dsn`.
    ///
    /// # Errors
    ///
    /// Returns [`DsnError::InvalidArgument`] when PHP `parse_url` fails, the
    /// scheme is missing, or the host is missing.
    pub fn new(dsn: impl AsRef<str>) -> Result<Self, DsnError> {
        let dsn = dsn.as_ref();
        let parts = parse_url(dsn).ok_or_else(|| DsnError::unparseable(dsn))?;

        if php_empty(parts.scheme.as_deref()) {
            return Err(DsnError::scheme_required());
        }
        if php_empty(parts.host.as_deref()) {
            return Err(DsnError::host_required());
        }

        Ok(Self {
            scheme: parts.scheme.unwrap_or_default(),
            user: parts.user.map(|user| php_urldecode(&user)),
            password: parts.pass.map(|pass| php_urldecode(&pass)),
            host: parts.host.unwrap_or_default(),
            port: parts.port.map(|port| port.to_string()),
            path: parts
                .path
                .map(|path| path.trim_start_matches('/').to_string())
                .unwrap_or_default(),
            query: parts.query,
            params: OnceLock::new(),
        })
    }

    /// DSN scheme (`mariadb`, `mysql`, `s3`, …).
    pub fn get_scheme(&self) -> &str {
        &self.scheme
    }

    /// Userinfo user, URL-decoded. `None` when omitted.
    pub fn get_user(&self) -> Option<&str> {
        self.user.as_deref()
    }

    /// Userinfo password, URL-decoded.
    ///
    /// Distinguishes omitted (`None`, `user@host`) from empty (`Some("")`,
    /// `user:@host`).
    pub fn get_password(&self) -> Option<&str> {
        self.password.as_deref()
    }

    /// Host (required).
    pub fn get_host(&self) -> &str {
        &self.host
    }

    /// Port as a decimal string, matching PHP's coerced `?string` (`"3306"`).
    pub fn get_port(&self) -> Option<&str> {
        self.port.as_deref()
    }

    /// Path with leading `/` stripped.
    ///
    /// PHP types this as `?string` but always stores a string: missing path
    /// becomes `""`, not `null`.
    pub fn get_path(&self) -> &str {
        &self.path
    }

    /// Raw query string (still encoded). `None` when omitted.
    pub fn get_query(&self) -> Option<&str> {
        self.query.as_deref()
    }

    /// Query parameter by key. Values are URL-decoded via PHP `parse_str`.
    ///
    /// PHP default for `default` is `''`.
    pub fn get_param(&self, key: &str, default: &str) -> String {
        self.params()
            .get(key)
            .cloned()
            .unwrap_or_else(|| default.to_string())
    }

    fn params(&self) -> &HashMap<String, String> {
        self.params.get_or_init(|| {
            if php_empty(self.query.as_deref()) {
                HashMap::new()
            } else {
                php_parse_str(self.query.as_deref().unwrap_or(""))
            }
        })
    }
}

/// PHP class name alias (`Utopia\DSN\DSN`).
#[allow(non_camel_case_types)]
pub type DSN = Dsn;
