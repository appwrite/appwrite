# Change Log

## v7.11.0

* Added: project environments with `Project.CreateEnvironment`, `GetEnvironment`, `ListEnvironments`, `UpdateEnvironment` and `DeleteEnvironment`
* Added: `Project.UpdatePasswordRotationPolicy` and the `PolicyPasswordRotation` model
* Added: `WithCreateMembershipMembershipId` on `Teams.CreateMembership`, and `fingerprints` options on Android platform create and update
* Added: `Project.ParentId` and `BillingPlan.Environments`

## v7.10.0

* Added: `Account` passkey methods `ListPasskeys`, `CreatePasskey`, `GetPasskey`, `UpdatePasskey`, `DeletePasskey` and `UpdatePasskeyVerification`
* Added: `Account.CreatePasskeyToken` and `Account.UpdatePasskeyToken` for passkey sign-in
* Added: `Users.ListPasskeys`, `Users.GetPasskey`, `Users.UpdatePasskey` and `Users.DeletePasskey`
* Added: `Project.UpdatePasskeyPolicy` to configure passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## v7.9.0

* Added: `WithCreateInterval` and `WithUpdateInterval` options on `Functions` to run a function every N minutes
* Added: `Interval` on the `Function` model and `FunctionsIntervalMinimum` on the `BillingPlan` model

## v7.8.0

* Breaking: `Analytics.CreateProperty` and `UpdateProperty` drop the `Timezone` option
* Breaking: `AnalyticsProperty` drops `Timezone` and `SnippetId`, adds `AccessedAt` and `FirstAccessedAt`
* Breaking: nullable `DedicatedDatabase`, `DedicatedDatabaseBranch` and `DedicatedDatabaseRestoration` fields are pointers
* Breaking: nullable `Organization` billing and payment method fields are pointers
* Breaking: `Oauth2` `resource` options take a list of resource URIs instead of a string
* Added: `Waf` service to list, get, delete and create or update bypass, challenge, deny, rate limit and redirect rules
* Added: a model per WAF rule action; `GetRule` and `ListRules` return the one matching each rule's `Action`
* Added: `PolicyPasskey` model returned by `Project.GetPolicy`
* Updated: requests send `X-Appwrite-Response-Format` `2.4.0`

## v7.7.0

* Added: optional `state` parameter on `createOAuth2Token`, returned unchanged on the success and failure URLs

## v7.6.0

* Breaking: `Domains.GetZone` returns the zone file as `*string`
* Breaking: `BackupArchive.Size` and `StartedAt` are pointers, nil until set
* Breaking: `BackupRestoration.StartedAt` is `*string` and `Options` is `interface{}`
* Added: `Analytics` service with property CRUD, `CreateEvent` and `ListMetrics`
* Added: `Avatars.UpdatePhoto` and `Avatars.DeletePhoto` with the `Account` model
* Added: `Project.UpdateOAuth2Webflow` and the `OAuth2Webflow` model
* Added: `WithCreatePushChannelId` and `WithUpdatePushChannelId` on `Messaging`
* Added: `WithCreateEmailPasswordSessionDuration` and `WithDeleteSessionsCurrent` on `Account`
* Added: Optional `Total` on list methods in `Account`, `Locale`, `Functions`, `Sites` and transactions
* Fixed: Uploads send the endpoint's HTTP method instead of always `POST`
* Fixed: `Decode()` works on models returned by uploads

## v7.5.0

* Added: OAuth2 token introspection with `Oauth2.Introspect` and the `Oauth2Introspection` response model
* Added: `prompt` options and model fields for Auth0, Discord, GitHub, Kakao, Microsoft, Okta, Salesforce, Zoho
* Added: MQTT topic builder with `topic.Path`, `topic.Any`, and `topic.All`
* Updated: API examples with realistic payloads, queries, scopes, and webhook events
* Updated: README compatibility target to Appwrite `2.3.x`

## v7.4.0

