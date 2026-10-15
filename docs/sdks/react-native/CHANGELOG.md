# Change log

## 1.3.0

* Added: `analytics.createEvent` in a new `Analytics` service to send tracking events
* Added: `avatars.updatePhoto` and `avatars.deletePhoto` to manage the user's custom profile photo, and the `Account` model
* Added: `Webflow` to the `OAuthProvider` enum
* Added: optional `state` on `account.createOAuth2Session` and `account.createOAuth2Token`, returned unchanged on the success and failure URLs
* Added: optional `duration` on `account.createEmailPasswordSession` to set the session length
* Added: optional `current` on `account.deleteSessions` to keep the current session signed in
* Added: optional `membershipId` on `teams.createMembership`
* Added: optional `total` on `account.listSessions`, the `locale` list methods and `listTransactions`

## 1.2.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 1.1.0

* Added: `Account.createRecoveryOTP` and `Account.updateRecoveryOTP` for code-based password recovery
* Updated: SDK now targets Appwrite 2.3 (`X-Appwrite-Response-Format: 2.3.0`)
* Updated: requests always send credentials now that dev keys are no longer supported

## 1.0.0

* Stable: first stable release of the React Native SDK, out of beta
* Breaking: removed `Account.listLogs` and the `Log`, `LogList` models
* Breaking: removed `Client.setDevKey`
* Breaking: SDK now targets Appwrite 2.2 (`X-Appwrite-Response-Format: 2.2.0`)
* Added: `Account.createIdTokenSession` for native Apple and Google sign-in with `IdTokenProvider` enum
* Added: `Account.createEmailVerificationOTP` and `Account.updateEmailVerificationOTP`
* Added: `Kakao` and `Tiktok` values to `OAuthProvider`
* Added: `passwordPwned` field on `User` and `providerIdToken` field on `Identity`
* Fixed: `Storage.createFile` uploads work with Expo fetch (Expo SDK 57+) as well as React Native fetch
* Fixed: empty-string required path parameters are rejected instead of sent to the API
* Updated: all JSON endpoints send an explicit `accept: application/json` header

## 0.35.0

* Breaking: `Execution.functionId` replaced by `resourceId` and `resourceType`
* Breaking: `react-native` is now a peer dependency instead of a dependency
* Breaking: SDK now targets Appwrite 2.0 (`X-Appwrite-Response-Format: 2.0.0`)
* Added: `DocumentsDB` and `VectorsDB` services for document and vector database access
* Added: `Organization` service with `listInstallations`, `createInstallation`, `getInstallation`, `updateInstallation`, `deleteInstallation`
* Added: `Teams` installation methods `listInstallations`, `createInstallation`, `getInstallation`, `updateInstallation`, `deleteInstallation`
* Added: `Account` consent methods `listConsents`, `getConsent`, `deleteConsent`, `listConsentTokens`, `getConsentToken`, `deleteConsentToken`
* Added: `Avatars.getPhoto` returning the best available user profile photo
* Added: `folder` parameter to `Storage.createFile` and `folder`, `key` fields on `File`
* Added: `ExecutionResourceType` enum with `Functions` and `Sites`
* Added: `AppInstallation`, `Oauth2Consent`, `Oauth2ConsentToken` models and list types
* Added: `Custom` value to `AuthenticationFactor` and `custom` field on `MfaFactors`
* Added: `Cloudflare`, `Huggingface`, `Resend` values to `OAuthProvider`
* Added: `Auto` value to `ImageGravity` enum
* Updated: source formatted with Prettier and linted with ESLint
* Fixed: positional calls with an `undefined` leading argument and trailing arguments dispatch correctly

## 0.34.0

* Added: `Client.setBearer()` method for OAuth access token authentication
* Added: `Query.vectorDot`, `Query.vectorCosine`, and `Query.vectorEuclidean` vector similarity queries
* Added: `Appwrite` value to the `OAuthProvider` enum
* Added: optional geolocation fields (`city`, `timeZone`, `latitude`, `isp`, etc.) to `Models.Locale`

## 0.33.0

* Added: Realtime connections now send the configured JWT for authentication.
* Added: Forwarded `impersonateUserId` on `avatars` and `storage` file requests.

## 0.32.0

* Breaking: Renamed `Theme` enum to `BrowserTheme`.
* Breaking: Removed `Models.DefaultPresence` and dropped the `Presence` generic from `presences` methods.
* Added: Email metadata fields to `User`, plus `Membership.userAccessedAt` and `Presence.metadata`.
* Updated: Requests now send an explicit `accept` header matching each endpoint's response type.

