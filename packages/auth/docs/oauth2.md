# OAuth2 and OpenID Connect

Utopia Auth includes small value objects and token issuers for OAuth2 and
OpenID Connect authorization servers. The token issuers mint signed JWTs, while
the OAuth2 helpers parse request parameters at the protocol boundary so
application code can work with typed values.

## OAuth2 access tokens (RFC 9068)

```php
<?php

use Utopia\Auth\Issuers\Asymmetric\AccessToken;

// Generate an RSA key pair (do this once and persist the keys)
[$privateKey, $publicKey] = AccessToken::generateKeyPair();

$accessToken = new AccessToken(
    $privateKey,
    $publicKey,
    'https://example.com/v1/oauth2/my-app' // The "iss" claim (authorization server)
);

// Issue a signed RS256 access token
$jwt = $accessToken->issue(
    subject: 'user-123',                   // "sub" — the resource owner
    audience: ['https://api.example.com'], // "aud" — the resource server
    clientId: 'client-abc',                // "client_id" — the client it was issued to
    authTime: time(),                      // "auth_time" — when the user authenticated
    duration: 3600,                        // Lifetime in seconds ("exp")
    scopes: ['openid', 'profile', 'email']
);

// Publish the public key as a JWK so resource servers can verify tokens
$jwk = $accessToken->getPublicJwk();
$keyId = $accessToken->getKeyId();
```

## OAuth2 refresh tokens

```php
<?php

use Utopia\Auth\Issuers\Symmetric\RefreshToken;

// Generate a signing secret (do this once and keep it server-side)
$secret = RefreshToken::generateSecret();

$refreshToken = new RefreshToken(
    $secret,
    'https://example.com/v1/oauth2/my-app'
);

// Issue a signed HS256 refresh token
$jwt = $refreshToken->issue(
    subject: 'user-123',                             // "sub"
    audience: 'https://example.com/v1/oauth2/token', // "aud" — the token endpoint
    clientId: 'client-abc',                          // "client_id"
    duration: 1209600,                               // Lifetime in seconds (e.g. 14 days)
    scopes: ['openid', 'profile']
);
```

## OpenID Connect ID tokens

```php
<?php

use Utopia\Auth\Issuers\Asymmetric\IdToken;

[$privateKey, $publicKey] = IdToken::generateKeyPair();

$idToken = new IdToken(
    $privateKey,
    $publicKey,
    'https://example.com/v1/oauth2/my-app'
);

// Issue a signed OIDC id_token
$jwt = $idToken->issue(
    subject: 'user-123',          // "sub" — the authenticated user
    audience: 'client-abc',       // "aud" — the client the token is for
    authTime: time(),             // "auth_time"
    duration: 3600,               // Lifetime in seconds ("exp")
    nonce: 'n-0S6_WzA2Mj',        // Optional "nonce" from the auth request
    accessToken: null,            // Optional co-issued access_token (adds "at_hash")
    code: null                    // Optional co-issued authorization code (adds "c_hash")
);
```

Both asymmetric and symmetric issuers accept an optional `keyId` constructor
argument (the JWS `kid` header) for key rotation. For asymmetric issuers it is
derived deterministically from the public key when omitted.

## OAuth2 resource indicators (RFC 8707)

`ResourceIndicators` parses the `resource` request parameter and keeps resource
server identifiers as a normalized list of absolute HTTP(S) URIs without
fragments.

```php
<?php

use Utopia\Auth\OAuth2\ResourceIndicators;

$resources = ResourceIndicators::from([
    'https://api.example.com/',
    'https://mcp.example.com/',
]);
$previouslyGrantedResources = ResourceIndicators::from(['https://api.example.com/']);

$isAllowed = $resources->isSubsetOf($previouslyGrantedResources);
$unchanged = $resources->equals($previouslyGrantedResources);
$audience = $resources->audience('https://cloud.example.com/v1/project');
$serialized = $resources->toArray();
```

For compatibility with clients that send a single resource identifier using
`audience`, pass it as the optional second argument. When `resource` is absent,
the audience becomes the resource indicator. When both are present, the
audience must exactly match one of the supplied resources.

```php
$resources = ResourceIndicators::from(null, 'https://api.example.com/');
```

