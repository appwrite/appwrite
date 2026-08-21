# Native OAuth2 Sign-In via ID Token Exchange

**Backend implementation guide — Appwrite**

## 1. Purpose and background

Appwrite's current OAuth2 support is a backend-brokered browser flow: the client calls `account.createOAuth2Token()`, the user is sent through the system browser to `/v1/account/tokens/oauth2/{provider}`, Appwrite performs the authorization-code exchange with the provider using project-configured credentials, and the result is delivered back to the app via a deep-link redirect carrying `userId` and `secret`.

This document specifies a second, complementary flow: **native ID token exchange** (sometimes called "token sign-in" or "credential sign-in"). In this flow, the mobile app obtains an OpenID Connect **ID token** directly from the identity provider using the platform's native SDK — Google Credential Manager on Android, `ASAuthorizationController` (Sign in with Apple) on iOS — and submits that token to Appwrite in a single API call. Appwrite verifies the token cryptographically, resolves or creates the user, and issues a session. No browser, no redirect, no deep link.

The motivations are concrete. First, UX: native sign-in surfaces (Google One Tap, the Apple Face ID sheet) convert dramatically better than a browser round-trip. Second, platform policy: Apple's App Store Review Guideline 4.8 effectively requires Sign in with Apple wherever third-party login exists, and the native `ASAuthorizationController` experience is the expected implementation. Third, competitive parity: Firebase is built around this model (`signInWithCredential`) and Supabase ships it as `signInWithIdToken`. This is a frequently requested capability for Appwrite's mobile story.

The critical mental shift from the browser flow: **Appwrite is no longer the OAuth client.** The *mobile app* is the OAuth client, registered with the provider under its own client ID. Appwrite's job reduces to being a **token verifier and session issuer**. There is no authorization-code exchange, no client secret involved on Appwrite's side, and no redirect URI handling. Everything hinges on correctly validating a JWT.

## 2. The flow, end to end

The sequence has three legs. First, the client leg: the app invokes the provider's native SDK, passing its provider-issued client ID and (mandatory for Apple, recommended everywhere) a freshly generated random **nonce** — actually the SHA-256 hash of the nonce, with the raw value kept in memory. The provider authenticates the user natively and returns an **ID token**: a signed JWT whose claims include `iss` (the provider), `aud` (the client ID the token was minted for), `sub` (the provider's stable user identifier), `email`, `exp`, `iat`, and the hashed `nonce` echoed back.

Second, the exchange leg: the app sends the ID token, the raw nonce, and the provider name to a new Appwrite endpoint. Appwrite verifies the token's signature against the provider's published JWKS, validates every relevant claim, and — only if everything passes — treats the token as proof of authentication.

Third, the session leg: Appwrite resolves the `(provider, sub)` pair against its identities, creating a user if needed, and returns a standard Appwrite session — the same session object the browser flow produces, indistinguishable to the rest of the platform.

The entire backend interaction is one stateless HTTPS request. That statelessness is what makes the flow simple, but it also means the token verification step carries the full security burden: there is no `state` parameter, no server-held PKCE verifier, nothing but the JWT and the nonce.

## 3. Proposed API surface

A single new endpoint, following Appwrite's existing route conventions under the account service:

```
POST /v1/account/sessions/oauth2/{provider}/token
```

Request body:

```json
{
  "idToken": "eyJhbGciOiJSUzI1NiIs...",
  "nonce": "d9f3a1c0-raw-nonce-value",
  "accessToken": "ya29.a0Af..."
}
```

`idToken` is required. `nonce` is required whenever the token contains a `nonce` claim (always, for Apple; whenever the client supplied one, for Google). `accessToken` is optional and only stored/used if the project wants to call provider APIs on the user's behalf afterward — it plays no role in authentication and must never be accepted as a substitute for the ID token.

Response: the standard `Session` model, identical to what `createSession` returns today. Scope: `guests` (unauthenticated callers create sessions), same as existing session-creation routes, subject to the same rate limits (`userId:{ip}` abuse limits apply).

Initial provider support should target **Apple** and **Google**, since those are the two with first-class native SDKs and the ones every mobile team needs. The endpoint shape generalizes to any OIDC-compliant provider later; keep the provider string aligned with the existing OAuth2 adapter names (`apple`, `google`) so project configuration stays unified.

