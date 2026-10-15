# Change Log

## 20.2.0

* Added: `analytics.createEvent` in a new `Analytics` service to send tracking events
* Added: `avatars.updatePhoto` and `avatars.deletePhoto` to manage the user's custom profile photo, and the `Account` model
* Added: `Webflow` to the `OAuthProvider` enum
* Added: optional `state` on `account.createOAuth2Session` and `account.createOAuth2Token`, returned unchanged on the success and failure URLs
* Added: optional `duration` on `account.createEmailPasswordSession` to set the session length
* Added: optional `current` on `account.deleteSessions` to keep the current session signed in
* Added: optional `membershipId` on `teams.createMembership`
* Added: optional `total` on `account.listSessions`, the `locale` list methods and `listTransactions`
* Fixed: OAuth2 query parameters (`success`, `failure`, `scopes`, `state`) are URL-encoded, so values containing `&`, `+` or `#` survive the redirect

## 20.1.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 20.0.0

* Breaking: removed `Account.listLogs` and the `Log`, `LogList` models
* Breaking: removed `Client.setDevKey`
* Breaking: SDK now targets Appwrite 2.3 (`x-appwrite-response-format: 2.3.0`)
* Added: `Account.createIdTokenSession` for native Apple and Google sign-in with `IdTokenProvider` enum
* Added: `Account.createRecoveryOTP` and `Account.updateRecoveryOTP` for code-based password recovery
* Added: `Account.createEmailVerificationOTP` and `Account.updateEmailVerificationOTP`
* Added: `kakao` and `tiktok` values to `OAuthProvider`
* Added: `passwordPwned` field on `User` and `providerIdToken` field on `Identity`
* Fixed: empty-string required path parameters are rejected instead of sent to the API

## 19.0.0

* Breaking: `Execution.functionId` replaced by `resourceId` and `resourceType` (`ExecutionResourceType`)
* Breaking: `createOperations` `operations` parameter now typed `[AnyCodable]` instead of `[Any]`
* Breaking: SDK now targets Appwrite 2.0 (`x-appwrite-response-format: 2.0.0`)
* Added: `DocumentsDB` and `VectorsDB` services for document and vector database access
* Added: `Organization` service with `listInstallations`, `createInstallation`, `getInstallation`, `updateInstallation`, `deleteInstallation`
* Added: `Teams` installation methods `listInstallations`, `createInstallation`, `getInstallation`, `updateInstallation`, `deleteInstallation`
* Added: `Account` consent methods `listConsents`, `getConsent`, `deleteConsent`, `listConsentTokens`, `getConsentToken`, `deleteConsentToken`
* Added: `Avatars.getPhoto` returning the best available user profile photo
* Added: `folder` parameter to `Storage.createFile` and `folder`, `key` fields on `File`
* Added: `AppInstallation`, `Oauth2Consent`, `Oauth2ConsentToken` models and list variants
* Added: `ExecutionResourceType` enum, `AuthenticationFactor.custom` and `MfaFactors.custom`
* Added: `cloudflare`, `huggingface`, `resend` cases to `OAuthProvider`
* Added: `auto` case to `ImageGravity` enum
* Updated: binary endpoints send `X-Appwrite-Project` header instead of `project` query parameter
* Updated: `async-http-client` to 1.36.1 and `swift-nio` to 2.102.0
* Fixed: README install snippet now uses product name `Appwrite`
* Fixed: object array parameters such as `createOperations` are sent as JSON arrays, not strings

## 18.3.0

* Added: `Client.setBearer` method for OAuth bearer token authentication
* Added: `Query.vectorDot`, `Query.vectorCosine`, `Query.vectorEuclidean` vector similarity query methods
* Added: `appwrite` case to `OAuthProvider` enum
* Added: optional `Locale` fields: `city`, `timeZone`, `postalCode`, `latitude`, `longitude`, ASN, ISP, connection info
* Updated: minimum toolchain to Swift 6.1; bumped `async-http-client` and `swift-nio` dependencies

## 18.2.0

* Added: Realtime connections now send an `x-appwrite-jwt` header for authentication.
* Added: Forwarded `impersonateUserId` on `avatars` and `storage` file requests.

## 18.1.0

* Added: Email metadata fields to `User` (`emailCanonical`, `emailIsFree`, `emailIsDisposable`, `emailIsCorporate`, `emailIsCanonical`).
* Added: `Membership.userAccessedAt` field.
* Updated: Requests now send an explicit `accept` header matching each endpoint's response type.

## 18.0.0

