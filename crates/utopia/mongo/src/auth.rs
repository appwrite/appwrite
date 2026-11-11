//! [`Auth`]: the client side of a SCRAM-SHA-256 conversation (RFC 5802,
//! RFC 7677), as MongoDB's `saslStart` / `saslContinue` commands carry it.
//!
//! [`crate::Client`] authenticates through the official driver, which runs
//! its own SCRAM exchange; this is the conversation as a value, for callers
//! that drive the commands themselves (a proxy, a test double).

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use bson::spec::BinarySubtype;
use bson::{Binary, Bson, Document, doc};
use hmac::{Hmac, Mac};
use sha2::{Digest, Sha256};

use crate::error::{Error, Result};

/// The server's first message: `r=<nonce>,s=<salt>,i=<iterations>` and
/// optional extensions (`#` delimiters; `\x21-\x2B\x2D-\x7E` as PHP's
/// double-quoted string writes those bytes).
const SERVER_FIRST: &[u8] = b"#^r=([\x21-\x2B\x2D-\x7E/]+),s=((?:[A-Za-z0-9/+]{4})*(?:[A-Za-z0-9/+]{3}=|[A-Za-z0-9/+]{2}==)?),i=([0-9]*)(,[A-Za-z]=[^,])*$#";

/// The server's final message: `v=<verifier>`.
const SERVER_FINAL: &[u8] = b"#^v=((?:[A-Za-z0-9/+]{4})*(?:[A-Za-z0-9/+]{3}=|[A-Za-z0-9/+]{2}==)?)$#";

/// Who authenticates, and against which database.
///
/// PHP: the `$options` array of `new Auth($options)`.
#[derive(Debug, Clone, Default)]
pub struct Credentials {
    /// `authcid`: the user name.
    pub authcid: String,
    /// `secret`: the password (see [`Auth::encode_credentials`]).
    pub secret: String,
    /// `authzid`: the identity to act as (empty for the user itself).
    pub authzid: String,
    /// `authSource`: the database the user is defined in (`admin` when `None`).
    pub auth_source: Option<String>,
}

/// One SCRAM-SHA-256 conversation. See the [module docs](self).
///
/// PHP: `Utopia\Mongo\Auth`.
#[derive(Debug, Clone)]
pub struct Auth {
    authcid: String,
    secret: String,
    authzid: String,
    auth_source: String,
    gs2_header: Option<Vec<u8>>,
    cnonce: String,
    first_message_bare: Vec<u8>,
    salted_password: Option<Vec<u8>>,
    auth_message: Option<Vec<u8>>,
}

impl Auth {
    /// A conversation for `credentials`, with a fresh random client nonce.
    ///
    /// PHP: `new Auth($options)`.
    pub fn new(credentials: Credentials) -> Result<Auth> {
        Ok(Auth {
            authcid: credentials.authcid,
            secret: credentials.secret,
            authzid: credentials.authzid,
            auth_source: credentials.auth_source.unwrap_or_else(|| "admin".to_owned()),
            gs2_header: None,
            cnonce: cnonce()?,
            first_message_bare: Vec::new(),
            salted_password: None,
            auth_message: None,
        })
    }

    /// The `saslStart` command opening the conversation, and the database
    /// to run it on.
    ///
    /// PHP: `start()`.
    pub fn start(&mut self) -> Result<(Document, String)> {
        let response = self.create_response(None)?.unwrap_or_default();
        Ok((
            doc! {
                "saslStart": 1,
                "mechanism": "SCRAM-SHA-256",
                "payload": binary(response),
                "autoAuthorize": 1,
                "options": { "skipEmptyExchange": true },
            },
            self.auth_source.clone(),
        ))
    }

    /// The `saslContinue` command answering the server's challenge (the
    /// `payload` of its `saslStart` reply, in conversation
    /// `conversation_id`), and the database to run it on.
    ///
    /// PHP: `continue($data)`, with `$data->conversationId` and `$data->payload`.
    pub fn continue_with(&mut self, conversation_id: Bson, payload: &[u8]) -> Result<(Document, String)> {
        let answer = self.create_response(Some(payload))?.unwrap_or_default();
        Ok((
            doc! {
                "saslContinue": 1,
                "conversationId": conversation_id,
                "payload": binary(answer),
            },
            self.auth_source.clone(),
        ))
    }

