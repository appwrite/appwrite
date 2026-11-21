//! URIs, split and normalised the way requests and redirects need them.

use std::fmt;

use crate::Error;

/// A URI: scheme, user info, host, port, path, query and fragment.
///
/// Parsing lowercases the scheme and host and percent-encodes any byte a
/// path, query or fragment may not hold as is (a `%` that does not start an
/// escape included), so every [`Uri`] prints as a valid URI. A default port
/// (80 for `http`, 443 for `https`) is dropped.
///
/// PHP: `Utopia\Psr7\Uri` (from `utopia-php/psr7`, the PSR-7 messages the
/// PHP client sends).
///
/// ```
/// use utopia_client::Uri;
///
/// let uri = Uri::parse("HTTPS://API.Example.com:443/v1/users?q=a b#top")?;
/// assert_eq!(uri.host(), "api.example.com");
/// assert_eq!(uri.port(), None);
/// assert_eq!(uri.to_string(), "https://api.example.com/v1/users?q=a%20b#top");
/// # Ok::<(), utopia_client::Error>(())
/// ```
///
/// ```php
/// use Utopia\Psr7\Uri;
///
/// $uri = Uri::parse('HTTPS://API.Example.com:443/v1/users?q=a b#top');
/// echo $uri->getHost(), "\n"; // api.example.com
/// var_dump($uri->getPort()); // NULL
/// echo $uri, "\n"; // https://api.example.com/v1/users?q=a%20b#top
/// ```
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

impl Uri {
    /// Parses a URI, as PHP's `parse_url` splits it.
    ///
    /// Fails with [`Error::InvalidArgument`] (`Invalid URI.`) where
    /// `parse_url` does (an invalid port, an empty host after `//`).
    ///
    /// PHP: `Uri::parse($uri)`.
    pub fn parse(uri: &str) -> Result<Uri, Error> {
        let parts =
            php_std::url::parse_url(uri.as_bytes()).ok_or_else(|| Error::InvalidArgument("Invalid URI.".into()))?;
        let text = |b: std::borrow::Cow<'_, [u8]>| String::from_utf8_lossy(&b).into_owned();
        let mut user_info = parts.user().map(text).unwrap_or_default();
        if let Some(pass) = parts.pass() {
            user_info.push(':');
            user_info.push_str(&text(pass));
        }
        Ok(Uri {
            scheme: parts.scheme().map(|s| text(s).to_ascii_lowercase()).unwrap_or_default(),
            user_info,
            host: parts.host().map(|h| text(h).to_ascii_lowercase()).unwrap_or_default(),
            port: parts.port(),
            path: parts.path().map(|p| encode(&p, false)).unwrap_or_default(),
            query: parts.query().map(|q| encode(&q, true)).unwrap_or_default(),
            fragment: parts.fragment().map(|f| encode(&f, true)).unwrap_or_default(),
        })
    }

    /// The scheme, lowercase; empty when there is none.
    ///
    /// PHP: `getScheme()`.
    pub fn scheme(&self) -> &str {
        &self.scheme
    }

    /// `user[:password]`, empty when there is none.
    ///
    /// PHP: `getUserInfo()`.
    pub fn user_info(&self) -> &str {
        &self.user_info
    }

    /// The host, lowercase (an IPv6 literal keeps its brackets).
    ///
    /// PHP: `getHost()`.
    pub fn host(&self) -> &str {
        &self.host
    }

    /// The port, `None` when absent or the scheme's default.
    ///
    /// PHP: `getPort()`.
    pub fn port(&self) -> Option<u16> {
        if self.is_default_port() { None } else { self.port }
    }

