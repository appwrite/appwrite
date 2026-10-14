# Change Log

## 0.9.0

* Added: `analytics.createEvent` in a new `Analytics` service to send tracking events
* Added: `avatars.updatePhoto` and `avatars.deletePhoto` to manage the user's custom profile photo, and the `Account` model
* Added: `Webflow` to the `OAuthProvider` enum
* Added: optional `state` on `account.createOAuth2Session` and `account.createOAuth2Token`, returned unchanged on the success and failure URLs
* Added: optional `duration` on `account.createEmailPasswordSession` to set the session length
* Added: optional `current` on `account.deleteSessions` to keep the current session signed in
* Added: optional `membershipId` on `teams.createMembership`
* Added: optional `total` on `account.listSessions`, the `locale` list methods and `listTransactions`
* Fixed: OAuth2 query parameters (`success`, `failure`, `scopes`, `state`) are URL-encoded, so values containing `&`, `+` or `#` survive the redirect

## 0.8.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 0.7.0

* Breaking: removed `Account.ListLogs` and the `Log`, `LogList` models
* Breaking: removed `Client.SetDevKey`, `Client.FromDevKey` and the `AppwriteConfig` dev key field
* Breaking: SDK now targets Appwrite 2.3 (`X-Appwrite-Response-Format: 2.3.0`)
* Added: `Account.CreateIdTokenSession` for native Apple and Google sign-in with `IdTokenProvider` enum
* Added: `Account.CreateRecoveryOTP` and `Account.UpdateRecoveryOTP` for code-based password recovery
* Added: `Account.CreateEmailVerificationOTP` and `Account.UpdateEmailVerificationOTP`
* Added: `Kakao` and `Tiktok` values to `OAuthProvider`
* Added: `PasswordPwned` field on `User` and `ProviderIdToken` field on `Identity`
* Fixed: empty-string required path parameters are rejected instead of sent to the API

## 0.6.0

* Breaking: `Execution.FunctionId` replaced by `ResourceId` and `ResourceType` (`ExecutionResourceType`)
* Breaking: `Storage.CreateFile` gains `folder` parameter before `onProgress`; positional callers must update
* Breaking: `AppwriteService` flag values shifted by new `DocumentsDB`, `Organization`, `VectorsDB` bits; re-save custom `AppwriteConfig` assets
* Breaking: SDK now targets Appwrite 2.0 (`X-Appwrite-Response-Format: 2.0.0`)
* Added: `DocumentsDB` and `VectorsDB` services for document and vector database access
* Added: `Organization` service with `ListInstallations`, `CreateInstallation`, `GetInstallation`, `UpdateInstallation`, `DeleteInstallation`
* Added: `Teams` installation methods `ListInstallations`, `CreateInstallation`, `GetInstallation`, `UpdateInstallation`, `DeleteInstallation`
* Added: `Account` consent methods `ListConsents`, `GetConsent`, `DeleteConsent`, `ListConsentTokens`, `GetConsentToken`, `DeleteConsentToken`
* Added: `Avatars.GetPhoto` returning the best available user profile photo
* Added: `Folder` parameter to `Storage.CreateFile` and `Folder`, `Key` fields on `File`
* Added: `AppInstallation`, `Oauth2Consent`, `Oauth2ConsentToken` models and their list models
* Added: `Custom` value to `AuthenticationFactor` enum and `MfaFactors.Custom` field
* Added: `Cloudflare`, `Huggingface`, `Resend` values to `OAuthProvider` enum
* Added: `Auto` value to `ImageGravity` enum
* Updated: `Presence.Metadata` and `User.HashOptions` expose raw objects instead of strings

## 0.5.0

* Added: `Client` header setters `SetProject`, `SetBearer`, `SetLocale`, `SetSession`, `SetDevKey`, `SetCookie`
* Added: `Client` impersonation setters `SetImpersonateUserId`, `SetImpersonateUserEmail`, `SetImpersonateUserPhone`
* Added: `Query.VectorDot`, `Query.VectorCosine`, `Query.VectorEuclidean` vector search queries
* Added: `Appwrite` value to `OAuthProvider` enum
* Added: geolocation and connection fields (`City`, `TimeZone`, `Latitude`, `Isp`, etc.) to `Locale` model

## 0.4.0

* Added: Realtime connections now send an `x-appwrite-jwt` header for authentication.

## 0.3.0

* Added: `userAccessedAt` field to the `Membership` model.
* Added: Email metadata fields to `User` (`emailCanonical`, `emailIsFree`, `emailIsDisposable`, `emailIsCorporate`, `emailIsCanonical`).
* Updated: Requests now send an explicit `accept` header matching each endpoint's response type.

## 0.2.1

* Fixed: `AndroidManifest.xml` now imports as an Android plugin instead of a generic asset
* Added: `author` field to `package.json`
* Updated: README pins the UPM install URL to the release tag (`#0.2.1`)

## 0.2.0

* Breaking: Install URL changed — drop `?path=Assets`, the package now lives at the repo root
* Updated: Restructured SDK as a proper UPM package (`Runtime`, `Editor`, `Samples~`)
* Added: `.meta` files for all scripts, folders, and assets for stable Unity GUIDs
* Added: `Oauth2Grant` model

## 0.1.0

* Added: Initial beta release of the Appwrite Unity SDK
* Added: Support for Appwrite server version `1.9.x`
* Added: `Account`, `Databases`, `TablesDB`, `Storage`, `Functions`, `Teams`, `Messaging`, `Locale`, `Avatars`, and `Graphql` services
* Added: Realtime subscriptions via `Channel`
* Added: Unity Package Manager installation with editor setup assistant