    /// The client's next message: the first one without a challenge (an
    /// empty one, or `"0"`, as PHP's `empty()` sees it), the proof answering
    /// `challenge` otherwise. `None` (PHP's `false`) for an empty user name,
    /// a challenge that does not parse or whose nonce is not this client's,
    /// or one answered before the first message.
    ///
    /// PHP: `createResponse($challenge)`.
    pub fn create_response(&mut self, challenge: Option<&[u8]>) -> Result<Option<Vec<u8>>> {
        let authcid = format_name(&self.authcid);
        if authcid.is_empty() || authcid == "0" {
            return Ok(None);
        }
        let authzid = if self.authzid.is_empty() || self.authzid == "0" {
            self.authzid.clone()
        } else {
            format_name(&self.authzid)
        };
        match challenge {
            None | Some(b"") | Some(b"0") => self.initial_response(&authcid, &authzid).map(Some),
            Some(challenge) => Ok(self.response(challenge)),
        }
    }

    /// PHP: `generateInitialResponse($authcid, $authzid)`.
    fn initial_response(&mut self, authcid: &str, authzid: &str) -> Result<Vec<u8>> {
        let mut header = b"n,".to_vec();
        if !(authzid.is_empty() || authzid == "0") {
            header.extend_from_slice(b"a=");
            header.extend_from_slice(authzid.as_bytes());
        }
        header.push(b',');
        self.cnonce = cnonce()?;
        self.first_message_bare = format!("n={authcid},r={}", self.cnonce).into_bytes();
        let mut out = header.clone();
        out.extend_from_slice(&self.first_message_bare);
        self.gs2_header = Some(header);
        Ok(out)
    }

    /// PHP: `generateResponse($challenge, $password)`.
    fn response(&mut self, challenge: &[u8]) -> Option<Vec<u8>> {
        let header = self.gs2_header.clone()?;
        let matches = preg_match(SERVER_FIRST, challenge)?;
        let nonce = matches.first()?.clone();
        let salt = php_std::encoding::base64_decode(matches.get(1)?, false).unwrap_or_default();
        if salt.is_empty() || salt == b"0" {
            return None;
        }
        let iterations = php_std::format::intval(php_std::format::Arg::Str(matches.get(2)?), 10);
        let prefix = &nonce[..nonce.len().min(self.cnonce.len())];
        if prefix != self.cnonce.as_bytes() {
            return None;
        }
        let mut final_message = b"c=".to_vec();
        final_message.extend_from_slice(BASE64.encode(&header).as_bytes());
        final_message.extend_from_slice(b",r=");
        final_message.extend_from_slice(&nonce);
        let salted = hi(self.secret.as_bytes(), &salt, iterations);
        let client_key = hmac(&salted, b"Client Key");
        let stored_key = Sha256::digest(&client_key).to_vec();
        let mut auth_message = self.first_message_bare.clone();
        auth_message.push(b',');
        auth_message.extend_from_slice(challenge);
        auth_message.push(b',');
        auth_message.extend_from_slice(&final_message);
        let signature = hmac(&stored_key, &auth_message);
        let proof: Vec<u8> = client_key.iter().zip(&signature).map(|(a, b)| a ^ b).collect();
        self.salted_password = Some(salted);
        self.auth_message = Some(auth_message);
        final_message.extend_from_slice(b",p=");
        final_message.extend_from_slice(BASE64.encode(proof).as_bytes());
        Some(final_message)
    }

    /// Whether the server's final message (`v=<signature>`) proves it knows
    /// the password: `false` means it does not, or that no proof was sent
    /// yet, and the connection must be dropped.
    ///
    /// PHP: `verify($data)`.
    pub fn verify(&self, data: &[u8]) -> bool {
        let (Some(salted), Some(message)) = (&self.salted_password, &self.auth_message) else {
            return false;
        };
        let Some(matches) = preg_match(SERVER_FINAL, data) else { return false };
        let Some(verifier) = matches.first() else { return false };
        let proposed = php_std::encoding::base64_decode(verifier, false).unwrap_or_default();
        let server_key = hmac(salted, b"Server Key");
        proposed == hmac(&server_key, message)
    }

