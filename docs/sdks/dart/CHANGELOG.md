# Change Log

## 29.4.0

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

## 29.3.1

* Fixed: added `ProjectAuthMethodId.passkey`, so `project.updateAuthMethod` can enable passkeys
* Fixed: added `ProjectPolicyId.passkey` and the `PolicyPasskey` model, so `getPolicy` and `listPolicies` return the passkey policy

## 29.3.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `users.listPasskeys`, `users.getPasskey`, `users.updatePasskey` and `users.deletePasskey`
* Added: `project.updatePasskeyPolicy` to configure passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 29.2.0

* Added: optional `interval` parameter on `functions.create` and `functions.update` to run a function every N minutes
* Added: `interval` on the `Function` model and `functionsIntervalMinimum` on the `BillingPlan` model

## 29.1.0

* Added: optional `state` parameter on `createOAuth2Token`, returned unchanged on the success and failure URLs

## 29.0.0

* Stable release of the dedicated database APIs: `mysql`, `postgresql`, `mongo`, `documentsDB`, and `vectorsDB` services, previously released as release candidates
* Breaking: `tablesDB.cutoverMigration` is renamed to `tablesDB.createCutover`
* Added: Cloudflare and Resend OAuth providers
* Added: `usageAggregateOnlyMetrics` on the `BillingPlan` model
* Fixed: `transactionId` is accepted again by `documentsDB` and `vectorsDB` `createDocument` and `createDocuments`
* Updated: `X-Appwrite-Response-Format` is now `2.0.0`
* Updated: `FrameworkAdapter.fallbackFile` is now optional

## 29.0.0-rc.1

* Breaking: `EmbeddingModel` no longer offers `embedding-gemma` or `bge-small`
* Added: `documentsDB`, `vectorsDB`, `mysql`, `postgresql`, and `mongo` services, no longer hidden from server SDKs
* Added: `DocumentsDBIndexType` and `VectorsDBIndexType` enums
* Added: dedicated database models for branches, backups, restorations, poolers, PITR windows, extensions, and executions
* Added: `PostgresExtension`, `VectorsdbCollection`, `AttributeObject`, and `AttributeVector` models
* Added: `userId`, `emailHash`, and `name` parameters to `avatars.getPhoto`
* Added: `error`, `containerStatus`, and `lifecycleState` on the `Database` model
* Added: `changelogWatermark` on the `DatabaseMigration` model
* Added: `total` on the `DedicatedDatabaseBranchList` model
* Updated: `DedicatedDatabaseOperation.status` documents the new `queued` state

## 28.0.0

* Breaking: `Execution.functionId` replaced by `resourceId` and `resourceType`, now that executions cover both functions and sites
* Added: `avatars.getPhoto` returning the user's best available profile photo
* Added: `project.updateOAuth2HuggingFace` and the `OAuth2HuggingFace` model
* Added: `huggingface` OAuth provider
* Added: `ExecutionResourceType` enum and `resourceType` attribute on the `Execution` model
* Fixed: empty JSON error responses no longer throw `FormatException`

## 27.0.0

* Breaking: removed `account.createJWT`; use `users.createJWT` instead. A leaked JWT could mint further JWTs, letting a credential outlive its own expiry — a session cannot duplicate itself to live forever either
* Breaking: removed `project.createKey`. A leaked key could mint further hidden keys, making a compromise far harder to contain and revoke
* Added: TablesDB migration methods `listMigrations`, `createMigration`, `getMigration`, `deleteMigration`, and `cutoverMigration`, plus the `DatabaseMigration` and `DatabaseMigrationList` models
* Added: `users.getMFAChallenge` and the `MfaChallengeSecret` model
* Added: `project.updateMFAFactorsPolicy` and the `PolicyMfaFactors` model
* Added: `installationScopes` parameter to `project.updateOAuth2Server`
* Added: `custom` authentication factor, `mfa-factors` project policy, and the `embeddings.write` key scope
* Updated: dedicated database status, replicas, operation, and pricing models

## 26.2.0

* Added: `Embeddings` service with `createTextEmbeddings` and the `EmbeddingModel` enum
* Added: `proxy.createInvalidation` with `ProxyInvalidation` model and `InvalidationType` enum
* Added: `tablesdb.listOperations` with `DedicatedDatabaseOperation` models
* Added: `apps.deleteInstallation` to revoke an app installation and its tokens
* Added: `Client.setOrganization()` for organization-scoped requests
* Added: `syncMode` parameter to `tablesdb.create` and `tablesdb.update`
* Added: `folder` parameter to `storage.createFile`, and `folder` and `key` to `File`
* Added: replication sync fields to `DatabaseStatus` and `DedicatedDatabaseReplicas`
* Added: `node-26` to the `Runtime` and `BuildRuntime` enums
* Added: `proxy.invalidations.write` to the `ProjectKeyScopes` enum
* Fixed: Binary download endpoints now authenticate with headers, not query parameters
* Fixed: `activities.listEvents` `queries` parameter now accepts a list of queries
* Fixed: `project.updateSessionLimitPolicy` `total` parameter is no longer nullable
* Fixed: `Organization` billing and program fields are nullable, matching the API
* Fixed: `BillingPlan`, `BillingPlanAddon`, and `UsageBillingPlan` optional fields are nullable
* Fixed: `DedicatedDatabaseMember.lagSeconds` is nullable when the lag is unknown

## 26.1.0

* Added: `Apps` service for managing OAuth2 applications, keys, and installations
* Added: `OAuth2` service with authorize, grant, device authorization, and consent flows
* Added: account OAuth2 consent methods `listConsents`, `getConsent`, `deleteConsent`, and consent token methods
* Added: app installation management methods to `Organization` and `Teams` services
* Added: `installationAccessTokenDuration` parameter to `project.updateOAuth2Server`
* Added: `token` parameter to `sites.getDeploymentDownload`
* Added: `oauth2.introspect` and organization installation key scopes

## 26.0.0

* Breaking: Removed `Health` service with its health models and enums
* Breaking: Removed `Usage` service and `UsageEvent`, `UsageGauge` models
* Breaking: Removed `Messaging` log methods `listMessageLogs`, `listProviderLogs`, `listSubscriberLogs`, `listTopicLogs`
* Breaking: Removed OS, client, and device fields plus `countryCode`/`countryName` from `ActivityEvent` (`country` remains)
* Breaking: Narrowed spatial attribute/column `xdefault` types to `List<double>`/`List<List>` in `Databases` and `TablesDB`
* Added: `Organization` service `get`, `update`, `delete`, and membership management methods
* Added: `Project.updateOAuth2Appwrite` and `updateDenyCorporateEmailPolicy` with `OAuth2Appwrite` and `PolicyDenyCorporateEmail` models
* Added: `appwrite` OAuth provider to `OAuthProvider` and `ProjectOAuthProviderId` enums
* Added: billing plan models (`BillingPlan`, `UsageBillingPlan`, `AdditionalResource`) and `BillingPlanGroup` enum
* Added: `prompt` and `maxAge` parameters to `Project.updateOAuth2Oidc` with `ProjectOAuth2OidcPrompt` enum
* Added: device authorization and default scope parameters to `Project.updateOAuth2Server`
* Added: `token` parameter to `Functions.getDeploymentDownload`; `type` to `listSpecifications`
* Added: `newSpecification` parameter to `Backups.createRestoration`; `specification` to `TablesDB.create`
* Added: geolocation fields (`city`, `isp`, `latitude`, `longitude`, `timeZone`) to `Locale` model
* Added: email classification fields (`emailCanonical`, `emailIsDisposable`, `emailIsFree`) to `User` model
* Added: `userAccessedAt` to `Membership` model and membership privacy policy
* Added: organization, dedicated database, stages, and OAuth2 key scopes; `Organization`, `Program` models; `DatabaseStatus` enum