* Breaking: `avatars.getScreenshot` `theme` parameter now uses the `BrowserTheme` enum
* Breaking: Removed generic type parameters from `presences` service methods
* Replaced: `BrowserTheme` enum
* Updated: `Presence` model is now concrete and adds a `metadata` field

## 17.1.1

* Fixed: Removed `Advisor` service and `Insight`, `InsightCTA`, `InsightList`, `Report`, `ReportList` models (admin-only endpoints, not intended for client SDKs)
* Added: `sizeActual` field to `File` model

## 17.1.0

* Added: Realtime `presences` channel and `RealtimePresence` types for presence subscriptions
* Added: `Advisor` and `Presences` services
* Added: `Insight`, `Presence`, and `Report` models with list variants
* Added: `fusionauth`, `keycloak`, and `kick` providers to `OAuthProvider` enum
* Updated: `X-Appwrite-Response-Format` header to `1.9.5`

## 17.0.0

* Breaking: Added `unsubscribe()`, `update()`, and `close()` to Realtime subscriptions
* Added: Added `userPhone` field to `Membership` model
* Updated: Updated `X-Appwrite-Response-Format` header to `1.9.2`

## 16.1.0

* Added `x` OAuth provider to `OAuthProvider` enum
* Added `userType` field to `Log` model
* Added `getHeaders()` method to `Client`
* Updated `X-Appwrite-Response-Format` header to `1.9.1`
* Updated TTL description for list caching in Databases and TablesDB

## 16.0.0

* [BREAKING] Changed `$sequence` type from `Int` to `String` for `Row` and `Document` models
* Added impersonation support: `setImpersonateUserId()`, `setImpersonateUserEmail()`, `setImpersonateUserPhone()` on `Client`
* Added `impersonator` and `impersonatorUserId` optional fields to `User` model
* Updated `Log` model field descriptions to clarify impersonation behavior for `userId`, `userEmail`, `userName`
* Fixed `NIOFoundationCompat` import to be conditional with `#if canImport` for platform compatibility
* Fixed `ByteBuffer` to `Data` conversion to use `readableBytesView` throughout (Client and WebSocket handler)
* Fixed `ByteBuffer(data:)` calls replaced with `ByteBuffer(bytes:)` for file/data loading
* Updated `X-Appwrite-Response-Format` header to `1.9.0`
* Updated API version badge to `1.9.0` and compatibility note to server version `1.9.x` in README

## 15.0.0

* Breaking: RealtimeChannel API required explicit IDs and threw errors.
* Added ttl parameter to listDocuments and listRows.
* Updated compatibility notes to latest server and SDK version.
* Updated Document and Row comments for clarity.

## 14.1.0

* Add `queries` parameter to Realtime subscriptions for filtering events
* Fix `Roles` enum removed from Teams service; `roles` parameter now accepts `[String]`
* Fix doc examples with proper formatting and complete client configuration

## 14.0.0

* Add array-based enum parameters (e.g., `permissions: [BrowserPermission]`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `Channel` helpers for Realtime.

## 13.5.0

* Add `getScreenshot` method to `Avatars` service
* Add `Theme`, `Timezone` and `Output` enums

## 13.4.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations

## 13.3.1

* Fix `onOpen` callback not being called when the websocket connection is established
* Fix add missing `scheduled` value to `ExecutionStatus` enum

## 13.3.0

* Add `onOpen`, `onClose` and `onError` callbacks to `Realtime` service

## 13.2.2

* Fix issue: Missing AppwriteEnums dependency causing build failure

## 13.2.1

* Add transaction support for Databases and TablesDB

## 13.1.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 10.2.0

* Update sdk to use swift-native doc comments instead of jsdoc styled comments as per [Swift Documentation Comments](https://github.com/swiftlang/swift/blob/main/docs/DocumentationComments.md)
* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `gif` support to `ImageFormat` enum
* Remove `Content-Type`, `Content-Length` headers and body from websocket requests

## 10.1.1

* Adds warnings to bulk operation methods
* Fix select Queries by updating internal attributes like id, createdAt, updatedAt etc. to be optional in Document model.
* Fix querying datetime values by properly encoding URLs

## 10.1.0

* Add `devKeys` support to `Client` service
* Add `upsertDocument` support to `Databases` service

## 10.0.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Remove `search` param from `listExecutions` method
* Remove `Gif` from ImageFormat enum

## 9.0.1

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests

## 9.0.0

* Remove redundant titles from method descriptions.
* Add `codable` models
* Ensure response attribute in `AppwriteException` is always string
