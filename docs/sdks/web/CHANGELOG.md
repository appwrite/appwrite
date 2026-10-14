# Change Log

## 28.3.0

* Added: `analytics.createEvent` in a new `Analytics` service to send tracking events
* Added: `Tracking` helper that auto-tracks pageviews, outbound links, downloads, scroll depth and engagement time
* Added: `avatars.updatePhoto` and `avatars.deletePhoto` to manage the user's custom profile photo, and the `Account` model
* Added: `Webflow` to the `OAuthProvider` enum
* Added: optional `state` on `account.createOAuth2Session` and `account.createOAuth2Token`, returned unchanged on the success and failure URLs
* Added: optional `duration` on `account.createEmailPasswordSession` to set the session length
* Added: optional `current` on `account.deleteSessions` to keep the current session signed in
* Added: optional `membershipId` on `teams.createMembership`
* Added: optional `total` on `account.listSessions`, the `locale` list methods and `listTransactions`

## 28.2.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 28.1.0

* Added: `Apps` service to create and manage OAuth2 apps, their secrets, keys, and installations
* Added: `Oauth2` service for authorization, consent, device, PAR, and token flows
* Added: `App`, `AppSecret`, `AppKey`, `AppScope` and `Oauth2*` response models

## 28.0.0

* Breaking: removed `Account.listLogs` and the `Log`, `LogList` models
* Breaking: removed `Client.setDevKey`
* Breaking: SDK now targets Appwrite 2.3 (`X-Appwrite-Response-Format: 2.3.0`)
* Added: `Account.createIdTokenSession` for native Apple and Google sign-in with `IdTokenProvider` enum
* Added: `Account.createRecoveryOTP` and `Account.updateRecoveryOTP` for code-based password recovery
* Added: `Account.createEmailVerificationOTP` and `Account.updateEmailVerificationOTP`
* Added: `Kakao` and `Tiktok` values to `OAuthProvider`
* Added: `passwordPwned` field on `User` and `providerIdToken` field on `Identity`
* Fixed: empty-string required path parameters are rejected instead of sent to the API

## 27.0.0

* Breaking: `Execution.functionId` replaced by `resourceId` and `resourceType`
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
* Fixed: removed unused `apiHeaders` and imports from URL-returning methods
* Fixed: positional calls with an `undefined` leading argument and trailing arguments dispatch correctly

## 26.2.0

* Added: `Client.setBearer()` to authenticate requests with an OAuth access token
* Added: `appwrite` value to `OAuthProvider` enum
* Added: `Query.vectorDot`, `Query.vectorCosine`, `Query.vectorEuclidean` vector similarity query helpers
* Added: geolocation and network fields (`city`, `timeZone`, `latitude`, `isp`, ASN, connection info) to `Locale` model
* Fixed: `AppwriteException` message now falls back to response text when missing

## 26.1.0

* Added: Realtime connections now send the configured JWT for authentication.
* Added: Forwarded `impersonateUserId` on `avatars` and `storage` file requests.

## 26.0.0

* Breaking: Renamed `Theme` enum to `BrowserTheme`, changing `avatars.getScreenshot()` `theme` param type.
* Breaking: Made `presences` `list`/`get`/`upsert`/`update` non-generic and removed `Models.DefaultPresence`.
* Added: Email metadata fields to `User` (`emailCanonical`, `emailIsFree`, `emailIsDisposable`, `emailIsCorporate`, `emailIsCanonical`).
* Added: `Membership.userAccessedAt` and `Presence.metadata` fields.
* Fixed: Removed `Client.setKey()` and `Client.setForwardedUserAgent()` methods that were exposed on the client SDK by mistake.
* Fixed: `toString()` on response objects now returns valid JSON via `JSONbig.stringify`, preserving large integers.
* Updated: Requests now send an explicit `accept` header matching each endpoint's response type.

## 25.1.1

* Fixed: Removed `Advisor` service and `Insight`, `InsightCTA`, `InsightList`, `Report`, `ReportList` models (admin-only endpoints, not intended for client SDKs)
* Added: `sizeActual` field to `File` model

## 25.1.0

* Added: Realtime `presences` channel and `RealtimePresence` types for presence subscriptions
* Added: `Advisor` and `Presences` services
* Added: `Insight`, `Presence`, and `Report` models with list variants
* Added: `fusionauth`, `keycloak`, and `kick` providers to `OAuthProvider` enum
* Added: `Client.setCookie()` method for forwarding cookies in server-side runtimes
* Updated: `X-Appwrite-Response-Format` header to `1.9.5`

## 25.0.0

