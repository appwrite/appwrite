# Change Log

## 28.4.0

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
* Fixed: OAuth2 provider models with an array `prompt` no longer raise `TypeError`

## 28.3.2

* Fixed: `Project` responses no longer raise `ArgumentError` on the `oauth2` service

## 28.3.1

* Fixed: added `ProjectAuthMethodId::PASSKEY`, so `project.updateAuthMethod` can enable passkeys
* Fixed: added `ProjectPolicyId::PASSKEY` and the `PolicyPasskey` model, so `getPolicy` and `listPolicies` return the passkey policy

## 28.3.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `users.listPasskeys`, `users.getPasskey`, `users.updatePasskey` and `users.deletePasskey`
* Added: `project.updatePasskeyPolicy` to configure passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 28.2.0

* Added: optional `interval` parameter on `functions.create` and `functions.update` to run a function every N minutes
* Added: `interval` on the `Function` model and `functionsIntervalMinimum` on the `BillingPlan` model

## 28.1.0

* Added: optional `state` parameter on `createOAuth2Token`, returned unchanged on the success and failure URLs

## 28.0.0

* Stable release of the dedicated database APIs: `mysql`, `postgresql`, `mongo`, `documentsDB`, and `vectorsDB` services, previously released as release candidates
* Breaking: `tablesDB.cutoverMigration` is renamed to `tablesDB.createCutover`
* Breaking: `Execution.functionId` is replaced by `resourceId` and `resourceType`, covering function and site executions
* Added: Cloudflare, Resend, and Hugging Face OAuth providers
* Added: `usageAggregateOnlyMetrics` on the `BillingPlan` model
* Fixed: `transactionId` is accepted again by `documentsDB` and `vectorsDB` `createDocument` and `createDocuments`
* Updated: `X-Appwrite-Response-Format` is now `2.0.0`
* Updated: `FrameworkAdapter.fallbackFile` is now optional

## 28.0.0.rc1

* Breaking: `Execution.functionId` is replaced by `resourceId` and `resourceType`, now that executions cover both functions and sites
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

## 27.0.0

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

## 26.1.0

* Added: `Apps` service for managing OAuth2 applications, keys, and installations
* Added: `OAuth2` service with authorize, grant, device authorization, and consent flows
* Added: account OAuth2 consent methods `list_consents`, `get_consent`, `delete_consent`, and consent token methods
* Added: app installation management methods to `Organization` and `Teams` services
* Added: `installation_access_token_duration` parameter to `project.update_o_auth2_server`
* Added: `token` parameter to `sites.get_deployment_download`
* Added: `oauth2.introspect` and organization installation key scopes

## 26.0.0

* Breaking: Removed `Health` service and all health models and enums
* Breaking: Removed `Usage` service and `UsageEvent`/`UsageGauge` models
* Breaking: Removed `Messaging` log methods `list_message_logs`, `list_provider_logs`, `list_subscriber_logs`, `list_topic_logs`
* Breaking: Removed OS, client, and device fields plus `countryCode`/`countryName` from `ActivityEvent` (`country` remains)
* Added: `Client#set_bearer` for OAuth access token authentication
* Added: `Organization` `get`, `update`, `delete`, and membership CRUD methods
* Added: `Query.vector_dot`, `Query.vector_cosine`, `Query.vector_euclidean` query helpers
* Added: `Project#update_o_auth2_appwrite` and `Project#update_deny_corporate_email_policy` methods
* Added: OAuth2 server device-flow params (`verification_url`, `user_code_length`, `device_code_duration`, `default_scopes`)
* Added: optional params `new_specification` (restorations), `token` (deployment download), `type` (list specifications), `specification` (TablesDB create)
* Added: `Organization`, `BillingPlan`, `AdditionalResource`, `Program`, `OAuth2Appwrite`, `PolicyDenyCorporateEmail` models
* Added: `DatabaseStatus`, `BillingPlanGroup`, `ProjectOAuth2OidcPrompt` enums and `APPWRITE` OAuth provider
* Added: `Locale` geolocation/ISP fields, `Membership#user_accessed_at`, `Block#mode`, `BackupPolicy#type`, `Database#status`
* Added: `User` email classification fields (`email_is_free`, `email_is_disposable`, `email_is_corporate`)
* Added: new key scopes (`stages.*`, `project.oauth2.*`, `organization.*`, `dedicatedDatabases.execute`)

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
* Breaking: Removed `Project#update_deny_canonical_email_policy`; replaced with `update_deny_aliased_email_policy`, `update_deny_disposable_email_policy`, and `update_deny_free_email_policy`
* Breaking: Removed `AuthProvider` model; use new `ProjectOAuthProviderId` enum instead
* Added: `Project#get` method to fetch current project details
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
* [BREAKING] Renamed `Webhooks#update_signature` to `Webhooks#update_secret` with new optional `secret` parameter
* Added `Client#get_headers` method to retrieve request headers
* Added `secret` parameter to Webhook create and update methods
* Added `x` OAuth provider to `OAuthProvider` enum
* Added `userType` field to `Log` model
* Added `purge` parameter to `update_collection` and `update_table` for cache invalidation
* Added Project service: platform CRUD, key CRUD, protocol/service status management
* Added new models: `Key`, `KeyList`, `Project`, `DevKey`, `MockNumber`, `AuthProvider`, `PlatformAndroid`, `PlatformApple`, `PlatformLinux`, `PlatformList`, `PlatformWeb`, `PlatformWindows`, `BillingLimits`, `Block`
* Added new enums: `PlatformType`, `ProtocolId`, `ServiceId`
* Updated `BuildRuntime`, `Runtime` enums with `dart-3.11` and `flutter-3.41`
* Updated `Scopes` enum with `keys_read`, `keys_write`, `platforms_read`, `platforms_write`
* Updated `X-Appwrite-Response-Format` header to `1.9.1`
* Updated TTL description for list caching in Databases and TablesDB

