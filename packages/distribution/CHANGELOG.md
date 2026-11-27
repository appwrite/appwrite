# Changelog

All notable changes to this project will be documented in this file.

This project follows semantic versioning.

## [Unreleased]

### Added

- `Utopia\Distribution\Store`, the contract for app store clients: account and app lookup, the latest build number, upload with resumable checkpoints, processing, release, state, staged rollout, halt, resume, withdraw and listings. Operations an adapter does not implement throw `Exception\Invalid`.
- `Store\Apple` for the App Store Connect API and `Store\Google` for the Google Play Developer API, both over any PSR-18 client with an overridable endpoint, and for Google an overridable token URL.
- `Progress`, which an upload calls with each checkpoint it could resume from.
- Readonly values `Account`, `App`, `Issue`, `Upload`, `Release`, `State` and `Listing`; `Upload`, `Release`, `State` and `Listing` validate on construction.
- `Credential\Apple` for App Store Connect team keys and `Credential\Google` for service-account keys, with `Credential\Google::fromServiceAccount()` reading a JSON key file. Private keys must be PEM text: a `file://` path or a key without a PEM block is rejected on construction.
- `JWT`, ES256 and RS256 token signing with `ext-openssl`, rejecting key paths and non-PEM keys on construction.
- Debug output (`var_dump()`, `print_r()`) of credentials, `JWT` and `Signing\Keystore` redacts private keys and passwords.
- Typed exceptions `Unauthorized`, `NotFound`, `Conflict`, `RateLimited`, `Unavailable` and `Invalid` under `Utopia\Distribution\Exception`, carrying the store's issues and the raw response body (`getBody()`); `Unauthorized::isCredential()` tells a rejected credential from a refused operation.
- `Issue::$reference`, the store's own id for an error, such as Apple's `errors[].id`.
- `Signing\Certificate`, `Signing\Keystore` and `Signing\Profile` for certificate requests, PKCS#12 import and export, Android upload keystores and provisioning profile parsing.
- PKCS#12 archives with legacy encryption (RC2-40) are reported as such, with how to re-export them with AES-256, instead of as a wrong password.
- `Keystore::JCEKS` and `Keystore::isJceks()`. `Keystore::isJks()` recognises only JKS, which `jarsigner` reads, so a JKS file can be accepted as-is and verified by the first sign job; JCEKS must be converted to PKCS#12. `Keystore::fromPkcs12()` rejects both with a hint to convert them.
- `Profile` strips the `ApplicationIdentifierPrefix` (falling back to the team id) from the bundle identifier, and returns entitlement `<data>` values as base64 strings.
- `Store\Apple::release()` declares `Release::$encryption` as the build's `usesNonExemptEncryption` on the production track too, before attaching the build to an editable App Store version; a version already past preparation is left alone. On both tracks the build is read first and declared only when it has no answer yet, so a retry, or a build that already has an answer, is never patched.
- `Store::promote()`, which opens what a release needs for a build the store already has, without uploading it again. `Store\Google::promote()` opens an edit and returns the same external ids as an upload, so listings and the release are written into that edit and committed once; Google Play keeps every bundle a committed edit uploaded. Other adapters throw `Exception\Invalid`.
