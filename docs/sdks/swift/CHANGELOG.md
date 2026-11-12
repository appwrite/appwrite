# Change Log

## 22.4.0

* Added: `domains` service to manage domains, DNS records, email presets, prices, transfer status and zones
* Added: `waf` service with bypass, challenge, deny, rate-limit and redirect rules
* Added: `account.createEmailVerificationOTP`, `updateEmailVerificationOTP`, `createRecoveryOTP` and `updateRecoveryOTP`
* Added: `account.createIdTokenSession` and `Identity.providerIdToken`
* Added: `oauth2.introspect`
* Added: project environments with `project.createEnvironment`, `getEnvironment`, `listEnvironments`, `updateEnvironment` and `deleteEnvironment`
* Added: `organization.createEphemeralProjectKey`, `listProjectKeys`, `getProjectKey`, `updateProjectKey` and `deleteProjectKey`
* Added: Kakao, TikTok and Webflow OAuth2 providers with `project.updateOAuth2Kakao`, `updateOAuth2TikTok` and `updateOAuth2Webflow`
* Added: optional `prompt` on the Auth0, Discord, GitHub, Microsoft, Okta, Salesforce and Zoho OAuth2 settings, and native client IDs on Apple and Google
* Added: `project.updatePasswordPwnedPolicy`, `project.updatePasswordRotationPolicy` and `User.passwordPwned`
* Added: `messaging.createAppwriteProvider` and `messaging.updateAppwriteProvider`
* Added: optional `replyToEmail` and `replyToName` on email messages, `channelId` on push messages, and `qos` and `expiry` on topics
* Added: `avatars.updatePhoto` and `avatars.deletePhoto`
* Added: optional `duration` on `account.createEmailPasswordSession`, `current` on `account.deleteSessions`, `membershipId` on `teams.createMembership` and `fingerprints` on Android platforms
* Added: optional `total` on the locale, transaction, runtime, specification and framework lists
* Added: `dart-3.13` and `flutter-3.47` runtimes, the `jaspr` framework, the `trigram` index type, `auto` image gravity, the `dedicatedDatabase` proxy rule resource type, OTP email templates and new key scopes
* Added: optional fields on `BillingPlan`, `DedicatedDatabase`, `DedicatedDatabaseSpecification`, `Project` and `Topic`, and `free` and `start` in `BillingPlanGroup`

## 22.3.1

* Fixed: added `ProjectAuthMethodId.passkey`, so `project.updateAuthMethod` can enable passkeys
* Fixed: added `ProjectPolicyId.passkey` and the `PolicyPasskey` model, so `getPolicy` and `listPolicies` return the passkey policy

## 22.3.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `users.listPasskeys`, `users.getPasskey`, `users.updatePasskey` and `users.deletePasskey`
* Added: `project.updatePasskeyPolicy` to configure passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 22.2.0

* Added: optional `interval` parameter on `functions.create` and `functions.update` to run a function every N minutes
* Added: `interval` on the `Function` model and `functionsIntervalMinimum` on the `BillingPlan` model

## 22.1.0

* Added: optional `state` parameter on `createOAuth2Token`, returned unchanged on the success and failure URLs

## 22.0.0

* Stable release of the dedicated database APIs: `mysql`, `postgresql`, `mongo`, `documentsDB`, and `vectorsDB` services, previously released as release candidates
* Breaking: `tablesDB.cutoverMigration` is renamed to `tablesDB.createCutover`
* Breaking: `Execution.functionId` is replaced by `resourceId` and `resourceType`, covering function and site executions
* Added: Cloudflare, Resend, and Hugging Face OAuth providers
* Added: `usageAggregateOnlyMetrics` on the `BillingPlan` model
* Fixed: `transactionId` is accepted again by `documentsDB` and `vectorsDB` `createDocument` and `createDocuments`
* Updated: `X-Appwrite-Response-Format` is now `2.0.0`
* Updated: `FrameworkAdapter.fallbackFile` is now optional

## 22.0.0-rc.1

* Breaking: `Execution.functionId` is replaced by `resourceId` and `resourceType`, now that executions cover both functions and sites
* Breaking: `AppInstallation.authorizationDetails` is now an array instead of an object
* Breaking: removed `dedicatedDatabases.execute` from `ProjectKeyScopes`
* Breaking: `EmbeddingModel` no longer offers `embedding-gemma` or `bge-small`
* Added: `documentsDB`, `vectorsDB`, `mysql`, `postgresql`, and `mongo` services, no longer hidden from server SDKs
* Added: `DocumentsDBIndexType` and `VectorsDBIndexType` enums
* Added: dedicated database models for branches, backups, restorations, poolers, PITR windows, extensions, and executions
* Added: `PostgresExtension`, `VectorsdbCollection`, `AttributeObject`, and `AttributeVector` models
* Added: `ExecutionResourceType` enum and `resourceType` on the `Execution` model
* Added: `OAuth2HuggingFace` model and the `huggingface` OAuth provider
* Added: `userId`, `emailHash`, and `name` parameters to `avatars.getPhoto`
* Added: `error`, `containerStatus`, and `lifecycleState` on the `Database` model
* Added: `changelogWatermark` on the `DatabaseMigration` model
* Added: `total` on the `DedicatedDatabaseBranchList` model
* Updated: `DedicatedDatabaseOperation.status` documents the new `queued` state