## 22.0.0

* [BREAKING] Changed `$sequence` type from `int` to `string` for rows and documents
* [BREAKING] Renamed `IndexType` enum: split into `DatabasesIndexType` (for Databases) and `TablesDBIndexType` (for TablesDB)
* [BREAKING] Replaced `specification` parameter with `build_specification` and `runtime_specification` in `Functions#create`, `Functions#update`, `Sites#create`, `Sites#update`
* Added new `Project` service with full CRUD for project-level environment variables
* Added new `Webhooks` service with full CRUD for project webhooks (including `update_signature`)
* Added `Webhook` and `WebhookList` models
* Added `Users#update_impersonator` method for enabling/disabling user impersonation
* Added impersonation support: `set_impersonate_user_id`, `set_impersonate_user_email`, `set_impersonate_user_phone` on `Client`
* Added `impersonator` and `impersonator_user_id` fields to `User` model
* Added `deployment_retention` parameter to Functions and Sites create/update
* Added `start_command` parameter to Sites create/update
* Added `Documentsdb`, `Vectorsdb` values to `BackupServices` and `DatabaseType` enums
* Added `WebhooksRead`, `WebhooksWrite`, `ProjectRead`, `ProjectWrite` scopes
* Removed `get_queue_billing_project_aggregation`, `get_queue_billing_team_aggregation`, `get_queue_priority_builds`, `get_queue_region_manager`, `get_queue_threats` from `Health` service
* Updated `Log` model field descriptions to clarify impersonation behavior
* Updated `X-Appwrite-Response-Format` header to `1.9.0`
* Updated README badge to API version `1.9.0` and compatibility to server version `1.9.x`

## 21.1.0

* Added get_console_pausing health endpoint to monitor console pausing.
* Added ttl parameter for cached responses in list_documents and list_rows.
* Made activate parameter optional in Sites.create_deployment.
* Updated collection_id doc to reference collection instead of table.
* Added update_relationship_attribute API to update relationship attributes.
* Updated team roles length limit docstrings from 32 to 81 chars.
* Updated subscriber list docs to reflect new query filters.
* Added Ruby example for health get_console_pausing.

## 21.0.1

* Remove obsolete GitHub issue templates (bug.yaml, documentation.yaml, feature.yaml) to simplify repository maintenance.
* Remove CI workflows for auto-closing external PRs (autoclose.yml) and publishing to RubyGems (publish.yml).
* Cleanup of documentation examples: several outdated docs under docs/examples/health, docs/examples/tablesdb, and docs/examples/databases have been deleted.
* Documentation improvements: updated several create_* attribute example blocks to include encrypt: false where applicable (e.g., longtext/mediumtext/text/varchar attribute examples and related columns).
* Note: This release aligns the gemspec/versioning for a patch release (bump to 21.0.1 in a real patch) to reflect these internal maintenance changes.
* Add support for the new `Backups` service

## 21.0.0

* Add array-based enum parameters (e.g., `permissions: Array<BrowserPermission>`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `getQueueAudits` support to `Health` service.
* Add longtext/mediumtext/text/varchar attribute and column helpers to `Databases` and `TablesDB` services.

## 20.1.0

* Added ability to create columns and indexes synchronously while creating a table

## 20.0.0

* Rename `VCSDeploymentType` enum to `VCSReferenceType`
* Change `create_template_deployment` method signature: replace `version` parameter with `type` (TemplateReferenceType) and `reference` parameters
* Add `get_screenshot` method to `Avatars` service
* Add `Theme`, `Timezone` and `Output` enums

## 19.3.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations
* Add `create_resend_provider` and `update_resend_provider` methods to `Messaging` service

## 19.2.1

* Add transaction support for Databases and TablesDB

## 19.1.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 16.1.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `dart38` and `flutter332` support to runtime models
* Add `gif` support to `ImageFormat` enum
* Add `encrypt` support to `StringAttribute` model
* Add `sequence` support to `Document` model
* Add `upsertDocument` support to `Databases` service

## 16.0.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Add doc examples and methods for bulk api transactions: `createDocuments`, `deleteDocuments` etc.
* Add doc examples, class and methods for new `Sites` service
* Add doc examples, class and methods for new `Tokens` service
* Add enums for `BuildRuntime `, `Adapter`, `Framework`, `DeploymentDownloadType` and `VCSDeploymentType`
* Update enum for `runtimes` with Pythonml312, Dart219, Flutter327 and Flutter329
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Add `queries` and `search` params to `listMemberships` method
* Remove `search` param from `listExecutions` method

## 15.0.0

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests

## 14.0.0

* Fix pong response & chunked upload
