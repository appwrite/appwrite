# Change Log

## 29.2.0

* Added: `analytics.createEvent` in a new `Analytics` service to send tracking events
* Added: optional `state` on `account.createOAuth2Session` and `account.createOAuth2Token`, returned unchanged on the success and failure URLs
* Added: optional `membershipId` on `teams.createMembership`
* Fixed: `avatars.updatePhoto` sends `PUT`, so uploading a profile photo no longer returns 404
* Fixed: OAuth2 query parameters (`success`, `failure`, `scopes`, `state`) are URL-encoded, so values containing `&`, `+` or `#` survive the redirect

## 29.1.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 29.0.0

* Breaking: minimum Android SDK raised from 23 to 24 (Android 7.0)
* Added: `Push` service for MQTT push messages without FCM, plus `PushReceiver` for background handling
* Added: `Topic` builder for push topics
* Added: `endpointPush` and `pushClientId` on `Client`, with `setPushEndpoint` and `setPushClientId`
* Added: `Apps` service for managing OAuth2 apps, keys, secrets and installations
* Added: `Oauth2` service for using Appwrite as an OAuth2/OIDC provider
* Added: `Avatars.updatePhoto` and `Avatars.deletePhoto`
* Added: `duration` parameter to `Account.createEmailPasswordSession`
* Added: `current` parameter to `Account.deleteSessions`
* Added: `total` parameter to `Account.listSessions`, `Locale` list methods and `listTransactions`
* Added: `WEBFLOW` value to `OAuthProvider`
* Added: `toMap()` and `toJson()` on `Query` and `Operator`
* Updated: SDK manifest merges Push permissions, a foreground service and a boot receiver into apps
* Updated: narrower Netty keep rules, raising the R8 obfuscation and optimization scores Google Play reads
* Updated: added the shaded `hivemq-mqtt-client` dependency for `Push`, with consumer R8 rules
* Fixed: `Query` and `Operator` serialize correctly under R8 instead of producing `{}`
* Fixed: Realtime delivers events and error messages under R8
* Fixed: session cookies saved under R8 stay readable after an app update
* Fixed: `ID.unique()` and `InputFile.fromFile` no longer crash on Android 7.x
* Fixed: Kotlin and Java examples now compile
* Fixed: unsubscribing one of several subscriptions on a topic stops its callback

## 28.0.0

* Breaking: removed `Account.listLogs` and the `Log`, `LogList` models
* Breaking: removed `Client.setDevKey`
* Breaking: SDK now targets Appwrite 2.3 (`x-appwrite-response-format: 2.3.0`)
* Added: `Account.createIdTokenSession` for native Apple and Google sign-in with `IdTokenProvider` enum
* Added: `Account.createRecoveryOTP` and `Account.updateRecoveryOTP` for code-based password recovery
* Added: `Account.createEmailVerificationOTP` and `Account.updateEmailVerificationOTP`
* Added: `KAKAO` and `TIKTOK` values to `OAuthProvider`
* Added: `passwordPwned` field on `User` and `providerIdToken` field on `Identity`
* Fixed: empty-string required path parameters are rejected instead of sent to the API

## 27.2.0

* Added: `DocumentsDB` and `VectorsDB` services for document and vector database access
* Added: `userId`, `emailHash` and `name` parameters to `Avatars.getPhoto()`
* Added: `CLOUDFLARE` and `RESEND` values to `OAuthProvider` enum
* Added: `AUTO` value to `ImageGravity` enum
* Fixed: `Client.setEndpoint` and `setEndpointRealtime` are fluent when called from Java
* Updated: `x-appwrite-response-format` header to `2.0.0`

## 27.1.0

* Added: `Realtime.onError`, `onOpen`, and `onClose` lifecycle callbacks
* Fixed: message-handler exceptions are delivered via `onError` instead of crashing the coroutine
* Fixed: lifecycle callbacks are isolated per connection and cleared on disconnect

## 27.0.0

* Breaking: object parameters and model fields are now typed `Map<String, Any?>` instead of `Any`
* Fixed: passing `null` in `data` now clears the attribute instead of being dropped from the request
* Added: `account.listConsents`, `getConsent`, `deleteConsent` for OAuth2 consent management
* Added: `account.listConsentTokens`, `getConsentToken`, `deleteConsentToken`, plus the `Oauth2ConsentToken` model
* Added: `organization` service
* Added: `teams.listInstallations`, `getInstallation`, `createInstallation`, `updateInstallation`, `deleteInstallation`, plus the `AppInstallation` model
* Added: `avatars.getPhoto`
* Added: `ExecutionResourceType` enum
* Added: `Client.prepareRequest()` for inspecting an encoded request

## 26.0.0

* Breaking: raised `minSdk` from 21 to 23, dropping support for Android 5.0/5.1 (API 21-22)
* Added: `Client.setBearer()` for OAuth access token authentication
* Added: `Query.vectorDot()`, `Query.vectorCosine()`, `Query.vectorEuclidean()` vector similarity queries
* Added: `appwrite` value to `OAuthProvider` enum
* Added: geolocation and network fields (`city`, `timeZone`, `latitude`, `isp`, etc.) to `Locale` model
* Updated: raised `compileSdk` to 37
* Updated: upgraded dependencies (OkHttp 5.4.0, Gson 2.14.0, coroutines 1.11.0, AndroidX)

## 25.2.0

* Added: Realtime connections now send an `x-appwrite-jwt` header for authentication.
* Added: Forwarded `impersonateUserId` on `avatars` and `storage` file requests.

## 25.1.0