## 4. Token verification — the heart of the implementation

This is where correctness matters most, and the checklist must be treated as non-negotiable. Verification of the incoming ID token proceeds as follows.

**Signature.** Decode the JWT header, read `kid` and `alg`. Fetch the provider's JWKS (Google: `https://www.googleapis.com/oauth2/v3/certs`; Apple: `https://appleid.apple.com/auth/keys`), select the key matching `kid`, and verify the RS256 signature. Reject any token whose header declares an algorithm other than what the provider documents — never let the token choose its own verification algorithm (`alg: none` and HS256-confusion attacks are the classic failure modes here).

**Issuer.** `iss` must exactly equal the expected value: `https://accounts.google.com` (Google) or `https://appleid.apple.com` (Apple).

**Audience.** `aud` must match a client ID **configured on the Appwrite project** for this provider. This is the claim that stops token replay across apps: a valid Google ID token minted for some *other* application must be rejected, otherwise any app the user has ever signed into could mint sessions on your project. See section 7 on configuration — a project needs to register *multiple* acceptable audiences, because Google issues distinct client IDs per platform (Android, iOS, web/server) and Apple distinguishes the app's bundle ID from a Services ID.

**Expiry and issue time.** `exp` must be in the future and `iat` in the past, with a small tolerated clock skew (±60 seconds is conventional). Consider rejecting tokens older than a short window (e.g., `iat` more than 10 minutes ago) even if unexpired, since a legitimate client exchanges the token immediately after receiving it.

**Nonce.** If the token carries a `nonce` claim, the request must include the raw nonce, and `SHA256(rawNonce)` must equal the claim (Apple hashes; Google echoes the raw value — handle both conventions per provider). The nonce binds the token to the specific sign-in ceremony the app initiated and is the primary defense against replay of a stolen token. For Apple, treat a missing nonce as a hard failure.

**Email claims.** Read `email` and `email_verified` if present, but treat them as profile data, not identity. Google sets `email_verified` reliably; Apple emails are always verified but may be private-relay addresses (`@privaterelay.appleid.com`). Never use email as the join key for authentication — that is what `sub` is for.

## 5. JWKS handling

JWKS endpoints must be fetched over TLS and **cached** — both providers serve `Cache-Control` headers (typically hours). Fetching on every request adds latency and a hard runtime dependency on provider uptime; never caching means an outage at Google takes down your sign-in. The right behavior: cache keys by `(provider, kid)`, respect cache headers, and on encountering an unknown `kid`, perform one forced refresh before rejecting (this handles provider key rotation gracefully). Appwrite's existing cache abstraction is the natural home for this. Impose a sane floor and ceiling on TTL regardless of headers (e.g., minimum 5 minutes, maximum 24 hours).

A note on implementation strategy: the verification logic is generic OIDC and should live in a shared component (e.g., a `TokenOAuth2` capability alongside the existing OAuth2 adapter classes), with per-provider subclasses supplying only the constants — issuer URL, JWKS URL, nonce hashing convention, and claim quirks. Resist the temptation to hand-roll JWT parsing; use the JWT primitives already vendored in the codebase and extend them with JWKS support if needed.

## 6. User resolution, identities, and account linking

Once the token is verified, resolve the user by the pair `(provider, sub)` — in Appwrite's data model, this maps onto the existing **identities** concept (`provider`, `providerUid`). The `sub` claim is the provider's permanent, unique user ID; email is not (users change emails; Apple relay addresses can be disabled).

The resolution logic, in order: if an identity with this `(provider, providerUid)` exists, load its user and issue a session — this is the returning-user path. If no identity exists but the token's email matches an existing user, **do not silently link**. Silent linking on email is the classic account-takeover vector: an attacker who controls an email at some provider (or exploits a provider that doesn't verify emails) could attach their identity to a victim's account. Mirror the existing behavior of the browser flow here: return `user_already_exists` (409) unless the project has explicitly opted into trusted-email linking, or the request arrives on an *already authenticated* session, in which case it becomes an explicit identity-link operation. If neither an identity nor an email collision exists, create a new user: mark the email verified if the provider attests it, store the name if present (see the Apple caveat below), create the identity record, and fire the standard `users.*.create` events.

