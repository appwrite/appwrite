# Change Log

## 0.19.0

* Breaking: new optional parameters are appended to existing methods (e.g. `delete_sessions`, the `total` lists, OAuth2 `prompt`); new enum variants and model fields break exhaustive matches and struct literals
* Added: `domains` service to manage domains, DNS records, email presets, prices, transfer status and zones
* Added: `waf` service with bypass, challenge, deny, rate-limit and redirect rules
* Added: `account.create_email_verification_otp`, `update_email_verification_otp`, `create_recovery_otp` and `update_recovery_otp`
* Added: `account.create_id_token_session` and `Identity.provider_id_token`
* Added: `oauth2.introspect`
* Added: project environments with `project.create_environment`, `get_environment`, `list_environments`, `update_environment` and `delete_environment`
* Added: `organization.create_ephemeral_project_key`, `list_project_keys`, `get_project_key`, `update_project_key` and `delete_project_key`
* Added: Kakao, TikTok and Webflow OAuth2 providers with `project.update_o_auth2_kakao`, `update_o_auth2_tik_tok` and `update_o_auth2_webflow`
* Added: optional `prompt` on the Auth0, Discord, GitHub, Microsoft, Okta, Salesforce and Zoho OAuth2 settings, and native client IDs on Apple and Google
* Added: `project.update_password_pwned_policy`, `project.update_password_rotation_policy` and `User.password_pwned`
* Added: `messaging.create_appwrite_provider` and `messaging.update_appwrite_provider`
* Added: optional `reply_to_email` and `reply_to_name` on email messages, `channel_id` on push messages, and `qos` and `expiry` on topics
* Added: `avatars.update_photo` and `avatars.delete_photo`
* Added: optional `duration` on `account.create_email_password_session`, `current` on `account.delete_sessions`, `membership_id` on `teams.create_membership` and `fingerprints` on Android platforms
* Added: optional `total` on the locale, transaction, runtime, specification and framework lists
* Added: `dart-3.13` and `flutter-3.47` runtimes, the `jaspr` framework, the `trigram` index type, `auto` image gravity, the `dedicatedDatabase` proxy rule resource type, OTP email templates and new key scopes
* Added: optional fields on `BillingPlan`, `DedicatedDatabase`, `DedicatedDatabaseSpecification`, `Project` and `Topic`, and `free` and `start` in `BillingPlanGroup`

## 0.18.0

* Breaking: `ProjectAuthMethodId` and `ProjectPolicyId` gain a `Passkey` variant; exhaustive `match` expressions need a new arm
* Added: `ProjectAuthMethodId::Passkey`, so `project.update_auth_method` can enable passkeys
* Added: `ProjectPolicyId::Passkey` and the `PolicyPasskey` model, so `get_policy` and `list_policies` return the passkey policy

## 0.17.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `users.listPasskeys`, `users.getPasskey`, `users.updatePasskey` and `users.deletePasskey`
* Added: `project.updatePasskeyPolicy` to configure passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 0.16.0

* Breaking: `functions.create` and `functions.update` take a new optional `interval` argument; pass `None` to keep the current behaviour
* Added: `interval` on the `Function` model and `functions_interval_minimum` on the `BillingPlan` model

## 0.15.0

* Breaking: `create_o_auth2_token` takes a new optional `state` argument, returned unchanged on the success and failure URLs; pass `None` to keep the current behaviour

## 0.14.0

* Stable release of the dedicated database APIs: `mysql`, `postgresql`, `mongo`, `documentsDB`, and `vectorsDB` services, previously released as release candidates
* Breaking: `tablesDB.cutoverMigration` is renamed to `tablesDB.createCutover`
* Breaking: `Execution.functionId` is replaced by `resourceId` and `resourceType`, covering function and site executions
* Breaking: `mysql.updateCredentials`, `postgresql.updateCredentials`, and `mongo.updateCredentials` now return `DedicatedDatabaseOperation`; poll the operation to a terminal status, then fetch the database for the refreshed credentials
* Added: Cloudflare, Resend, and Hugging Face OAuth providers
* Added: `usageAggregateOnlyMetrics` on the `BillingPlan` model
* Added: `credentialGeneration` on the `DedicatedDatabase` model
* Added: `documentsdb.indexes.read`, `documentsdb.indexes.write`, `vectorsdb.indexes.read`, and `vectorsdb.indexes.write` to `ProjectKeyScopes`
* Fixed: `transactionId` is accepted again by `documentsDB` and `vectorsDB` `createDocument` and `createDocuments`
* Updated: `X-Appwrite-Response-Format` is now `2.0.0`
* Updated: `FrameworkAdapter.fallbackFile` is now optional

