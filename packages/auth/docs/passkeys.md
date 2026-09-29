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

`Origin` accepts HTTPS origins on the RP ID or one of its subdomains, without a path, query, fragment or credentials. HTTP is only accepted when the RP ID is `localhost`. `normalize()` returns `null` for anything else. Validating the RP ID itself (for example rejecting public suffixes) is left to the caller.

## Registration

```php
$options = $ceremony->createRegistration($userHandle, 'user@example.com', 'User', exclude: $existingCredentialIds);
$json = $ceremony->encode($options); // send to the browser, store server-side until verified

// Browser: navigator.credentials.create({ publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(json) })
$record = $ceremony->verifyRegistration(
    $ceremony->decodeCredential($credentialFromBrowser), // credential.toJSON()
    $ceremony->decodeRegistration($json),
);

$stored = $ceremony->encodeRecord($record);          // array, persist it
$lookup = Ceremony::getIdentifier($record->publicKeyCredentialId); // indexed lookup key
```

## Sign-in

```php
$json = $ceremony->encode($ceremony->createAuthentication()); // usernameless: no allowCredentials

// Browser: navigator.credentials.get({ publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(json) })
$credential = $ceremony->decodeCredential($credentialFromBrowser);
$record = $ceremony->verifyAuthentication(
    $credential,
    $ceremony->decodeRecord($storedFor(Ceremony::getIdentifier($credential->rawId))),
    $ceremony->decodeAuthentication($json),
);
```

Every failure throws `Webauthn\Exception\WebauthnException`. Challenges are single-use and expire after `Ceremony::TIMEOUT` seconds; storing and consuming them is up to the application.
