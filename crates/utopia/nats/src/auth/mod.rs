//! How the client proves who it is in its `CONNECT`.

mod nkey;

use serde_json::{Map, Value};

pub use nkey::{CredentialsAuth, NKeyAuth};

use crate::error::Result;

/// Fields an authentication method adds to the `CONNECT` payload.
pub type Fields = Map<String, Value>;

/// An authentication method: the fields to merge into `CONNECT`, given the
/// server's nonce (for challenge-response methods).
///
/// PHP: `Utopia\NATS\Auth\Authenticator`.
pub trait Authenticator: Send + Sync {
    /// The `CONNECT` fields.
    ///
    /// PHP: `Authenticator::authenticate()`.
    fn authenticate(&self, nonce: Option<&str>) -> Result<Fields>;
}

/// No credentials.
///
/// PHP: `Utopia\NATS\Auth\NoAuth`.
#[derive(Debug, Clone, Copy, Default)]
pub struct NoAuth;

impl Authenticator for NoAuth {
    fn authenticate(&self, _nonce: Option<&str>) -> Result<Fields> {
        Ok(Fields::new())
    }
}

/// A token: `{"auth_token": token}`.
///
/// PHP: `Utopia\NATS\Auth\TokenAuth`.
#[derive(Debug, Clone)]
pub struct TokenAuth {
    token: String,
}

impl TokenAuth {
    /// Authenticates with `token`.
    ///
    /// PHP: `new TokenAuth($token)`.
    pub fn new(token: impl Into<String>) -> Self {
        TokenAuth { token: token.into() }
    }
}

impl Authenticator for TokenAuth {
    fn authenticate(&self, _nonce: Option<&str>) -> Result<Fields> {
        let mut fields = Fields::new();
        fields.insert("auth_token".into(), Value::String(self.token.clone()));
        Ok(fields)
    }
}

/// A user and password: `{"user": user, "pass": pass}`.
///
/// PHP: `Utopia\NATS\Auth\UserPassAuth`.
#[derive(Debug, Clone)]
pub struct UserPassAuth {
    user: String,
    pass: String,
}

impl UserPassAuth {
    /// Authenticates as `user` with `pass`.
    ///
    /// PHP: `new UserPassAuth($user, $pass)`.
    pub fn new(user: impl Into<String>, pass: impl Into<String>) -> Self {
        UserPassAuth { user: user.into(), pass: pass.into() }
    }
}

impl Authenticator for UserPassAuth {
    fn authenticate(&self, _nonce: Option<&str>) -> Result<Fields> {
        let mut fields = Fields::new();
        fields.insert("user".into(), Value::String(self.user.clone()));
        fields.insert("pass".into(), Value::String(self.pass.clone()));
        Ok(fields)
    }
}