## 21.0.0

* Breaking: removed `account.createJWT`; use `users.createJWT` instead. A leaked JWT could mint further JWTs, letting a credential outlive its own expiry — a session cannot duplicate itself to live forever either
* Breaking: removed `project.createKey`. A leaked key could mint further hidden keys, making a compromise far harder to contain and revoke
* Breaking: `activities.listEvents` `queries` now takes an array instead of a string
* Added: `embeddings` service with `createTextEmbeddings`, plus the `EmbeddingModel` enum
* Added: TablesDB migration methods `listMigrations`, `createMigration`, `getMigration`, `deleteMigration`, `cutoverMigration`, and `listOperations`
* Added: `proxy.createInvalidation` for purging cached edge responses, plus the `InvalidationType` enum
* Added: `apps.deleteInstallation`
* Added: `users.getMFAChallenge` and the `MfaChallengeSecret` model
* Added: `project.updateMFAFactorsPolicy` and the `PolicyMfaFactors` model
* Added: `client.setOrganization` for organization-scoped requests
* Added: `folder` parameter to `storage.createFile`
* Added: `syncMode` parameter to `tablesDB.create` and `tablesDB.update`, and `specification` to `tablesDB.update`
* Added: `installationScopes` parameter to `project.updateOAuth2Server`
* Added: `custom` authentication factor, `node-26` runtime, `mfa-factors` project policy, and the `embeddings.write` and `proxy.invalidations.write` key scopes
* Updated: response format to `1.9.6`

## 20.1.0

* Added: `Apps` service for managing OAuth2 applications, keys, and installations
* Added: `OAuth2` service with authorize, grant, device authorization, and consent flows
* Added: account OAuth2 consent methods `listConsents`, `getConsent`, `deleteConsent`, and consent token methods
* Added: app installation management methods to `Organization` and `Teams` services
* Added: `installationAccessTokenDuration` parameter to `project.updateOAuth2Server`
* Added: `token` parameter to `sites.getDeploymentDownload`
* Added: `oauth2.introspect` and organization installation key scopes

## 20.0.0

* Breaking: Removed `Health` service with all health models and enums
* Breaking: Removed `Usage` service and `UsageEvent`, `UsageGauge` models
* Breaking: Removed `Messaging.listMessageLogs`, `listProviderLogs`, `listSubscriberLogs`, `listTopicLogs` methods
* Breaking: Removed OS, client, and device fields plus `countryCode`/`countryName` from `ActivityEvent` (`country` remains)
* Breaking: Made `Project` OAuth2 server fields optional; geometry attribute `default` types now typed doubles
* Added: `Organization` service `get`, `update`, `delete`, and membership management methods
* Added: `Client.setBearer` for OAuth access token authentication
* Added: `Query.vectorDot`, `Query.vectorCosine`, `Query.vectorEuclidean` vector search queries
* Added: `Projects.updateOAuth2Appwrite` and `updateDenyCorporateEmailPolicy` methods
* Added: `appwrite` OAuth provider; organization, stages, and `dedicatedDatabases.execute` key scopes
* Added: `DatabaseStatus` enum and `status` field on `Database` model
* Added: billing plan models (`BillingPlan`, `BillingPlanLimits`, addons) and `Organization`, `Program` models
* Added: OIDC `prompt` and `maxAge` support with `OAuth2OidcPrompt` enum
* Added: geolocation and ISP fields (`city`, `timeZone`, `latitude`, `isp`) on `Locale` model
* Added: `userAccessedAt` on `Membership` and membership privacy policy
* Added: `onboarding` stage progress and OAuth2 device-flow fields on `Project` model
* Updated: deprecated `Databases` transaction and text-column methods in favor of `TablesDB`

## 19.1.0

* Added: `createSesProvider` and `updateSesProvider` to `messaging`
* Added: `updateOAuth2Server` to `project` for OAuth2 server settings
* Added: `updatePasswordStrengthPolicy` and `PolicyPasswordStrength` to `project`
* Added: `getAuditsDB` health check to `health`
* Added: `password-strength` to `ProjectPolicyId`
* Added: `apps.read` and `apps.write` to `ProjectKeyScopes`


