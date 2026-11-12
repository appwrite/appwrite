# Passkeys

`Utopia\Auth\Passkeys` runs WebAuthn registration and sign-in ceremonies for one relying party. It wraps [`web-auth/webauthn-lib`](https://github.com/web-auth/webauthn-framework), which you install yourself:

```bash
composer require web-auth/webauthn-lib
```

Defaults follow the passkey profile: discoverable credentials, user verification required, attestation `none`, ES256 and RS256. Cross-origin (iframe) ceremonies are rejected. Signature counters are only relaxed for backup-eligible (synced) credentials, and backup eligibility may not change after registration.

## Relying party

```php
use Utopia\Auth\Passkeys\Ceremony;
use Utopia\Auth\Passkeys\Origin;
use Utopia\Auth\Passkeys\RelyingParty;

$origin = (new Origin('example.com'))->normalize('https://app.example.com:443'); // https://app.example.com

$ceremony = new Ceremony(new RelyingParty('example.com', 'Example', [$origin]));
```

`Origin` accepts HTTPS origins on the RP ID or one of its subdomains, without a path, query, fragment or credentials. HTTP is only accepted when the RP ID is `localhost`. `normalize()` returns `null` for anything else. Validating the RP ID itself is left to the caller: `Utopia\Domains\Validator\RegistrableDomain` rejects public suffixes and IP addresses, and `localhost` can be allowed alongside it.

## Registration

```php
$challenge = $ceremony->register('user@example.com', 'User', records: $existingRecords);
// Send $challenge->options to the browser; persist $challenge->state until the credential comes back.

// Browser: navigator.credentials.create({ publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(options) })
$passkey = $ceremony->verifyRegistration($state, $credentialFromBrowser); // credential.toJSON()

// Persist $passkey->record, and index $passkey->identifier to find it at sign-in.
```

Passing the user's existing records excludes authenticators that already hold one of them and keeps a single user handle per user.

## Sign-in

```php
$challenge = $ceremony->authenticate(); // usernameless: the user picks any passkey for this relying party

// Browser: navigator.credentials.get({ publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(options) })
$record = $recordFor($ceremony->identify($credentialFromBrowser));
$passkey = $ceremony->verifyAuthentication($state, $credentialFromBrowser, $record);

// Store $passkey->record back: it carries the updated signature counter and backup state.
```

Every failure throws `Utopia\Auth\Passkeys\Exception`. The state is opaque and must be used once: expiring it after `Ceremony::TIMEOUT` seconds and consuming it atomically is up to the application.
