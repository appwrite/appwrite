//! JWT issuers: HS256 with a shared secret, RS256 with an RSA key pair,
//! and the token kinds built on them.

use hmac::{Hmac, Mac};
use php_std::encoding::bin2hex;
use php_std::json::{self, Flags};
use php_std::types::KeyRef;
use php_std::{Array, ArrayKey, Str, Value};
use sha2::{Digest, Sha256};

use super::key::{self, Public, Rsa};
use super::{Audience, Claim, Header, base64url_encode};
use crate::Error;
use crate::hash::random_bytes;

fn string(s: &[u8]) -> Value {
    Value::Str(Str::copy_from(s))
}

/// `$now + $duration` with PHP's integer overflow into float.
fn add(now: i64, duration: i64) -> Value {
    match now.checked_add(duration) {
        Some(v) => Value::Int(v),
        None => Value::Float(now as f64 + duration as f64),
    }
}

/// `[...$claims, ...]`: string keys keep their place, integer keys are renumbered.
fn spread(claims: &Array) -> Array {
    let mut out = Array::with_capacity(claims.len() + 8);
    for (k, v) in claims.iter() {
        match k {
            KeyRef::Str(_) => {
                out.set(k.to_owned(), v.clone());
            }
            KeyRef::Int(_) => {
                // Overflow is impossible: renumbering starts at 0.
                let _ = out.push(v.clone());
            }
        }
    }
    out
}

fn set(claims: &mut Array, claim: Claim, value: Value) {
    claims.set(ArrayKey::from(claim.as_str()), value);
}

fn unset(claims: &Array, claim: Claim) -> Array {
    let key = KeyRef::Str(claim.as_str().as_bytes());
    claims.iter().filter(|(k, _)| *k != key).map(|(k, v)| (k.to_owned(), v.clone())).collect()
}

/// `generateJti()`: 16 random bytes as hex.
fn jti() -> String {
    let mut b = [0u8; 16];
    random_bytes(&mut b);
    bin2hex(&b)
}

/// `Issuer::sign()`: the compact JWS of `claims`, with header `typ`, `alg` and `kid`.
fn sign(
    typ: &str,
    alg: &str,
    key_id: Option<&[u8]>,
    claims: Array,
    signature: impl FnOnce(&[u8]) -> Result<Vec<u8>, Error>,
) -> Result<String, Error> {
    let mut header = Array::with_capacity(3);
    header.set(ArrayKey::Str(Header::Type.as_str().into()), string(typ.as_bytes()));
    header.set(ArrayKey::Str(Header::Algorithm.as_str().into()), string(alg.as_bytes()));
    if let Some(kid) = key_id {
        header.set(ArrayKey::Str(Header::KeyId.as_str().into()), string(kid));
    }
    let encode = |v: Value| json::encode(&v, Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH).map_err(Error::json);
    let header = encode(Value::Array(header))?;
    let claims = encode(Value::Array(claims))?;
    let input = format!("{}.{}", base64url_encode(header.as_bytes()), base64url_encode(claims.as_bytes()));
    let signature = signature(input.as_bytes())?;
    Ok(format!("{input}.{}", base64url_encode(&signature)))
}

fn check_issuer(issuer: &[u8]) -> Result<(), Error> {
    if issuer.is_empty() || issuer == b"0" {
        return Err(Error::Exception("An issuer is required".into()));
    }
    Ok(())
}

/// Signs JWTs with HS256 and a shared secret.
///
/// PHP: `Utopia\Auth\Issuers\Symmetric`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Symmetric {
    secret: Vec<u8>,
    issuer: Vec<u8>,
    key_id: Option<Vec<u8>>,
}

impl Symmetric {
    /// An issuer named `issuer` (the `iss` claim) signing with `secret`,
    /// with an optional `kid` header.
    ///
    /// Fails with [`Error::Exception`] when the issuer or the secret is
    /// empty or `"0"`.
    ///
    /// PHP: `new Symmetric($secret, $issuer, $keyId)`.
    pub fn new(secret: &[u8], issuer: &[u8], key_id: Option<&[u8]>) -> Result<Self, Error> {
        check_issuer(issuer)?;
        if secret.is_empty() || secret == b"0" {
            return Err(Error::Exception("A signing secret is required".into()));
        }
        Ok(Self { secret: secret.to_vec(), issuer: issuer.to_vec(), key_id: key_id.map(<[u8]>::to_vec) })
    }

