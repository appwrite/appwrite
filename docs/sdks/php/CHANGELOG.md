# Change Log

## 32.3.0

* Updated: `Project::listEnvironments`, `Project::createEnvironment`, `Project::getEnvironment`, `Project::updateEnvironment` return `Environment` models instead of `Project`
* Added: `Account::createDevice`, `Account::getDevice`, `Account::deleteDevice` and `Account::updateDeviceSession`, and the `Device` model
* Added: `Project::updateAccountDevicesPolicy`, `ProjectPolicyId::ACCOUNTDEVICES()` and the `PolicyAccountDevices` model
* Added: `Project::updateOAuth2Vipps`, `Project::updateOAuth2VippsSandbox` and the `OAuth2Vipps` model
* Added: `OAuthProvider::VIPPS()` and `OAuthProvider::VIPPSSANDBOX()`
* Added: `Proxy::updateFunctionRule`, `Proxy::updateRedirectRule` and `Proxy::updateSiteRule`
* Added: optional `schedule` on `waf` rule create and update methods and on `WafRule*` models
* Added: `ProjectKeyScopes::ALERTSWRITE()`
* Added: `Organization::$budgetCritical`
* Added: `Project::$environmentId`, `Session::$userName` and `ActivityEvent::$reason`
* Added: `ProxyRule::$expireAt` and `certificateStatus`
* Updated: requests send response format `2.7.1`

## 32.2.0

* Added: `Project::getPrefs` and `Project::updatePrefs` for project preferences, and `Project::$prefs`
* Added: `AppwriteMigrationResource::PROJECTPREFERENCES()` to migrate project preferences
* Added: `Client::setEnvironment` to act in a project environment
* Added: `monitors.read`, `monitors.write`, `alerts.read`, `reviews.read` and `reviews.write` to `ProjectKeyScopes`
* Added: `Organization::$budgetNotify` and `Organization::$educationExpiresAt`
* Updated: requests send `X-Appwrite-Response-Format: 2.7.0`; README targets Appwrite 2.7.x

## 32.1.0

* Updated: protected `Client::getHttpClient()` and `createHttpClient()` drop their `bool` argument; `$httpClientNoRedirect` removed
* Updated: redirects are no longer followed; a 3xx throws `AppwriteException` naming the `Location`
* Added: `Migrations` service to run Appwrite, Firebase, NHost, Supabase, CSV and JSON migrations, plus retries and listing
* Added: `Migration`, `MigrationList` and `MigrationReport` models and the `AppwriteMigrationResource`, `FirebaseMigrationResource`, `NHostMigrationResource`, `SupabaseMigrationResource` and `OnDuplicate` enums
* Added: `Project::updateDenyRealtimeGuestsPolicy`, the `PolicyDenyRealtimeGuests` model and `ProjectPolicyId::DENYREALTIMEGUESTS()`
* Added: `Project::deleteSessions` to sign out every user in the project
* Added: `BillingPlan::$deploymentRetention`, `$deploymentRetentionMax` and `$deploymentsRetentionCount`
* Added: `python-3.15`, `deno-2.9`, `go-1.27` and `rust-1.99` to `Runtime` and `BuildRuntime`
* Updated: requests send `X-Appwrite-Response-Format: 2.6.2`; README targets Appwrite 2.6.x
* Fixed: small (≤ 5 MB) uploads send `X-Appwrite-Project` and the other method headers instead of failing with 401
* Fixed: path parameters are percent-encoded as one segment; `.` and `..` throw `AppwriteException`
* Fixed: header names or values containing CR, LF or NUL throw `AppwriteException` instead of being sent
* Fixed: enum values are sent in GET query strings instead of being dropped, e.g. `avatars->getBrowser` `code`

## 32.0.0