## 19.0.0

* Breaking: Removed `githubImagine` and `googleImagine` from `ProjectOAuthProviderId`
* Breaking: Removed `deno-1.21`, `deno-1.24`, and `deno-1.35` from `Runtime` and `BuildRuntime`
* Breaking: Dropped numeric suffixes from `StatusCode` redirect members
* Added: `Organization` service for managing projects and API keys
* Added: `PolicyDenyAliasedEmail`, `PolicyDenyDisposableEmail`, and `PolicyDenyFreeEmail` policy models
* Added: `deny-aliased-email`, `deny-disposable-email`, and `deny-free-email` to `ProjectPolicyId`
* Replaced: `BrowserTheme`, `HealthQueueName`, `OrganizationKeyScopes`, and `Region` enums
* Added: `dart-3.12` and `flutter-3.44` runtimes
* Added: `ProjectList` model and new attributes on `Function`, `Site`, and `UsageGauge`
* Updated: `functions`, `sites`, `usage`, `health`, and `avatars` services
* Updated: Renamed `updatePresence` to `update` in the `presences` service

## 18.0.0

* Breaking: Renamed `AuthMethod` enum to `ProjectAuthMethodId`
* Breaking: Renamed `EmailTemplateType` to `ProjectEmailTemplateId` and `EmailTemplateLocale` to `ProjectEmailTemplateLocale`
* Breaking: Renamed `ServiceId` to `ProjectServiceId`, `ProtocolId` to `ProjectProtocolId`, `Secure` to `ProjectSMTPSecure`, `ProjectPolicy` to `ProjectPolicyId`
* Breaking: Replaced `Scopes` enum with `ProjectKeyScopes` for project key endpoints
* Breaking: Removed `Project.updateDenyCanonicalEmailPolicy`; replaced with `updateDenyAliasedEmailPolicy`, `updateDenyDisposableEmailPolicy`, and `updateDenyFreeEmailPolicy`
* Breaking: Removed `AuthProvider` model; use new `ProjectOAuthProviderId` enum instead
* Added: `Project.get` method to fetch current project details
* Added: `Advisor`, `Presences`, and `Usage` services
* Added: `Insight`, `Presence`, `Report`, `UsageEvent`, and `UsageGauge` models with list variants
* Added: `ProjectAuthMethod`, `ProjectProtocol`, and `ProjectService` models
* Added: `ProjectOAuthProviderId` and `ProjectOAuth2GooglePrompt` enums
* Updated: `Project`, `Database`, and `OAuth2Google` model schemas
* Updated: `X-Appwrite-Response-Format` header to `1.9.5`

## 17.1.0

* Added: Introduced `bigint` create/update APIs for legacy Databases attributes
* Added: Introduced `bigint` create/update APIs for `TablesDB` columns
* Updated: Extended key-list query filters with `key`, `resourceType`, `resourceId`, and `secret`

## 17.0.0

* [BREAKING] Renamed Webhook model fields: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`, `signatureKey` → `secret`
* [BREAKING] Renamed Webhook service parameters to match: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`
* [BREAKING] Renamed `Webhooks.updateSignature()` to `Webhooks.updateSecret()` with new optional `secret` parameter
* [BREAKING] Converter closures now throw (`(Any) throws -> T`) across all service methods
* Added `Client.getHeaders()` method to retrieve request headers
* Added `secret` parameter to Webhook create and update methods
* Added `x` OAuth provider to `OAuthProvider` enum
* Added `userType` field to `Log` model
* Added `purge` parameter to `updateCollection` and `updateTable` for cache invalidation
* Added Project service: platform CRUD, key CRUD, protocol/service status management
* Added new models: `Key`, `KeyList`, `Project`, `DevKey`, `MockNumber`, `AuthProvider`, `PlatformAndroid`, `PlatformApple`, `PlatformLinux`, `PlatformList`, `PlatformWeb`, `PlatformWindows`, `BillingLimits`, `Block`
* Added new enums: `PlatformType`, `ProtocolId`, `ServiceId`
* Updated `BuildRuntime`, `Runtime` enums with `dart-3.11` and `flutter-3.41`
* Updated `Scopes` enum with `keysRead`, `keysWrite`, `platformsRead`, `platformsWrite`
* Updated `X-Appwrite-Response-Format` header to `1.9.1`
* Updated TTL description for list caching in Databases and TablesDB

## 16.0.0