## 25.1.0

* Added: `createSesProvider` and `updateSesProvider` to `messaging`
* Added: `updateOAuth2Server` to `project` for OAuth2 server settings
* Added: `updatePasswordStrengthPolicy` and `PolicyPasswordStrength` to `project`
* Added: `getAuditsDB` health check to `health`
* Added: `password-strength` to `ProjectPolicyId`
* Added: `apps.read` and `apps.write` to `ProjectKeyScopes`


## 25.0.0

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

## 24.0.0

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

## 23.1.0

* Added: Introduced `bigint` create/update APIs for legacy Databases attributes
* Added: Introduced `bigint` create/update APIs for `TablesDB` columns
* Updated: Extended key-list query filters with `key`, `resourceType`, `resourceId`, and `secret`

## 23.0.0

* [BREAKING] Renamed Webhook model fields: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`, `signatureKey` → `secret`
* [BREAKING] Renamed Webhook service parameters to match: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`
* [BREAKING] Renamed `Webhooks.updateSignature()` to `Webhooks.updateSecret()` with new optional `secret` parameter
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

## 22.0.0

* [BREAKING] Changed `$sequence` type from `int` to `String` for `Row` and `Document` models
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
* Removed `getQueueBillingProjectAggregation`, `getQueueBillingTeamAggregation`, `getQueuePriorityBuilds`, `getQueueRegionManager`, `getQueueThreats` from `Health` service
* Updated `Log` model field descriptions to clarify impersonation behavior
* Updated `X-Appwrite-Response-Format` header to `1.9.0`

## 21.3.0

* Added `ttl` parameter to listDocuments
* Added `ttl` parameter to listRows
* Added example for Health.getConsolePausing usage

## 21.2.0

* Added optional encrypt parameter to multiple attribute and column creation APIs (default: false) to support encryption at rest in database-related operations.
* Documentation updates across Longtext/Mediumtext/Text/Varchar attributes and corresponding database columns to include the new encrypt option.
* README updated to reflect compatibility with Appwrite server version 1.8.x.
* Add support for the new `Backups` service
* Repo cleanup: removed obsolete GitHub issue templates (Bug, Documentation, Feature) and related CI workflows (autoclose, format, publish, test) to streamline maintenance.

## 21.0.1

* Fix doc examples with proper formatting

## 21.0.0