    /// `[user-info@]host[:port]`, empty without a host.
    ///
    /// PHP: `getAuthority()`.
    pub fn authority(&self) -> String {
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
            authority.push(':');
            authority.push_str(&port.to_string());
        }
        authority
    }

    /// The path, percent-encoded.
    ///
    /// PHP: `getPath()`.
    pub fn path(&self) -> &str {
        &self.path
    }

    /// The query, without its `?`.
    ///
    /// PHP: `getQuery()`.
    pub fn query(&self) -> &str {
        &self.query
    }

    /// The fragment, without its `#`.
    ///
    /// PHP: `getFragment()`.
    pub fn fragment(&self) -> &str {
        &self.fragment
    }

    /// With another scheme (lowercased).
    ///
    /// PHP: `withScheme($scheme)`.
    pub fn with_scheme(mut self, scheme: &str) -> Uri {
        self.scheme = scheme.to_ascii_lowercase();
        self
    }

    /// With another host (lowercased).
    ///
    /// PHP: `withHost($host)`.
    pub fn with_host(mut self, host: &str) -> Uri {
        self.host = host.to_ascii_lowercase();
        self
    }

    /// With another port.
    ///
    /// PHP: `withPort($port)`; the `u16` makes PHP's `Invalid URI port.`
    /// for values out of range unrepresentable, except 0.
    pub fn with_port(mut self, port: Option<u16>) -> Result<Uri, Error> {
        if port == Some(0) {
            return Err(Error::InvalidArgument("Invalid URI port.".into()));
        }
        self.port = port;
        Ok(self)
    }

    /// With another path, percent-encoded as [`Uri::parse`] encodes one.
    ///
    /// PHP: `withPath($path)`.
    pub fn with_path(mut self, path: &str) -> Uri {
        self.path = encode(path.as_bytes(), false);
        self
    }

    /// With another query (a leading `?` is dropped).
    ///
    /// PHP: `withQuery($query)`.
    pub fn with_query(mut self, query: &str) -> Uri {
        self.query = encode(query.trim_start_matches('?').as_bytes(), true);
        self
    }

    /// With another fragment (a leading `#` is dropped).
    ///
    /// PHP: `withFragment($fragment)`.
    pub fn with_fragment(mut self, fragment: &str) -> Uri {
        self.fragment = encode(fragment.trim_start_matches('#').as_bytes(), true);
        self
    }

    /// The port to connect to: the explicit one, or the scheme's default.
    pub(crate) fn effective_port(&self) -> u16 {
        self.port.unwrap_or(if self.scheme == "https" { 443 } else { 80 })
    }

    fn is_default_port(&self) -> bool {
        (self.scheme == "http" && self.port == Some(80)) || (self.scheme == "https" && self.port == Some(443))
    }
}

impl fmt::Display for Uri {
    /// The URI as text.
    ///
    /// PHP: `__toString()`.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        if !self.scheme.is_empty() {
            write!(f, "{}:", self.scheme)?;
        }
        let authority = self.authority();
        if !authority.is_empty() {
            write!(f, "//{authority}")?;
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

impl std::str::FromStr for Uri {
    type Err = Error;

    fn from_str(s: &str) -> Result<Uri, Error> {
        Uri::parse(s)
    }
}

/// Percent-encodes every byte outside RFC 3986's unreserved, sub-delims,
/// `:`, `@`, `/` (and `?` in a query or fragment), and every `%` that does not
/// start a `%XX` escape.
///
/// PHP: `encodePath()` / `encodeQueryOrFragment()`, a `preg_replace_callback`
/// that `rawurlencode`s each run of such bytes.
fn encode(value: &[u8], query: bool) -> String {
    let allowed = |b: u8| {
        b.is_ascii_alphanumeric()
            || matches!(
                b,
                b'_' | b'-'
                    | b'.'
                    | b'~'
                    | b'!'
                    | b'$'
                    | b'&'
                    | b'\''
                    | b'('
                    | b')'
                    | b'*'
                    | b'+'
                    | b','
                    | b';'
                    | b'='
                    | b':'
                    | b'@'
                    | b'/'
            )
            || (query && b == b'?')
    };
    let mut out = Vec::with_capacity(value.len());
    for (i, &b) in value.iter().enumerate() {
        let escape =
            b == b'%' && value.len() > i + 2 && value[i + 1].is_ascii_hexdigit() && value[i + 2].is_ascii_hexdigit();
        if allowed(b) || escape {
            out.push(b);
        } else {
            out.extend_from_slice(php_std::encoding::rawurlencode(&[b]).as_bytes());
        }
    }
    String::from_utf8(out).unwrap_or_default()
}
