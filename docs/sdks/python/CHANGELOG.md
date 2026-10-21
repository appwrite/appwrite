# Change Log

## 24.4.0

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

## 24.3.1

* Fixed: added `ProjectAuthMethodId.PASSKEY`, so `project.updateAuthMethod` can enable passkeys
* Fixed: added `ProjectPolicyId.PASSKEY` and the `PolicyPasskey` model, so `getPolicy` and `listPolicies` return the passkey policy

## 24.3.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `users.listPasskeys`, `users.getPasskey`, `users.updatePasskey` and `users.deletePasskey`
* Added: `project.updatePasskeyPolicy` to configure passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 24.2.0

* Added: optional `interval` parameter on `functions.create` and `functions.update` to run a function every N minutes
* Added: `interval` on the `Function` model and `functionsIntervalMinimum` on the `BillingPlan` model

## 24.1.0

* Added: optional `state` parameter on `createOAuth2Token`, returned unchanged on the success and failure URLs

## 24.0.0

* Stable release of the dedicated database APIs: `mysql`, `postgresql`, `mongo`, `documentsDB`, and `vectorsDB` services, previously released as release candidates
* Breaking: `tablesDB.cutoverMigration` is renamed to `tablesDB.createCutover`
* Breaking: `Execution.functionId` is replaced by `resourceId` and `resourceType`, covering function and site executions
* Added: Cloudflare, Resend, and Hugging Face OAuth providers
* Added: `usageAggregateOnlyMetrics` on the `BillingPlan` model
* Fixed: `transactionId` is accepted again by `documentsDB` and `vectorsDB` `createDocument` and `createDocuments`
* Updated: `X-Appwrite-Response-Format` is now `2.0.0`
* Updated: `FrameworkAdapter.fallbackFile` is now optional

## 24.0.0rc1

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

## 23.0.0

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

## 22.2.0

* Added: `apps` installation and key management methods (`list_installations`, `get_installation`, `create_installation_token`, `list_installation_scopes`, `list_keys`, `create_key`, `get_key`, `delete_key`)
* Added: `installation_scopes` and `installation_redirect_url` parameters to `apps.update`
* Added: app installation management methods to `organization` and `teams` services
* Added: `installation_access_token_duration` parameter to `project.update_o_auth2_server`
* Added: `installationScopes` and `installationRedirectUrl` fields to `App` model
* Added: `organization.installations.read`/`organization.installations.write` key scopes

## 22.1.0

* Updated: removed `new_specification` parameter from `backups.create_restoration`
* Updated: `Database.policies` and `Database.archives` are now optional
* Added: account OAuth2 consent methods `list_consents`, `get_consent`, `delete_consent` and consent token methods
* Added: `Oauth2Consent` and `Oauth2ConsentToken` models
* Added: `oauth2.authorize_post` method and `oauth2.introspect` key scope
* Added: `apps.list_o_auth2_scopes` method and `AppScope` model
* Added: geolocation, connection, and SDK attribution fields on `ActivityEvent`
* Added: `token` parameter to `sites.get_deployment_download`
* Updated: `AppSecret.secret` is always empty; the secret is returned only on creation

## 22.0.0

* Breaking: removed `Health` service and all health models and enums
* Breaking: removed `Usage` service and `UsageMetric`, `UsageDataPoint`, `UsageEventList`, `UsageGaugeList` models
* Breaking: removed messaging `list_message_logs`, `list_provider_logs`, `list_subscriber_logs`, `list_topic_logs` methods
* Breaking: renamed `tables_db.create` parameter `dedicated_database_id` to `specification`
* Breaking: removed `countryCode` and `countryName` from `ActivityEvent` model
* Added: `Client.set_bearer` for OAuth access token authentication
* Added: `organization` service `get`, `update`, `delete` and membership CRUD methods
* Added: `oauth2` service `create_par`, `list_organizations`, `list_projects` methods
* Added: `resource`, `audience`, `request_uri` OAuth2 parameters; `scope` on `approve`
* Added: `appwrite` OAuth provider, `OAuth2Appwrite` model, `update_o_auth2_appwrite` method
* Added: `Query.vector_dot`, `Query.vector_cosine`, `Query.vector_euclidean` methods
* Added: `apps.update_labels` method and `labels` field on `App` model
* Added: `new_specification` parameter to `backups.create_restoration`
* Added: `type` parameter to `functions` and `sites` `list_specifications`
* Added: `token` parameter to `functions.get_deployment_download`
* Added: `prompt` and `max_age` parameters to `update_o_auth2_oidc`; `default_scopes` to `update_o_auth2_server`
* Added: `BillingPlan`, `Organization`, `Program`, `Oauth2PAR`, `DatabaseStatus`, `BillingPlanGroup` models and enums
* Added: `status` on `Database`, `mode` on `Block`, `onboarding` on `Project`, geolocation fields on `Locale`
* Added: organization, `project.oauth2`, and `stages` key scope enum values
* Fixed: booleans now serialized as `true`/`false` in flattened query parameters
* Fixed: `model_dump` on `Document`, `Row`, `Preferences` now includes dynamic `data`

