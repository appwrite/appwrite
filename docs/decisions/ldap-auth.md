# LDAP authentication

Why LDAP sign-in is built the way it is, and what was deliberately left out.
Read this before changing the flow: a few of the choices look arbitrary until
you know what they are defending against.

## Scope

Authentication against a directory server: a user signs in with their corporate
credentials, Appwrite verifies them by binding to the directory, and issues a
normal Appwrite session.

**Not in this change:** directory-to-Appwrite user sync, group-to-team mapping,
attribute sync after first sign-in, connection pooling, console UI, and multiple
directories per project (see section 2).

## 1. FreeDSx rather than ext-ldap

`ext-ldap` is a blocking C extension and Swoole has no hook for it, so every
bind would stall the worker's event loop for the duration of a network round
trip to the directory. Appwrite enables `SWOOLE_HOOK_ALL` (`app/cli.php`,
`app/realtime.php`), and LDAP is not in Swoole's hook list.

`freedsx/ldap` is pure PHP and its transport uses `stream_socket_client`, which
`SWOOLE_HOOK_TCP` does hook, so binds become non-blocking coroutine I/O for
free. Measured before any of this was built: ten concurrent binds completed in
25ms, against 230ms if they had serialised, and an unrelated coroutine kept
running throughout.

`symfony/ldap` and `laminas/ldap` both wrap `ext-ldap` and would block. The
library choice is the whole design here, not an incidental preference.

No new PHP extension is required, so the Dockerfile is untouched.

## 2. Configuration is stored as a list, though only one entry is used