* Added: Email metadata fields to `User` (`emailCanonical`, `emailIsFree`, `emailIsDisposable`, `emailIsCorporate`, `emailIsCanonical`).
* Added: `Membership.userAccessedAt` field.
* Updated: Requests now send an explicit `accept` header matching each endpoint's response type.

## 25.0.0

* Breaking: `avatars.getScreenshot` `theme` parameter now uses the `BrowserTheme` enum
* Breaking: Removed generic type parameters from `presences` service methods
* Replaced: `BrowserTheme` enum
* Updated: `Presence` model is now concrete and adds a `metadata` field

## 24.1.1

* Fixed: Removed `Advisor` service and `Insight`, `InsightCTA`, `InsightList`, `Report`, `ReportList` models (admin-only endpoints, not intended for client SDKs)
* Added: `sizeActual` field to `File` model

## 24.1.0

* Added: Realtime `presences` channel and `RealtimePresence` types for presence subscriptions
* Added: `Advisor` and `Presences` services
* Added: `Insight`, `Presence`, and `Report` models with list variants
* Added: `fusionauth`, `keycloak`, and `kick` providers to `OAuthProvider` enum
* Updated: Migrated Gradle build files to Kotlin DSL (`build.gradle.kts`)
* Updated: `X-Appwrite-Response-Format` header to `1.9.5`

## 24.0.0

* Breaking: Added `unsubscribe()`, `update()`, and `close()` to Realtime subscriptions
* Added: Added `userPhone` field to `Membership` model
* Updated: Updated `X-Appwrite-Response-Format` header to `1.9.2`

## 23.1.0

* Added `x` OAuth provider to `OAuthProvider` enum
* Added `userType` field to `Log` model
* Updated `X-Appwrite-Response-Format` header to `1.9.1`
* Updated TTL description for list caching in Databases and TablesDB

## 23.0.0

* **Note:** Version 22.2.0 was accidentally released in a previous cycle. This release (23.0.0) supersedes it as the correct next major version.

### Summary of changes since 14.1.0

* [BREAKING] Changed `$sequence` type from `Long` to `String` for `Row` and `Document` models
* Added impersonation support: `setImpersonateUserId()`, `setImpersonateUserEmail()`, `setImpersonateUserPhone()` on `Client`
* Added `impersonator` and `impersonatorUserId` optional fields to `User` model
* Added `getHeaders()`, `getCookies(url)`, and `getHttpClient()` methods to `Client`
* Breaking: Channel factory methods require explicit IDs (no wildcard defaults)
* Added `ttl` parameter to `listDocuments` and `listRows` for caching
* Added `queries` parameter to Realtime subscriptions for filtering events
* Added array-based enum parameters (e.g., `permissions: List<BrowserPermission>`)
* Breaking: `Output` enum removed; use `ImageFormat` instead
* Added `Channel` helpers for Realtime
* Added `getScreenshot` method to `Avatars` service
* Added `Theme` and `Timezone` enums
* Added `total` parameter to list queries for skipping row counting
* Added `Operator` class for atomic modification of rows
* Added transaction support for Databases and TablesDB
* Deprecated `createVerification`; added `createEmailVerification` in `Account` service
* Added `incrementDocumentAttribute` and `decrementDocumentAttribute` to `Databases` service
* Added `gif` support to `ImageFormat` enum and `sequence` support to `Document` model
* Added `devKeys` support to `Client` and `upsertDocument` to `Databases` service
* Added `token` param to `getFilePreview` and `getFileView` for File tokens
* Fixed requests failing by removing `Content-Type` header from `GET` and `HEAD` requests

## 14.1.0

* Added `getHeaders()` method to `Client` for accessing current request headers
* Added `getCookies(url)` method to `Client` for retrieving cookies from the cookie store
* Added `getHttpClient()` method to `Client` for accessing the underlying OkHttpClient instance

## 14.0.0

* [BREAKING] Changed `$sequence` type from `Long` to `String` for `Row` and `Document` models
* Added impersonation support: `setImpersonateUserId()`, `setImpersonateUserEmail()`, `setImpersonateUserPhone()` on `Client`
* Added `impersonator` and `impersonatorUserId` optional fields to `User` model
* Updated `Log` model field descriptions to clarify impersonation behavior for `userId`, `userEmail`, `userName`
* Updated `X-Appwrite-Response-Format` header to `1.9.0`
* Updated API version badge to `1.9.0` and compatibility note to server version `1.9.x` in README

## 13.0.0

* Breaking: Channel factory methods require explicit IDs (no wildcard defaults)
* Added ttl parameter to listDocuments and listRows for caching
* Updated x-sdk-version header to 12.2.1 in Client
* Updated docs and examples to show TTL usage and latest compatibility note
* Updated Document and Row sequence descriptions in models

## 12.1.0

* Add `queries` parameter to Realtime subscriptions for filtering events
* Add `subscriptions` field to `RealtimeCallback` and `RealtimeResponseEvent` types
* Fix `Roles` enum removed from Teams service; `roles` parameter now accepts `List<String>`

## 12.0.0

* Add array-based enum parameters (e.g., `permissions: List<BrowserPermission>`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `Channel` helpers for Realtime.

## 11.4.0

* Add `getScreenshot` method to `Avatars` service
* Add `Theme`, `Timezone` and `Output` enums

## 11.3.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations

## 11.2.1

* Add transaction support for Databases and TablesDB

## 11.1.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 8.2.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `gif` support to `ImageFormat` enum
* Add `sequence` support to `Document` model

## 8.1.0

* Add `devKeys` support to `Client` service
* Add `upsertDocument` support to `Databases` service

## 8.0.0

* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Update default `quality` for `getFilePreview` from 0 to -1
* Remove `Gif` from ImageFormat enum
* Remove `search` param from `listExecutions` method

## 7.0.1

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests
