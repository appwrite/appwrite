# Change Log

## 8.3.1

* Fixed: added `ProjectAuthMethodId.Passkey`, so `project.updateAuthMethod` can enable passkeys
* Fixed: added `ProjectPolicyId.Passkey` and the `PolicyPasskey` model, so `getPolicy` and `listPolicies` return the passkey policy

## 8.3.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `users.listPasskeys`, `users.getPasskey`, `users.updatePasskey` and `users.deletePasskey`
* Added: `project.updatePasskeyPolicy` to configure passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 8.2.0

* Added: optional `interval` parameter on `functions.create` and `functions.update` to run a function every N minutes
* Added: `interval` on the `Function` model and `functionsIntervalMinimum` on the `BillingPlan` model

## 8.1.0

* Added: optional `state` parameter on `createOAuth2Token`, returned unchanged on the success and failure URLs

## 8.0.0

* Stable release of the dedicated database APIs: `mysql`, `postgresql`, `mongo`, `documentsDB`, and `vectorsDB` services, previously released as release candidates
* Breaking: `tablesDB.cutoverMigration` is renamed to `tablesDB.createCutover`
* Breaking: `Execution.functionId` is replaced by `resourceId` and `resourceType`, covering function and site executions
* Added: Cloudflare, Resend, and Hugging Face OAuth providers
* Added: `usageAggregateOnlyMetrics` on the `BillingPlan` model
* Fixed: `transactionId` is accepted again by `documentsDB` and `vectorsDB` `createDocument` and `createDocuments`
* Updated: `X-Appwrite-Response-Format` is now `2.0.0`
* Updated: `FrameworkAdapter.fallbackFile` is now optional

## 8.0.0-rc.1

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

## 7.0.0

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

## 6.1.0

* Added: `Apps` service for managing OAuth2 applications, keys, and installations
* Added: `OAuth2` service with authorize, grant, device authorization, and consent flows
* Added: `Account` OAuth2 consent methods `ListConsents`, `GetConsent`, `DeleteConsent`, and consent token methods
* Added: app installation management methods to `Organization` and `Teams` services
* Added: `installationAccessTokenDuration` parameter to `Project.UpdateOAuth2Server`
* Added: `token` parameter to `Sites.GetDeploymentDownload`
* Added: `oauth2.introspect` and organization installation key scopes

## 6.0.0

* Breaking: Removed `Health` service with all its models and enums
* Breaking: Removed `Usage` service and `UsageEvent`/`UsageGauge` models
* Breaking: Removed `Messaging` log methods `ListMessageLogs`, `ListProviderLogs`, `ListSubscriberLogs`, `ListTopicLogs`
* Breaking: Removed OS, client, and device fields plus `countryCode`/`countryName` from `ActivityEvent` (`country` remains)
* Breaking: Changed spatial attribute/column `default` parameters from `List<object>` to typed coordinate lists
* Breaking: Made `Project` OAuth2 server properties nullable
* Added: `Organization` service methods `Get`, `Update`, `Delete`, and membership CRUD
* Added: `Client.SetBearer` for OAuth access token authentication
* Added: vector query methods `Query.VectorDot`, `Query.VectorCosine`, `Query.VectorEuclidean`
* Added: `Project.UpdateOAuth2Appwrite` and `Project.UpdateDenyCorporateEmailPolicy` methods
* Added: OIDC `prompt` and `maxAge` parameters plus OAuth2 device-flow server options
* Added: optional `specification` on `TablesDB.Create`, `newSpecification` on `Backups.CreateRestoration`
* Added: optional `type` on `ListSpecifications`, `token` on `GetDeploymentDownload`
* Added: geolocation and ISP fields on `Locale`, email classification fields on `User`
* Added: `DatabaseStatus`, `BillingPlanGroup`, `OAuth2OidcPrompt` enums and new key scopes
* Added: billing plan models, `Database.Status`, `BackupPolicy.Type`, `Block.Mode`, `Membership.UserAccessedAt`

## 5.1.0

* Added: `createSesProvider` and `updateSesProvider` to `messaging`
* Added: `updateOAuth2Server` to `project` for OAuth2 server settings
* Added: `updatePasswordStrengthPolicy` and `PolicyPasswordStrength` to `project`
* Added: `getAuditsDB` health check to `health`
* Added: `password-strength` to `ProjectPolicyId`
* Added: `apps.read` and `apps.write` to `ProjectKeyScopes`


## 5.0.0

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

## 4.0.0