`InvalidResourceException::ERROR_CODE` is `invalid_target`, matching RFC 8707.

## OAuth2 rich authorization requests (RFC 9396)

`AuthorizationDetails` reads a token's `authorization_details`: a JSON array of
typed entries, each an object with a `type` and, per its type, further fields.
`grants()` answers whether an entry of a given type lists a value in one of its
array-valued fields — an RFC 9396 §2 common field (`locations`, `actions`,
`datatypes`, `privileges`) or a field a type defines itself. `AuthorizationDetail`
enumerates those common field names.

The constructor accepts the raw value and keeps only list-shaped input: a value
that is not a JSON array, or a field that is a JSON object rather than an array,
contributes nothing, so a malformed claim cannot grant access. Value comparison
is type-strict.

RFC 9396 defines no wildcard. A profile that lets one identifier stand for every
value passes that sentinel as `$wildcard` to opt a field into it; left out, only
an exact value matches.

```php
<?php

use Utopia\Auth\Enums\AuthorizationDetail;
use Utopia\Auth\OAuth2\AuthorizationDetails;

$details = new AuthorizationDetails([
    ['type' => 'project', 'identifiers' => ['p1', '*'], 'actions' => ['read']],
    ['type' => 'organization', 'identifiers' => ['t1']],
]);

$details->grants('project', 'p1', 'identifiers');                          // true (exact)
$details->grants('project', 'anything', 'identifiers', '*');               // true (wildcard opted in)
$details->grants('project', 'anything', 'identifiers');                    // false (no wildcard)
$details->grants('project', 'read', AuthorizationDetail::Actions->value);  // true (RFC common field)
$details->grants('organization', 't1', 'identifiers');                     // true
```

`restrict()` narrows a field of every entry to the values a resolver allows, so
an issued token asserts only what the subject can reach now. The resolver gets
an entry's `type` and the field's values and returns the allowed subset; `null`
leaves an entry of a type it does not govern untouched, an empty result drops
the entry. The result can only narrow the grant: a value the entry did not list
is discarded, unless the entry lists `$wildcard`, in which case the result from
the resolver is taken as its expansion, as with `grants()`. `toArray()` yields
the entries for the `authorization_details` claim.

```php
<?php

$issued = $granted->restrict('identifiers', fn (string $type, array $values): ?array => match ($type) {
    'organization' => $memberships->intersect($values),
    default => null,
});

$issued->toArray();
```

## OAuth2 redirect URI matching (RFC 8252)

`RedirectUris` wraps a client's registered redirect URIs and matches a
presented `redirect_uri` against them. Matching is exact string comparison.
With `allowLoopback` enabled, http loopback URIs (`localhost`,
`127.0.0.1`, `[::1]`) match with any port per RFC 8252 Section 7.3 — native
and CLI clients bind an ephemeral port per run and cannot register it ahead
of time. RFC 8252 scopes that carve-out to native apps, which are public
clients (Section 8.4), so enable it for public clients only and keep
confidential clients on exact matching. The loopback hosts are an exact
allowlist (lookalikes such as `localhost.evil.com` never qualify), the host
itself must still match, and scheme, path, and query compare exactly.

```php
<?php

use Utopia\Auth\OAuth2\RedirectUris;

$uris = RedirectUris::from([
    'https://example.com/callback',
    'http://localhost:3118/callback',
]);

$isPublicClient = true; // e.g. token_endpoint_auth_method 'none' (PKCE)

$uris->matches('https://example.com/callback');                        // true (exact)
$uris->matches('http://localhost:54155/callback', $isPublicClient);    // true (loopback, port ignored)
$uris->matches('http://localhost:54155/callback');                     // false (strict without opt-in)
$uris->matches('http://127.0.0.1:54155/callback', $isPublicClient);    // false (host must match)
$uris->matches('https://example.com/other');                           // false
```

## OpenID Connect prompts

`Prompts` parses the OIDC `prompt` authorization request parameter into typed
`Prompt` enum values. Empty input means no prompt preference was requested,
duplicate values are collapsed, and `prompt=none` cannot be combined with any
other prompt value.

```php
<?php

use Utopia\Auth\Enums\Prompt;
use Utopia\Auth\OAuth2\Prompts;

$prompts = Prompts::fromString('login consent select_account');

$requiresLogin = $prompts->contains(Prompt::Login);
$serialized = $prompts->toArray();  // ['login', 'consent', 'select_account']
$parameter = $prompts->toString();  // 'login consent select_account'
```