* Breaking: `TablesDB`, `DocumentsDB` and `VectorsDB` `create` and `update` take `$description` before `$specification`
* Breaking: `Functions::update` takes `$description` before `$emailEnabled`
* Added: `createQuery` on `Databases`, `DocumentsDB` and `TablesDB` to send list queries in the request body
* Added: `Account::listMemberships`
* Added: optional `$description` on create and update for databases, collections, tables, attributes, columns, indexes, buckets, functions, sites, variables, topics, webhooks and keys
* Added: `$description` on the matching models
* Added: `$total` on list methods in `Activities`, `Backups`, `Domains`, `Mongo`, `Mysql` and `Postgresql`
* Added: `$emails` on `Messaging::createEmail` and `Messaging::updateEmail`
* Added: `$denyList` on `Project::updatePasswordDictionaryPolicy`, `updatePasswordPersonalDataPolicy` and their policy models
* Added: `BillingPlan::$functionsAllowCron`
* Added: `$stripMetadata` on `Storage::createBucket`, `updateBucket` and the `Bucket` model

## 31.0.0

* Breaking: requests send `X-Appwrite-Response-Format: 2.5.0`; responses follow the 2.5 shapes
* Breaking: removed `Account::listLogs`, `Users::listLogs` and the `Log`/`LogList` models
* Breaking: removed `Organization::createKey`, `getKey`, `listKeys`, `updateKey` and `deleteKey`, and `OrganizationKeyScopes`; use `Organization::listProjectKeys`, `getProjectKey`, `updateProjectKey` and `deleteProjectKey` instead
* Breaking: removed `Client::setDevKey`, the `DevKey` model and `Project::$devKeys`
* Breaking: removed `BillingPlanGroup::STARTER()`; use `BillingPlanGroup::FREE()`
* Breaking: `listSpecifications` returns each specification's prices and overage rates on the row itself. Removed the list-level `$pricing` field and the `DedicatedDatabaseSpecificationPricing` model
* Breaking: removed `Block` fields `reason`, `projectName`, `region`, `organizationName`, `organizationId` and `billingPlan`
* Breaking: OAuth2 `resource` params now take `?array`
* Breaking: `Project::updateOAuth2Kakao` takes `$enabled` before `$prompt`
* Breaking: `BackupRestoration::$options` is now an array
* Breaking: some response fields became nullable, such as `BillingPlan::$usage` and `DedicatedDatabase::$engine`
* Breaking: the protected `Client::flatten` is renamed `flattenForm`; subclasses that override it must rename it
* Added: `Topic` helper to build MQTT topics for push messages
* Added: `Mongo`, `Mysql` and `Postgresql` `promoteRestoration`, `$restoreMode`, `$targetName` and `$autoPromote` on `createRestoration`, and `DedicatedDatabaseRestoration::$restoreMode`
* Added: `Proxy::createSMTPRule`, the `ProxyRuleProtocol` enum and `ProxyRule::$protocol`
* Added: `$emailEnabled` on `Functions::update` and `FunctionModel`, and `ExecutionTrigger::EMAIL()`
* Added: `Query` aggregates (`count`, `countDistinct`, `sum`, `avg`, `min`, `max` and more), `groupBy`, `having`, `distinct`, joins, `relationship`, `covers`, `spatialEquals` and `page`
* Added: `Query::builder()` to chain queries; `build()` returns the query list
* Fixed: OAuth2 models wrap a single `prompt` value in a list

## 30.4.0

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

## 30.3.1

* Fixed: added `ProjectAuthMethodId::PASSKEY()`, so `project.updateAuthMethod` can enable passkeys
* Fixed: added `ProjectPolicyId::PASSKEY()` and the `PolicyPasskey` model, so `getPolicy` and `listPolicies` return the passkey policy

## 30.3.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `users.listPasskeys`, `users.getPasskey`, `users.updatePasskey` and `users.deletePasskey`
* Added: `project.updatePasskeyPolicy` to configure passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 30.2.2

* Fixed: a response model fills a missing field from its spec default instead of throwing `Missing required field`, so the SDK reads responses from servers that predate the field, such as `File.folder` and `File.key` from Appwrite 1.x

## 30.2.1

* Fixed: requires `utopia-php/client` `^0.6` and imports `Utopia\Client\Client`, so the SDK installs and runs next to client 0.6, which dropped the `Utopia\Client` alias

## 30.2.0

