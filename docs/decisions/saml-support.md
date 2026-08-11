# SAML 2.0 support

Why SAML is built the way it is, and what was rejected on the way there. Read
this before changing the SAML flow: several of the choices below look
unnecessary until you know what they are defending against.

## Scope

SP-initiated SAML 2.0 sign-in with the HTTP-POST binding, generic across
identity providers.

**Not in this change:** IdP-initiated sign-in, Single Logout, encrypted
assertions, signed AuthnRequests, console UI.

## 1. SAML is not an OAuth2 provider, and does not enter through OAuth2 routes

**Decision.** SAML has its own endpoints. `saml` is registered in
`app/config/oAuthProviders.php` with `'protocol' => 'saml'`, and every OAuth2
entry point filters on that key through `Appwrite\Auth\SAML\Provider`.

**Why not just add `saml` as another provider value.** The config keys feed
`WhiteList` at six route declarations (`account.php` x5, `projects.php` x1).
Registering SAML without the filter makes
`GET /v1/account/sessions/oauth2/saml` a callable route that cannot work, and
leaks `saml` into the generated `OAuthProvider` SDK enum.

The deeper mismatch is the protocol. `Appwrite\Auth\OAuth2::getTokens($code)`
assumes an authorization code exchanged for a token over a back channel. SAML
has no such exchange: the assertion *is* the credential, it arrives as a POSTed
XML document, and it is already signed. Three concrete helpers on the base class
(`getAccessToken`, `getRefreshToken`, `getAccessTokenExpiry`) are built on
`getTokens`, so satisfying that contract honestly is not possible.

**Kept from the config anyway:** credential storage and the console provider
listing, both of which are protocol independent.

## 2. The shared session pipeline is reused, not duplicated or extracted

**Decision.** After the assertion is validated, the SAML assertion consumer
service mints a single-use code and redirects to the existing OAuth2 redirect
route, which does the user lookup, account creation and session issuing.
`Appwrite\Auth\OAuth2\Saml` is a thin adapter that resolves that code from the
cache and presents the already-validated identity through the OAuth2 provider
interface.

**Why not extract the shared tail into a function.** That was the original
plan. Reading it closely changed the answer: `account.php:1638-2125` is ~470
lines reading ~58 distinct variables, 17 of them injected dependencies, plus the
`$failureRedirect` closure. A shared callable needs roughly 25 parameters, which
is harder to review than the duplication it removes, in the most
security-sensitive route in the codebase.

**Why not duplicate it.** ~480 lines of account-creation and session logic in
two places drift, and the SAML copy would be the one that quietly falls behind
on security fixes.

The adapter is a deliberate shim and is documented as one. The trade is a small
amount of protocol-shaped indirection in exchange for the entire OAuth2 pipeline
staying untouched: the diff modifies 8 lines of existing controller logic, all
whitelist declarations.

**Consequence to know about.** The redirect worker route accepts every
configured provider, including SAML, while the four public OAuth2 entry points
reject it. That asymmetry is intentional and commented at the route: the worker
is not an entry point, and is only reachable with a single-use code the
assertion consumer service minted after verifying a signature.

**The exchange code is never persisted.** The pipeline stores whatever
`getAccessToken()` returns on the identity and session documents as the provider
access token. SAML has no provider token, and the exchange code is a credential,
so the adapter resolves the identity into memory and returns an empty string;
the identity getters then read from what was already resolved.

**Project resolution.** These routes take the project id from the path and look
it up directly, rather than relying on the `X-Appwrite-Project` header the rest
of the API uses. A browser following a sign-in link cannot set headers, and the
identity provider posts the assertion back with no Appwrite headers at all, so
the path segment is the only thing that can be authoritative here.

## 3. Signature verification order, and the wrapping defence

**Decision.** `Appwrite\Auth\SAML\Response::validate()` runs in a fixed order:
parse with entities disabled, verify the signature, confirm the signature covers
the assertion actually read, then check status, conditions, audience, recipient
and `InResponseTo`. No claim is read before the element carrying it is known to
be signed.