* Breaking: `Account.ListLogs`, `Users.ListLogs`, `Log` and `LogList` removed
* Breaking: `Organization` key methods renamed to `CreateEphemeralProjectKey`, `GetProjectKey`, `ListProjectKeys`, `UpdateProjectKey`, `DeleteProjectKey`
* Breaking: `WithDevKey` client option, `DevKey` model and `Project.DevKeys` removed
* Breaking: `Region`, `Reason`, `ProjectName`, `OrganizationId`, `OrganizationName`, `BillingPlan` removed from `Block`
* Breaking: nullable scalar model fields are now pointers, e.g. `Default`, `Min`, `Max`, `Array`, `Encrypt`
* Added: `Domains` service for domains, DNS records, email presets, prices and transfers
* Added: `Account.CreateIdTokenSession` and email verification and recovery OTP methods
* Added: `Messaging.CreateAppwriteProvider` and `Messaging.UpdateAppwriteProvider`
* Added: `Project.UpdatePasswordPwnedPolicy` with `PolicyPasswordPwned` model and `User.PasswordPwned`
* Added: native client IDs on the Apple and Google OAuth2 providers
* Added: reply-to on email messages, `Qos` and `Expiry` on topics
* Fixed: a `null` in a response is no longer read as the field's zero value
* Fixed: `TablesDB.GetColumn` and `Databases.GetAttribute` decode varchar, text, bigint and spatial columns
* Updated: requests send `X-Appwrite-Response-Format` `2.3.0`

## v7.3.0

* Breaking: `DedicatedDatabaseSpecificationList.Pricing` removed; rates now live on each `DedicatedDatabaseSpecification`
* Added: `StorageOverageRate`, `BandwidthOverageRate`, `ReplicaRate`, and `PitrRate` on `DedicatedDatabaseSpecification`
* Added: `StorageStatus`, `StorageTargetGb`, and `StorageResizeStartedAt` on `DedicatedDatabase` for in-flight storage resizes
* Added: `Project.UpdateOAuth2Kakao` and `Project.UpdateOAuth2TikTok` with `OAuth2Kakao` and `OAuth2TikTok` models
* Added: `failed` container status on `Database` and `DedicatedDatabase`
* Fixed: required path parameters are validated before the request is sent
* Fixed: JSON response methods now send an explicit `accept: application/json` header
* Updated: SDK now targets Appwrite `2.0.x`

## v7.2.0

* Stable release of the dedicated database APIs: `Mysql`, `Postgresql`, `Mongo`, `DocumentsDB`, and `VectorsDB` services, previously released as release candidates
* Breaking: `TablesDB.CutoverMigration` renamed to `TablesDB.CreateCutover`
* Breaking: `Execution.FunctionId` replaced by `ResourceId` and `ResourceType`, covering function and site executions
* Added: Cloudflare, Resend, and Hugging Face OAuth providers
* Added: `UsageAggregateOnlyMetrics` on the `BillingPlan` model
* Fixed: `TransactionId` is accepted again by `DocumentsDB` and `VectorsDB` `CreateDocument` and `CreateDocuments`
* Updated: `X-Appwrite-Response-Format` is now `2.0.0`

## v7.2.0-rc.5

* Breaking: `TablesDB.CutoverMigration` renamed to `TablesDB.CreateCutover`
* Breaking: `Mysql`, `Postgresql`, and `Mongo` `UpdateCredentials` now return `DedicatedDatabaseOperation`
* Added: `Project.UpdateOAuth2Cloudflare` and `UpdateOAuth2Resend`, with `OAuth2Cloudflare` and `OAuth2Resend` models
* Added: `UsageAggregateOnlyMetrics` on the `BillingPlan` model
* Added: `CredentialGeneration` on the `DedicatedDatabase` model
* Updated: `X-Appwrite-Response-Format` is now `2.0.0`
* Updated: `Avatars.GetPhoto` docs explain how `userId`, `emailHash`, and `name` combine
* Updated: `DedicatedDatabaseOperation.Type` documents the new `credentials-update` type

## v7.2.0-rc.4

* Added: `UserId`, `EmailHash`, and `Name` options on `Avatars.GetPhoto`
* Added: `Error`, `ContainerStatus`, and `LifecycleState` on the `Database` model
* Added: `ChangelogWatermark` on the `DatabaseMigration` model
* Added: `Total` on the `DedicatedDatabaseBranchList` model
* Updated: `DedicatedDatabaseOperation.Status` documents the new `queued` state

## v7.2.0-rc.3

