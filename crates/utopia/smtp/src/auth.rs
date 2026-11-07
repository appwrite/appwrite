//! SASL mechanisms for the AUTH command (RFC 4954).

use std::fmt;

/// One RFC 4954 mechanism. The client tries its authenticators in order
/// against what the server advertises, so the order is the security policy.
///
/// Implement it for a mechanism this crate does not ship.
///
/// PHP: `Utopia\SMTP\Auth\Authenticator`.
pub trait Authenticator: Send + Sync {
    /// The name as it appears in the AUTH capability, e.g. `PLAIN`.
    ///
    /// PHP: `mechanism()`.
    fn mechanism(&self) -> &str;

    /// The initial response sent with the AUTH command, decoded (the client
    /// applies base64). `None` when the mechanism waits for the server.
    ///
    /// PHP: `initial()`.
    fn initial(&self) -> Option<Vec<u8>>;

    /// Answers a server challenge, decoded in and decoded out. `step` counts
    /// challenges from zero; an authenticator outlives connections and keeps
    /// no state of its own.
    ///
    /// PHP: `respond()`.
    fn respond(&self, challenge: &[u8], step: usize) -> Vec<u8>;
}

/// RFC 4616: both credentials in the initial response, one round trip.
///
/// PHP: `Utopia\SMTP\Auth\Plain`.
///
/// ```
/// use utopia_smtp::auth::{Authenticator, Plain};
///
/// assert_eq!(Plain::new("jane", "secret").initial(), Some(b"\0jane\0secret".to_vec()));
/// ```
///
/// ```php
/// use Utopia\SMTP\Auth\Plain;
///
/// var_dump((new Plain('jane', 'secret'))->initial() === "\0jane\0secret"); // bool(true)
/// ```
#[derive(Clone)]
pub struct Plain {
    username: String,
    password: String,
}

impl Plain {
    /// PHP: `new Plain($username, $password)`.
    pub fn new(username: impl Into<String>, password: impl Into<String>) -> Plain {
        Plain { username: username.into(), password: password.into() }
    }
}

impl Authenticator for Plain {
    fn mechanism(&self) -> &str {
        "PLAIN"
    }

    fn initial(&self) -> Option<Vec<u8>> {
        let mut out = Vec::with_capacity(self.username.len() + self.password.len() + 2);
        out.push(0);
        out.extend_from_slice(self.username.as_bytes());
        out.push(0);
        out.extend_from_slice(self.password.as_bytes());
        Some(out)
    }

    fn respond(&self, _challenge: &[u8], _step: usize) -> Vec<u8> {
        self.initial().unwrap_or_default()
    }
}

/// The mechanism every server implements anyway: username, then password,
/// each in answer to a prompt.
///
/// PHP: `Utopia\SMTP\Auth\Login`.
///
/// ```
/// use utopia_smtp::auth::{Authenticator, Login};
///
/// let login = Login::new("jane", "secret");
/// assert_eq!(login.initial(), None);
/// assert_eq!(login.respond(b"Username:", 0), b"jane");
/// assert_eq!(login.respond(b"Password:", 1), b"secret");
/// ```
///
/// ```php
/// use Utopia\SMTP\Auth\Login;
///
/// echo (new Login('jane', 'secret'))->respond('Password:', 1), "\n"; // secret
/// ```
#[derive(Clone)]
pub struct Login {
    username: String,
    password: String,
}

impl Login {
    /// PHP: `new Login($username, $password)`.
    pub fn new(username: impl Into<String>, password: impl Into<String>) -> Login {
        Login { username: username.into(), password: password.into() }
    }
}

impl Authenticator for Login {
    fn mechanism(&self) -> &str {
        "LOGIN"
    }

    fn initial(&self) -> Option<Vec<u8>> {
        None
    }

    fn respond(&self, _challenge: &[u8], step: usize) -> Vec<u8> {
        if step == 0 { self.username.as_bytes().to_vec() } else { self.password.as_bytes().to_vec() }
    }
}

/// Google's and Microsoft's bearer-token mechanism.
///
/// PHP: `Utopia\SMTP\Auth\XOAuth2`.
///
/// ```
/// use utopia_smtp::auth::{Authenticator, XOAuth2};
///
/// let oauth = XOAuth2::new("jane@example.test", "token-value");
/// assert_eq!(oauth.initial(), Some(b"user=jane@example.test\x01auth=Bearer token-value\x01\x01".to_vec()));
/// assert_eq!(oauth.respond(b"{\"status\":\"401\"}", 0), b"");
/// ```
///
/// ```php
/// use Utopia\SMTP\Auth\XOAuth2;
///
/// var_dump((new XOAuth2('jane@example.test', 'token'))->respond('{"status":"401"}', 0)); // string(0) ""
/// ```
#[derive(Clone)]
pub struct XOAuth2 {
    username: String,
    token: String,
}

impl XOAuth2 {
    /// PHP: `new XOAuth2($username, $token)`.
    pub fn new(username: impl Into<String>, token: impl Into<String>) -> XOAuth2 {
        XOAuth2 { username: username.into(), token: token.into() }
    }
}

impl Authenticator for XOAuth2 {
    fn mechanism(&self) -> &str {
        "XOAUTH2"
    }

    fn initial(&self) -> Option<Vec<u8>> {
        Some(format!("user={}\x01auth=Bearer {}\x01\x01", self.username, self.token).into_bytes())
    }

    /// A challenge here is the server reporting why the token was refused;
    /// answering with nothing ends the exchange so the failure surfaces.
    fn respond(&self, _challenge: &[u8], _step: usize) -> Vec<u8> {
        Vec::new()
    }
}

macro_rules! redacted {
    ($($t:ty),*) => {$(
        impl fmt::Debug for $t {
            fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
                f.debug_struct(stringify!($t)).field("username", &self.username).finish_non_exhaustive()
            }
        }
    )*};
}

redacted!(Plain, Login, XOAuth2);