Invalid prompt values throw `InvalidPromptException`.
`InvalidPromptException::ERROR_CODE` is `invalid_request`.

## Pushed authorization requests (RFC 9126)

`PAR` represents a pushed authorization request `request_uri`. Build one from a
stored request id when returning a PAR response, or parse one from a
`request_uri` parameter when the client later calls the authorization endpoint.

```php
<?php

use Utopia\Auth\OAuth2\PAR;

$par = PAR::fromId('urn:appwrite:oauth2:request:', 'grant123');

$requestUri = $par->requestUri(); // 'urn:appwrite:oauth2:request:grant123'
$id = $par->id();                 // 'grant123'

$parsed = PAR::fromRequestUri('urn:appwrite:oauth2:request:', $requestUri);
$storedRequestId = $parsed->id();
```

Malformed request URIs throw `InvalidRequestUriException`.
`InvalidRequestUriException::ERROR_CODE` is `invalid_request`.

<!-- vale Utopia.HeadingSentenceCase = NO -->

## OAuth Client ID Metadata Documents

<!-- vale Utopia.HeadingSentenceCase = YES -->

OAuth Client ID Metadata Documents let a client use an HTTPS URL as its
`client_id`. An authorization server fetches the JSON metadata published at
that URL, allowing clients to identify themselves without prior registration.

Utopia Auth validates the protocol values after the authorization server has
retrieved the document. HTTP requests, response-size limits, redirect handling,
caching, and SSRF protection remain the responsibility of the authorization
server.

```php
<?php

use Utopia\Auth\OAuth2\ClientIdentifierUrl;
use Utopia\Auth\OAuth2\ClientIdMetadataDocument;

$clientId = ClientIdentifierUrl::fromString(
    'https://client.example/oauth/client-metadata.json'
);

$document = ClientIdMetadataDocument::fromJson($clientId, $responseBody);

$authMethod = $document->tokenEndpointAuthMethod();
$grantTypes = $document->grantTypes();
$redirectUris = $document->redirectUris();
$metadata = $document->toArray();
```

The identifier keeps its original serialization because Client Identifier URLs
use exact string comparison. `ClientIdMetadataDocument` verifies the echoed
`client_id`, rejects shared secrets and private key material, and validates the
standard metadata fields it exposes. Authorization-server-specific support for
authentication methods, grants, and response types should be checked after
parsing.

For isolated development environments, `ClientIdentifierUrl::fromString()`
accepts an `allowHttp` argument. Production authorization servers should leave
it disabled and must independently prevent requests to special-use addresses.

Invalid identifiers and documents throw `InvalidClientMetadataException`.

## Verifying OAuth2 and OIDC tokens

OAuth2 and OIDC tokens are verified with the JWT verifiers. Pin the token type
where possible so one token kind cannot be accepted in place of another.

```php
<?php

use Utopia\Auth\Verifiers\Asymmetric;
use Utopia\Auth\Verifiers\VerificationException;

// $publicKey is the PEM advertised on the issuer's JWKS endpoint.
$verifier = new Asymmetric(
    $publicKey,
    issuer: 'https://example.com/v1/oauth2/project',
    audience: 'https://example.com/v1/project',
    type: 'at+jwt', // require an RFC 9068 access token
    leeway: 30,     // tolerate 30s of clock skew
);

try {
    $claims = $verifier->verify($accessToken);
} catch (VerificationException) {
    // malformed, bad signature, wrong alg/type, expired, or a claim mismatch
}
```

For an OpenID Connect `id_token_hint` (which must be accepted even after it
expires), relax only the expiry check with `allowExpired: true` (`nbf`/`iat` are
still enforced):

```php
$claims = (new Asymmetric($publicKey, issuer: $issuer, allowExpired: true))
    ->verify($idToken);
```

HS256 tokens, such as refresh tokens, are verified the same way with the shared
secret:

```php
use Utopia\Auth\Verifiers\Symmetric;

$claims = (new Symmetric($secret, issuer: $issuer, audience: 'https://example.com/token'))
    ->verify($refreshToken);
```