    /// A new random secret of `bytes` bytes, as lowercase hex.
    ///
    /// PHP: `Symmetric::generateSecret($bytes)`.
    pub fn generate_secret(bytes: usize) -> String {
        let mut b = vec![0u8; bytes];
        random_bytes(&mut b);
        bin2hex(&b)
    }

    /// The `kid` header, if one is set.
    ///
    /// PHP: `getKeyId()`.
    pub fn key_id(&self) -> Option<&[u8]> {
        self.key_id.as_deref()
    }

    /// The `iss` claim.
    pub fn issuer(&self) -> &[u8] {
        &self.issuer
    }

    /// Signs `claims` as an HS256 JWS of type `typ`.
    pub fn sign(&self, typ: &str, claims: Array) -> Result<String, Error> {
        sign(typ, "HS256", self.key_id.as_deref(), claims, |input| {
            let mut mac = Hmac::<Sha256>::new_from_slice(&self.secret).expect("HMAC takes any key length");
            mac.update(input);
            Ok(mac.finalize().into_bytes().to_vec())
        })
    }
}

/// Signs JWTs with RS256 and an RSA key pair.
///
/// The keys are PEM strings, parsed when used: construction only checks
/// they are not empty.
///
/// PHP: `Utopia\Auth\Issuers\Asymmetric`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Asymmetric {
    private_key: Vec<u8>,
    public_key: Vec<u8>,
    issuer: Vec<u8>,
    key_id: Option<Vec<u8>>,
}

impl Asymmetric {
    /// An issuer named `issuer` (the `iss` claim) signing with a PEM key
    /// pair, with an optional `kid` header.
    ///
    /// Fails with [`Error::Exception`] when the issuer or either key is
    /// empty or `"0"`.
    ///
    /// PHP: `new Asymmetric($privateKey, $publicKey, $issuer, $keyId)`.
    pub fn new(private_key: &[u8], public_key: &[u8], issuer: &[u8], key_id: Option<&[u8]>) -> Result<Self, Error> {
        check_issuer(issuer)?;
        let missing = |k: &[u8]| k.is_empty() || k == b"0";
        if missing(private_key) || missing(public_key) {
            return Err(Error::Exception("Both a private and a public key are required".into()));
        }
        Ok(Self {
            private_key: private_key.to_vec(),
            public_key: public_key.to_vec(),
            issuer: issuer.to_vec(),
            key_id: key_id.map(<[u8]>::to_vec),
        })
    }

    /// The `iss` claim.
    pub fn issuer(&self) -> &[u8] {
        &self.issuer
    }

    fn modulus(&self) -> Result<(Vec<u8>, Vec<u8>), Error> {
        match key::public_key(&self.public_key)? {
            None => Err(Error::Exception("Unable to parse the public key".into())),
            Some(Public::Other) => Err(Error::Exception("Public key is not an RSA key".into())),
            Some(Public::Rsa(k)) => Ok((Rsa::modulus(&k), Rsa::exponent(&k))),
        }
    }

    /// The `kid` header: the one configured, else the SHA-256 of the RSA
    /// modulus as hex.
    ///
    /// Fails when the public key cannot be read or is not RSA.
    ///
    /// PHP: `getKeyId()`.
    pub fn key_id(&self) -> Result<Vec<u8>, Error> {
        match &self.key_id {
            Some(kid) => Ok(kid.clone()),
            None => Ok(hex::encode(Sha256::digest(self.modulus()?.0)).into_bytes()),
        }
    }

