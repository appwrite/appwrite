//! URIs: parsing, the parts of one, and changing a part.

use std::fmt;

use crate::Error;

/// A URI reference (RFC 3986), immutable.
///
/// [`Uri::parse`] lower-cases the scheme and host and percent-encodes
/// characters a path, query or fragment cannot hold as they are (`%` stays
/// when it starts an escape). Building one with [`Uri::from_parts`] keeps the
/// parts exactly as given.
///
/// ```
/// use utopia_psr7::Uri;
///
/// let uri = Uri::parse("HTTPS://user:pass@Example.com:443/a path?x=1#frag")?;
/// assert_eq!(uri.scheme(), "https");
/// assert_eq!(uri.authority(), "user:pass@example.com");
/// assert_eq!(uri.port(), None); // 443 is the default for https
/// assert_eq!(uri.to_string(), "https://user:pass@example.com/a%20path?x=1#frag");
///
/// let other = uri.with_port(Some(8443))?;
/// assert_eq!(other.to_string(), "https://user:pass@example.com:8443/a%20path?x=1#frag");
/// # Ok::<(), utopia_psr7::Error>(())
/// ```
///
/// ```php
/// use Utopia\Psr7\Uri;
///
/// $uri = Uri::parse('HTTPS://user:pass@Example.com:443/a path?x=1#frag');
/// echo $uri->getScheme(), "\n";    // https
/// echo $uri->getAuthority(), "\n"; // user:pass@example.com
/// var_dump($uri->getPort());       // NULL
/// echo $uri, "\n";                 // https://user:pass@example.com/a%20path?x=1#frag
///
/// echo $uri->withPort(8443), "\n"; // https://user:pass@example.com:8443/a%20path?x=1#frag
/// ```
///
/// PHP: `Utopia\Psr7\Uri` (`Psr\Http\Message\UriInterface`).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Uri {
    scheme: String,
    user_info: String,
    host: String,
    port: Option<u16>,
    path: String,
    query: String,
    fragment: String,
}

/// The parts of a [`Uri`], for [`Uri::from_parts`].
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Parts {
    /// `https`
    pub scheme: String,
    /// `user:pass`
    pub user_info: String,
    /// `example.com`
    pub host: String,
    /// `8443`
    pub port: Option<u16>,
    /// `/users`
    pub path: String,
    /// `active=1`
    pub query: String,
    /// `top`
    pub fragment: String,
}

impl Uri {
    /// Parses a URI the way PHP's `parse_url()` splits one.
    ///
    /// Fails with [`Error::InvalidArgument`] (`Invalid URI.`) when
    /// `parse_url()` would return `false` (a port out of range, a scheme
    /// with nothing after it, ...).
    ///
    /// PHP: `Uri::parse()`, `Uri\Factory::createUri()`.
    pub fn parse(uri: &str) -> Result<Uri, Error> {
        let parts = php_std::url::parse_url(uri.as_bytes()).ok_or_else(|| Error::invalid("Invalid URI."))?;
        let text = |part: Option<std::borrow::Cow<'_, [u8]>>| -> String {
            part.map(|p| String::from_utf8_lossy(&p).into_owned()).unwrap_or_default()
        };
        let mut user_info = text(parts.user());
        if let Some(pass) = parts.pass() {
            user_info.push(':');
            user_info.push_str(&String::from_utf8_lossy(&pass));
        }
        Ok(Uri {
            scheme: text(parts.scheme()).to_ascii_lowercase(),
            user_info,
            host: text(parts.host()).to_ascii_lowercase(),
            port: parts.port(),
            path: parts.path().map(|p| encode(&String::from_utf8_lossy(&p), false)).unwrap_or_default(),
            query: parts.query().map(|q| encode(&String::from_utf8_lossy(&q), true)).unwrap_or_default(),
            fragment: parts.fragment().map(|f| encode(&String::from_utf8_lossy(&f), true)).unwrap_or_default(),
        })
    }

    /// A URI of exactly these parts (nothing is lower-cased or encoded).
    ///
    /// PHP: `new Uri(...)`.
    pub fn from_parts(parts: Parts) -> Uri {
        Uri {
            scheme: parts.scheme,
            user_info: parts.user_info,
            host: parts.host,
            port: parts.port,
            path: parts.path,
            query: parts.query,
            fragment: parts.fragment,
        }
    }

    /// The scheme, lower case (`""` when there is none).
    ///
    /// PHP: `getScheme()`.
    pub fn scheme(&self) -> &str {
        &self.scheme
    }

    /// `user-info@host:port`, without a default port; `""` without a host.
    ///
    /// PHP: `getAuthority()`.
    pub fn authority(&self) -> String {
        if self.host.is_empty() {
            return String::new();
        }
        let mut authority = String::with_capacity(self.user_info.len() + self.host.len() + 7);
        if !self.user_info.is_empty() {
            authority.push_str(&self.user_info);
            authority.push('@');
        }
        authority.push_str(&self.host);
        if let Some(port) = self.port()
            && !self.is_default_port()
        {
            authority.push(':');
            authority.push_str(&port.to_string());
        }
        authority
    }

    /// `user` or `user:password`.
    ///
    /// PHP: `getUserInfo()`.
    pub fn user_info(&self) -> &str {
        &self.user_info
    }

    /// The host, lower case.
    ///
    /// PHP: `getHost()`.
    pub fn host(&self) -> &str {
        &self.host
    }

    /// The port, unless it is the scheme's default (80 for `http`, 443 for `https`).
    ///
    /// PHP: `getPort()`.
    pub fn port(&self) -> Option<u16> {
        if self.is_default_port() { None } else { self.port }
    }

    /// The path.
    ///
    /// PHP: `getPath()`.
    pub fn path(&self) -> &str {
        &self.path
    }

    /// The query, without `?`.
    ///
    /// PHP: `getQuery()`.
    pub fn query(&self) -> &str {
        &self.query
    }

    /// The fragment, without `#`.
    ///
    /// PHP: `getFragment()`.
    pub fn fragment(&self) -> &str {
        &self.fragment
    }

    /// The URI with another scheme (lower-cased).
    ///
    /// PHP: `withScheme()`.
    pub fn with_scheme(&self, scheme: &str) -> Uri {
        Uri { scheme: scheme.to_ascii_lowercase(), ..self.clone() }
    }

    /// The URI with another user and, optionally, password.
    ///
    /// PHP: `withUserInfo()`.
    pub fn with_user_info(&self, user: &str, password: Option<&str>) -> Uri {
        let user_info = match password {
            None => user.to_owned(),
            Some(password) => format!("{user}:{password}"),
        };
        Uri { user_info, ..self.clone() }
    }

    /// The URI with another host (lower-cased).
    ///
    /// PHP: `withHost()`.
    pub fn with_host(&self, host: &str) -> Uri {
        Uri { host: host.to_ascii_lowercase(), ..self.clone() }
    }

    /// The URI with another port, or none.
    ///
    /// Fails with [`Error::InvalidArgument`] (`Invalid URI port.`) for port 0.
    ///
    /// PHP: `withPort()`.
    pub fn with_port(&self, port: Option<u16>) -> Result<Uri, Error> {
        if port == Some(0) {
            return Err(Error::invalid("Invalid URI port."));
        }
        Ok(Uri { port, ..self.clone() })
    }

    /// The URI with another path (percent-encoded where needed).
    ///
    /// PHP: `withPath()`.
    pub fn with_path(&self, path: &str) -> Uri {
        Uri { path: encode(path, false), ..self.clone() }
    }

    /// The URI with another query (leading `?`s dropped, percent-encoded where needed).
    ///
    /// PHP: `withQuery()`.
    pub fn with_query(&self, query: &str) -> Uri {
        Uri { query: encode(query.trim_start_matches('?'), true), ..self.clone() }
    }

    /// The URI with another fragment (leading `#`s dropped, percent-encoded where needed).
    ///
    /// PHP: `withFragment()`.
    pub fn with_fragment(&self, fragment: &str) -> Uri {
        Uri { fragment: encode(fragment.trim_start_matches('#'), true), ..self.clone() }
    }

    fn is_default_port(&self) -> bool {
        (self.scheme == "http" && self.port == Some(80)) || (self.scheme == "https" && self.port == Some(443))
    }
}