* Breaking: Renamed `AuthMethod` enum to `ProjectAuthMethodId`
* Breaking: Renamed `EmailTemplateType` to `ProjectEmailTemplateId` and `EmailTemplateLocale` to `ProjectEmailTemplateLocale`
* Breaking: Renamed `ServiceId` to `ProjectServiceId`, `ProtocolId` to `ProjectProtocolId`, `Secure` to `ProjectSMTPSecure`, `ProjectPolicy` to `ProjectPolicyId`
* Breaking: Replaced `Scopes` enum with `ProjectKeyScopes` for project key endpoints
* Breaking: Removed `Project.UpdateDenyCanonicalEmailPolicy`; replaced with `UpdateDenyAliasedEmailPolicy`, `UpdateDenyDisposableEmailPolicy`, and `UpdateDenyFreeEmailPolicy`
* Breaking: Removed `AuthProvider` model; use new `ProjectOAuthProviderId` enum instead
* Added: `Project.Get` method to fetch current project details
* Added: `Advisor`, `Presences`, and `Usage` services
* Added: `Insight`, `Presence`, `Report`, `UsageEvent`, and `UsageGauge` models with list variants
* Added: `ProjectAuthMethod`, `ProjectProtocol`, and `ProjectService` models
* Added: `ProjectOAuthProviderId` and `ProjectOAuth2GooglePrompt` enums
* Updated: `Project`, `Database`, and `OAuth2Google` model schemas
* Updated: `X-Appwrite-Response-Format` header to `1.9.5`

## 3.1.0

* Added: Introduced `bigint` create/update APIs for legacy Databases attributes
* Added: Introduced `bigint` create/update APIs for `TablesDB` columns
* Updated: Extended key-list query filters with `key`, `resourceType`, `resourceId`, and `secret`

## 3.0.0

* [BREAKING] Renamed Webhook model fields: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`, `signatureKey` → `secret`
* [BREAKING] Renamed Webhook service parameters to match: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`
* [BREAKING] Renamed `Webhooks.UpdateSignature()` to `Webhooks.UpdateSecret()` with new optional `secret` parameter
* Added `Client.GetHeaders()` method to retrieve request headers
* Added `secret` parameter to Webhook create and update methods
* Added `x` OAuth provider to `OAuthProvider` enum
* Added `userType` field to `Log` model
* Added `purge` parameter to `UpdateCollection` and `UpdateTable` for cache invalidation
* Added Project service: platform CRUD, key CRUD, protocol/service status management
* Added new models: `Key`, `KeyList`, `Project`, `DevKey`, `MockNumber`, `AuthProvider`, `PlatformAndroid`, `PlatformApple`, `PlatformLinux`, `PlatformList`, `PlatformWeb`, `PlatformWindows`, `BillingLimits`, `Block`
* Added new enums: `PlatformType`, `ProtocolId`, `ServiceId`
* Updated `BuildRuntime`, `Runtime` enums with `dart-3.11` and `flutter-3.41`
* Updated `Scopes` enum with `KeysRead`, `KeysWrite`, `PlatformsRead`, `PlatformsWrite`
* Updated `X-Appwrite-Response-Format` header to `1.9.1`
* Updated TTL description for list caching in Databases and TablesDB

## 2.0.0

* [BREAKING] Changed `$sequence` type from `int` to `string` for rows and documents
* Breaking: Removed `IndexType`; replaced with `DatabasesIndexType`, `TablesDBIndexType`
* Added `Model` class with `Embeddinggemma` value
* Expanded runtime RCs in `BuildRuntime`/`Runtime` for multiple languages
* Added impersonation support via `SetImpersonateUserId`, `SetImpersonateUserEmail`, `SetImpersonateUserPhone`
* Added scopes `WebhooksRead`, `WebhooksWrite`, `ProjectRead`, `ProjectWrite`
* Updated `Client` headers: `User-Agent` and `x-sdk-version` to 0.28.0
* Updated `X-Appwrite-Response-Format` to `1.9.0`

## 1.0.0

* Breaking: Sites.CreateDeployment signature changed; 'activate' moved after outputDirectory.
* Breaking: Relationship API path updated; old path deprecated.
* Added GetConsolePausing health check method.
* Added ttl parameter to ListDocuments and ListRows.
* Updated deployment example to show activate parameter placement.

## 0.27.0