    /// The client nonce of the conversation.
    ///
    /// PHP: `getCnonce()`.
    pub fn cnonce(&self) -> &str {
        &self.cnonce
    }

    /// The salted password, once the client answered a challenge.
    ///
    /// PHP: `getSaltedPassword()` (an `Error` before that).
    pub fn salted_password(&self) -> Result<&[u8]> {
        self.salted_password.as_deref().ok_or_else(|| uninitialized("saltedPassword"))
    }

    /// The SCRAM auth message, once the client answered a challenge.
    ///
    /// PHP: `getAuthMessage()` (an `Error` before that).
    pub fn auth_message(&self) -> Result<&[u8]> {
        self.auth_message.as_deref().ok_or_else(|| uninitialized("authMessage"))
    }

    /// The secret SCRAM-SHA-256 authenticates with: the password itself
    /// (SCRAM salts and hashes it during the conversation).
    ///
    /// PHP: `Auth::encodeCredentials($username, $password)`.
    pub fn encode_credentials(_username: &str, password: &str) -> String {
        password.to_owned()
    }
}

/// PHP reads a typed property before it is set: `Error`.
fn uninitialized(property: &str) -> Error {
    Error::Engine {
        class: "Error",
        message: format!("Typed property Utopia\\Mongo\\Auth::${property} must not be accessed before initialization"),
    }
}

/// `formatName()`: `=` and `,` escaped as SCRAM names need.
fn format_name(name: &str) -> String {
    name.replace('=', "=3D").replace(',', "=2C")
}

/// A generic BSON binary of `bytes`.
fn binary(bytes: Vec<u8>) -> Binary {
    Binary { subtype: BinarySubtype::Generic, bytes }
}

/// 32 random bytes, base64: a client nonce.
fn cnonce() -> Result<String> {
    let mut bytes = [0u8; 32];
    getrandom::fill(&mut bytes).map_err(|e| Error::exception(e.to_string(), 0))?;
    Ok(BASE64.encode(bytes))
}

/// The capture groups of `pattern` in `subject` (group 1 first), as
/// PHP's `preg_match` fills `$matches`; `None` when it does not match.
fn preg_match(pattern: &[u8], subject: &[u8]) -> Option<Vec<Vec<u8>>> {
    use php_std::pcre::Value;
    let found = php_std::pcre::preg_match(pattern, subject, 0, 0).ok()?.value;
    if found.result != Value::Int(1) {
        return None;
    }
    let groups = found.matches?;
    Some(
        groups
            .entries()
            .iter()
            .skip(1)
            .map(|(_, v)| match v {
                Value::Str(s) => s.clone(),
                _ => Vec::new(),
            })
            .collect(),
    )
}

/// HMAC-SHA-256 of `data` under `key` (PHP's `hmac($key, $str)`).
fn hmac(key: &[u8], data: &[u8]) -> Vec<u8> {
    let mut mac = <Hmac<Sha256>>::new_from_slice(key).unwrap_or_else(|_| unreachable!("HMAC takes keys of any length"));
    mac.update(data);
    mac.finalize().into_bytes().to_vec()
}

/// `Hi()`: PBKDF2 with HMAC-SHA-256, one block, `iterations` rounds (at
/// least the first, as PHP's loop always computes it).
fn hi(password: &[u8], salt: &[u8], iterations: i64) -> Vec<u8> {
    let mut first = salt.to_vec();
    first.extend_from_slice(&[0, 0, 0, 1]);
    let mut u = hmac(password, &first);
    let mut result = u.clone();
    for _ in 1..iterations.max(1) {
        u = hmac(password, &u);
        result.iter_mut().zip(&u).for_each(|(r, b)| *r ^= b);
    }
    result
}