* Added: optional `interval` parameter on `functions.create` and `functions.update` to run a function every N minutes
* Added: `interval` on the `Function` model and `functionsIntervalMinimum` on the `BillingPlan` model

## 30.1.0

* Added: optional `state` parameter on `createOAuth2Token`, returned unchanged on the success and failure URLs

## 30.0.0

* Stable release of the dedicated database APIs: `mysql`, `postgresql`, `mongo`, `documentsDB`, and `vectorsDB` services, previously released as release candidates
* Breaking: `tablesDB.cutoverMigration` is renamed to `tablesDB.createCutover`
* Added: Cloudflare and Resend OAuth providers
* Added: `usageAggregateOnlyMetrics` on the `BillingPlan` model
* Fixed: `transactionId` is accepted again by `documentsDB` and `vectorsDB` `createDocument` and `createDocuments`
* Updated: `X-Appwrite-Response-Format` is now `2.0.0`
* Updated: `FrameworkAdapter.fallbackFile` is now optional

## 30.0.0-rc.1

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

## 29.0.0

* Breaking: requires PHP 8.5 or later, up from PHP 8.2
* Breaking: HTTP transport rewritten on `utopia-php/client` (PSR-18), now a required dependency
* Breaking: `Execution.functionId` replaced by `resourceId` and `resourceType`
* Breaking: removed `dedicatedDatabases.execute` from `ProjectKeyScopes`
* Breaking: `Project.wafEnabled` is now nullable
* Added: `avatars.getPhoto`, with `userId`, `emailHash`, and `name` to resolve an avatar for someone other than the caller
* Added: `project.updateOAuth2HuggingFace`, the `OAuth2HuggingFace` model, and the `huggingface` OAuth provider
* Added: `scopes` parameter to `sites.create` and `sites.update`, and `Site.scopes`
* Added: `ExecutionResourceType` enum and the `bun-1.4` runtime
* Added: `Database` fields `containerStatus`, `error`, and `lifecycleState`
* Added: `BillingPlan` fields `supportsDedicatedDatabases` and `databaseComputeCredit`
* Added: `DatabaseMigration.changelogWatermark`, and `replicating` on `DatabaseStatusReplica` and `DedicatedDatabaseMember`
* Updated: enums now implement `Stringable`
* Updated: sources declare `strict_types` and ship `pint`, `phpstan`, and `rector` configs


## 28.0.0

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

## 27.1.0

* Added: `Apps` service for managing OAuth2 applications, keys, and installations
* Added: `OAuth2` service with authorize, grant, device authorization, and consent flows
* Added: `Account` OAuth2 consent methods `listConsents`, `getConsent`, `deleteConsent`, and consent token methods
* Added: app installation management methods to `Organization` and `Teams` services
* Added: `installationAccessTokenDuration` parameter to `Project::updateOAuth2Server`
* Added: `token` parameter to `Sites::getDeploymentDownload`
* Added: `oauth2.introspect` and organization installation key scopes

## 27.0.0

* Breaking: Removed `Health` service with its models and enums
* Breaking: Removed `Usage` service and usage models
* Breaking: Removed `Messaging` methods `listMessageLogs`, `listProviderLogs`, `listSubscriberLogs`, `listTopicLogs`
* Breaking: Renamed `TablesDB::create` parameter `dedicatedDatabaseId` to `specification`
* Breaking: Removed `countryCode` and `countryName` from `ActivityEvent` model
* Added: `Organization` service `get`, `update`, `delete`, and membership CRUD methods
* Added: `Client::setSigningKey` and `Client::setBearer` for JWT and bearer authentication
* Added: vector query helpers `Query::vectorDot`, `Query::vectorCosine`, `Query::vectorEuclidean`
* Added: `appwrite` OAuth2 provider and `Project::updateOAuth2Appwrite` method
* Added: `prompt` and `maxAge` parameters to `updateOAuth2Oidc`, `defaultScopes` to `updateOAuth2Server`
* Added: `newSpecification` parameter to `Backups::createRestoration`
* Added: `type` parameter to `Functions` and `Sites` `listSpecifications`
* Added: `token` parameter to `Functions::getDeploymentDownload`
* Added: `mode` to `Block`, `status` to `Database`, geolocation/ISP fields to `Locale` models
* Added: `onboarding` and `oAuth2ServerDefaultScopes` to `Project` model
* Added: organization membership, organization, and `project.oauth2`/`stages` key scopes
* Added: billing plan models, `BillingPlanGroup`, `DatabaseStatus`, OIDC prompt enums
* Updated: deprecated `Databases` transaction and text attribute methods in favor of `TablesDB`