/// The URI as a string: `scheme://authority/path?query#fragment`. A rootless
/// path gets a `/` after an authority; without one, a path starting with
/// `//` is reduced to a single `/`.
///
/// PHP: `__toString()`.
impl fmt::Display for Uri {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        if !self.scheme.is_empty() {
            f.write_str(&self.scheme)?;
            f.write_str(":")?;
        }
        let authority = self.authority();
        if !authority.is_empty() {
            f.write_str("//")?;
            f.write_str(&authority)?;
            if !self.path.is_empty() && !self.path.starts_with('/') {
                f.write_str("/")?;
            }
            f.write_str(&self.path)?;
        } else if self.path.starts_with("//") {
            f.write_str("/")?;
            f.write_str(self.path.trim_start_matches('/'))?;
        } else {
            f.write_str(&self.path)?;
        }
        if !self.query.is_empty() {
            f.write_str("?")?;
            f.write_str(&self.query)?;
        }
        if !self.fragment.is_empty() {
            f.write_str("#")?;
            f.write_str(&self.fragment)?;
        }
        Ok(())
    }
}

/// Percent-encodes every byte a path (or, with `query`, a query or
/// fragment, which may also hold `?`) cannot hold, and every `%` that does
/// not start an escape.
fn encode(value: &str, query: bool) -> String {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    let bytes = value.as_bytes();
    if bytes.iter().enumerate().all(|(i, &b)| kept(bytes, i, b, query)) {
        return value.to_owned();
    }
    let mut out = String::with_capacity(bytes.len() + 8);
    for (i, &b) in bytes.iter().enumerate() {
        if kept(bytes, i, b, query) {
            out.push(b as char);
        } else {
            out.push('%');
            out.push(HEX[usize::from(b >> 4)] as char);
            out.push(HEX[usize::from(b & 0xF)] as char);
        }
    }
    out
}

fn kept(bytes: &[u8], i: usize, b: u8, query: bool) -> bool {
    match b {
        b'%' => bytes.get(i + 1..i + 3).is_some_and(|h| h.iter().all(u8::is_ascii_hexdigit)),
        b'?' => query,
        b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' => true,
        _ => b"_-.~!$&'()*+,;=:@/".contains(&b),
    }
}

/// Creates URIs.
///
/// PHP: `Psr\Http\Message\UriFactoryInterface`.
pub trait UriFactory {
    /// Parses a URI.
    ///
    /// PHP: `createUri()`.
    fn create_uri(&self, uri: &str) -> Result<Uri, Error>;
}

/// The default [`UriFactory`]: [`Uri::parse`].
///
/// PHP: `Utopia\Psr7\Uri\Factory`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Factory;

impl UriFactory for Factory {
    fn create_uri(&self, uri: &str) -> Result<Uri, Error> {
        Uri::parse(uri)
    }
}