Every comparable product supports several directories per tenant — Keycloak
("possible to federate multiple different LDAP servers in the same realm",
chosen between by a priority field), Auth0 ("if you have multiple AD/LDAP
directories... you can set up multiple", routed by email domain), WorkOS, and
Okta. None cap it at one; where limits exist they are commercial rather than
architectural. The drivers are subsidiaries with their own directories, mergers
leaving several AD forests, and separate contractor directories.

This change exposes one directory, because one is enough to prove the flow and
the console has to show something. But it is stored as a list under
`auths.ldapDirectories`, and `Settings::fromProject()` reads the first entry, so
allowing several later is a change to this action and the console rather than a
migration of stored data. Storing the fields flat on the project document is the
version of this that would need a migration and an API break.

If multiple directories are added, the rule worth copying from Keycloak is: when
a user matches a directory but the bind fails, **fail the sign-in rather than
trying the next directory**. Falling through means duplicate usernames across
directories authenticate people against the wrong store.

## 3. Auto-provision, gated by a filter, evaluated every time

A successful bind creates an Appwrite account if none exists. The directory
stays the source of truth for who exists, and nobody has to create accounts by
hand.

An optional `provisionFilter` restricts which directory entries are eligible,
typically by group membership. It is evaluated on **every** sign-in, not only
the first: removing someone from the group in the directory then revokes their
access rather than merely preventing a new account. It is the same filter
evaluated in the same place, so enforcing it properly costs nothing.

The match has to **name the entry being authenticated**: either it is that entry,
or it is an object listing the entry in `member` or `uniqueMember`. Checking only
that *something* matched would let a filter satisfied by an unrelated object
authorise anyone able to bind. Both filter shapes therefore work — one describing
the user's own entry, `(sn=Smith)`, and one describing a group they belong to,
`(&(cn=staff)(member={{username}}))` — while a filter that names neither admits
nobody.

The `{{username}}` placeholder receives that entry's DN rather than the value
typed at sign-in, since a membership attribute holds DNs.

Provisioned accounts have `password` set to null. They can only ever be
authenticated by the directory, never by a local password.

An account is matched by the **directory entry's DN**, not by email. An
`identities` record links the authenticated DN to the account, and that is what
a repeat sign-in resolves. That record carries a unique index on
`(provider, providerUid)`, which is what arbitrates two first-time sign-ins
racing for the same entry: the loser deletes the account it just created and
adopts the winner's, rather than leaving an orphaned duplicate behind.

There are two unique indexes a concurrent pair can collide on — the email on the
user, and `(provider, providerUid)` on the identity — and both resolve the same
way, through one recovery path: adopt whatever the winner created, and release
anything this request had already created so a failed sign-in never leaves an
orphan. The identity index covers only the first 128 characters of a value that
may be far longer, so a clash there is not necessarily a race at all; when no
identity for the DN can be found, the sign-in is refused rather than guessed at.

Distinguished names are compared by parsed component rather than byte for byte.
A directory treats `uid=Alice,OU=People` and `uid=alice, ou=people` as the same
entry, so an exact comparison would reject a group whose member values differ
only in case or spacing from what the search returned.

Case is folded only for naming attributes whose equality rule is
`caseIgnoreMatch` in the standard schemas and in Active Directory — `uid`, `cn`,
`ou`, `dc` and the like. A schema may define a case-exact naming attribute, and
folding one of those would let a directory-distinct entry satisfy another's
membership, so anything outside that set is compared exactly. An email address is not proof of anything on its own:
matching on it would let a directory entry sign into an existing password
account that merely shares the address. When no link exists and the address is
already taken, the sign-in is refused instead. Linking a local account to a
directory should be a deliberate act.

## 4. Sessions survive directory changes — be precise about this

Disabling a user in the directory prevents **new** sessions. It does not
invalidate existing ones: session validation checks the session secret against
the user's `sessions` array and never re-contacts the provider, and the default
duration is `TOKEN_EXPIRATION_LOGIN_LONG`, one year.

This is not specific to LDAP — OAuth2 and SAML sessions behave the same way —
but LDAP makes it conspicuous, because there is an obvious authority that could
be asked. It matters here because "the employee left, revoke their access" is
the enterprise case this feature is for.

For now this is documented rather than solved. Administrators revoke with
`DELETE /v1/users/:userId/sessions`. A shorter session duration for
LDAP-created sessions, and periodic revalidation against the directory, are both
reasonable follow-ups; the latter is a decision about Appwrite's session model
generally rather than an LDAP feature, which is why it is not in this change.

## 5. Failures are deliberately indistinguishable

A wrong password, an unknown user, and a user outside the provisioning filter
all produce the same `user_invalid_credentials` response. Telling them apart
would let anyone probe the directory for valid usernames.

Directory faults — unreachable host, bad service credentials, malformed base DN
— are separate, and do surface with actionable messages, because they are
configuration problems an administrator needs to see rather than sign-in
outcomes.

An empty password is rejected before any bind is attempted. LDAP treats a bind
with an empty password as an "unauthenticated bind" and reports success, which
would otherwise authenticate anyone.

## 6. The bind password is not carried to a new host

Configuration updates merge over what is stored, so an administrator can change
one field without re-sending the service account password. That convenience has
a sharp edge: a caller with `project.write` could change only the host and have
the enable check hand the stored credentials to a server they control.

Changing the host, port or bind DN therefore requires the password to be supplied
again, as does dropping encryption: a different port is a different destination,
and plaintext puts the credential on the wire in the clear. It is scoped to the
destination it was entered for, and to the protection it was entered under.

## 7. Filter values are escaped

The search filter is assembled from the value the user typed, which carries the
same injection risk as a SQL query built by concatenation. A username of `*`
would otherwise match every entry in the subtree. `Settings::escape()` applies
RFC 4515 escaping, and there are tests for wildcard, filter-breakout, null-byte
and backslash inputs.

A search returning more than one entry is treated as no match: binding as an
arbitrary one of several would be a coin toss over identity.

The user filter must contain the `{{username}}` placeholder, enforced in the
`Settings` constructor. Without it the filter resolves to the same entry for
every attempt, which would let anyone sign in as whoever it matched.

## 8. Known consequence: the generated SDK enum

Adding `ldap` to `app/config/auth.php` puts it in the project's auth-methods
list, and `ProjectAuthMethodId` in the vendored `appwrite/appwrite` SDK is
generated from that config. Until the SDK is regenerated, that enum does not
know the value, and `ResponseTest::testProjectResponseCanHydrateGeneratedSdkProjectWithoutOAuth2Fields`
fails.

This is left visible rather than worked around, because it is a real cross-repo
step that has to happen for the feature to ship, not a test to be silenced.

## Testing

Unit tests cover configuration validation and filter escaping — the parts that
need to be right regardless of any directory.

The flow has been exercised end to end against an OpenLDAP server: configure,
sign in, auto-provision, and confirm that a repeat sign-in reuses the account
rather than creating a second. Wrong password, unknown user, empty password and
a wildcard username are all rejected with 401. With a group-membership
provisioning filter set, a member is admitted and a non-member with otherwise
valid credentials is refused, and a filter satisfied by some other object in the
directory admits nobody.

An existing password account sharing the directory entry's email address is not
adopted: the sign-in is refused. Changing the configured host without supplying
the bind password again is rejected.

Unlike SAML, none of this needs a public URL, a tunnel, or a third-party
account: OpenLDAP runs in Docker and the whole suite works offline, which is
what makes it viable in CI.