## 0.30.1

* Fixed: Removed `Advisor` service and `Insight`, `InsightCTA`, `InsightList`, `Report`, `ReportList` models (admin-only endpoints, not intended for client SDKs)
* Added: `sizeActual` field to `File` model

## 0.30.0

* Added: Realtime `presences` channel and `RealtimePresence` types for presence subscriptions
* Added: `Advisor` and `Presences` services
* Added: `Insight`, `Presence`, and `Report` models with list variants
* Added: `fusionauth`, `keycloak`, and `kick` providers to `OAuthProvider` enum
* Added: `Client.setCookie()` method for forwarding cookies in server-side runtimes
* Updated: `X-Appwrite-Response-Format` header to `1.9.5`

## 0.29.0

* Breaking: Added `subscribe` message flow for Realtime subscription updates
* Breaking: Added `close()` support for Realtime subscriptions
* Added: Added `subscriptions` metadata to Realtime events for targeted callbacks
* Updated: Updated `X-Appwrite-Response-Format` header to `1.9.2`

## 0.28.0

* Added `x` OAuth provider to `OAuthProvider` enum
* Added `userType` field to `Log` model
* Updated `X-Appwrite-Response-Format` header to `1.9.1`
* Updated TTL description for list caching in Databases and TablesDB
* Updated dev dependencies: Rollup 3→4, Playwright 1.56→1.59, related plugin upgrades

## 0.27.1

* Fixed: Added `files` field to `package.json` to publish only built artifacts to npm

## 0.27.0

* Added: Added `getHeaders()` method to `Client` to expose current request headers
* Added: Added `package-lock.json` to track dependency lockfile in version control

## 0.26.0

* [BREAKING] Changed `$sequence` type from `number` to `string` for `Row` and `Document` models
* Added impersonation support: `setImpersonateUserId()`, `setImpersonateUserEmail()`, `setImpersonateUserPhone()` on `Client`
* Added `impersonator` and `impersonatorUserId` optional fields to `User` model
* Added custom `toString()` on response data objects using `JSONbig.stringify` to fix BigInt serialization
* Updated `Log` model field descriptions to clarify impersonation behavior for `userId`, `userEmail`, `userName`
* Updated `X-Appwrite-Response-Format` header to `1.9.0`
* Updated devDependencies: Rollup 2→3, TypeScript pinned to 5.7.3, and related plugin upgrades

## 0.24.1

* Fix very large double values (for example 1.7976931348623157e+308) from being expanded into giant integer literals.

## 0.24.0

* Added Query.contains, Query.containsAny, and Query.containsAll for enhanced filtering capabilities.

## 0.23.1

* Add `upsert` method to Realtime `Channels` helper class
* Fix `bignumber.js` bundler conflict by removing direct dependency in favor of transitive dependency from `json-bigint`

## 0.21.0

* Add `queries` parameter to `client.subscribe()` for filtering Realtime events
* Fix `Roles` enum removed from Teams service; `roles` parameter now accepts `string[]`
* Fix parameter detection in overloaded methods to check for optional params (Account, Avatars, Graphql)

## 0.20.0

* Add array-based enum parameters (e.g., `permissions: BrowserPermission[]`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `Channel` helpers for Realtime.

## 0.19.0

* Add `getScreenshot` method to `Avatars` service
* Add `Theme`, `Timezone` and `Output` enums

## 0.18.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations

## 0.17.1

* Add transaction support for Databases and TablesDB

## 0.16.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 0.11.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `sequence` support to `Document` model
* Fix autocompletion not working for `Document` model even when generic is passed

## 0.10.1

* Fix URL based methods like `getFileViewURL`, `getFilePreviewURL` etc. by adding the missing `projectId` to searchParams
* Add `gif` to ImageFormat enum

## 0.10.0

* Add generate file URL methods like`getFilePreviewURL`, `getFileViewURL` etc.
* Update (breaking) existing methods like `getFilePreview` to download the image instead of returning URLs

## 0.9.2

* Fix `devKeys` by removing credentials from requests when the key is set

## 0.9.1

* Add `setDevkey` and `upsertDocument` methods

## 0.9.0

* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Update default `quality` for `getFilePreview` from 0 to -1
* Remove `Gif` from ImageFormat enum
* Remove `search` param from `listExecutions` method

## 0.7.4

* Upgrade dependencies to resolve PlatformConstants error with Expo 53
* Update doc examples to use new multi-region endpoint