* Breaking: Added `unsubscribe()`, `update()`, and `close()` to Realtime subscriptions
* Added: Added `userPhone` field to `Membership` model
* Updated: Updated `X-Appwrite-Response-Format` header to `1.9.2`

## 24.2.0

* Added `x` OAuth provider to `OAuthProvider` enum
* Added `userType` field to `Log` model
* Updated `X-Appwrite-Response-Format` header to `1.9.1`
* Updated TTL description for list caching in Databases and TablesDB
* Updated dev dependencies: Rollup 3→4, related plugin upgrades

## 24.1.1

* Fixed: Added `files` field to `package.json` to publish only built artifacts to npm

## 24.1.0

* Added: Added `getHeaders()` method to `Client` to expose current request headers
* Added: Added `package-lock.json` to track dependency lockfile in version control

## 24.0.0

* [BREAKING] Changed `$sequence` type from `number` to `string` for `Row` and `Document` models
* Added impersonation support: `setImpersonateUserId()`, `setImpersonateUserEmail()`, `setImpersonateUserPhone()` on `Client`
* Added `impersonator` and `impersonatorUserId` optional fields to `User` model
* Added custom `toString()` on response data objects using `JSONbig.stringify` to fix BigInt serialization
* Updated `Log` model field descriptions to clarify impersonation behavior for `userId`, `userEmail`, `userName`
* Updated `X-Appwrite-Response-Format` header to `1.9.0`
* Updated devDependencies: Rollup 2→3, TypeScript 4.7→5.7, and related plugin upgrades

## 23.0.0

* Breaking: Made Channel.collection() require id parameter
* Breaking: Made Channel.table() require id parameter
* Breaking: Root factory methods require explicit IDs (databases, executions, tablesdb, bucket, function, team, membership)
* Added ttl option to listDocuments and listRows for caching

## 22.4.1

* Fix very large double values (for example 1.7976931348623157e+308) from being expanded into giant integer literals

## 22.4.0

* Added Query.containsAny(attribute, value[]) to filter resources where the attribute contains any of the given values.
* Added Query.containsAll(attribute, value[]) to filter resources where the attribute contains all of the given values.
* Updated Query.contains documentation to clarify behavior: string attributes are matched by substring, and for array attributes use containsAny/containsAll.

## 22.3.1

* Add `upsert` method to Realtime `Channels` helper class
* Fix `bignumber.js` bundler conflict with Next.js Turbopack by removing direct dependency in favor of transitive dependency from `json-bigint`

## 22.1.0

* Add `queries` parameter to `Realtime.subscribe()` and `client.subscribe()` for server-side query filtering
* Add slot-based subscription management with subscription ID mappings from backend
* Add `subscriptions` field to `RealtimeResponseEvent` type
* Fix `Roles` enum removed from Teams service; `roles` parameter now accepts `string[]`
* Fix parameter detection in overloaded methods to check for optional params (Account, Avatars, Graphql)
* Fix WebSocket connection handling with stale connection guards and improved close/reconnect logic
* Fix doc examples wrapped in markdown code fences

## 22.0.0

* Add array-based enum parameters (e.g., `permissions: BrowserPermission[]`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `Channel` helpers for Realtime.

## 21.5.0

* Add `getScreenshot` method to `Avatars` service
* Add `Theme`, `Timezone` and `Output` enums

## 21.4.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations

## 21.3.0

* Add new `Realtime` service with methods for subscribing to channels and receiving messages
* Fix `client.setSession` not working when using realtime
* Deprecate `client.subscribe` method in favor of `Realtime` service

> Note: Deprecated methods are still available for backwards compatibility, but might be removed in future versions.

## 21.2.1

* Add transaction support for Databases and TablesDB

## 21.1.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 18.2.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `gif` support to `ImageFormat` enum
* Fix undefined `fileParam` error in `chunkedUpload` method
* Fix autocompletion not working for `Document` model even when generic is passed

## 18.1.1

* Fix using `devKeys` resulting in an error by conditionally removing credentials

## 18.1.0

* Add `devKeys` support to `Client` service
* Add `upsertDocument` support to `Databases` service

## 18.0.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Remove `Gif` from ImageFormat enum
* Remove `search` param from `listExecutions` method
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Improve CORS error catching in `client.call` method

## 17.0.2

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests

## 17.0.1

* Remove unnecessary titles from method descriptions
* Fix duplicate adding of payload params
* Remove unnecessary awaits and asyncs
* Ensure `AppwriteException` response is always string

## 17.0.0

* Fix pong response & chunked upload
* Add `ping` support to `Realtime` service

