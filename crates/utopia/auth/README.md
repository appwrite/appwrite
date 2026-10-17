# Utopia Auth

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/auth`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/auth) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Password hashing, proofs, session stores, JWT issuers and verifiers, OAuth2 server values and WebAuthn passkeys.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-auth = { git = "https://github.com/utopia-rs/auth" }
```

Authentication needs a handful of primitives that must agree byte for byte with what is already stored: password hashes, one-time secrets, signed tokens and session data. This guide covers the common tasks.

## Hash and verify a password

A [`Password`](proofs::Password) proof hashes with Argon2 by default and verifies a candidate against a stored hash. Hashes are salted, so hashing the same password twice gives two different strings.

```rust
use utopia_auth::proofs::{Password, Proof};

let password = Password::new();
let hash = password.hash(b"correct horse battery staple")?;

assert!(hash.starts_with(b"$argon2id$"));
assert!(password.verify(b"correct horse battery staple", &hash)?);
assert!(!password.verify(b"wrong", &hash)?);
```

## Verify a hash imported from another system

Users imported from elsewhere keep their hash and the options it was made with. Rebuild the algorithm from its name and stored options, then verify as usual.

```rust
use php_std::{Array, ArrayKey, Value};
use utopia_auth::proofs::Password;

let mut options = Array::new();
options.set(ArrayKey::from("version"), Value::from("sha256"));
let sha = Password::create_hash("sha", &options)?;

let stored = b"5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8";
assert!(sha.verify(b"password", stored)?);
```

## Send a one-time code

[`Code`](proofs::Code) generates numeric codes for emails and SMS, [`Token`](proofs::Token) long random tokens for links. Store only the hash; verify what the user sends back.

```rust
use utopia_auth::proofs::{Code, Proof};

let code = Code::new(6)?;
let secret = code.generate()?;
assert_eq!(secret.len(), 6);
assert!(secret.iter().all(u8::is_ascii_digit));

let stored = code.hash(&secret)?;
assert!(code.verify(&secret, &stored)?);
```

## Keep session data in a cookie

A `Store` carries a few properties as base64 JSON, the format Appwrite's session cookies use. Decoding merges the properties back.

```rust
use php_std::Value;
use utopia_auth::Store;

let mut store = Store::new();
store.set_property(b"id", Value::from("session-1")).set_property(b"secret", Value::from("s3cr3t"));
let cookie = store.encode()?;
assert_eq!(cookie, "eyJpZCI6InNlc3Npb24tMSIsInNlY3JldCI6InMzY3IzdCJ9");

let mut read = Store::new();
read.decode(cookie.as_bytes());
assert_eq!(read.property(b"secret"), Some(&Value::from("s3cr3t")));
```

## Issue and verify a JWT

A [`Jwt`](jwt::Jwt) issuer signs claims with a shared secret (HS256); a [`Verifier`](jwt::Verifier) checks the signature, the issuer, the audience and the expiry. Both take the current time, so tokens are testable.

```rust
use php_std::{Array, ArrayKey, Value};
use utopia_auth::jwt::{Audience, Hs256, Jwt, Symmetric, Verifier};

let secret = b"a-long-random-secret";
let issuer = Jwt(Symmetric::new(secret, b"https://cloud.example", None)?);
let audience = Audience::One(b"my-app".to_vec());

let mut claims = Array::new();
claims.set(ArrayKey::from("userId"), Value::from("u1"));
let now = 1_700_000_000;
let token = issuer.issue(&audience, 900, &claims, now)?;

let verifier = Verifier::new(Hs256::new(secret)?, Some(b"https://cloud.example"), Some(&audience), None, false, 0)?;
let verified = verifier.verify(token.as_bytes(), now + 60)?;
assert_eq!(verified.get_str("userId"), Some(&Value::from("u1")));
assert_eq!(verified.get_str("exp"), Some(&Value::Int(now + 900)));

// Fifteen minutes later the token has expired.
assert!(verifier.verify(token.as_bytes(), now + 901).is_err());
```

## Sign access tokens with a key file

Access tokens are signed with an RSA key (RS256) so anyone holding the public key can verify them. Keys can be given inline as PEM or as a `file://` path, which is read when signing.

```rust
use php_std::Array;
use utopia_auth::jwt::{generate_key_pair, AccessToken, Asymmetric, Rs256, Verifier};

let pair = generate_key_pair(2048)?;
let dir = std::env::temp_dir().join(format!("utopia-auth-guide-{}", std::process::id()));
std::fs::create_dir_all(&dir).unwrap();
let path = dir.join("private.pem");
std::fs::write(&path, &pair.private_key).unwrap();

let private_key = format!("file://{}", path.display());
let issuer = AccessToken(Asymmetric::new(private_key.as_bytes(), pair.public_key.as_bytes(), b"https://cloud.example", None)?);
let audience = Array::from_list(vec!["https://api.example".into()]);
let now = 1_700_000_000;
let token = issuer.issue(b"user-1", &audience, b"client-1", now, 3600, &[b"read".to_vec()], None, &Array::new(), now)?;

let verifier = Verifier::new(Rs256::new(pair.public_key.as_bytes())?, None, None, Some(b"at+jwt"), false, 0)?;
let claims = verifier.verify(token.as_bytes(), now)?;
assert_eq!(claims.get_str("scope"), Some(&"read".into()));
std::fs::remove_dir_all(&dir).unwrap();
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
