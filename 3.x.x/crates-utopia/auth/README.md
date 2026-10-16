# utopia-auth

Authentication and authorization for Utopia - a Rust port of [`utopia-php/auth`](https://github.com/utopia-php/auth).

## Features

| Feature | Default | Description |
|---------|---------|-------------|
| `argon2` | yes | Argon2id password hashing |
| `bcrypt` | yes | Bcrypt password hashing |
| `jwt` | yes | HS256 / RS256 JWT issuers and verifiers (`jsonwebtoken`, `rsa`) |
| `legacy` | yes | SHA, MD5, plaintext, scrypt, modified scrypt, and PHPass hashers (not for production) |
| `oauth2` | yes | OAuth2/OIDC helpers for PAR, prompts, redirect URIs, resource indicators, and client metadata |

## Getting started

```toml
[dependencies]
utopia-auth = { path = "../crates-utopia/auth", default-features = true }
```

```rust
use utopia_auth::hashes::Argon2;
use utopia_auth::{Hash, Password, Proof};

let password = Password::new();
let hash = password.hash("user-password")?;
let valid = password.verify("user-password", &hash);
```

## OAuth2 helpers

The `oauth2` module includes typed helpers ported from the PHP library:

- `PAR` request URI builder/parser
- `Prompts` and `Prompt`
- `RedirectUris`
- `ResourceIndicators`
- `ClientIdentifierUrl`
- `ClientIdMetadataDocument`

---

## API Reference

### Errors

#### `AuthError`

Unified error type for hashing, store, and JWT operations.

| Variant | Description |
|---------|-------------|
| `InvalidInput(String)` | Missing or invalid configuration |
| `HashingFailed(String)` | Password hashing failed |
| `Json(serde_json::Error)` | JSON serialization error |
| `InvalidBase64` | Invalid base64 in store decode |
| `SigningFailed(String)` | JWT signing failed |
| `Verification(String)` | JWT verification failed |

#### `VerificationException`

Type alias for `AuthError` - thrown when a JWT fails verification.

---

### Hashing (`Hash` trait)

Object-safe trait for password hashing algorithms.

```rust
pub trait Hash: Send + Sync {
    fn hash(&self, value: &str) -> Result<String, AuthError>;
    fn verify(&self, value: &str, hash: &str) -> bool;
    fn name(&self) -> &str;
    fn options(&self) -> &HashMap<String, Value>;
}
```

#### `HashMut` trait

Mutable option helpers for concrete hash implementations.

| Method | Description |
|--------|-------------|
| `set_option(key, value)` | Set a single option |
| `set_options(map)` | Set multiple options |
| `get_option(key)` | Read a single option |

#### `HashOptions`

Shared `HashMap<String, Value>` storage with `require_string` / `require_u32` helpers.

#### `Argon2` (`argon2` feature)

Argon2id hasher. Defaults: `memory_cost=65536`, `time_cost=4`, `threads=3`.

| Method | Description |
|--------|-------------|
| `new()` | Create with defaults |
| `set_memory_cost(cost)` | Memory cost in KiB |
| `set_time_cost(cost)` | Iteration count |
| `set_threads(threads)` | Parallelism |

#### `Bcrypt` (`bcrypt` feature)

Bcrypt hasher. Default `cost=8`.

| Method | Description |
|--------|-------------|
| `new()` | Create with defaults |
| `set_cost(cost)` | Cost factor (4–31) |

#### `Sha` (`legacy` feature)

SHA digest hasher. Default `version=sha256`. Supports `sha1`, `sha224`, `sha256`, `sha384`, `sha512`.

| Method | Description |
|--------|-------------|
| `new()` | Create with SHA-256 default |
| `set_version(version)` | Select algorithm |

#### `Md5` (`legacy` feature)

MD5 digest hasher (legacy only).

#### `Plaintext` (`legacy` feature)

Pass-through hasher for testing only.

---

### Proofs (`Proof` trait)

```rust
pub trait Proof: Send + Sync {
    fn generate(&self) -> Result<String, AuthError>;
    fn hash(&self, proof: &str) -> Result<String, AuthError>;
    fn verify(&self, proof: &str, hash: &str) -> bool;
    fn hasher(&self) -> &dyn Hash;
    fn set_hasher(&mut self, hasher: Arc<dyn Hash>);
}
```

#### `Password`

Random password generator with a hash registry.

| Constant / Default | Value |
|--------------------|-------|
| Default length | 16 |
| Minimum length | 8 |
| Default charset | `a-zA-Z0-9!@#$%^&*()_+-=[]{}|;:,.<>?` |
| Minimum charset size | 10 |

| Method | Description |
|--------|-------------|
| `new()` | Create with default hash registry |
| `with_hashes(map)` | Create with custom registry |
| `add_hash(name, hasher)` | Register a hash |
| `remove_hash(name)` | Remove a hash (not the active one) |
| `hash_by_name(name)` | Look up a registered hash |
| `set_length(n)` | Set generation length (≥ 8) |
| `set_charset(s)` | Set generation charset (≥ 10 chars) |
| `generate()` | Generate a random password |
| `hash(proof)` | Hash a password |
| `verify(proof, hash)` | Verify a password |

#### `Token`

Hex-encoded random token.

| Default | Value |
|---------|-------|
| Length | 256 |

| Method | Description |
|--------|-------------|
| `new(length)` | Create with given length (> 0) |
| `with_default_length()` | Create with length 256 |
| `length()` | Current length |
| `set_length(n)` | Set length (> 0) |
| `generate()` | Generate token |

#### `Code`

Numeric one-time code (e.g. 2FA).

| Default | Value |
|---------|-------|
| Length | 6 |
| Charset | digits `0-9` |

| Method | Description |
|--------|-------------|
| `new(length)` | Create with given length (> 0) |
| `with_default_length()` | Create with length 6 |
| `length()` | Current length |
| `set_length(n)` | Set length (> 0) |
| `generate()` | Generate numeric code |

#### `Phrase`

Human-readable `"Adjective noun"` phrase from fixed word lists.

| Method | Description |
|--------|-------------|
| `new()` | Create phrase proof |
| `generate()` | Generate `"Abundant apple"`-style phrase |

---

### Store

Base64-encodable key/value envelope for authentication state.

| Method | Description |
|--------|-------------|
| `new()` | Empty store |
| `get_property(key)` | Read a property |
| `set_property(key, value)` | Set a property (chainable) |
| `key()` | Optional encryption key |
| `set_key(key)` | Set encryption key (chainable) |
| `encode()` | Base64-encode JSON properties |
| `decode(data)` | Decode base64 JSON into properties (invalid input ignored) |
| `properties()` | All stored properties |

---

### JWT (`jwt` feature)

Compact JWS (RFC 7515) issuers and verifiers with algorithm-confusion guards and standard claim checks.

#### Enums

**`Claim`** - `iss`, `sub`, `aud`, `exp`, `nbf`, `iat`, `jti`, `client_id`, `auth_time`, `scope`, `nonce`, `at_hash`, `c_hash`

**`Header`** - `typ`, `alg`, `kid`

#### `Issuer` trait

| Method | Description |
|--------|-------------|
| `issuer()` | `iss` claim value |
| `token_type()` | `typ` header |
| `algorithm()` | `alg` header |
| `sign(claims)` | Produce signed compact JWS |
| `generate_jti(bytes)` | Random hex `jti` |

#### `SymmetricIssuer` (HS256)

| Method | Description |
|--------|-------------|
| `new(secret, issuer, typ, kid)` | Create issuer |
| `generate_secret(bytes)` | Random hex secret |
| `issue_claims(claims)` | Sign claims with `iss` injected |

Uses HMAC-SHA256; `jsonwebtoken` is a dependency for ecosystem interoperability.

#### `RefreshToken` (HS256, `typ=JWT`)

OAuth2 refresh token issuer.

```rust
pub fn issue(
    &self,
    subject: &str,
    audience: &str,
    client_id: &str,
    duration_secs: i64,
    scopes: &[&str],
    jti: Option<&str>,
    extra_claims: HashMap<String, Value>,
) -> Result<String, AuthError>
```

#### `AsymmetricIssuer` (RS256)

| Method | Description |
|--------|-------------|
| `new(private_pem, public_pem, issuer, typ, kid)` | Create issuer |
| `generate_key_pair(bits)` | Generate RSA keypair (default 2048 bits) |
| `key_id()` | Deterministic `kid` from modulus |
| `public_jwk()` | JWK for JWKS endpoint |
| `issue_claims(claims)` | Sign claims with `iss` injected |

#### `VerifierConfig`

Immutable verification expectations (coroutine-safe shared instances).

| Field / Builder | Description |
|-----------------|-------------|
| `issuer` | Required `iss` (optional) |
| `audience` | Acceptable `aud` values (optional) |
| `token_type` | Required `typ` header (optional) |
| `allow_expired` | Skip `exp` check (default `false`) |
| `leeway` | Clock-skew seconds (default `0`) |

#### `SymmetricVerifier` (HS256)

| Method | Description |
|--------|-------------|
| `new(secret, config)` | Create verifier |
| `with_secret(secret)` | Create with default config |
| `verify(token)` | Verify and return claims |

#### `AsymmetricVerifier` (RS256)

| Method | Description |
|--------|-------------|
| `new(public_pem, config)` | Create verifier |
| `with_public_key(public_pem)` | Create with default config |
| `key_id()` | Deterministic `kid` from modulus |
| `verify(token)` | Verify and return claims |

Verification order: signature → `alg` guard → `typ` → `nbf`/`iat` → `exp` (required unless `allow_expired`) → `iss` → `aud`.

---

### Prelude

```rust
use utopia_auth::prelude::*;
```

Re-exports the most common types and traits.

---

## Tests

```bash
cd crates-utopia/auth
cargo test
```

Integration tests cover:

- Argon2 hash/verify roundtrip
- Store encode/decode
- JWT HS256 issue/verify (including wrong secret rejection)
- JWT RS256 issue/verify
- Token/code generation lengths

## Benchmarks

```bash
cd crates-utopia/auth
cargo bench --bench auth
```

| Benchmark | Description |
|-----------|-------------|
| `auth_argon2_hash` | Argon2id hashing (3 iterations - slow by design) |
| `auth_store_encode` | Store base64 JSON encode |
| `auth_jwt_hs256` | Refresh token issue + HS256 verify roundtrip |

## License

MIT - see [LICENSE](LICENSE).
