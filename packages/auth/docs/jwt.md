# JSON Web Tokens

The library mints and verifies signed [JWS](https://datatracker.ietf.org/doc/html/rfc7515)
tokens. Issuers and verifiers share a common base that owns the JWS mechanics
and delegates only the signing algorithm:

- **Issuers** — `Issuer` owns header assembly, `jti` generation, base64url
  encoding and the header/payload/signature structure.
- **Verifiers** — `Verifier` owns splitting the compact form, base64url/JSON
  decoding, the `alg` guard and the standard `exp`/`nbf`/`iat`/`iss`/`aud`
  claim checks.

The two signing families are `Asymmetric` (RS256, RSA keypair) and `Symmetric`
(HS256, shared secret). For OAuth2 and OpenID Connect token examples, see
[OAuth2 and OpenID Connect](oauth2.md).

## Issuing application tokens

Use `Issuers\Symmetric\Jwt` for HS256 application tokens such as login state
or session cookies, without an OAuth2 token profile.

```php
use Utopia\Auth\Issuers\Symmetric\Jwt;

// Generate once and persist server-side.
$secret = Jwt::generateSecret();
$jwt = (new Jwt($secret, 'https://example.com'))->issue(
    audience: 'preview', // A non-empty string or list of non-empty strings.
    duration: 600,
    claims: ['purpose' => 'state'],
);
```

The issuer controls `iss`, `aud`, `iat`, and `exp`; custom claims cannot
override them. `duration` must be positive. Tokens are signed, not encrypted.
After [verification](#verifying-tokens), applications must check custom claims
and enforce browser binding, authorization, and replay protection as needed.

## Verifying tokens

Verify a token minted by one of the issuers (or any compliant JWS). The
signature is checked first, then the `alg` header, then the claim expectations
you pass to the constructor. `verify()` returns the decoded claims or throws a
`VerificationException`.

Expectations are passed at construction (not fluent setters) and held
read-only, so a verifier instance is immutable and safe to share across
coroutines. By default a bounded lifetime is enforced: `exp` is **required** and
must be in the future, and `nbf`/`iat` are rejected if the token isn't valid yet
or claims a future issuance. The `issuer`, `audience` and `type` checks are
opt-in.

```php
<?php

use Utopia\Auth\Verifiers\Asymmetric;
use Utopia\Auth\Verifiers\VerificationException;

// $publicKey is the PEM advertised by the issuer.
$verifier = new Asymmetric(
    $publicKey,
    issuer: 'https://example.com',
    audience: 'https://api.example.com',
    type: 'JWT',
    leeway: 30, // tolerate 30s of clock skew
);

try {
    $claims = $verifier->verify($jwt);
} catch (VerificationException) {
    // malformed, bad signature, wrong alg/type, expired, or a claim mismatch
}
```

HS256 tokens are verified the same way with the shared secret:

```php
use Utopia\Auth\Verifiers\Symmetric;

$claims = (new Symmetric($secret, issuer: $issuer, audience: 'https://example.com'))
    ->verify($jwt);
```

`Verifiers\Asymmetric` also exposes `getKeyId()`, which derives the JWS `kid`
deterministically from the public key the same way the issuer does — useful for
matching a token's `kid` header or selecting the right key from a JWKS:

```php
$verifier = new Asymmetric($publicKey);
$kid = $verifier->getKeyId(); // matches the issuer's getKeyId() for the same key
```

## Claim and header names

The claim and header names used above are also available as string-backed enums,
so you can reference them without magic strings when reading verified claims:

```php
<?php

use Utopia\Auth\Enums\Claim;
use Utopia\Auth\Enums\Header;

$subject = $claims[Claim::Subject->value]; // 'sub'
$algorithm = Header::Algorithm->value;      // 'alg'
```

`Claim` covers the RFC 7519 registered claims plus the OAuth2 (RFC 9068) and
OpenID Connect claims this library issues; `Header` covers the RFC 7515 JOSE
header parameters (`typ`, `alg`, `kid`).