**The subtle part.** A valid signature over *some* element says nothing about
the element the claims are read from. XML Signature Wrapping exploits exactly
that: keep the legitimately signed assertion so the signature still verifies,
and add a forged one alongside it. Defences here are layered: the document must
contain exactly one `Response` and one `Assertion`, the assertion must be a
direct child of the response, and the verified `Reference` URI is tied back to
the ID of the element the signature is attached to.

**A trap worth recording.** `xmlseclibs` detaches the `Signature` node from the
document during `validateReference()`, to apply the enveloped-signature
transform. Any wrapping check written *after* that call queries an empty tree
and silently passes. The reference URI is therefore captured before validation
runs. This was found by the negative tests, not by review.

**Certificates.** The key is always the one the administrator configured. A
certificate embedded in the response is never trusted; otherwise anyone could
sign their own assertion and ship the matching certificate with it.

**Multiple signatures are accepted.** Okta, and several other providers, sign
the `Response` *and* the `Assertion` by default. An earlier version of this code
rejected any document with more than one signature, on the theory that extra
signatures were suspicious. That was wrong twice over: it is the stronger
configuration, and it would have failed against most real identity providers.
The assertion signature is preferred when both are present, since the assertion
is what carries the identity, and `xmlseclibs` is pointed at that specific node
rather than being allowed to locate one itself — otherwise it could verify the
response signature while the wrapping check reasons about the assertion, which
is the exact gap wrapping attacks exploit.

## 4. `robrichards/xmlseclibs`, and not hand-rolled XML-DSig

**Decision.** One new dependency, `robrichards/xmlseclibs` 3.1.x. It pulls no
transitive packages and needs only `ext-openssl`, which is already required.

**Why not hand-roll it.** `ext-dom`, `ext-openssl` and `ext-zlib` are all
already required, so it is technically possible. It is also how service
providers get CVEs: correct canonicalisation, reference resolution and wrapping
defence are the hard 80%, and the primitives are the easy 20%.

**Why not the alternatives.** `simplesamlphp/saml2` brings a much larger
dependency tree. `php-saml` is a whole SP toolkit with its own session and
configuration assumptions that fight Appwrite's routing. Version 4.x of
xmlseclibs adds a `phpseclib` dependency, so 3.1.x is the smaller footprint.

**Deviation from `AGENTS.md`.** This is the first non-`utopia-php` dependency in
this feature area, approved explicitly for that reason. If it should live behind
a `utopia-php/saml` package later, the protocol code in
`src/Appwrite/Auth/SAML/` is deliberately free of HTTP and database coupling and
can move as-is.

## 5. An email attribute is required

**Decision.** Sign-in fails, with a message naming the fix, when the assertion
carries no usable email address. A `NameID` that is itself an email address is
accepted as a fallback.

**Why.** `account.php` refuses to create a user without an email. SAML keys
identity on `NameID`, which is frequently an opaque persistent identifier, with
email arriving only as an attribute the IdP administrator chose to release.

The alternative was to wait for the wider "accounts without an email" work.
Requiring the attribute is how most service providers ship SAML first, and it
does not foreclose relaxing the requirement later. The failure message is the
important part: it tells the administrator to release an email attribute rather
than reporting a generic authentication failure.

## 6. Server-side state instead of RelayState

**Decision.** `RelayState` carries only an opaque 64-character token. The real
payload lives in Redis via `Appwrite\Auth\SAML\Ticket`.

**Why.** The SAML binding spec caps `RelayState` at 80 bytes. Appwrite's OAuth2
flow carries a JSON `state` blob in a `Text(2048)` parameter, which does not
fit.

**The subtlety.** The stored state must carry `success`, `failure` *and*
`token`. The shared redirect route reads `$state['token']` unconditionally when
choosing between issuing a session and issuing a token, while its own
`$defaultState` only supplies the first two. Omitting `token` fails on the token
flow only, which is exactly the kind of gap that reaches production. There is a
test for it.

## 7. Unsolicited responses are rejected

**Decision.** A response with no `InResponseTo` matching a request we issued is
refused.

**Why.** Without a request of our own there is nothing binding the response to
this browser, so a captured assertion could be replayed into any session. This
also means the "IdP-initiated" tile in an Okta dashboard will not work; that is
a deliberate v1 limitation, not an oversight, and is the main candidate for a
follow-up.

## 8. Replay prevention