    /// The public key as JWK members, in order: `kty`, `use`, `alg`, `kid`,
    /// `n` and `e`, for a JWKS endpoint.
    ///
    /// Fails when the public key cannot be read or is not RSA.
    ///
    /// PHP: `getPublicJwk()`.
    pub fn public_jwk(&self) -> Result<Vec<(&'static str, Vec<u8>)>, Error> {
        let (n, e) = self.modulus()?;
        let kid = match &self.key_id {
            Some(kid) => kid.clone(),
            None => hex::encode(Sha256::digest(&n)).into_bytes(),
        };
        Ok(vec![
            ("kty", b"RSA".to_vec()),
            ("use", b"sig".to_vec()),
            ("alg", b"RS256".to_vec()),
            ("kid", kid),
            ("n", base64url_encode(&n).into_bytes()),
            ("e", base64url_encode(&e).into_bytes()),
        ])
    }

    /// Signs `claims` as an RS256 JWS of type `typ`.
    pub fn sign(&self, typ: &str, claims: Array) -> Result<String, Error> {
        let kid = self.key_id()?;
        sign(typ, "RS256", Some(&kid), claims, |input| {
            let key = key::private_key(&self.private_key)?
                .ok_or_else(|| Error::Exception("Unable to parse the private key".into()))?;
            key::sign(key, input)
        })
    }
}

/// Issues general-purpose HS256 JWTs with application-defined claims.
///
/// PHP: `Utopia\Auth\Issuers\Symmetric\Jwt`.
///
/// ```
/// use php_std::Array;
/// use utopia_auth::jwt::{Audience, Jwt, Symmetric};
///
/// let issuer = Jwt(Symmetric::new(b"secret", b"https://cloud.example", None)?);
/// let token = issuer.issue(&Audience::One(b"app".to_vec()), 60, &Array::new(), 1_700_000_000)?;
/// assert_eq!(token.split('.').count(), 3);
/// assert!(token.starts_with("eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9."));
/// # Ok::<(), utopia_auth::Error>(())
/// ```
///
/// ```php
/// use Utopia\Auth\Issuers\Symmetric\Jwt;
///
/// $token = (new Jwt('secret', 'https://cloud.example'))->issue('app', 60);
/// echo count(explode('.', $token)), "\n"; // 3
/// echo explode('.', $token)[0], "\n";     // eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Jwt(pub Symmetric);

impl Jwt {
    /// A token for `audience` valid `duration` seconds from `now`: `claims`
    /// plus `iss`, `aud`, `iat` and `exp`, which `claims` cannot override.
    ///
    /// Fails with [`Error::InvalidArgument`] when `duration` is not positive
    /// or the audience is empty.
    ///
    /// PHP: `issue($audience, $duration, $claims)`.
    pub fn issue(&self, audience: &Audience, duration: i64, claims: &Array, now: i64) -> Result<String, Error> {
        if duration < 1 {
            return Err(Error::InvalidArgument("Token duration must be greater than zero".into()));
        }
        let recipients: Vec<&Value> = match audience {
            Audience::One(_) => vec![],
            Audience::Many(list) => {
                if list.is_empty() || !list.is_list() {
                    return Err(Error::InvalidArgument("Token audience must be a non-empty list of recipients".into()));
                }
                list.iter().map(|(_, v)| v).collect()
            }
        };
        let valid = |v: &Value| matches!(v, Value::Str(s) if !s.is_empty());
        let one_valid = match audience {
            Audience::One(s) => !s.is_empty(),
            Audience::Many(_) => recipients.iter().all(|v| valid(v)),
        };
        if !one_valid {
            return Err(Error::InvalidArgument("Token audience recipients must be non-empty strings".into()));
        }
        let mut out = spread(claims);
        set(&mut out, Claim::Issuer, string(&self.0.issuer));
        set(&mut out, Claim::Audience, audience.to_value());
        set(&mut out, Claim::IssuedAt, Value::Int(now));
        set(&mut out, Claim::Expiration, add(now, duration));
        self.0.sign("JWT", out)
    }
}

/// Issues OAuth2 refresh tokens as HS256 JWTs.
///
/// PHP: `Utopia\Auth\Issuers\Symmetric\RefreshToken`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RefreshToken(pub Symmetric);