* Add array-based enum parameters (e.g., `permissions: List<BrowserPermission>`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `getQueueAudits` support to `Health` service.
* Add longtext/mediumtext/text/varchar attribute and column helpers to `Databases` and `TablesDB` services.

## 20.1.1

* Fix boolean parameter not handled correctly in Client requests

## 20.1.0

* Added ability to create columns and indexes synchronously while creating a table

## 20.0.0

* Rename `VCSDeploymentType` enum to `VCSReferenceType`
* Change `createTemplateDeployment` method signature: replace `version` parameter with `type` (TemplateReferenceType) and `reference` parameters
* Add `Theme`, `Timezone` and `Output` enums

## 19.4.0

* Add `getScreenshot` method to `Avatars` service
* Add enums `Theme`, `Output` and `Timezone`
* Update runtime enums to add support for `dart39` and `flutter335` runtimes
* Fix passing of `null` values and stripping only non-nullable optional parameters from the request body

## 19.3.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations
* Add `createResendProvider` and `updateResendProvider` methods to `Messaging` service

## 19.2.1

* Add transaction support for Databases and TablesDB

## 19.1.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 18.1.0

* Add `orderRandom` query support

## 18.0.0

* Rename `CreditCard` enum value `unionChinaPay` to `unionPay`
* Add time between query support
* Add spatial attribute support
* Add spatial index support
* Add spatial query support

## 17.0.0

* Support for Appwrite 1.8
* Added TablesDB service
* Added new query types:
    * `notContains`
    * `notSearch`
    * `notBetween`
    * `notStartsWith`
    * `notEndsWith`
    * `createdBefore`
    * `createdAfter`
    * `updatedBefore`
    * `updatedAfter`
* Deprecated `updateMagicURLSession`
* Deprecated `updatePhoneSession`
* Deprecated Databases service
> The TablesDB service is the new recommended way to work with databases.
> Existing databases/collections/attributes/documents can be managed using the TablesDB service.
> Existing Databases service will continue to work, but new features may only be added to the TablesDB service.

## 16.2.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `encrypt` support to string attribute model
* Add `sequence` support to `Document` model

## 16.1.0

* Add `gif` support to `ImageFormat` enum
* Add support for `dart38` and `flutter332` runtimes
* Add `upsertDocument()` method to Databases service
* Fix `convertTo()` method in `Document` and `Preferences` models to correctly accept `Map<String, dynamic>`
* Fix `upsertDocuments()` method to have `documents` attribute as required parameter

## 16.0.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Add doc examples and methods for bulk api transactions: `createDocuments`, `deleteDocuments` etc.
* Add doc examples, class and methods for new `Sites` service
* Add doc examples, class and methods for new `Tokens` service
* Add enums for `BuildRuntime `, `Adapter`, `Framework`, `DeploymentDownloadType` and `VCSDeploymentType`
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Add `queries` and `search` params to `listMemberships` method
* Update enum for `runtimes` with Pythonml312, Dart219, Flutter327 and Flutter329
* Remove `search` param from `listExecutions` method

## 14.0.0

* Breaking changes:
  * Changed the typing of `AppwriteException`'s response parameter from a `dynamic` object to an optional string (`?String`).

## 13.0.0

* Fixed realtime pong response.
* Fixed issues with `chunkedUpload` method.
* Fixed type mismatch bug where `List<dynamic>` was incorrectly causing runtime type errors.
* Updated return type of `updateMfaChallenge()` from raw data to properly typed `models.Session` object.

## 12.0.0

* Support for Appwrite 1.6
* Added `key` attribute to `Runtime` response model.
* Added `buildSize` attribute to `Deployments` response model.
* Added `scheduledAt` attribute to `Executions` response model.
* Added `scopes` attribute to `Functions` response model.
* Added `specifications` attribute to `Functions` response model.
* Added new response model for `Specifications`.
* Added new response model for `Builds`.
* Added `createJWT()` : Enables creating a JWT using the `userId`.
* Added `listSpecifications()`: Enables listing available runtime specifications.
* Added `deleteExecution()` : Enables deleting executions.
* Added `updateDeploymentBuild()`: Enables cancelling a deployment.
* Added `scheduledAt` parameter to `createExecution()`: Enables creating a delayed execution
* Breaking changes:
  * Removed `otp` parameter from `deleteMFAAuthenticator`.
  * Added `scopes` parameter for create/update function.
  * Renamed `templateBranch` to `templateVersion`  in `createFunction()`.
  * Renamed `downloadDeployment()` to `getDeploymentDownload()`

You can find the new syntax for breaking changes in the [Appwrite API references](https://appwrite.io/docs/references). Select version `1.6.x`.

**Please note: This version is compatible with Appwrite 1.6 and later only. If you do not update your Appwrite SDK, old SDKs will not break your app. Appwrite APIs are backwards compatible.**

## 11.0.3

* Minor bugfixes

## 11.0.2

* Fixed MSG91 missing template ID

## 11.0.1

* Fixed parameters using enum types

## 11.0.0

* Added enum support
* Added SSR support
* Added messaging service support
* Added contains query support
* Added or query support

## 10.1.0

* Add new queue health endpoints
* Fix between queries

## 10.0.0

* Parameter `url` is now optional in the `createMembership` endpoint
* Parameter `runtime` is now optional in the `update` endpoint of the `Functions` class

## 9.0.1

* Added a new `label` function to the `Role` helper class
* Update internal variable names to prevent name collision
* Fix: content range header inconsistency in chunked uploads [#648](https://github.com/appwrite/sdk-generator/pull/648)

## 9.0.0

* Support for Appwrite 1.4.0
* New endpoints for fetching user identities
* New endpoints for listing locale codes
* New endpoint for downloading a function deployment
* Updated documentation
* Breaking changes:
  * The `createFunction` method has a new signature.
  * The `createExecution` method has a new signature.
  * The `updateFunction` method has a new signature.
  * The `createDeployment` method no longer requires an entrypoint.
  * The `updateFile` method now includes the ability to update the file name.
  * The `updateMembershipRoles` method has been renamed to `updateMembership`.

## 8.0.1

* Added documentation comments
* Added unit tests
* Upgraded dependencies

## 8.0.0

* Added relationships support
* Added support for new queries: `isNull`, `isNotNull`, `startsWith`, `notStartsWith`, `endsWith`, `between` and `select`.
* Added update attribute support
* Added team prefs support
* Changed function create/update `execute` parameter to optional
* Changed team `update` to `updateName`
* Changed `Account` service to use the `User` model instead of `Account`

## 7.3.0

* Improve helper classes
* Deprecated `InputFile` default constructor and introduced `InputFile.fromPath` and `InputFile.fromBytes` for consistency with other SDKs

## 7.2.0

* Support for GraphQL

## 7.1.0

* Role helper update

## 7.0.0

### NEW
* Support for Appwrite 1.0.0
* More verbose headers have been included in the Clients - `x-sdk-name`, `x-sdk-platform`, `x-sdk-language`, `x-sdk-version`
* Helper classes and methods for Permissions, Roles and IDs
* Helper methods to suport new queries
* All Dates and times are now returned in the ISO 8601 format
* Execution Model now has an additional `stdout` attribute
* Endpoint for creating DateTime attribute
* User imports API with support for multiple hashing algorithms
* CRUD API for functions environment variables
* `createBucket` now supports different compression algorithms

### BREAKING CHANGES

* `databaseId` is no longer part of the `Database` Service constructor. `databaseId` will be part of the respective methods of the database service.
* The `Users.create()` method signature has now been updated to include a `phone` parameter
* `color` attribute is no longer supported in the Avatars Service
* The `number` argument in phone endpoints have been renamed to `phone`
* List endpoints no longer support `limit`, `offset`, `cursor`, `cursorDirection`, `orderAttributes`, `orderTypes` as they have been moved to the `queries` array
* `read` and `write` permission have been deprecated and they are now included in the `permissions` array
* Parameter `permission` for collections and buckets are now renamed to `documentSecurity` & `fileSecurity` respectively
* Renamed methods of the Query helper
    1.  `lesser` renamed to `lessThan`
    2.  `lesserEqual` renamed to `lessThanEqual`
    3.  `greater` renamed to `greaterThan`
    4.  `greaterEqual` renamed to `greaterThanEqual`

**Full Changelog for Appwrite 1.0.0 can be found here**: https://github.com/appwrite/appwrite/blob/master/CHANGES.md

## 6.0.1
* Dependency upgrades
* Doc comments updates
* Cleanup code

## 6.0.0
* Support for Appwrite 0.15
* **BREAKING** `Database` -> `Databases`
* **BREAKING** `account.createSession()` -> `account.createEmailSession()`
* **BREAKING** `dateCreated` attribute removed from `Team`, `Execution`, `File` models
* **BREAKING** `dateCreated` and `dateUpdated` attribute removed from `Func`, `Deployment`, `Bucket` models
* **BREAKING** Realtime channels
    * collections.[COLLECTION_ID] is now databases.[DATABASE_ID].collections.[COLLECTION_ID]
    * collections.[COLLECTION_ID].documents is now databases.[DATABASE_ID].collections.[COLLECTION_ID].documents

**Full Changelog for Appwrite 0.15 can be found here**: https://github.com/appwrite/appwrite/blob/master/CHANGES.md#version-0150

## 5.0.1
* Code formatting fix

## 5.0.0
* Support for Appwrite 0.14
* **BREAKING** `account.delete()` -> `account.updateStatus()`
* **BREAKING** Execution model `stdout` renamed to `response`
* **BREAKING** Membership model `name` renamed to `userName` and `email` renamed to `userEmail`
* Added `teamName` to Membership model
* New `users.getMemberships` function

## 4.0.2
* Fix null issues with float attributes (https://github.com/appwrite/sdk-for-dart/issues/17 and https://github.com/appwrite/sdk-for-dart/issues/16)

## 4.0.1
* Fix InputFile filename param
* Fix examples

## 4.0.0
* Support for Appwrite 0.13
* **BREAKING** **Tags** have been renamed to **Deployments**
* **BREAKING** `createFile` function expects Bucket ID as the first parameter
* **BREAKING** `createDeployment` and `createFile` functions expect an instance **InputFile** rather than the instance of **MultipartFile**
* **BREAKING** `list<Entity>` endpoints now contain a `total` attribute instead of `sum`
* `onProgress()` callback function for endpoints supporting file uploads
* Support for synchronous function executions
* Bug fixes and Improvements

**Full Changelog for Appwrite 0.13 can be found here**: https://github.com/appwrite/appwrite/blob/master/CHANGES.md#version-0130

## 3.0.2
- String Attribute Type got fixed

## 3.0.1
- Export Query Builder

## 3.0.0
- Support for Appwrite 0.12
- **BREAKING** Updated database service to adapt 0.12 API
- **BREAKING** Custom ID support while creating resources
- [View all the changes](https://github.com/appwrite/appwrite/blob/master/CHANGES.md#version-0120)

## 2.0.0
- BREAKING All services and methods now return structured response objects instead of `Response` object

## 1.0.2
- Support for Appwrite 0.11

## 1.0.1
- Export, separate IO and Browser clients for Flutter (Client and Realtime as well) and Dart (Client)

## 1.0.0
- Support for Appwrite 0.10
- Refactored for better cross platform support
- Exception implements `toString()` to get proper error message for unhandled exceptions
- **Breaking** - Signature for `MultipartFile` has changed as we have dropped Dio in favor of [http](https://pub.dev/packages/http) package. [Here is the new signature for MultipartFile](https://pub.dev/documentation/http/latest/http/MultipartFile-class.html)
- **Breaking** - Signature for `Response` has changed, now it only exposes the data.

## 0.7.0
- Support for Appwrite 0.9
- Breaking - removed order type enum, now you should pass string 'ASC' or 'DESC'
- Breaking - changed param name from `env` to `runtime` in the **Functions** API
- Image Crop Gravity support in image preview service
- New endpoint in Account getSession to get session by ID
- New endpoint in the Users API to update user verification status
- Fix - issues with User-Agent when app name consisted of non-ASCII characters

## 0.6.2

- Removed default values, nothing should change in usage as default values are already allocated in server

## 0.6.1

- Fix for image preview param types

## 0.6.0

- Upgraded to Null-safety, minimum Dart SDK required 2.12.0
- Upgraded all underlying dependencies to null safe version
- BREAKING Renamed users.deleteUser to users.delete
- BREAKING Renamed parameter inviteId to membershipId on teams.updateMembershipStatus, teams.deleteMembership
- JWT Support client.setJWT('JWT_GENERATED_IN_CLIENT')
- [Update membership roles](https://appwrite.io/docs/references/cloud/server-dart/teams?sdk=dart#updateMembershipRoles)
- New awesome image preview features, supports borderRadius, borderColor, borderWidth

## 0.5.0-dev.1

- Upgraded to Null-safety, minimum Dart SDK required 2.12.0
- Upgraded all underlying dependencies to null safe version

## 0.3.1

- Minor fixes for custom exceptions

## 0.3.0

- Improved code quality
- Added a custom Appwrite exception
- Enabled access to private storage file

## 0.2.0

- Upgraded to work with Appwrite 0.7

## 0.1.0

- First release