## 0.13.0

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

## 0.12.0

* Breaking: added `token` parameter to `sites.get_deployment_download`
* Added: `Apps` service for managing OAuth2 applications, keys, and installations
* Added: `OAuth2` service with authorize, grant, device authorization, and consent flows
* Added: account OAuth2 consent methods `list_consents`, `get_consent`, `delete_consent`, and consent token methods
* Added: app installation management methods to `Organization` and `Teams` services
* Added: `installation_access_token_duration` parameter to `project.update_o_auth2_server`
* Added: `oauth2.introspect` and organization installation key scopes

## 0.11.0

* Breaking: removed `Health` service and all `Health*` models and enums
* Breaking: removed `Usage` service (`list_events`, `list_gauges`) and `Usage*` list/metric models
* Breaking: removed `Notifications` service, `Notification`, and `NotificationList` models
* Breaking: removed messaging `list_message_logs`, `list_provider_logs`, `list_subscriber_logs`, `list_topic_logs`
* Breaking: renamed `tables_db.create()` parameter `dedicated_database_id` to `specification`
* Breaking: added `new_specification` parameter to `backups.create_restoration()`
* Breaking: added `token` parameter to `functions.get_deployment_download()`
* Breaking: added `prompt` and `max_age` parameters to `project.update_o_auth2_oidc()`
* Breaking: added `default_scopes` parameter to `project.update_o_auth2_server()`
* Breaking: added required `mode` field to `Block` model
* Added: `Organization` service `get`, `update`, `delete`, and membership management methods
* Added: `update_o_auth2_appwrite()` method, `OAuth2Appwrite` model, and `Appwrite` OAuth provider
* Added: client setters `set_bearer`, `set_dev_key`, `set_cookie`, `set_forwarded_user_agent`, and impersonation headers
* Added: `Query::vector_dot`, `Query::vector_cosine`, `Query::vector_euclidean` vector search methods
* Added: `BillingPlan` model family, `Program`, `AdditionalResource`, `UsageBillingPlan` models
* Added: `DatabaseStatus`, `BillingPlanGroup`, `OAuth2OidcPrompt`, `ProjectOAuth2OidcPrompt` enums
* Added: optional `status` field on `Database` model
* Added: geolocation, timezone, ASN, and ISP fields on `Locale` model
* Added: organization and `project.oauth2` key scopes to key scope enums
* Added: `oAuth2ServerDefaultScopes` field on `Project` model

## 0.10.0

* Breaking: Reworked `listEvents` and `listGauges` in `usage` with a required `metrics` param and new `interval`, `dimensions`, date-range, and ordering options
* Breaking: Replaced `UsageEvent` and `UsageGauge` models with `UsageDataPoint` and `UsageMetric`
* Added: `Notifications` service with `Notification` and `NotificationList` models
* Added: `getQueueNotifications` health check to `health`
* Added: `updateDenyCorporateEmailPolicy` and `PolicyDenyCorporateEmail` to `project`
* Updated: All requests now send an `Accept: application/json` header

## 0.9.0

* Added: `createSesProvider` and `updateSesProvider` to `messaging`
* Added: `updateOAuth2Server` to `project` for OAuth2 server settings
* Added: `updatePasswordStrengthPolicy` and `PolicyPasswordStrength` to `project`
* Added: `getAuditsDB` health check to `health`
* Added: `password-strength` to `ProjectPolicyId`
* Added: `apps.read` and `apps.write` to `ProjectKeyScopes`

## 0.8.0

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

## 0.7.0

* Breaking: Changed `BillingLimits` fields to `Option<i64>` from required `i64`
* Breaking: Changed `Project.billing_limits` field to `Option<BillingLimits>` from required
* Added `size_actual` field to `File` model
* Added `Deno121`, `Deno124`, and `Deno135` runtime support
* Updated advisor examples to use API key authentication

## 0.6.0

* Breaking: Renamed `AuthMethod` enum to `ProjectAuthMethodId`
* Breaking: Renamed `EmailTemplateType` to `ProjectEmailTemplateId` and `EmailTemplateLocale` to `ProjectEmailTemplateLocale`
* Breaking: Renamed `ServiceId` to `ProjectServiceId`, `ProtocolId` to `ProjectProtocolId`, `Secure` to `ProjectSMTPSecure`, `ProjectPolicy` to `ProjectPolicyId`
* Breaking: Replaced `Scopes` enum with `ProjectKeyScopes` for project key endpoints
* Breaking: Removed `Project::update_deny_canonical_email_policy`; replaced with `update_deny_aliased_email_policy`, `update_deny_disposable_email_policy`, and `update_deny_free_email_policy`
* Breaking: Removed `AuthProvider` model; use new `ProjectOAuthProviderId` enum instead
* Added: `Project::get` method to fetch current project details
* Added: `Advisor`, `Presences`, and `Usage` services
* Added: `Insight`, `Presence`, `Report`, `UsageEvent`, and `UsageGauge` models with list variants
* Added: `ProjectAuthMethod`, `ProjectProtocol`, and `ProjectService` models
* Added: `ProjectOAuthProviderId` and `ProjectOAuth2GooglePrompt` enums
* Updated: `Project`, `Database`, and `OAuth2Google` model schemas
* Updated: `X-Appwrite-Response-Format` header to `1.9.5`

## 0.5.0

* Added: Introduced `bigint` create/update APIs for legacy Databases attributes
* Added: Introduced `bigint` create/update APIs for `TablesDB` columns
* Updated: Extended key-list query filters with `key`, `resourceType`, `resourceId`, and `secret`

## 0.4.0

* Breaking: Renamed `Project::update_protocol_status` to `Project::update_protocol`
* Breaking: Renamed `Project::update_service_status` to `Project::update_service`
* Breaking: Renamed `Project` model field `smtp_reply_to` to `smtp_reply_to_email`
* Added password, session and membership policy methods: `update_membership_privacy_policy`, `update_password_dictionary_policy`, `update_password_history_policy`, `update_password_personal_data_policy`, `update_session_alert_policy`, `update_session_duration_policy`, `update_session_invalidation_policy`, `update_session_limit_policy`, `update_user_limit_policy`
* Added `Project::update_smtp` and `Project::create_smtp_test`
* Added email template methods: `update_email_template`, `get_email_template`
* Added `EmailTemplate` model and `EmailTemplateLocale`, `EmailTemplateType`, `Secure` enums
* Added `auth_memberships_user_id`, `auth_memberships_user_phone`, `smtp_reply_to_name` fields to `Project` model
* Added `user_phone` field to `Membership` model
* Added `templates.read` and `templates.write` to `Scopes` enum
* Added `deno-1.21`, `deno-1.24`, `deno-1.35` to `Runtime` and `BuildRuntime` enums
* Updated `X-Appwrite-Response-Format` header to `1.9.2`
* Updated `Cargo.toml` to constrain `url` to `>=2.5.4, <2.6` and add `idna` and `idna_adapter`
* Fixed examples to wrap optional parameters in `Some(...)`

## 0.3.0

* [BREAKING] Renamed Webhook model fields: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`, `signatureKey` → `secret`
* [BREAKING] Renamed Webhook service parameters to match: `security` → `tls`, `httpUser` → `authUsername`, `httpPass` → `authPassword`
* [BREAKING] Renamed `Webhooks::update_signature()` to `Webhooks::update_secret()` with new optional `secret` parameter
* Added `Client::get_headers()` method to retrieve request headers
* Added `secret` parameter to Webhook create and update methods
* Added `x` OAuth provider to `OAuthProvider` enum
* Added `userType` field to `Log` model
* Added `purge` parameter to `update_collection` and `update_table` for cache invalidation
* Added Project service: platform CRUD, key CRUD, protocol/service status management
* Added new models: `Key`, `KeyList`, `Project`, `DevKey`, `MockNumber`, `AuthProvider`, `PlatformAndroid`, `PlatformApple`, `PlatformLinux`, `PlatformList`, `PlatformWeb`, `PlatformWindows`, `BillingLimits`, `Block`
* Added new enums: `PlatformType`, `ProtocolId`, `ServiceId`
* Updated `BuildRuntime`, `Runtime` enums with `dart-3.11` and `flutter-3.41`
* Updated `Scopes` enum with `keysRead`, `keysWrite`, `platformsRead`, `platformsWrite`
* Updated `X-Appwrite-Response-Format` header to `1.9.1`
* Updated TTL description for list caching in Databases and TablesDB
* Updated `Cargo.toml` dependencies with pinned versions and added `security-framework` for Apple platforms

## 0.2.0

* Added Project service with 5 methods
* Updated Health service from 29 to 24 methods
* Added new enums: BackupServices, BuildRuntime, DatabaseType, DatabasesIndexType, Runtime, Scopes, TablesDbIndexType, TemplateReferenceType
* Added new models: AttributeList, ColumnList, Table
* Updated Collection model
* Added new services: Backups, TablesDB

## 0.1.0

* feat: Rust SDK update for version 0.1.0 by @ChiragAgg5k in https://github.com/appwrite/sdk-for-rust/pull/6
## New Contributors
* @ChiragAgg5k made their first contribution in https://github.com/appwrite/sdk-for-rust/pull/6
