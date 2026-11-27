# Utopia Distribution

> [!IMPORTANT]
> This repository is a read-only mirror of [`packages/distribution`](https://github.com/appwrite/appwrite/tree/main/packages/distribution) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

Store clients for the App Store Connect API and the Google Play Developer API, the credentials they sign in with, and the code-signing primitives an iOS or Android release needs: certificates, keystores and provisioning profiles. PHP 8.5+, `ext-openssl`, `ext-dom`, `ext-json` and `ext-hash`. No vendor SDKs.

## Install

```bash
composer require utopia-php/distribution
```

The adapters take any PSR-18 `Psr\Http\Client\ClientInterface`. [`utopia-php/client`](https://github.com/utopia-php/client) is a good fit:

```php
<?php

use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Store\Apple;
use Utopia\Distribution\Store\Google;

$client = new Client(new CurlAdapter());

$apple = new Apple(new Credential\Apple($keyId, $issuerId, $privateKey), $client);
$google = new Google(Credential\Google::fromServiceAccount($serviceAccount), $client);

$app = $apple->app('io.example.app');
$next = $google->latestVersionCode('io.example.app') + 1;
```

## Stores

`Utopia\Distribution\Store` is the contract every adapter implements. One instance holds one credential and talks to one store.

| Method | Does |
|---|---|
| `getName(): string` | the store's name (`apple`, `google`) |
| `account(): Account` | proves the credential works and returns who it belongs to |
| `app(string $identifier): App` | finds the store's record for a bundle id or package name; `NotFound` when there is none |
| `latestVersionCode(string $identifier): int` | the highest build number or version code the store holds, `0` when there is none |
| `upload(Upload $upload, ?array $checkpoint, ?Progress $progress = null): array` | sends a build, resuming from `$checkpoint`, and returns the store's ids for it |
| `processed(array $external): ?array` | the updated ids once the store has processed the upload, `null` while it is still processing |
| `release(Release $release, array $external): array` | releases an uploaded build to a track |
| `state(array $external, string $track): State` | the review and release state of a build on a track |
| `rollout(array $external, string $track, float $fraction): void` | changes the fraction of users a staged release reaches |
| `halt(array $external, string $track): void` / `resume(...)` | pauses or resumes a staged release |
| `withdraw(array $external): void` | takes a build out of review |
| `listing(Listing $listing, array $external): array` | writes store listing text and screenshots |

`$external` is the array of store-side ids an earlier call returned; persist it and pass it back. Operations an adapter does not implement throw `Exception\Invalid` with the message `<operation> is not supported by <store>`.

Each adapter takes the base endpoint as a constructor argument, defaulting to the vendor's; `Store\Google` also takes the token URL. Point them at a fake server in tests; production code keeps the defaults.

### `Store\Apple`

App Store Connect API. Signs in with a team API key: every request carries a 20-minute ES256 token that the adapter mints, reuses until shortly before it expires, and re-mints once on a `401`. Each token's `iat` is backdated by `Apple::TOKEN_SKEW` seconds so a server clock slightly ahead of App Store Connect's does not get it rejected as issued in the future; `exp` stays `TOKEN_LIFETIME` after `iat`. It reads the `X-Rate-Limit` header and refuses to send once the hourly allowance is spent, until an hour after it reached zero. App Store Connect error codes become the exceptions below, each nested error becoming one `Issue`.

### `Store\Google`

Google Play Developer API (Android Publisher v3). Signs in with a service-account key, exchanging an RS256 assertion for an access token that is cached until shortly before it expires. The token URL can be overridden through the constructor for tests, but the assertion's audience is always Google's own token endpoint, and the service account file's `token_uri` is ignored. Work happens inside edits, which the adapter opens and deletes itself. A failed delete never hides the real outcome: `app()` returns once opening the edit proved access, and when a request inside an edit fails, that failure is thrown even if deleting the edit fails too. Play expires abandoned edits on its own. A delete that fails after every request succeeded is thrown.

### Resuming uploads

`upload()` accepts the checkpoint it last reported and continues from there, so a retry after a crash does not start over. Pass a `Utopia\Distribution\Progress` to hear about each completed part:

```php
<?php

use Utopia\Distribution\Progress;

final class Checkpoint implements Progress
{
    public function save(array $checkpoint): void
    {
        // persist $checkpoint; pass it back to upload() on the next attempt
    }
}
```

The adapter calls `save()` after every part with the checkpoint it would resume from.

## Values

All are final readonly classes. `Upload`, `Release`, `State` and `Listing` validate in their constructors, throwing `Exception\Invalid`.

| Class | Holds |
|---|---|
| `Account` | `id` (Apple issuer id, Google service-account email), optional `team` |
| `App` | `id` (Apple app id, Google package name), `identifier`, optional `name` |
| `Upload` | the `Utopia\Storage\Device` and `path` the build is read from, the file `name` sent to the store, `size`, `identifier`, `version`, `build` and `platform` (`PLATFORM_IOS`, `PLATFORM_ANDROID`). It references the file, not its bytes |
| `Release` | `identifier`, `track` (`TRACK_*` or a custom Google track), `version`, `build`, `fraction` in (0, 1], release `notes` per locale, `automatic`, TestFlight `groups`, and the `encryption` declaration |
| `State` | normalised `status` (`STATUS_PROCESSING`, `PENDING`, `REVIEWING`, `APPROVED`, `PUBLISHED`, `REJECTED`, `HALTED`), the store's `raw` state, `fraction` and `issues` |
| `Listing` | `identifier`, `locale`, text `fields` keyed by `FIELD_*`, and `screenshots` read from a `device` |
| `Issue` | the store's own error `code`, `message`, the `field` it concerns, whether it needs a `manual` action in the store's console, and the store's `reference` for the error when it gives one (Apple's `errors[].id`) |

## Credentials

`Credential\Apple(keyId, issuerId, privateKey)` holds an App Store Connect team key: the `.p8` text and its ids. Individual keys have no issuer id and are rejected.

`Credential\Google(email, privateKey, project)` holds a service-account key. `Credential\Google::fromServiceAccount(array $json)` builds one from the decoded JSON key file, reading only `client_email`, `private_key` and `project_id`.

`Utopia\Distribution\JWT` signs the tokens both adapters use: `new JWT($key, JWT::ES256, $keyId)->encode($claims)` (or `JWT::RS256`). ES256 signatures are emitted as the raw 64-byte `r‖s` JWTs require, not OpenSSL's DER.

Private keys are PEM text. Both credentials and `JWT` reject a key that is a `file://` path or holds no `-----BEGIN` block with `Exception\Invalid` when they are constructed, so a bad credential fails on creation rather than on the first request, and nothing is ever read from disk. Debug output (`var_dump()`, `print_r()`) of a credential, a `JWT` or a `Signing\Keystore` shows `[redacted]` in place of the private key or password; `var_export()` and `serialize()` do not go through that redaction, so do not log them.

## Exceptions

Every failure is a subclass of `Utopia\Distribution\Exception`, which carries the store's `Issue`s (`getIssues()`) and, when the failure came from a store response, its raw body (`getBody(): ?string`) for logging what the store actually said:

| Exception | Means | Retry? |
|---|---|---|
| `Exception\Unauthorized` | the store refused the credential or the operation | no |
| `Exception\NotFound` | the app, build or release does not exist | no |
| `Exception\Conflict` | the store's state does not allow the operation now | later |
| `Exception\RateLimited` | the store's rate limit is spent | with backoff |
| `Exception\Unavailable` | the store or the network failed; transport errors arrive as this, with the original as `getPrevious()` | with backoff |
| `Exception\Invalid` | the request is wrong, or the operation is not supported by the store | no |

`Unauthorized::isCredential()` is `true` when the credential itself was rejected (revoked key, bad signature), so it should be marked unusable, and `false` when only this operation was refused (a role too low, an agreement to accept).

## Signing

`Utopia\Distribution\Signing` creates and reads the files that sign iOS and Android builds, with `ext-openssl` only. Every certificate request passes the package's own `data/openssl.cnf` (`Certificate::CONFIG`), so no default subject fields are injected.

| Class | Does |
|---|---|
| `Signing\Certificate` | wraps a PEM certificate. `Certificate::key()` generates an RSA-2048 key, `Certificate::request($key, $subject)` a CSR for it, `Certificate::fromPkcs12($archive, $password)` reads a `.p12`, and `export($key, $password, $name)` writes one. Reads `fingerprint()`, `expiresAt()`, `subject()`, `team()` (the Apple team id in the OU) and `der()` |
| `Signing\Keystore` | an Android upload keystore. `Keystore::generate($alias, $subject)` creates a PKCS#12 keystore with a self-signed certificate valid for 30 years, one password for store and key, and the alias as its friendly name. `Keystore::fromPkcs12()` reads one; `Keystore::isJks()` and `Keystore::isJceks()` recognise a JKS or JCEKS file by its magic (`Keystore::JKS`, `Keystore::JCEKS`), which PHP cannot read, and `fromPkcs12()` rejects both with a hint to convert them with `keytool -importkeystore -deststoretype PKCS12`. A caller may accept a JKS file as-is, since `jarsigner` reads it and the first sign job verifies it; a JCEKS file must be converted to PKCS#12 |
| `Signing\Profile` | `Profile::parse($envelope)` reads a `.mobileprovision`: its name, UUID, team, bundle identifier, type (`TYPE_APP_STORE`, `TYPE_AD_HOC`, `TYPE_DEVELOPMENT`, `TYPE_ENTERPRISE`), expiry, entitlements and the fingerprints of the certificates it allows. `contains($certificate)` tells whether a certificate may sign with it |

PKCS#12 archives exported with legacy encryption (RC2-40, as `openssl pkcs12 -export -legacy` and older tools write them) cannot be read by OpenSSL 3 without its legacy provider. `fromPkcs12()` reports these as legacy encryption, not as a wrong password, and asks for the archive to be re-exported with AES-256, for example `openssl pkcs12 -export -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg sha256`.

A profile's bundle identifier is its `application-identifier` entitlement without the App ID prefix: the first `ApplicationIdentifierPrefix`, or the team id when the profile has none. `<data>` values in the entitlements are returned as base64 strings, so the entitlements can be stored as JSON.

Malformed input and mismatched keys throw `Exception\Invalid`. `Profile::parse()` throws `Exception\Unavailable` when its temporary files cannot be created or read.

## Tests

```bash
composer test
```

The unit tier needs no network and no store account: adapters are exercised against hand-written responses in the shape the stores document, replayed through a fake PSR-18 client.
