//! A client's registered redirect URIs and how a presented one matches.

use php_std::Value;
use php_std::string::strtolower;

use super::parse_url;

const LOOPBACK_HOSTS: [&[u8]; 3] = [b"localhost", b"127.0.0.1", b"[::1]"];

/// A client's registered redirect URIs: exact matching, plus any port on
/// http loopback URIs when asked for (RFC 8252 §7.3, public clients only).
///
/// PHP: `Utopia\Auth\OAuth2\RedirectUris`.
///
/// ```
/// use utopia_auth::oauth2::RedirectUris;
///
/// let uris = RedirectUris::from_strings([&b"https://app.example/callback"[..], b"http://127.0.0.1/cb"]);
/// assert!(uris.matches(b"https://app.example/callback", false));
/// assert!(!uris.matches(b"https://app.example/other", false));
/// // Loopback redirects may use any port, when allowed.
/// assert!(uris.matches(b"http://127.0.0.1:51004/cb", true));
/// ```
///
/// ```php
/// use Utopia\Auth\OAuth2\RedirectUris;
///
/// $uris = RedirectUris::from(['https://app.example/callback', 'http://127.0.0.1/cb']);
/// var_dump($uris->matches('https://app.example/callback'));     // bool(true)
/// var_dump($uris->matches('https://app.example/other'));        // bool(false)
/// var_dump($uris->matches('http://127.0.0.1:51004/cb', true));  // bool(true)
/// ```
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct RedirectUris(Vec<Vec<u8>>);

/// The port-insensitive parts of an http loopback URI.
#[derive(PartialEq, Eq)]
struct Loopback {
    host: Vec<u8>,
    path: Vec<u8>,
    query: Vec<u8>,
}

fn loopback(uri: &[u8]) -> Option<Loopback> {
    let parts = parse_url(uri)?;
    let host = strtolower(parts.host.as_deref().unwrap_or_default()).into_owned();
    let scheme = strtolower(parts.scheme.as_deref().unwrap_or_default()).into_owned();
    if scheme != b"http"
        || !LOOPBACK_HOSTS.contains(&host.as_slice())
        || parts.user.is_some()
        || parts.pass.is_some()
        || parts.fragment.is_some()
    {
        return None;
    }
    let path = match parts.path {
        Some(p) if !p.is_empty() => p,
        _ => b"/".to_vec(),
    };
    Some(Loopback { host, path, query: parts.query.unwrap_or_default() })
}

impl RedirectUris {
    /// The registered URIs among stored values: every non-empty string.
    ///
    /// PHP: `RedirectUris::from($uris)`.
    pub fn from_values<'a>(uris: impl IntoIterator<Item = &'a Value>) -> Self {
        Self(
            uris.into_iter()
                .filter_map(|u| match u {
                    Value::Str(s) if !s.is_empty() => Some(s.to_vec()),
                    _ => None,
                })
                .collect(),
        )
    }

    /// The registered URIs in a list of strings, skipping empty ones.
    ///
    /// PHP: `RedirectUris::from($uris)`.
    pub fn from_strings<'a>(uris: impl IntoIterator<Item = &'a [u8]>) -> Self {
        Self(uris.into_iter().filter(|u| !u.is_empty()).map(<[u8]>::to_vec).collect())
    }

    /// Whether `presented` is a registered URI.
    ///
    /// With `allow_loopback`, an http URI on `localhost`, `127.0.0.1` or
    /// `[::1]` also matches a registered one that differs only in its port.
    ///
    /// PHP: `matches($presented, $allowLoopback)`.
    pub fn matches(&self, presented: &[u8], allow_loopback: bool) -> bool {
        if presented.is_empty() {
            return false;
        }
        if self.0.iter().any(|u| u == presented) {
            return true;
        }
        if !allow_loopback {
            return false;
        }
        let Some(parts) = loopback(presented) else {
            return false;
        };
        self.0.iter().any(|registered| loopback(registered).as_ref() == Some(&parts))
    }

    /// The registered URIs, in order.
    ///
    /// PHP: `toArray()`.
    pub fn to_vec(&self) -> &[Vec<u8>] {
        &self.0
    }
}