* Added broad new runtime options across multiple languages (Node 23, 24, 25; PHP 8.4; Ruby 3.4 and 4.0; Python 3.13 and 3.14; Python ML 3.13 and 3.14; Deno 2.5 and 2.6; Go 1.23–1.26; Bun 1.2 and 1.3; Java 25; Kotlin 2.3; Swift 6.2; and Dotnet 10).
* Introduced new API access scopes: schedules.read, schedules.write, and events.read for finer-grained permissions.
* Encryption support added to text attributes: AttributeLongtext and AttributeMediumtext now include an optional Encrypt boolean property, with mapping to and from API payloads.
* Minor model/serialization updates to accommodate the new encrypt field across longtext and mediumtext attributes.

## 0.26.0

* Add `dart-3.10` and `flutter-3.38` to `BuildRuntime` and `Runtime` enums
* Add `bytesMax` and `bytesUsed` properties to `Collection` and `Table` models
* Fix `Roles` enum removed from Teams service; `roles` parameter now accepts `IReadOnlyList<string>`
* Add support for the new `Backups` service

## 0.25.0

* Add array-based enum parameters (e.g., `permissions: IReadOnlyList<BrowserPermission>`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `getQueueAudits` support to `Health` service.
* Add longtext/mediumtext/text/varchar attribute and column helpers to `Databases` and `TablesDB` services.

## 0.24.0

* Added ability to create columns and indexes synchronously while creating a table

## 0.23.0

* Rename `VCSDeploymentType` enum to `VCSReferenceType`
* Change `CreateTemplateDeployment` method signature: replace `Version` parameter with `Type` (TemplateReferenceType) and `Reference` parameters
* Add `GetScreenshot` method to `Avatars` service
* Add `Theme`, `Timezone` and `Output` enums

## 0.22.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations
* Add `CreateResendProvider` and `UpdateResendProvider` methods to `Messaging` service

## 0.21.2

* Fix: handle Object[] during array deserialization

## 0.21.1

* Add transaction support for Databases and TablesDB

## 0.20.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 0.15.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `encrypt` support to `StringAttribute` model
* Add `sequence` support to `Document` model
* Fix: pass enum value as string in API params

## 0.14.0

* Refactor from Newtonsoft.Json to System.Text.Json for serialization/deserialization
* Update package dependencies in `Package.csproj.twig`
* Migrate all serialization/deserialization logic in `Client.cs.twig`, `Query.cs.twig`, and `Extensions.cs.twig`
* Update model attributes from `[JsonProperty]` to `[JsonPropertyName]` in `Model.cs.twig`
* Create new `ObjectToInferredTypesConverter.cs.twig` for proper object type handling
* Replace `JsonConverter` with `JsonConverter<object>` in `ValueClassConverter.cs.twig`
* Update error handling to use `JsonDocument` instead of `JObject`

## 0.13.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Add doc examples and methods for bulk api transactions: `createDocuments`, `deleteDocuments` etc.
* Add doc examples, class and methods for new `Sites` service
* Add doc examples, class and methods for new `Tokens` service
* Add enums for `BuildRuntime `, `Adapter`, `Framework`, `DeploymentDownloadType` and `VCSDeploymentType`
* Update enum for `runtimes` with Pythonml312, Dart219, Flutter327 and Flutter329
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Add `queries` and `search` params to `listMemberships` method
* Remove `search` param from `listExecutions` method

## 0.12.0

* fix: remove content-type from GET requests by @loks0n in https://github.com/appwrite/sdk-for-dotnet/pull/59
* update: min and max are not optional in methods like `UpdateIntegerAttribute` etc.
* chore: regenerate sdk by @ChiragAgg5k in https://github.com/appwrite/sdk-for-dotnet/pull/60
* chore: fix build error by @ChiragAgg5k in https://github.com/appwrite/sdk-for-dotnet/pull/61

## 0.11.0

* Add new push message parameters by @abnegate in https://github.com/appwrite/sdk-for-dotnet/pull/56

## 0.10.0

* fix: chunk upload by @byawitz in https://github.com/appwrite/sdk-for-dotnet/pull/52

## 0.9.0

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

#### Breaking changes
You can find the new syntax for breaking changes in the [Appwrite API references](https://appwrite.io/docs/references). Select version `1.6.x`.
* Removed `otp` parameter from `deleteMFAAuthenticator`.
* Added `scopes` parameter for create/update function.
* Renamed `templateBranch` to `templateVersion`  in `createFunction()`.
* Renamed `downloadDeployment()` to `getDeploymentDownload()`

> **Please note: This version is compatible with Appwrite 1.6 and later only. If you do not update your Appwrite SDK, old SDKs will not break your app. Appwrite APIs are backwards compatible.**