## 21.0.0

* Added: `apps` service for managing apps and app secrets
* Added: `oauth2` service for the OAuth2 authorization, device, and token flows
* Added: device authorization grant parameters (`verificationUrl`, `userCodeLength`, `userCodeFormat`, `deviceCodeDuration`) to `updateOAuth2Server`
* Added: `emailCanonical`, `emailIsFree`, `emailIsDisposable`, `emailIsCorporate`, and `emailIsCanonical` to the `User` model
* Added: `userAccessedAt` to the `Membership` model
* Added: `PolicyDenyCorporateEmail` and `deny-corporate-email` to `ProjectPolicyId`
* Added: `dedicatedDatabases.execute` to `ProjectKeyScopes`
* Breaking: `usage.listEvents` now takes a required `metrics` array with `resource`, `interval`, and `dimensions` instead of `queries` and `total`
* Breaking: Replaced `UsageEvent` and `UsageGauge` models with `UsageDataPoint` and `UsageMetric`
* Updated: Send an `Accept: application/json` header on all requests


## 20.1.0

* Added: `createSesProvider` and `updateSesProvider` to `messaging`
* Added: `updateOAuth2Server` to `project` for OAuth2 server settings
* Added: `updatePasswordStrengthPolicy` and `PolicyPasswordStrength` to `project`
* Added: `getAuditsDB` health check to `health`
* Added: `password-strength` to `ProjectPolicyId`
* Added: `apps.read` and `apps.write` to `ProjectKeyScopes`


## 20.0.0

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

## 19.0.0

* Breaking: Renamed `AuthMethod` enum to `ProjectAuthMethodId`
* Breaking: Renamed `EmailTemplateType` to `ProjectEmailTemplateId` and `EmailTemplateLocale` to `ProjectEmailTemplateLocale`
* Breaking: Renamed `ServiceId` to `ProjectServiceId`, `ProtocolId` to `ProjectProtocolId`, `Secure` to `ProjectSMTPSecure`, `ProjectPolicy` to `ProjectPolicyId`
* Breaking: Replaced `Scopes` enum with `ProjectKeyScopes` for project key endpoints
* Breaking: Removed `update_deny_canonical_email_policy`; replaced with `update_deny_aliased_email_policy`, `update_deny_disposable_email_policy`, and `update_deny_free_email_policy`
* Breaking: Removed `AuthProvider` model; use new `ProjectOAuthProviderId` enum instead
* Added: `Project.get` method to fetch current project details
* Added: `Advisor`, `Presences`, and `Usage` services
* Added: `Insight`, `Presence`, `Report`, `UsageEvent`, and `UsageGauge` models with list variants
* Added: `ProjectAuthMethod`, `ProjectProtocol`, and `ProjectService` models
* Added: `ProjectOAuthProviderId` and `ProjectOAuth2GooglePrompt` enums
* Updated: `Project`, `Database`, and `OAuth2Google` model schemas
* Updated: `X-Appwrite-Response-Format` header to `1.9.5`

## 18.1.0

* Added: Introduced `bigint` create/update APIs for legacy Databases attributes
* Added: Introduced `bigint` create/update APIs for `TablesDB` columns
* Updated: Extended key-list query filters with `key`, `resourceType`, `resourceId`, and `secret`

## 18.0.0

* [BREAKING] Renamed Webhook model fields: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`, `signatureKey` → `secret`
* [BREAKING] Renamed Webhook service parameters to match: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`
* [BREAKING] Renamed `Webhooks.update_signature()` to `Webhooks.update_secret()` with new optional `secret` parameter
* Added `Client.get_headers()` method to retrieve request headers
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
* Simplified `_parse_response()` by removing `union_models` parameter

## 17.0.0

* [BREAKING] Changed `$sequence` type from `float` to `str` for `Row` and `Document` models
* [BREAKING] Renamed `IndexType` enum: split into `DatabasesIndexType` (with new `SPATIAL` value) and `TablesDBIndexType`
* [BREAKING] Replaced `specification` parameter with `build_specification` and `runtime_specification` in `functions.create()`, `functions.update()`, `sites.create()`, `sites.update()`
* Added new `Project` service with full CRUD for project-level environment variables
* Added new `Webhooks` service with full CRUD for project webhooks (including `update_signature`)
* Added `Webhook` and `WebhookList` models
* Added `users.update_impersonator()` method for enabling/disabling user impersonation
* Added impersonation support: `set_impersonate_user_id()`, `set_impersonate_user_email()`, `set_impersonate_user_phone()` on `Client`
* Added `impersonator` and `impersonatoruserid` optional fields to `User` model
* Added `deployment_retention` parameter to Functions and Sites create/update
* Added `start_command` parameter to Sites create/update
* Added `Documentsdb`, `Vectorsdb` values to `BackupServices` and `DatabaseType` enums
* Added `WebhooksRead`, `WebhooksWrite`, `ProjectRead`, `ProjectWrite` scopes
* Removed `get_queue_billing_project_aggregation`, `get_queue_billing_team_aggregation`, `get_queue_priority_builds`, `get_queue_region_manager`, `get_queue_threats` from `Health` service
* Updated `Log` model field descriptions to clarify impersonation behavior
* Updated `X-Appwrite-Response-Format` header to `1.9.0`