**Apple caveat that will generate support tickets if missed:** Apple includes the user's name and email in the *authorization response* only on the **first ever** authorization for that Apple ID + app combination — and the name is never inside the ID token at all; it arrives as a separate field on the client. The endpoint should therefore accept an optional `name` field in the request body, trusted only for profile purposes, and documentation must tell client developers to capture it on first sign-in. If the user later deletes and recreates their account, the name won't come back unless they revoke the app in their Apple ID settings.

## 7. Project configuration

The existing OAuth2 provider settings (App ID + secret) were designed for the browser flow. This flow needs a different shape of configuration: a list of **allowed audiences** per provider. Concretely, extend the provider config with an `allowedClientIds` array (or reuse the App ID field as one entry and add the array for the rest). A typical Google setup registers three: the Android client ID, the iOS client ID, and the web/server client ID (Credential Manager on Android is usually configured with the *web* client ID as the requested audience — a persistent source of developer confusion worth spelling out in the docs). A typical Apple setup registers the app's bundle identifier and, if the project also does web sign-in, the Services ID.

Whether the token flow is enabled should be a per-provider toggle, defaulting to off, so existing projects see zero behavior change. No client secret is required for this flow; validation should not demand one when only the token flow is enabled.

## 8. Error semantics

Return existing Appwrite error types where they fit and add specific ones where diagnosis matters to client developers. Suggested mapping: malformed/unsigned/expired token or nonce mismatch → a new `user_oauth2_token_invalid` (401) with a message naming the failed check (safe to be specific — the caller already holds the token); audience not in the allow-list → same error type, message indicating audience mismatch, since this is overwhelmingly a misconfiguration developers need to self-diagnose; provider disabled or token flow not enabled → existing `project_provider_disabled` (412); email collision without linking → `user_already_exists` (409); JWKS fetch failure after retry → 503 with a retryable error, never a silent pass.

Log verification failures with the failing claim (never the token itself — treat the raw JWT as a credential in logs) to make abuse patterns visible.

## 9. Security review checklist

The failure modes worth a dedicated review before shipping, because each has burned real products: accepting `alg` from the attacker-controlled header instead of pinning per provider; verifying signature but skipping `aud`, which turns every Google app on earth into a session mint for your project; skipping nonce verification, enabling replay of tokens harvested elsewhere; joining on email instead of `sub`, enabling takeover via provider email reuse; accepting an OAuth **access token** where an **ID token** is required (access tokens are opaque, unverifiable bearer credentials with the wrong audience semantics — Firebase and Supabase both explicitly require ID tokens for this reason); and unbounded trust in JWKS caching, where a compromised-then-rotated provider key stays trusted past its life. Rate limiting matters too: this endpoint performs signature verification (cheap) but also user lookups and session writes; the existing session-creation abuse limits should apply unchanged.

One deliberate scope exclusion: this flow does **not** return provider refresh tokens, because the native SDKs don't expose the authorization-code grant that yields them. If a project needs long-lived provider API access (e.g., ongoing Google Drive access), that remains a job for the browser flow. Document this so expectations are set.

## 10. Testing notes

Unit-test the verifier against a locally generated RSA keypair serving as a fake JWKS: happy path, expired token, future `iat`, wrong `iss`, wrong `aud`, tampered payload, unknown `kid` (must trigger one JWKS refresh then fail), `alg: none`, HS256 downgrade, nonce mismatch, missing nonce on Apple. Integration-test the identity resolution matrix: new user, returning user, email collision with linking disabled, explicit link from an authenticated session, and Apple first-sign-in name capture. For live-provider smoke tests, Google ID tokens can be minted easily via the OAuth Playground with a test client; Apple requires a real device or simulator with a signed-in Apple ID, so budget for that in QA.

## 11. Client-side summary (for the docs team)

Although this document is backend-focused, the endpoint's contract implies client responsibilities worth stating: Android obtains the ID token via Credential Manager (`GetGoogleIdOption`, typically with the web client ID and a nonce); iOS uses `ASAuthorizationAppleIDProvider` with `request.nonce = SHA256(rawNonce)` and submits the `identityToken` plus raw nonce; both platforms must call the exchange endpoint immediately and treat the ID token as single-use. SDK convenience wrappers (e.g., `account.createOAuth2TokenSession(provider: .apple, idToken: ..., nonce: ...)`) can come after the REST endpoint stabilizes.