## 26.1.0

* Updated: Reworked `usage.listEvents` into an aggregation query with `metric`, `interval`, `dimensions`, and `orderBy`
* Updated: Reworked `usage.listGauges` into an aggregation query with `metric`, `teamId`, `interval`, and `dimensions`
* Added: Unified `UsageGroup` model for aggregated usage results
* Updated: `UsageEventList` and `UsageGaugeList` now expose `groups`
* Fixed: Made `Project` OAuth2 server fields optional and nullable

## 26.0.0

* Breaking: Removed client, OS, and device fields (`osCode`, `clientName`, `deviceModel`, etc.) from `ActivityEvent`
* Breaking: Inserted `authorizationDetailsTypes` mid-signature in `project.updateOAuth2Server`
* Added: OAuth2 device flow options `verificationUrl`, `userCodeLength`, `userCodeFormat`, and `deviceCodeDuration` to `updateOAuth2Server`
* Added: `updateDenyCorporateEmailPolicy` and `PolicyDenyCorporateEmail` to `project`
* Added: `deny-corporate-email` to `ProjectPolicyId`
* Added: `oauth2` to `ProjectServiceId` and `dedicatedDatabases.execute` to `ProjectKeyScopes`
* Added: `emailCanonical`, `emailIsFree`, `emailIsDisposable`, `emailIsCorporate`, and `emailIsCanonical` to `User`
* Added: `userAccessedAt` to `Membership` and `PolicyMembershipPrivacy`
* Added: `type` to `BackupPolicy`
* Updated: Send an `accept` header on all requests, including chunked uploads

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
* Breaking: Removed `updateDenyCanonicalEmailPolicy`; replaced with `updateDenyAliasedEmailPolicy`, `updateDenyDisposableEmailPolicy`, and `updateDenyFreeEmailPolicy`
* Breaking: Removed `AuthProvider` model; use new `ProjectOAuthProviderId` enum instead
* Added: `Project::get` method to fetch current project details
* Added: `Advisor`, `Presences`, and `Usage` services
* Added: `Insight`, `Presence`, `Report`, `UsageEvent`, and `UsageGauge` models with list variants
* Added: `ProjectAuthMethod`, `ProjectProtocol`, and `ProjectService` models
* Added: `ProjectOAuthProviderId` and `ProjectOAuth2GooglePrompt` enums
* Updated: `Project`, `Database`, and `OAuth2Google` model schemas
* Updated: `X-Appwrite-Response-Format` header to `1.9.5`

## 23.1.1

* Fixed: `Database` model `policies` and `archives` now hydrate as `BackupPolicy` / `BackupArchive` instead of `Index` / `Collection`
* Added: `prompt` parameter to `Project::updateOAuth2Google` and `prompt` field on the `OAuth2Google` model, backed by the new `OAuth2GooglePrompt` enum
* Added: `Project::updateDenyCanonicalEmailPolicy`, `Project::updateDenyDisposableEmailPolicy`, and `Project::updateDenyFreeEmailPolicy`
* Updated: `BuildRuntime` and `Runtime` enums with `deno-1.21`, `deno-1.24`, and `deno-1.35`

## 23.1.0

* Added: Introduced `bigint` create/update APIs for legacy Databases attributes
* Added: Introduced `bigint` create/update APIs for `TablesDB` columns
* Updated: Extended key-list query filters with `key`, `resourceType`, `resourceId`, and `secret`

## 23.0.0