## 16.0.0

* Breaking change: All service methods now return typed Pydantic models instead of `Dict[str, Any]`
* Breaking change: Models with dynamic fields (e.g., `Row`, `Document`) now store user-defined data in a typed `.data` property instead of direct attribute access
* Breaking change: Added `pydantic>=2,<3` as a required dependency
* Breaking change: Minimum Python version raised from 3.5 to 3.9
* Added `AppwriteModel` base class (Pydantic `BaseModel`) for all response models with `from_dict()` and `to_dict()` helpers
* Added 130+ typed model classes under `appwrite/models/` (e.g., `Database`, `Collection`, `Document`, `User`, `Session`, `File`, `Bucket`, etc.)
* Added Generic[T] support for models with dynamic fields (e.g., `Row`, `Document`) - pass `model_type=YourModel` to methods like `get_row()` or `list_rows()` for type-safe access to user-defined data via `result.data.field_name`
* Updated README with `uv add appwrite` installation example
* Updated all doc examples to use typed response models (e.g., `result: TemplateFunctionList = functions.list_templates(...)`)

## 15.3.0

* Added get_console_pausing health endpoint
* Added ttl parameter to list_documents and list_rows for cached responses
* Added optional activate parameter to Sites.create_deployment
* Updated docs and examples to reflect TTL usage and activation
* Updated query filtering docs in Messaging service

## 15.2.0

* Extended BuildRuntime and Runtime enums with new runtime versions (e.g., node-23/24/25, php-8.4, ruby-3.4/4.0, python-3.13/3.14, python-ml-3.13, deno-2.5/2.6, dotnet-10, java-25, swift-6.2, kotlin-2.3, bun-1.2/1.3, go-1.24/1.25/1.26).
* Added new permission scopes: schedules.read, schedules.write, and events.read.
* Added contains_any and contains_all filter helpers to the Query API (plus enhanced contains with docstrings).
* Added optional encrypt parameter to all string attribute creation methods (e.g., longtext, mediumtext, text, varchar) in Databases to support encryption at rest.
* Updated README to reflect Appwrite server compatibility version 1.8.x.
* Backward-compatible enhancements: no breaking changes.

## 15.1.0

* Add `dart-3.10` and `flutter-3.38` to `BuildRuntime` and `Runtime` enums
* Fix `Roles` enum removed from Teams service; `roles` parameter now accepts `list[str]`
* Add support for the new `Backups` service

## 15.0.0

* Add array-based enum parameters (e.g., `permissions: list[BrowserPermission]`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `getQueueAudits` support to `Health` service.
* Add longtext/mediumtext/text/varchar attribute and column helpers to `Databases` and `TablesDB` services.

## 14.1.0

* Added ability to create columns and indexes synchronously while creating a table

## 14.0.0

* Rename `VCSDeploymentType` enum to `VCSReferenceType`
* Change `create_template_deployment` method signature: replace `version` parameter with `type` (TemplateReferenceType) and `reference` parameters
* Add `get_screenshot` method to `Avatars` service
* Add `Theme`, `Timezone` and `Output` enums
* Add support for dart39 and flutter335 runtimes

## 13.6.1

* Fix passing of `None` to nullable parameters

## 13.6.0

* Add `total` parameter to list queries allowing skipping counting rows in a table for improved performance
* Add `Operator` class for atomic modification of rows via update, bulk update, upsert, and bulk upsert operations

## 13.5.0

* Add `create_resend_provider` and `update_resend_provider` methods to `Messaging` service
* Improve deprecation warnings
* Fix adding `Optional[]` to optional parameters
* Fix passing of `None` to nullable parameters

## 13.4.1

* Add transaction support for Databases and TablesDB

## 13.3.0

* Deprecate `createVerification` method in `Account` service
* Add `createEmailVerification` method in `Account` service

## 11.1.0

* Add `incrementDocumentAttribute` and `decrementDocumentAttribute` support to `Databases` service
* Add `dart38` and `flutter332` support to runtime models
* Add `gif` support to `ImageFormat` enum
* Add `upsertDocument` support to `Databases` service

## 11.0.0

* Add `<REGION>` to doc examples due to the new multi region endpoints
* Add doc examples and methods for bulk api transactions: `createDocuments`, `deleteDocuments` etc.
* Add doc examples, class and methods for new `Sites` service
* Add doc examples, class and methods for new `Tokens` service
* Add enums for `BuildRuntime `, `Adapter`, `Framework`, `DeploymentDownloadType` and `VCSDeploymentType`
* Update enum for `runtimes` with Pythonml312, Dart219, Flutter327 and Flutter329
* Add `token` param to `getFilePreview` and `getFileView` for File tokens usage
* Add `queries` and `search` params to `listMemberships` method
* Remove `search` param from `listExecutions` method

## 10.0.0

* Fix requests failing by removing `Content-Type` header from `GET` and `HEAD` requests

## 9.0.3

* Update sdk to use Numpy-style docstrings