impl RefreshToken {
    /// A refresh token for `subject` and `client_id`, valid `duration` seconds
    /// from `now`.
    ///
    /// It holds `claims` plus `iss`, `aud`, `sub`, `client_id`, `exp`, `iat`,
    /// `jti` (random unless given) and `scope` (space-separated, when
    /// `scopes` is not empty).
    ///
    /// PHP: `issue($subject, $audience, $clientId, $duration, $scopes, $jti, $claims)`.
    #[allow(clippy::too_many_arguments)]
    pub fn issue(
        &self,
        subject: &[u8],
        audience: &[u8],
        client_id: &[u8],
        duration: i64,
        scopes: &[Vec<u8>],
        jti: Option<&[u8]>,
        claims: &Array,
        now: i64,
    ) -> Result<String, Error> {
        let mut out = spread(&unset(claims, Claim::Scope));
        set(&mut out, Claim::Issuer, string(&self.0.issuer));
        set(&mut out, Claim::Audience, string(audience));
        set(&mut out, Claim::Subject, string(subject));
        set(&mut out, Claim::ClientId, string(client_id));
        set(&mut out, Claim::Expiration, add(now, duration));
        set(&mut out, Claim::IssuedAt, Value::Int(now));
        set(&mut out, Claim::JwtId, jti.map(string).unwrap_or_else(|| Value::Str(Str::from(self::jti().into_bytes()))));
        if !scopes.is_empty() {
            set(&mut out, Claim::Scope, Value::Str(Str::from(scopes.join(&b' '))));
        }
        self.0.sign("JWT", out)
    }
}

/// Issues OAuth2 access tokens as RS256 JWTs of type `at+jwt` (RFC 9068).
///
/// PHP: `Utopia\Auth\Issuers\Asymmetric\AccessToken`.
///
/// ```
/// use php_std::Array;
/// use utopia_auth::jwt::{generate_key_pair, AccessToken, Asymmetric};
///
/// let pair = generate_key_pair(2048)?;
/// let issuer = AccessToken(Asymmetric::new(pair.private_key.as_bytes(), pair.public_key.as_bytes(), b"https://cloud.example", Some(b"key-1"))?);
/// let audience = Array::from_list(vec!["https://api.example".into()]);
/// let token = issuer.issue(b"user", &audience, b"client", 0, 3600, &[], None, &Array::new(), 1_700_000_000)?;
/// assert!(token.starts_with("eyJ0eXAiOiJhdCtqd3QiLCJhbGciOiJSUzI1NiIsImtpZCI6ImtleS0xIn0."));
/// # Ok::<(), utopia_auth::Error>(())
/// ```
///
/// ```php
/// use Utopia\Auth\Issuers\Asymmetric;
/// use Utopia\Auth\Issuers\Asymmetric\AccessToken;
///
/// [$private, $public] = Asymmetric::generateKeyPair(2048);
/// $issuer = new AccessToken($private, $public, 'https://cloud.example', 'key-1');
/// $token = $issuer->issue('user', ['https://api.example'], 'client', 0, 3600);
/// echo explode('.', $token)[0], "\n"; // eyJ0eXAiOiJhdCtqd3QiLCJhbGciOiJSUzI1NiIsImtpZCI6ImtleS0xIn0
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct AccessToken(pub Asymmetric);

