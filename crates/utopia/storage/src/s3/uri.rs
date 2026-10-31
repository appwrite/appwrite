//! URLs as the PSR-7 `Uri` S3 builds its requests with normalizes them.

use crate::{Error, Result};

/// A parsed URL.
///
/// PHP: `Utopia\Psr7\Uri::parse()`: `parse_url()`, the scheme and host
/// lowercased, characters outside RFC 3986's sets percent-encoded in the
/// path, query and fragment.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) struct Uri {
    pub scheme: String,
    user_info: String,
    pub host: String,
    port: Option<u16>,
    pub path: String,
    query: String,
    fragment: String,
}

impl Uri {
    /// Parses `uri`, or fails with `Invalid URI.` where `parse_url()` does.
    pub fn parse(uri: &str) -> Result<Uri> {
        let url =
            php_std::url::parse_url(uri.as_bytes()).ok_or_else(|| Error::InvalidArgument("Invalid URI.".into()))?;
        let text = |b: Option<std::borrow::Cow<'_, [u8]>>| b.map(|b| String::from_utf8_lossy(&b).into_owned());
        let mut user_info = text(url.user()).unwrap_or_default();
        if let Some(pass) = text(url.pass()) {
            user_info.push(':');
            user_info.push_str(&pass);
        }
        Ok(Uri {
            scheme: text(url.scheme()).map(|s| s.to_ascii_lowercase()).unwrap_or_default(),
            user_info,
            host: text(url.host()).map(|h| h.to_ascii_lowercase()).unwrap_or_default(),
            port: url.port(),
            path: text(url.path()).map(|p| encode(&p, false)).unwrap_or_default(),
            query: text(url.query()).map(|q| encode(&q, true)).unwrap_or_default(),
            fragment: text(url.fragment()).map(|f| encode(&f, true)).unwrap_or_default(),
        })
    }

    /// The port, unless it is the scheme's default.
    ///
    /// PHP: `getPort()`.
    pub fn port(&self) -> Option<u16> {
        match (self.scheme.as_str(), self.port) {
            ("http", Some(80)) | ("https", Some(443)) => None,
            (_, port) => port,
        }
    }

    fn authority(&self) -> String {
        if self.host.is_empty() {
            return String::new();
        }
        let mut authority = String::new();
        if !self.user_info.is_empty() {
            authority.push_str(&self.user_info);
            authority.push('@');
        }
        authority.push_str(&self.host);
        if let Some(port) = self.port() {
            authority.push_str(&format!(":{port}"));
        }
        authority
    }
}

impl std::fmt::Display for Uri {
    /// PHP: `__toString()`.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        if !self.scheme.is_empty() {
            write!(f, "{}:", self.scheme)?;
        }
        let authority = self.authority();
        if !authority.is_empty() {
            write!(f, "//{authority}")?;
        }
        if !authority.is_empty() {
            if !self.path.is_empty() && !self.path.starts_with('/') {
                f.write_str("/")?;
            }
            f.write_str(&self.path)?;
        } else if self.path.starts_with("//") {
            write!(f, "/{}", self.path.trim_start_matches('/'))?;
        } else {
            f.write_str(&self.path)?;
        }
        if !self.query.is_empty() {
            write!(f, "?{}", self.query)?;
        }
        if !self.fragment.is_empty() {
            write!(f, "#{}", self.fragment)?;
        }
        Ok(())
    }
}

/// Percent-encodes the bytes outside RFC 3986's unreserved and sub-delims
/// characters, `:`, `@`, `/` (and `?` in a query or fragment), and every
/// `%` that does not start an escape.
///
/// PHP: `encodePath()` and `encodeQueryOrFragment()`.
fn encode(value: &str, query: bool) -> String {
    let bytes = value.as_bytes();
    let mut out = String::with_capacity(bytes.len());
    let hex = |b: Option<&u8>| b.is_some_and(u8::is_ascii_hexdigit);
    for (i, &b) in bytes.iter().enumerate() {
        let allowed = b.is_ascii_alphanumeric()
            || b"_-.~!$&'()*+,;=:@/".contains(&b)
            || (query && b == b'?')
            || (b == b'%' && hex(bytes.get(i + 1)) && hex(bytes.get(i + 2)));
        if allowed {
            out.push(b as char);
        } else {
            out.push_str(&format!("%{b:02X}"));
        }
    }
    out
}