* Added: `Avatars.GetPhoto` and `GetPhotoURL` returning the user's best available profile photo
* Added: `Project.UpdateOAuth2HuggingFace` and the `OAuth2HuggingFace` model, resolved by `GetOAuth2Provider`
* Breaking: `Execution.FunctionId` replaced by `ResourceId` and `ResourceType`, covering function and site executions
* Breaking: `AppInstallation.AuthorizationDetails` is now `[]interface{}` instead of `interface{}`
* Breaking: `DedicatedDatabaseExecution.Rows` is now `[]interface{}` instead of `interface{}`
* Updated: `CreateFailover` docs describe repairing stranded operations and when `targetReplicaId` is required
* Updated: examples are complete runnable programs with `func main()` and gofmt formatting

## v7.2.0-rc.2

* Added: `DocumentsDB` service for schemaless document databases, reachable as `appwrite.NewDocumentsDB(client)`
* Added: `VectorsDB` service for vector databases, reachable as `appwrite.NewVectorsDB(client)`
* Added: database, collection, document, and index lifecycle on both services
* Added: transactions on both services — `CreateTransaction`, `GetTransaction`, `UpdateTransaction`, `DeleteTransaction`, `CreateOperations`
* Added: bulk document writes — `CreateDocuments`, `UpsertDocuments`, `UpdateDocuments`, `DeleteDocuments`
* Added: `DocumentsDB.IncrementDocumentAttribute` and `DecrementDocumentAttribute` for atomic counters
* Added: `VectorsDB.CreateQuery` for vector search, and `Dimension` on vector collections
* Added: replica controls on both services — `ListSpecifications`, `CreateFailover`, `ListOperations`, `GetReplicas`, `GetStatus`
* Added: `AttributeObject`, `AttributeVector`, `VectorsdbCollection`, and `VectorsdbCollectionList` models
* Added: `SupportsDedicatedDatabases` and `DatabaseComputeCredit` to the `BillingPlan` model

## v7.2.0-rc.1

* Added: `Mysql`, `Postgresql`, and `Mongo` services for dedicated databases, reachable as `appwrite.NewMysql(client)` and siblings
* Added: dedicated database lifecycle across all three engines — `List`, `Create`, `Get`, `Update`, `Delete`, `ListSpecifications`, `CreateUpgrade`, `UpdateMaintenance`, `UpdateCredentials`, `CreateFailover`, `CreateMigration`, `ListOperations`, `GetStatus`, `GetReplicas`
* Added: backups and recovery — `ListBackups`, `CreateBackup`, `GetBackup`, `DeleteBackup`, `UpdateBackupStorage`, backup policy CRUD, `GetPitr`, `ListRestorations`, `CreateRestoration`, `GetRestoration`
* Added: branches — `ListBranches`, `CreateBranch`, `DeleteBranch`
* Added: `Mysql.GetPooler`/`UpdatePooler` and `Postgresql.GetPooler`/`UpdatePooler`
* Added: `Mysql.CreateExecution` and `Postgresql.CreateExecution` for SQL over the API
* Added: `Postgresql.ListExtensions`, `CreateExtension`, and `DeleteExtension`
* Added: `DedicatedDatabase*` models, including backups, backup storage, branches, executions, PITR windows, poolers, restorations, and `PostgresExtension`
* Added: `Replicating` to `DatabaseStatusReplica` and `DedicatedDatabaseMember`, true while the engine reports the replication link up
* Fixed: model doc comments hold `"` and `'` again instead of `&quot;` and `&#039;`
* Updated: generated sources are gofmt-clean

## v7.1.0

* Added: `Scopes` support to `Sites.Create`, `Sites.Update`, and the `Site` model

## v7.0.0

* Breaking: removed `account.createJWT`; use `users.createJWT` instead. A leaked JWT could mint further JWTs, letting a credential outlive its own expiry — a session cannot duplicate itself to live forever either
* Breaking: removed `project.createKey`. A leaked key could mint further hidden keys, making a compromise far harder to contain and revoke

## v6.5.0

