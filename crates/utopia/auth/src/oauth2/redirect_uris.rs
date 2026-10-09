use php_std::string::strtolower;
use php_std::zval::Zval;

use super::parse_url;

const LOOPBACK_HOSTS: [&[u8]; 3] = [b"localhost", b"127.0.0.1", b"[::1]"];

/// A client's registered redirect URIs (`Utopia\Auth\OAuth2\RedirectUris`):
/// exact matching, plus RFC 8252 §7.3 port variance for http loopback URIs
/// when asked for (public clients only).
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
    /// `RedirectUris::from($uris)`: the non-empty strings among stored values.
    pub fn from_values<'a>(uris: impl IntoIterator<Item = &'a Zval>) -> Self {
        Self(
            uris.into_iter()
                .filter_map(|u| match u {
                    Zval::String(s) if !s.is_empty() => Some(s.clone()),
                    _ => None,
                })
                .collect(),
        )
    }

    /// `RedirectUris::from($uris)` for string lists.
    pub fn from_strings<'a>(uris: impl IntoIterator<Item = &'a [u8]>) -> Self {
        Self(uris.into_iter().filter(|u| !u.is_empty()).map(<[u8]>::to_vec).collect())
    }

    /// `matches($presented, $allowLoopback)`.
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

    /// `toArray()`.
    pub fn to_vec(&self) -> &[Vec<u8>] {
        &self.0
    }
}