* [BREAKING] Renamed Webhook model fields: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`, `signatureKey` → `secret`
* [BREAKING] Renamed Webhook service parameters to match: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`
* [BREAKING] Renamed `Webhooks::updateSignature()` to `Webhooks::updateSecret()` with new optional `secret` parameter
* Added `from(array)` static factory method and `toArray()` serializer to all response models with required-field validation
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
* Replaced internal `FIELD_MAP`/`ARRAY_TYPES` constants and `parseResponse()` with explicit `Model::from()` hydration

## 22.0.0

* [BREAKING] Raised minimum PHP version from 8.0 to 8.2
* [BREAKING] All service methods now return typed model objects instead of `array`
* Added 142 typed response model classes with `ArraySerializable` trait for backward-compatible array access
* Added `from(string)` factory method to all enums for value-based construction
* Added `Client::getHeaders()` method to retrieve request headers
* Added `dart-3.11` and `flutter-3.41` runtime enum values

## 21.0.0

* [BREAKING] Changed `$sequence` type from `int` to `string` for rows and documents
* [BREAKING] Renamed `IndexType` enum: split into `DatabasesIndexType` (for Databases) and `TablesDBIndexType` (for TablesDB)
* [BREAKING] Replaced `specification` parameter with `buildSpecification` and `runtimeSpecification` in `Functions::create()`, `Functions::update()`, `Sites::create()`, `Sites::update()`
* Added new `Project` service with full CRUD for project-level environment variables
* Added new `Webhooks` service with full CRUD for project webhooks (including `updateSignature`)
* Added `Users::updateImpersonator()` method for enabling/disabling user impersonation
* Added impersonation support: `setImpersonateUserId()`, `setImpersonateUserEmail()`, `setImpersonateUserPhone()` on `Client`
* Added `deploymentRetention` parameter to Functions and Sites create/update
* Added `startCommand` parameter to Sites create/update
* Added `Documentsdb`, `Vectorsdb` values to `BackupServices` and `DatabaseType` enums
* Added `WebhooksRead`, `WebhooksWrite`, `ProjectRead`, `ProjectWrite` scopes
* Removed `getQueueBillingProjectAggregation`, `getQueueBillingTeamAggregation`, `getQueuePriorityBuilds`, `getQueueRegionManager`, `getQueueThreats` from `Health` service
* Updated `Log` model field descriptions to clarify impersonation behavior
* Updated `X-Appwrite-Response-Format` header to `1.9.0`

## 20.2.0

* Added optional encrypt parameter for database attributes (Text, Longtext, Mediumtext, Varchar) and corresponding column creation methods to enable encryption at rest. Encrypted attributes/columns cannot be queried.
* Updated API docs and code examples to include the new encrypt option (defaulting to false) across databases and TablesDB sections.
* Updated README compatibility note to reflect Appwrite server version 1.8.x.
* Add support for the new `Backups` service

## 20.0.1

* Fix doc examples with proper formatting

## 20.0.0

* Add array-based enum parameters (e.g., `permissions: array<BrowserPermission>`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `getQueueAudits` support to `Health` service.
* Add longtext/mediumtext/text/varchar attribute and column helpers to `Databases` and `TablesDB` services.

## 19.1.0

* Added ability to create columns and indexes synchronously while creating a table

## 19.0.0

* Rename `VCSDeploymentType` enum to `VCSReferenceType`
* Change `createTemplateDeployment` method signature: replace `version` parameter with `type` (TemplateReferenceType) and `reference` parameters
* Add `Theme`, `Timezone` and `Output` enums

## 18.0.1

* Fix `TablesDB` service to use correct file name

## 18.0.0

* Fix duplicate methods issue (e.g., `updateMFA` and `updateMfa`) causing build and runtime errors
* Add support for `getScreenshot` method to `Avatars` service
* Add `Output`, `Theme` and `Timezone` enums

## 17.5.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations
* Add `createResendProvider` and `updateResendProvider` methods to `Messaging` service

## 17.4.1

* Add transaction support for Databases and TablesDB

## 17.3.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 15.1.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `dart38` and `flutter332` support to runtime models
* Add `gif` support to `ImageFormat` enum
* Add `upsertDocument` support to `Databases` service
* Add `sequence` support to `Document` model