impl AccessToken {
    /// An access token for `subject` and `client_id`, for the resource
    /// servers in `audience`, valid `duration` seconds from `now`.
    ///
    /// It holds `claims` plus `iss`, `aud`, `sub`, `client_id`, `exp`, `iat`,
    /// `jti` (random unless given), `auth_time` and `scope` (space-separated,
    /// when `scopes` is not empty). Fails with [`Error::InvalidArgument`]
    /// unless `audience` is a non-empty list of non-empty identifiers.
    ///
    /// PHP: `issue($subject, $audience, $clientId, $authTime, $duration, $scopes, $jti, $claims)`.
    #[allow(clippy::too_many_arguments)]
    pub fn issue(
        &self,
        subject: &[u8],
        audience: &Array,
        client_id: &[u8],
        auth_time: i64,
        duration: i64,
        scopes: &[Vec<u8>],
        jti: Option<&[u8]>,
        claims: &Array,
        now: i64,
    ) -> Result<String, Error> {
        if audience.is_empty() {
            return Err(Error::InvalidArgument(
                "audience must contain at least one resource server identifier.".into(),
            ));
        }
        if !audience.is_list() {
            return Err(Error::InvalidArgument("audience must be a list of resource server identifiers.".into()));
        }
        if audience.iter().any(|(_, v)| matches!(v, Value::Str(s) if s.is_empty())) {
            return Err(Error::InvalidArgument("audience must contain non-empty resource server identifiers.".into()));
        }
        let mut out = spread(&unset(claims, Claim::Scope));
        set(&mut out, Claim::Issuer, string(&self.0.issuer));
        set(&mut out, Claim::Audience, Value::Array(audience.clone()));
        set(&mut out, Claim::Subject, string(subject));
        set(&mut out, Claim::ClientId, string(client_id));
        set(&mut out, Claim::Expiration, add(now, duration));
        set(&mut out, Claim::IssuedAt, Value::Int(now));
        set(&mut out, Claim::JwtId, jti.map(string).unwrap_or_else(|| Value::Str(Str::from(self::jti().into_bytes()))));
        set(&mut out, Claim::AuthTime, Value::Int(auth_time));
        if !scopes.is_empty() {
            set(&mut out, Claim::Scope, Value::Str(Str::from(scopes.join(&b' '))));
        }
        self.0.sign("at+jwt", out)
    }
}

/// Issues OpenID Connect ID tokens as RS256 JWTs.
///
/// PHP: `Utopia\Auth\Issuers\Asymmetric\IdToken`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct IdToken(pub Asymmetric);

impl IdToken {
    /// An ID token for `subject` and the client `audience`, valid `duration`
    /// seconds from `now`.
    ///
    /// It holds `claims` plus `iss`, `sub`, `aud`, `exp`, `iat` and
    /// `auth_time`, and when given, `nonce`, `at_hash` (of `access_token`)
    /// and `c_hash` (of `code`).
    ///
    /// PHP: `issue($subject, $audience, $authTime, $duration, $nonce, $accessToken, $code, $claims)`.
    #[allow(clippy::too_many_arguments)]
    pub fn issue(
        &self,
        subject: &[u8],
        audience: &[u8],
        auth_time: i64,
        duration: i64,
        nonce: Option<&[u8]>,
        access_token: Option<&[u8]>,
        code: Option<&[u8]>,
        claims: &Array,
        now: i64,
    ) -> Result<String, Error> {
        let mut base = claims.clone();
        for claim in [Claim::Nonce, Claim::AccessTokenHash, Claim::CodeHash] {
            base = unset(&base, claim);
        }
        let mut out = spread(&base);
        set(&mut out, Claim::Issuer, string(&self.0.issuer));
        set(&mut out, Claim::Subject, string(subject));
        set(&mut out, Claim::Audience, string(audience));
        set(&mut out, Claim::Expiration, add(now, duration));
        set(&mut out, Claim::IssuedAt, Value::Int(now));
        set(&mut out, Claim::AuthTime, Value::Int(auth_time));
        fn present(v: Option<&[u8]>) -> Option<&[u8]> {
            v.filter(|v| !v.is_empty() && *v != b"0")
        }
        if let Some(nonce) = present(nonce) {
            set(&mut out, Claim::Nonce, string(nonce));
        }
        if let Some(token) = present(access_token) {
            set(&mut out, Claim::AccessTokenHash, Value::Str(Str::from(left_half_hash(token).into_bytes())));
        }
        if let Some(code) = present(code) {
            set(&mut out, Claim::CodeHash, Value::Str(Str::from(left_half_hash(code).into_bytes())));
        }
        self.0.sign("JWT", out)
    }
}

/// `leftHalfHash()`: base64url of the first 16 bytes of SHA-256.
fn left_half_hash(value: &[u8]) -> String {
    base64url_encode(&Sha256::digest(value)[..16])
}