* Breaking: Removed `CrossRegionReplicas` from `DedicatedDatabase`, as cross-region replication was withdrawn
* Breaking: Removed `CrossRegionReplicaRate` from `DedicatedDatabaseSpecificationPricing`
* Breaking: `ClientResponse.Result` holds `[]byte` for JSON responses, not `string`; read it with `client.ResponseBody`
* Added: `TablesDB` migration methods `CreateMigration`, `ListMigrations`, `GetMigration`, `CutoverMigration`, `DeleteMigration`
* Added: `DatabaseMigration` and `DatabaseMigrationList` models
* Added: `Project.UpdateMFAFactorsPolicy` with `Totp`, `Email`, `Phone`, and `Custom` options
* Added: `PolicyMfaFactors` model, returned by `Project.GetPolicy` for `mfa-factors`
* Added: `InstallationScopes` option to `Project.UpdateOAuth2Server`
* Added: `OAuth2ServerInstallationScopes` field to the `Project` model
* Added: `Users.GetMFAChallenge` and the `MfaChallengeSecret` model
* Added: `Custom` field to `MfaFactors`
* Added: `client.ResponseBody` helper for reading a response body of either type
* Fixed: responses no longer panic when the body is not a string

## v6.4.0

* Fixed: path parameters are percent-encoded, so an id containing `/`, `&`, or `?` stays in its segment
* Fixed: path parameters are no longer also sent in the query string or request body
* Fixed: `SelfSigned` no longer disables certificate verification on `http.DefaultTransport` process-wide
* Fixed: `SelfSigned` now applies whatever order `WithSelfSigned` and `WithTimeout` are passed in
* Updated: array query parameters are encoded as `key[0]`, `key[1]` instead of repeated `key[]`
* Added: `client.EncodePath` and `client.AddQueryParam` helpers

## v6.3.0

* Added: `Proxy.CreateInvalidation` to purge CDN cache for a domain by tag, path, or all
* Added: `ProxyInvalidation` model

## v6.2.0

* Added: `Embeddings` service with `CreateTextEmbeddings`, plus `Embedding` and `EmbeddingList` models
* Added: `TablesDB.ListOperations`, with `DedicatedDatabaseOperation` and `DedicatedDatabaseOperationList` models
* Added: `Apps.DeleteInstallation`
* Added: `SyncMode`, `EffectiveSyncMode`, and replication degradation fields to `DatabaseStatus`
* Updated: `DedicatedDatabase.Engine` and `Port` are null until the backing reports them
* Updated: `mariadb` is no longer a documented database engine
* Fixed: `GetSession`, `UpdateSession`, and `DeleteSession` compile again

## v6.1.0

* Added: `Apps` service for managing OAuth2 applications, keys, and installations
* Added: `OAuth2` service with authorize, grant, device authorization, and consent flows
* Added: `Account` OAuth2 consent methods `ListConsents`, `GetConsent`, `DeleteConsent`, and consent token methods
* Added: app installation management methods to `Organization` and `Teams` services
* Added: `InstallationAccessTokenDuration` option to `Project.UpdateOAuth2Server`
* Added: `Token` option to `Sites.GetDeploymentDownload`
* Added: `oauth2.introspect` and organization installation key scopes

## v6.0.0

* Breaking: Removed `Health` and `Usage` services and their models
* Breaking: Removed `ListMessageLogs`, `ListProviderLogs`, `ListSubscriberLogs`, `ListTopicLogs` from `Messaging`
* Breaking: Changed spatial default setter types to `[][]interface{}` and `[]float64` in `Databases`/`TablesDB`
* Breaking: Removed OS, client, and device fields plus `countryCode`/`countryName` from `ActivityEvent` (`country` remains)
* Added: `Organization` `Get`, `Update`, `Delete`, and membership management methods
* Added: `WithBearer` client option for OAuth bearer-token authentication
* Added: `VectorDot`, `VectorCosine`, `VectorEuclidean` query helpers
* Added: `NewSpecification` option to `Backups.CreateRestoration`, `Specification` option to `TablesDB.Create`
* Added: `Type` filter option to `Functions.ListSpecifications` and `Sites.ListSpecifications`
* Added: `UpdateOAuth2Appwrite` and `UpdateDenyCorporateEmailPolicy` methods to `Project`
* Added: `Token` option to `Functions.GetDeploymentDownload`, new OAuth2 OIDC/server options
* Added: billing plan, `Organization`, `OAuth2Appwrite`, `Program` response models
* Added: email canonicalization and classification fields to `User` model
* Added: `Type` field to `BackupPolicy` model
* Updated: deprecated `Databases` transaction methods in favor of `TablesDB` equivalents

## v5.1.0

* Added: `createSesProvider` and `updateSesProvider` to `messaging`
* Added: `updateOAuth2Server` to `project` for OAuth2 server settings
* Added: `updatePasswordStrengthPolicy` and `PolicyPasswordStrength` to `project`
* Added: `getAuditsDB` health check to `health`
* Added: `password-strength` to `ProjectPolicyId`
* Added: `apps.read` and `apps.write` to `ProjectKeyScopes`