Two independent single-use mechanisms:

- The `RelayState` record and the exchange code are both deleted on read, so
  neither a replayed callback nor a replayed redirect can mint a second session.
- Assertion IDs are recorded for the lifetime of the assertion
  (`Ticket::claimAssertion`), closing the narrower window where the same
  assertion is delivered twice before either delivery completes.

**Both run under a distributed lock.** Each is a check-then-act sequence — read
the record, then delete or write it — and under Swoole's concurrent workers two
requests carrying the same relay token, exchange code or assertion ID can
otherwise both pass the check before either writes. "Single use" enforced by two
separate cache calls is not single use. `Ticket` takes the `locks` resource and
runs each redemption inside a lock keyed on the record.

## 9. Only fully constrained bearer confirmations are accepted

`SubjectConfirmation` must use `urn:oasis:names:tc:SAML:2.0:cm:bearer`, and its
`Recipient`, `NotOnOrAfter` and `InResponseTo` are all mandatory.

**Why the method matters.** Bearer is the only method meaning "possession of
this assertion is proof of identity", which is the assumption this service
provider makes. `holder-of-key` requires the presenter to prove they hold a
key, and `sender-vouches` puts the attesting party on the hook — honouring
either as though it were bearer authenticates whoever delivered the assertion
rather than whoever it was issued for.

**Why the constraints are mandatory rather than checked-if-present.** They are
what stop a captured bearer assertion being useful: `Recipient` binds it to this
ACS, `NotOnOrAfter` bounds how long it lives, `InResponseTo` binds it to a
request we issued. An assertion that omits them is not a weaker credential to be
accepted with fewer checks; it is one that was never constrained, and the
earlier "validate only when present" reading meant a stripped-down assertion
skipped the very checks that made it safe.

An assertion may carry several `SubjectConfirmation` elements and is confirmed
if any one is satisfied, so each is tried in turn.

## Testing

32 unit tests in `tests/unit/Auth/SAML/`. The negative cases are the point:
signature wrapping, XXE, foreign-key forgery, tampering, wrong audience, wrong
issuer, wrong recipient, expired, not-yet-valid, mismatched `InResponseTo`,
unsolicited, failed status, malformed XML.

Assertions are signed with a throwaway key pair generated per test run, rather
than a committed fixture, so no real identity provider certificate enters the
repository and the negative tests can forge signatures with a second key.

The full flow has been exercised against a running instance: configure, publish
metadata, start sign-in, receive a signed assertion, create a user and issue a
session, then confirm that replaying the same assertion fails and that the
OAuth2 entry point still rejects `saml`.

It has also been driven end to end against a real Okta org in a browser,
signing in a real user and creating the account, identity and session from
Okta's own signed assertion. That is what surfaced the project-resolution and
multiple-signature problems above: both passed the synthetic tests, because the
test client always sent the `X-Appwrite-Project` header and signed at one level
only. Any future change to this flow deserves the same treatment — a real
identity provider does things a fixture does not.

The round trip, as captured from the browser network log:

| Hop | Request | Result |
|---|---|---|
| 1 | `GET /v1/account/sessions/saml/:projectId` | `301` to the Okta sign-in URL, carrying a deflated base64 `SAMLRequest` and a 64-byte `RelayState` |
| 2 | `GET {okta}/app/.../sso/saml?SAMLRequest=…` | `200`, user authenticates |
| 3 | `POST /v1/account/sessions/saml/:projectId/callback` | `301`, assertion verified and exchanged for a single-use code |
| 4 | `GET /v1/account/sessions/oauth2/saml/redirect?project=…&code=…&state=…` | `301`, shared pipeline creates the account and issues the session |
| 5 | `GET /success` | `200` |

The identity the flow produced:

```json
{
  "provider": "saml",
  "providerUid": "harshvardhan@jecjabalpur.ac.in",
  "providerEmail": "harshvardhan@jecjabalpur.ac.in",
  "providerAccessToken": "",
  "providerRefreshToken": ""
}
```

The display name on the resulting user was assembled from Okta's `firstName`
and `lastName` attribute statements, and the email came from its `email`
attribute. No password was ever set on the account. Both token fields are empty
by design, per the note in section 2.