* [BREAKING] Changed `$sequence` type from `Int` to `String` for `Row` and `Document` models
* [BREAKING] Renamed `IndexType` enum: split into `DatabasesIndexType` (for Databases) and `TablesDBIndexType` (for TablesDB)
* [BREAKING] Replaced `specification` parameter with `buildSpecification` and `runtimeSpecification` in `Functions.create()`, `Functions.update()`, `Sites.create()`, `Sites.update()`
* Added new `Project` service with full CRUD for project-level environment variables
* Added new `Webhooks` service with full CRUD for project webhooks (including `updateSignature`)
* Added `Webhook` and `WebhookList` models
* Added `Users.updateImpersonator()` method for enabling/disabling user impersonation
* Added impersonation support: `setImpersonateUserId()`, `setImpersonateUserEmail()`, `setImpersonateUserPhone()` on `Client`
* Added `impersonator` and `impersonatorUserId` optional fields to `User` model
* Added `deploymentRetention` parameter to Functions and Sites create/update
* Added `startCommand` parameter to Sites create/update
* Added `Documentsdb`, `Vectorsdb` values to `BackupServices` and `DatabaseType` enums
* Added `WebhooksRead`, `WebhooksWrite`, `ProjectRead`, `ProjectWrite` scopes
* Fixed `NIOFoundationCompat` import to be conditional with `#if canImport` for platform compatibility
* Fixed `ByteBuffer` to `Data` conversion to use `readableBytesView` throughout
* Removed `getQueueBillingProjectAggregation`, `getQueueBillingTeamAggregation`, `getQueuePriorityBuilds`, `getQueueRegionManager`, `getQueueThreats` from `Health` service
* Updated `Log` model field descriptions to clarify impersonation behavior
* Updated `X-Appwrite-Response-Format` header to `1.9.0`

## 15.2.0

* Added `getConsolePausing` health endpoint
* Added `ttl` parameter to listDocuments and listRows and updated examples
* Added `activate` optional parameter to Sites.createDeployment and updated docs example
* Updated Document and Row sequence IDs documentation
* Added usage example for Health.getConsolePausing

## 15.1.0

* Added new query filtering helpers: Query.contains(_:, value:), Query.containsAny(_:, value: [Any]), and Query.containsAll(_:, value: [Any]) for string and array attributes.
* Extended Databases and TablesDB attribute creation APIs with an optional encrypt: Bool flag to support encrypted attributes (Longtext, Mediumtext, Text, Varchar) across multiple attribute types.
* Updated README and package manifest references to reflect versioning and compatibility: server compatibility noted as Appwrite server version 1.8.x; package dependency snippet updated from 15.1.0 to 15.0.0.
* Updated Client header x-sdk-version to 15.0.0 to align with the release.
* Removed deprecated/auxiliary CI templates and an autoclose workflow (internal maintenance).
* Add support for the new `Backups` service

## 15.0.0

* Add array-based enum parameters (e.g., `permissions: [BrowserPermission]`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `getQueueAudits` support to `Health` service.
* Add longtext/mediumtext/text/varchar attribute and column helpers to `Databases` and `TablesDB` services.

## 14.1.0

* Added ability to create columns and indexes synchronously while creating a table

## 14.0.0

* Rename `VCSDeploymentType` enum to `VCSReferenceType`
* Change `createTemplateDeployment` method signature: replace `version` parameter with `type` (TemplateReferenceType) and `reference` parameters
* Add `getScreenshot` method to `Avatars` service
* Add `Theme`, `Timezone` and `Output` enums

## 13.3.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations
* Add `createResendProvider` and `updateResendProvider` methods to `Messaging` service

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
* Add `sequence` support to `Document` model
* Add `dart38` and `flutter332` support to runtime models

## 10.1.0

* Adds `upsertDocument` method
* Adds warnings to bulk operation methods
* Adds the new `encrypt` attribute
* Adds runtimes: `flutter332` and `dart38`
* Fix `select` Queries by updating internal attributes like `id`, `createdAt`, `updatedAt` etc. to be optional in `Document` model.
* Fix `listCollection` errors by updating `attributes` typing
* Fix querying datetime values by properly encoding URLs

## 10.0.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Add doc examples and methods for bulk api transactions: `createDocuments`, `deleteDocuments` etc.
* Add doc examples, class and methods for new `Sites` service
* Add doc examples, class and methods for new `Tokens` service
* Add enums for `BuildRuntime `, `Adapter`, `Framework`, `DeploymentDownloadType` and `VCSDeploymentType`
* Update enum for `runtimes` with Pythonml312, Dart219, Flutter327 and Flutter329
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Add `queries` and `search` params to `listMemberships` method
* Remove `search` param from `listExecutions` method

## 9.0.0

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests

## 8.0.0

* Remove redundant titles from method descriptions.
* Add `codable` models
* Ensure response attribute in `AppwriteException` is always string

## 7.0.0

* Fix pong response & chunked upload