## v5.0.0

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

## v4.0.0

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

## v3.1.0

* Added: Introduced `bigint` create/update APIs for legacy Databases attributes
* Added: Introduced `bigint` create/update APIs for `TablesDB` columns
* Updated: Extended key-list query filters with `key`, `resourceType`, `resourceId`, and `secret`

## v3.0.0

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

## v2.1.0

* Added `GetHeaders` method to `Client` for retrieving current request headers
* Fixed chunked upload resume handling for responses returned as strings
* Updated Go module path to `v2` for proper Go modules compatibility

## v2.0.0

* [BREAKING] Changed `$sequence` type from `int` to `string` for rows and documents
* Added `NewProject` and `NewWebhooks` client constructors
* Added impersonation helpers `WithImpersonateUserId`, `WithImpersonateUserEmail`, `WithImpersonateUserPhone`
* Added avatar URL helpers: `GetBrowserURL`, `GetCreditCardURL`, `GetFaviconURL`, `GetFlagURL`, `GetImageURL`, `GetInitialsURL`, `GetQRURL`, `GetScreenshotURL`
* Updated README badge to API version 1.9.0

## v1.0.0

* Breaking: Activate parameter was removed from CreateDeployment; use WithCreateDeploymentActivate.
* Breaking: UpdateRelationshipAttribute API path changed and old overload removed.
* Added: GetConsolePausing endpoint to monitor console pausing status.
* Added: TTL option to list operations for documents and rows.
* Updated: Document and Row sequence comments to reflect sequence IDs.

## v0.17.0

* Added new Activities service to the Go SDK with a NewActivities constructor to access Activities endpoints.
* Extended Databases attribute APIs to support encryption: introduced Encrypt option for Longtext, Mediumtext, Text, and Varchar attributes, along with corresponding New/Create option builders (WithCreateLongtextAttributeEncrypt, WithCreateMediumtextAttributeEncrypt, WithCreateTextAttributeEncrypt, WithCreateVarcharAttributeEncrypt) and wiring to send the encrypt parameter when enabled.
* Updated documentation and examples to demonstrate the new encrypt option for attribute creation (e.g., docs/examples/databases/create-longtext-attribute.md, create-mediumtext-attribute.md, create-text-attribute.md, create-varchar-attribute.md).
* Updated README to reflect compatibility with Appwrite server version 1.8.x.
* Add support for the new `Backups` service

## v0.16.1

* Fix doc examples with proper formatting

## v0.16.0

* Added ability to create columns and indexes synchronously while creating a table
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `getQueueAudits` support to `Health` service.
* Add longtext/mediumtext/text/varchar attribute and column helpers to `Databases` and `TablesDB` services.

## v0.15.0

* Rename `VCSDeploymentType` enum to `VCSReferenceType`
* Change `CreateTemplateDeployment` method signature: replace `Version` parameter with `Type` (TemplateReferenceType) and `Reference` parameters
* Add `GetScreenshot` method to `Avatars` service
* Add `Theme`, `Timezone` and `Output` enums

## v0.14.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations
* Add `createResendProvider` and `updateResendProvider` methods to `Messaging` service

## v0.13.1

* Add transaction support for Databases and TablesDB

## v0.12.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service
* Add `orderRandom` query support

## 0.9.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `upsertDocument` support to `Databases` service
* Update doc examples to use correct syntax

## 0.8.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Add doc examples and methods for bulk api transactions: `createDocuments`, `deleteDocuments` etc.
* Add doc examples, class and methods for new `Sites` service
* Add doc examples, class and methods for new `Tokens` service
* Add enums for `BuildRuntime `, `Adapter`, `Framework`, `DeploymentDownloadType` and `VCSDeploymentType`
* Update enum for `runtimes` with Pythonml312, Dart219, Flutter327 and Flutter329
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Add `queries` and `search` params to `listMemberships` method
* Remove `search` param from `listExecutions` method

## 0.7.0

* Version skipped

## 0.6.0

* Add bulk API methods: `createDocuments`, `deleteDocuments` etc.

## 0.5.0

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests

## 0.4.0

* Fix pong response and chunked upload

## 0.3.0

* Add new push message parameters
