# Change Log

## 22.0.0

* Breaking: `Alert.critical` and the `AlertSeverity` enum are removed; `Alert.severity` uses `MonitorSeverity`
* Breaking: `Alert.metric` and `Alert.comparator` are null for a probe monitor's alert
* Added: `monitors` service to create, list, get, update and delete HTTP, DNS, TCP, SMTP and usage monitors
* Added: `alerts` service to list and get alerts, and acknowledge them with `alerts.update`
* Added: `organization.listEscalations`, `createEscalation`, `getEscalation`, `updateEscalation` and `deleteEscalation`
* Added: `organizations.listMonitors`, `getMonitor`, `listAlerts` and `getAlert`
* Added: `projectId`, `escalationId`, `escalationStep`, `escalationRound`, `escalatesAt`, `latency` and `result` on `Alert`
* Added: `Monitor`, `Escalation` and `EscalationStep` models, their list models, and the `Monitor*` enums
* Added: `http`, `dns`, `tcp` and `smtp` on `MonitorType`, and analytics metrics on `AlertMetric`

## 21.2.0

* Added: `account.createLDAPSession` for LDAP sign-in
* Added: `project.updateLDAP` and `project.createLDAPTest`, the `ProjectLDAPEncryption` enum and `ldap*` fields on `Project`
* Added: `project.updateOAuth2Naver`, the `OAuth2Naver` model and `OAuthProvider.Naver`
* Added: `project.deletePasswords` to force every user to reset their password
* Added: `sites.updateAnalytics` and `analyticsEnabled` and `analyticsPropertyId` on `Site`
* Added: `organizations.updateAlert`, the `Alert` model and the `AlertStatus`, `AlertSeverity`, `AlertMetric` and `AlertComparator` enums
* Added: `reason` on `DomainPrice`, set when a domain was not priced for the requested registration type
* Added: `ImageFormat.Auto` on `storage.getFilePreview` and the avatar endpoints, serving avif or webp when the `Accept` header supports it
* Updated: requests send `X-Appwrite-Response-Format: 2.7.2`

## 21.1.0

* Added: typed saved `Invoice.items` with signed amounts, attribution and service periods.
* Added: account device creation, lookup, session switching and deletion methods.
* Added: account-device project policy and Vipps sandbox configuration methods.
* Added: `Session.userName`, `ProxyRule.expireAt` and `ProxyRule.certificateStatus`.
* Updated: response format to `2.7.1`.

## 21.0.0

* Breaking: requests send `X-Appwrite-Response-Format: 2.7.0`; responses follow the 2.7 shapes
* Breaking: renamed the `Review` model to `CampaignReview`, as typed on `Campaign.reviews`
* Breaking: `MigrationReport` keys now match the API's kebab case, such as `apikey` to `'api-key'`
* Added: `membership` and `'project-preferences'` counts on `MigrationReport`
* Added: optional `schedule` on every `waf` create and update rule method, and `schedule` on the `WafRule*` models
* Added: `organization` project transfers: `createProjectTransfer`, `getProjectTransfer`, `listProjectTransfers`, `updateProjectTransferStatus`, `deleteProjectTransfer`
* Added: `project.getPrefs`, `project.updatePrefs`, `project.deleteSessions`, `project.updateDenyRealtimeGuestsPolicy` and `project.updateOAuth2Vipps`
* Added: `proxy.updateFunctionRule`, `proxy.updateSiteRule` and `proxy.updateRedirectRule`
* Added: `admin` methods for credits, invoices, payments, blocks, aggregations and organizations
* Added: `console.updateSources` and `Client.setEnvironment()`
* Added: `stripMetadata` on `storage.createBucket` and `storage.updateBucket`
* Added: analytics property `type` and `identifyUsers`, event `interactive`, and `propKey` on `analytics.listMetrics`
* Added: `resources` on `project.createEnvironment`, with the `EnvironmentResource` enum
* Added: `tracker.js` on the CDN, which tracks pageviews and engagement from a script tag
* Fixed: `.` and `..` path parameters are rejected instead of resolving to another route

## 20.0.0

* Breaking: requests send `X-Appwrite-Response-Format: 2.5.0`; responses follow the 2.5 shapes
* Breaking: nullable model fields are typed `T | null`, such as `Account.emailCanonical` and `Execution.scheduledAt`
* Breaking: removed `Topic.any()`, `Topic.all()` and `ResolvedTopic`; topic levels reject `+` and `#`
* Breaking: requires Node.js 20 or later to build
* Added: `proxy.createSMTPRule` and the `ProxyRuleProtocol` enum, with `ProxyRule.protocol`
* Added: `promoteRestoration` on `mysql`, `postgresql` and `mongo`
* Added: optional `description` on databases, collections, tables, columns, indexes, buckets, functions, sites, keys, topics, variables and webhooks
* Added: `Query` aggregates, `groupBy`, `having`, `distinct`, joins, `relationship`, `covers`, `spatialEquals` and `page`
* Added: `Query.builder()` to chain queries; `build()` returns the `string[]` list methods take, and realtime subscriptions accept a builder directly
* Added: `ExecutionTrigger.Email`, `Function.emailEnabled` and `ConsoleVariables._APP_DOMAIN_EMAIL`
* Added: per-service ES modules and a minified script on `sdk.appwrite.io/console/<version>/`
* Updated: nullable request params accept `null`
* Fixed: list and object defaults filled into a response are copied per model

## 19.0.0

* Breaking: removed the `Assistant` service (`assistant.chat`) and `_APP_ASSISTANT_ENABLED` from `ConsoleVariables`
* Breaking: `waf.getRule` and `waf.listRules` return the action-specific rule models (`WafRuleBypass`, `WafRuleChallenge`, `WafRuleDeny`, `WafRuleRateLimit`, `WafRuleRedirect`); `WafRule` is removed
* Breaking: removed the `deno-1.21`, `deno-1.24` and `deno-1.35` runtimes from `Runtime` and `BuildRuntime`
* Updated: `X-Appwrite-Response-Format` is now `2.4.0`
* Updated: missing fields in API responses are filled with their documented defaults
* Added: `Analytics` service: `listProperties`, `createProperty`, `getProperty`, `updateProperty`, `deleteProperty`, `createEvent` and `listMetrics`, with `AnalyticsDimension` and `AnalyticsInterval` enums
* Added: `Tracking` helper that auto-tracks pageviews, outbound links, downloads, scroll depth and engagement time
* Added: `admin` threat methods `listThreats`, `createThreat`, `getThreat`, `deleteThreat` and `updateThreatStatus`
* Added: `admin` cache methods `deletePlatformCache`, `deletePlatformResourceCache`, `deleteProjectCache` and `deleteProjectResourceCache`
* Added: `project.updatePasswordRotationPolicy` and the `PolicyPasswordRotation` model
* Added: optional `state` on `account.createOAuth2Session` and `account.createOAuth2Token`
* Added: optional `fingerprints` on `project.createAndroidPlatform` and `project.updateAndroidPlatform`, and on `PlatformAndroid`
* Added: optional `membershipId` on `teams.createMembership`
* Added: `project.listEnvironments`, `createEnvironment`, `getEnvironment`, `updateEnvironment` and `deleteEnvironment`, with `parentId` on `Project` and `environments` on `BillingPlan`
* Added: `trigram` in `DatabasesIndexType` and `TablesDBIndexType`, and `supportForTrigramIndex` on `ConsoleVariables`
* Added: the package ships the spec it was generated from as `spec.json`, exported as `@appwrite.io/console/spec.json`
* Added: `analytics.read` and `analytics.write` key scopes, analytics usage metrics, and `avatarsStorageTotal` on `UsageOrganization`

## 18.4.1

* Fixed: added `ProjectAuthMethodId.Passkey`, so `project.updateAuthMethod` can enable passkeys
* Fixed: added `ProjectPolicyId.Passkey` and the `Models.PolicyPasskey` model, so `getPolicy` and `listPolicies` return the passkey policy

## 18.4.0

* Added: `account` passkey methods `listPasskeys`, `createPasskey`, `getPasskey`, `updatePasskey`, `deletePasskey` and `updatePasskeyVerification`
* Added: `account.createPasskeyToken` and `account.updatePasskeyToken` for passkey sign-in
* Added: `users.listPasskeys`, `users.getPasskey`, `users.updatePasskey` and `users.deletePasskey`
* Added: `project.updatePasskeyPolicy` to configure passkey sign-in
* Added: `Passkey`, `PasskeyList`, `PasskeyChallenge` and `TokenSecret` models

## 18.3.0

* Added: optional `interval` parameter on `Functions.create`, `Functions.update` and `Projects.createSchedule`
* Updated: `schedule` is optional on `Projects.createSchedule`; pass either `schedule` or `interval`
* Added: `interval` on the `Function` and `Schedule` models, `functionsIntervalMinimum` on `BillingPlan`

## 18.2.0

* Added: `Avatars.updatePhoto` and `Avatars.deletePhoto` to upload and remove the current user's profile photo; `updatePhoto` returns the new `Account` model
* Added: `Account` model, the `User` model without `password`, `hash` and `hashOptions`
* Added: `Project.updateOAuth2Webflow`, `OAuthProvider.Webflow`, `ProjectOAuthProviderId.Webflow` and the `OAuth2Webflow` model
* Added: `ProjectKeyScopes.AvatarsWrite` (`avatars.write`)
* Added: optional `total` parameter on `Account.listSessions`, `Console.listOAuth2Providers`, `Console.listOrganizationScopes`, `Console.listProjectScopes`, `Databases.listTransactions`, `DocumentsDB.listTransactions`, `TablesDB.listTransactions`, `VectorsDB.listTransactions`, `Functions.listRuntimes`, `Functions.listSpecifications`, `Locale.listCodes`, `Locale.listContinents`, `Locale.listCountries`, `Locale.listCountriesEU`, `Locale.listCountriesPhones`, `Locale.listCurrencies`, `Locale.listLanguages`, `Notifications.list`, `Sites.listFrameworks`, `Sites.listSpecifications`, `Sites.listTemplates`, `VCS.listNamespaces`, `VCS.listRepositories` and `VCS.listRepositoryBranches`
* Added: optional `current` parameter on `Account.deleteSessions` to choose whether the current session is deleted too
* Added: `channelId` on `Messaging.createPush` and `Messaging.updatePush`
* Added: `UsageEventDimension` values `accept`, `acceptLanguage`, `latitude`, `longitude`, `postalCode`, `protocol`, `queryKeys`, `timeZone` and `weatherCode`, `UsageEventMetric.MessagesFailed`, and `latitude`, `longitude`, `postalCode`, `timeZone` and `weatherCode` on `UsageDataPoint`
* Added: `deno-1.21`, `deno-1.24` and `deno-1.35` are back in the `Runtime` and `BuildRuntime` enums
* Changed: `Push` picks a default client id per user and tab that survives reloads, so tabs no longer take over each other's connection

## 18.1.0

* Breaking: removed `deno-1.21`, `deno-1.24` and `deno-1.35` from `Runtime` and `BuildRuntime` enums
* Breaking: `Assistant.chat` now returns the response text as a `string` instead of `{ message }`
* Breaking: `Domains.getZone` now returns the zone file as a `string` instead of `{ message }`
* Fixed: nested objects in multipart requests are sent as JSON instead of `[object Object]`

## 18.0.0

* Breaking: SDK now targets Appwrite 2.3 (`X-Appwrite-Response-Format: 2.3.0`)
* Breaking: removed `Organizations.getEstimation`
* Breaking: `QuerySuggestionResource.AppwritePushLedger` renamed to `PushLedger`
* Added: `Manager.updateOrganizationStatus` to block and unblock an organization
* Added: `Push` service over MQTT with `Topic`, plus `Client.setPushEndpoint` and `Client.setPushClientId`
* Added: `Growth` service with `createConversation`, `createInstallation`, `ConversationType` enum and `GrowthConversation` model
* Added: `Account.createRecoveryOTP` and `Account.updateRecoveryOTP` for code-based password recovery
* Added: `prompt` parameter on `Project.updateOAuth2*` for Auth0, Discord, GitHub, Kakao, Microsoft, Okta, Salesforce, Zoho
* Added: matching `prompt` fields and enums on the `OAuth2*` provider models
* Added: `replyToName` and `replyToEmail` on `Messaging.createEmail` and `Messaging.updateEmail`
* Added: `organizationUrl` on the `Installation` model
* Added: `ProjectEmailTemplateId.OtpRecovery` and Deno 1.21, 1.24, 1.35 in `Runtime` and `BuildRuntime`
* Updated: `Databases.getAttribute` return type now includes bigint, spatial, varchar and text attributes
* Updated: added `mqtt` and `buffer` dependencies and browser Node polyfills for `Push`
* Fixed: chunked uploads without a file in the payload now send a regular request

## 17.0.0

* Breaking: SDK now targets Appwrite 2.2 (`X-Appwrite-Response-Format: 2.2.0`)
* Breaking: removed `Account.listLogs`, `Teams.listLogs`, `Users.listLogs` and the `Log`, `LogList` models
* Breaking: removed `Client.setDevKey`, the `Projects` dev key methods, and the `DevKey`, `DevKeyList` models
* Breaking: removed `Project.getUsage`, the `ProjectUsageRange` enum and `UsageProject`, `MetricBreakdown` models
* Breaking: removed `Organizations.cancelDowngrade`
* Breaking: `Manager.listBlocks` returns `ManagerBlockList`; `BlockList`, `BlockDelete` renamed to `ManagerBlockList`, `ManagerBlockDelete` with a new `ManagerBlock` model
* Breaking: `Block` model dropped `billingPlan`, `organizationId`, `organizationName`, `projectName`, `region`, `reason`
* Breaking: `Project.devKeys` field removed
* Breaking: `BillingPlanGroup.Starter` replaced by `Free` and `Start`
* Breaking: `OrganizationKeyScopes` dev key and `organization.keys.*` scopes replaced by `organization.projects.keys.*`
* Breaking: `CacheDatabase.Logs` and `QuerySuggestionResource` `DevKeys`, `Executions`, `Stats` values removed
* Added: `Organization` project key methods `listProjectKeys`, `createProjectKey`, `createEphemeralProjectKey`, `getProjectKey`, `updateProjectKey`, `deleteProjectKey`
* Added: `Account.createIdTokenSession` for native Apple and Google sign-in with `IdTokenProvider` enum
* Added: `Account.createEmailVerificationOTP` and `Account.updateEmailVerificationOTP`
* Added: `Messaging.createAppwriteProvider` and `Messaging.updateAppwriteProvider` for Appwrite push, with `qos` and `expiry` on `Topic`
* Added: `Project.updatePasswordPwnedPolicy` with `PolicyPasswordPwned` model and `ProjectPolicyId.Passwordpwned`
* Added: `nativeEnabled`, `nativeClientIds` parameters on `Project.updateOAuth2Apple` and `Project.updateOAuth2Google` and matching fields on `OAuth2Apple`, `OAuth2Google`
* Added: `Framework.Jaspr`, `ProjectEmailTemplateId.OtpVerification`, `ProjectKeyScopes.DedicatedDatabasesExecute`, `QuerySuggestionResource.AppwritePushLedger`
* Added: `passwordPwned` on `User`, `providerIdToken` on `Identity`, `firstAccessedAt` and `mcpAccessedAt` on `Project`, `eligibleCountries` on `BillingPlan`
* Added: `protocol`, `accept`, `acceptLanguage`, `queryKeys` fields on `UsageDataPoint`
* Fixed: empty-string required path parameters are rejected instead of sent to the API

## 16.1.0

* Fixed: `impersonateuserid` is carried again by every client URL builder — the avatar helpers, `functions`/`sites` `getDeploymentDownload()`, `organizations` `getInvoiceDownload()`/`getInvoiceView()`, and `storage` `getFileDownload()`/`getFilePreview()`/`getFileView()`. The browser loads these URLs directly and cannot send the impersonation header, so an impersonating console operator was served their own resources or a 401
* Breaking: the `Addon` enum is now `AddonKey`
* Breaking: removed `EmbeddingModel.Bgesmall` and `EmbeddingModel.Embeddinggemma`
* Breaking: removed `ProjectKeyScopes.DedicatedDatabasesExecute`; dedicated database execution follows `databases.write`
* Breaking: removed the `DedicatedDatabaseSpecificationPricing` model
* Added: `avatars.getPhoto()` and `ImageGravity.Auto`
* Added: `domains.listPrices()` with the `DomainPricesList` model
* Added: `tablesDB.createCutover()`
* Added: `users.getMFAChallenge()` with the `MfaChallengeSecret` model
* Added: `project.updateMFAFactorsPolicy()` with the `PolicyMfaFactors` model, `ProjectPolicyId.Mfafactors` and `AuthenticationFactor.Custom`
* Added: `project.updateOAuth2Cloudflare()`, `updateOAuth2HuggingFace()`, `updateOAuth2Kakao()`, `updateOAuth2Resend()` and `updateOAuth2TikTok()`, with matching models and `OAuthProvider` / `ProjectOAuthProviderId` members
* Added: `UsageEventMetric` and `UsageGaugeMetric` enums for `usage.listEvents()` and `usage.listGauges()`
* Added: `ExecutionResourceType` enum
* Added: network dimensions to `UsageEventDimension` — `autonomousSystemNumber`, `autonomousSystemOrganization`, `connectionOrganization`, `connectionType`, `connectionUsageType` and `isp`
* Added: `documentsdb.*` and `vectorsdb.*` scopes plus `embeddings.write` to `ProjectKeyScopes`
* Added: `bun-1.4`, `dart-3.13` and `flutter-3.47` to the `Runtime` and `BuildRuntime` enums
* Added: `resourcesdeleted` to the `QuerySuggestionResource` enum

## 16.0.0

* Breaking: Moved `vectorsDB.createTextEmbeddings()` to the new `Embeddings` service
* Breaking: `usage.listEvents()` `interval`, `dimensions`, `orderBy`, and `orderDir` now take enums
* Breaking: `usage.listGauges()` `interval`, `dimensions`, `orderBy`, and `orderDir` now take enums
* Added: `Affiliates` service for referral links, referrals, and rewards
* Added: `Embeddings` service with `createTextEmbeddings()`
* Added: `proxy.createInvalidation()` with the `ProxyInvalidation` model and `InvalidationType` enum
* Added: `Client.setOrganization()` for organization-scoped requests
* Added: `UsageInterval`, `UsageEventDimension`, `UsageGaugeDimension`, `UsageOrderBy`, `UsageOrderDirection` enums
* Added: affiliate resources to the `QuerySuggestionResource` enum
* Added: `proxy.invalidations.write` to the `ProjectKeyScopes` enum
* Added: `affiliates.clicks` as a `usage.listEvents()` metric

## 15.8.0

* Breaking: `create()` on `mysql`, `postgresql`, and `mongo` no longer accepts `api`
* Breaking: `project.updateSessionLimitPolicy()` now requires `total`
* Added: `syncMode` parameter to `create()` and `update()` on `tablesDB`, `documentsDB`, and `vectorsDB`
* Added: `difficulty` and `ttl` parameters to `waf.createChallengeRule()` and `waf.updateChallengeRule()`
* Added: `key` parameter to `waf.createRateLimitRule()` and `waf.updateRateLimitRule()`
* Added: replication sync state fields to `DatabaseStatus` and `DedicatedDatabaseReplicas`
* Added: `syncMode` to the `DedicatedDatabase` model
* Added: `difficulty` and `ttl` to `WafRuleChallenge`, and `key` to `WafRuleRateLimit`
* Added: `requestedType` and `fallbackReason` to `DedicatedDatabaseBackup`
* Added: `attempt` and `lastError` to `DatabaseMigration`
* Added: `node-26` to the `Runtime` and `BuildRuntime` enums
* Updated: `DatabaseStatusReplica.role` documents the `unknown` role during topology transitions
* Updated: Project policy limits for password history, session duration, session count, and users

## 15.7.0

* Added: `waf.createChallengeRule()` and `waf.updateChallengeRule()` for managing challenge rules
* Added: `WafRuleChallenge` model
* Added: `specification` parameter to `update()` on `tablesDB`, `documentsDB`, and `vectorsDB`
* Added: `folder` parameter to `storage.createFile()` for placing files in virtual folders
* Updated: `backups.createRestoration()` documents in-place restores when `newResourceId` is omitted

## 15.6.0

* Added: `apps` installation methods `listInstallations`, `getInstallation`, `deleteInstallation`, and `createInstallationToken`
* Added: `vcs.listNamespaces()` for listing provider namespaces available to an installation
* Added: `providerNamespace` parameter to `vcs.createRepository()`
* Added: `listOperations()` on `tablesDB`, `documentsDB`, `vectorsDB`, `mysql`, `postgresql`, and `mongo` for dedicated database lifecycle operations
* Added: `VcsNamespace`, `VcsNamespaceList`, `DedicatedDatabaseOperation`, and `DedicatedDatabaseOperationList` models
* Fixed: `activities.listEvents()` takes `queries` as `string[]` instead of `string`
* Updated: `vcs.listRepositories()` supports `equal` on namespace in queries
* Updated: `storage.listFiles()` documents `folder` as a filterable attribute
* Updated: Usage metric docs distinguish an empty `points[]` from a genuine zero value

## 15.5.0

* Updated: Removed `projects.createDevKey()` method
* Added: `apps` key management methods `listKeys`, `createKey`, `getKey`, `deleteKey`, and `listInstallationScopes`
* Added: `installationScopes` and `installationRedirectUrl` parameters to `apps.update()`
* Added: app installation management methods to `organization` and `teams` services
* Added: `installationAccessTokenDuration` parameter to `project.updateOAuth2Server()`
* Added: `vectorsDB.createQuery()` for querying documents via POST request body
* Added: organization installation key scopes

## 15.4.0

* Updated: Removed the `Health` service
* Updated: Removed usage methods `getUsage`, `listUsage`, `getCollectionUsage`, `getTableUsage`, and `getBucketUsage` across services
* Updated: Removed `newSpecification` parameter from `backups.createRestoration()`
* Added: Account OAuth2 consent methods `listConsents`, `getConsent`, `deleteConsent`, `listConsentTokens`, `getConsentToken`, and `deleteConsentToken`
* Added: `apps.listOAuth2Scopes()` and `apps.updateLabels()` methods
* Added: `oauth2.authorizePost()` and `oauth2.logoutPost()` methods
* Added: `project.updateOAuth2Appwrite()` method
* Added: `token` parameter to `functions.getDeploymentDownload()` and `sites.getDeploymentDownload()`
* Added: Geolocation, connection, and SDK attribution fields on `ActivityEvent`
* Updated: `AppSecret.secret` is always empty; the secret is returned only on creation

## 15.3.0

* Updated: Split the `Compute` service into engine-specific `Mysql`, `Postgresql`, and `Mongo` services
* Updated: `tablesDB.create()` now takes `specification` instead of `dedicatedDatabaseId`
* Updated: Removed `health.getQueueLogs()`
* Added: `Notifications` service for console notifications
* Added: `organization.get()`, `update()`, and `delete()` methods
* Added: Organization membership methods `listMemberships`, `createMembership`, `getMembership`, `updateMembership`, and `deleteMembership`
* Added: TablesDB dedicated migration methods `listMigrations`, `createMigration`, `getMigration`, and `deleteMigration`
* Added: `oauth2.createPAR()`, `listOrganizations()`, and `listProjects()` methods
* Added: `requestUri` parameter on `oauth2.authorize()`; client ID, redirect URI, and response type are now optional
* Added: Project addon methods `listAddons`, `getAddon`, `deleteAddon`, `confirmAddonPayment`, and `getAddonPrice`
* Added: `createPremiumGeoDBAddon()` on `Projects` and `Organizations`
* Added: `account.listLogs()`, `teams.listLogs()`, and `users.listLogs()` methods
* Added: `health.getGeo()` and `health.getGeoPremium()` methods
* Added: `newSpecification` parameter on `backups.createRestoration()`
* Added: `prompt` and `maxAge` parameters on `project.updateOAuth2Oidc()`
* Added: `BlockMode`, `DatabaseStatus`, `OAuth2OidcPrompt`, and `ProjectOAuth2OidcPrompt` enums

## 15.2.0

* Added: Realtime connections now send the configured JWT for authentication.

## 15.1.1

* Breaking: Removed `getQueuePriorityBuilds` from `Health` along with the `/health/queue/builds-priority` endpoint
* Added: `type` parameter on `Functions` and `Sites` `listSpecifications` to filter by `runtimes` or `builds`

## 15.1.0

* Breaking: `listEvents` and `listGauges` on `Usage` now take `metrics` (string array, 1-10) instead of a single `metric`
* Breaking: `UsageEventList` and `UsageGaugeList` now expose `interval` and a `metrics` array; `UsageGroup` is replaced by `UsageMetric` and `UsageDataPoint`
* Breaking: `createDatabaseExecution` on `Compute` now posts to the `/executions` endpoint
* Added: `resource` parameter on `Oauth2` `authorize`, `createDeviceAuthorization`, and `createToken` for RFC 8707 resource indicators
* Added: `resources` field on the `Oauth2Authorize` model
* Added: `service` and `resource` break-down dimensions on `Usage` `listGauges`
* Updated: `Databases` transaction and text attribute methods are now marked deprecated in favor of `TablesDB`
* Updated: OAuth2 server fields on the project model are now optional

## 15.0.0

* Breaking: `listEvents` and `listGauges` on `Usage` now require `metric` and use dimension/interval aggregation instead of `queries`/`total`
* Breaking: Removed `UsageEvent` and `UsageGauge` models; `UsageEventList` and `UsageGaugeList` now expose `metric`, `interval`, and `groups`
* Added: New `Oauth2` service with `authorize`, `approve`, `reject`, `createGrant`, `getGrant`, `revoke`, `logout`, `createDeviceAuthorization`, and `createToken`
* Added: OAuth2 models `Oauth2Authorize`, `Oauth2Approve`, `Oauth2Reject`, `Oauth2Grant`, `Oauth2DeviceAuthorization`, and `Oauth2Token`
* Added: `setCredentials` on `Client` to control whether fetch sends cookies
* Added: `tagline`, `tags`, `images`, `supportUrl`, and `dataDeletionUrl` consent params on `Apps` `create` and `update`
* Added: `postLogoutRedirectUris` on `Apps` `create` and `update` for OpenID Connect RP-Initiated Logout
* Added: `updateDenyCorporateEmailPolicy` on `Project` and `PolicyDenyCorporateEmail` model
* Added: `supportsCorporateEmailValidation` field on plan model
* Added: `oauth2` service ID and `disputes` query suggestion resource enum values
* Updated: `storageClass` now documents `ssd` as the only allowed value on `Compute`

## 14.0.0

* Breaking: Removed `getDatabaseMetrics`, `getDatabaseInsights`, `getDatabaseSchema`, and `createDatabaseSchemaPreview` from `Compute` service
* Breaking: Removed `region` and `type` parameters from `createDatabase` on `Compute` service
* Breaking: Replaced `highAvailability` and `highAvailabilityReplicaCount` with `replicas` on `createDatabase` and `updateDatabase`
* Breaking: Removed `type`, `region`, and `highAvailability` fields from `DedicatedDatabase` model
* Breaking: Removed `DedicatedDatabaseMetrics`, `DedicatedDatabaseSchema`, `DedicatedDatabaseSchemaPreview`, and performance insights models
* Breaking: Removed OS, client, and device fields from `ActivityEvent` model
* Breaking: Removed `internal` parameter and field from `Apps` service and `App` model
* Breaking: `getInvoiceDownload` and `getInvoiceView` on `Organizations` now return a URL string
* Breaking: Geometric attribute and column models now type `default` as number arrays instead of `any[]`
* Added: OAuth2 Device Authorization Grant support with `deviceFlow` on apps and device code settings on `updateOAuth2Server`
* Added: Consent metadata on `Apps` service (`description`, `clientUri`, `logoUri`, `privacyPolicyUrl`, `termsUrl`, `contacts`)
* Added: `authorizationDetailsTypes` parameter on `updateOAuth2Server` for RFC 9396 support
* Added: `dedicatedDatabaseId` parameter on `create` for `TablesDB`, `DocumentsDB`, and `VectorsDB`
* Added: `api` parameter on `createDatabase` and `DedicatedDatabasesExecute` key scope
* Added: `userAccessedAt` membership privacy setting and field on `Membership` model
* Added: Email classification fields on `User` model (`emailCanonical`, `emailIsFree`, `emailIsDisposable`, `emailIsCorporate`, `emailIsCanonical`)
* Added: Connection details on `Branch` model and TCP connection fields on dedicated database connection model
* Added: `type` field on backup policies for full or incremental backups
* Added: `hint` field on app secret models for identifying secrets
* Updated: SQL API allowed statements now support DDL and DCL types as opt-in
* Updated: All requests now send an explicit `accept` header

## 13.2.0

* Added: `Apps` service for managing OAuth2 apps, secrets, and tokens
* Added: `Compute` service for dedicated database management (backups, branches, connections, replicas, restorations)
* Added: `Advisor` service with `listInsights`, `getInsight`, `listReports`, `getReport`, and `deleteReport`
* Added: `getAuditsDB` method on `Health` service
* Added: `createSesProvider` and `updateSesProvider` methods on `Messaging` service
* Added: `updateOAuth2Server` method on `Project` service
* Added: `updatePasswordStrengthPolicy` method on `Project` service
* Added: Models for dedicated databases, apps, advisor insights/reports, and new password policies
* Updated: `getPolicy` now also returns password-strength and email-deny policy types
* Updated: Renamed enums to match server naming (`FunctionRuntime` to `Runtime`, `SiteFramework` to `Framework`, `SiteBuildRuntime` to `BuildRuntime`, `SiteAdapter` to `Adapter`, `MigrationOnDuplicate` to `OnDuplicate`, `RedirectStatusCode` to `StatusCode`, `OrganizationAddon` to `Addon`, `ProjectStatus` to `Status`)
* Updated: `X-Appwrite-Project` is now sent per request instead of set globally on `setProject`

## 13.1.0

* Added: `Organization` service with organization-scoped key methods (`listKeys`, `createKey`, `getKey`, `updateKey`, `deleteKey`)
* Added: `Presences` service with `list`, `get`, and `getUsage` methods
* Added: `Usage` service with `listEvents` and `listGauges` methods
* Added: `listOrganizationScopes` and `getEmailTemplate` methods on `Console` service
* Updated: Renamed enums for clearer namespacing (e.g. `Scopes` to `AccountKeyScopes`, `Theme` to `BrowserTheme`, `Runtime` to `FunctionRuntime`)

## 13.0.0

* Breaking: Renamed `updateCanonicalEmails` to `updateDenyCanonicalEmailPolicy` on `Project` service
* Breaking: Renamed `updateDisposableEmails` to `updateDenyDisposableEmailPolicy` on `Project` service
* Breaking: Renamed `updateFreeEmails` to `updateDenyFreeEmailPolicy` on `Project` service
* Added: `prompt` parameter on `updateOAuth2Google` for Google OAuth2 prompt configuration
* Added: `Prompt` and `OAuth2GooglePrompt` enums
* Added: `prompt` field on `OAuth2Google` model

## 12.2.0

* Added: Introduced `bigint` create/update APIs for legacy Databases attributes
* Added: Introduced `bigint` create/update APIs for `TablesDB` columns
* Updated: Extended key-list query filters with `key`, `resourceType`, `resourceId`, and `secret`

## 12.1.0

* Added: `setSession` method on `Client` for `X-Appwrite-Session` authentication
* Added: `setDevKey` method on `Client` for `X-Appwrite-Dev-Key` authentication
* Added: `suggestQueries` method on `Console` service
* Added: `QuerySuggestionResource` enum for supported list resource types
* Added: `project.policies.read`, `project.policies.write`, `backups.policies.read`, `backups.policies.write` scopes
* Updated: `setCookie` documentation clarified for server-side runtimes

## 12.0.0

* Breaking: Renamed `AuthMethod` enum to `MethodId`
* Breaking: Replaced `Projects.updateAuthStatus` with `Project.updateAuthMethod`
* Breaking: Replaced `Projects.updateOAuth2` with per-provider methods on `Project` service (e.g. `updateOAuth2Google`, `updateOAuth2GitHub`)
* Breaking: Replaced `Projects.updateMockNumbers` with `listMockPhones`, `createMockPhone`, `getMockPhone`, `updateMockPhone`, `deleteMockPhone` on `Project` service
* Breaking: Replaced `Projects.createJWT` with `Project.createEphemeralKey`
* Breaking: Moved `delete` from `Projects` to `Project` service
* Breaking: Renamed `execution.read`/`execution.write` scopes to `executions.read`/`executions.write`
* Breaking: Renamed `domainId` parameter to `invoiceId` in `domains.updatePurchase` and `domains.updateTransferIn`
* Added: `Manager` service with `createBlock`, `deleteBlock`, `listBlocks` methods
* Added: `listEmailTemplates`, `listOAuth2Providers`, `getOAuth2Provider`, `listPolicies`, `getPolicy` methods on `Project` service
* Added: `createPlanEstimation` method on `Organizations` service
* Added: `listOAuth2Providers` and `listProjectScopes` methods on `Console` service
* Added: `fusionauth`, `keycloak`, `kick` to `OAuthProvider` enum
* Added: `MethodId`, `PolicyId` enums
* Added: `mocks.read`, `mocks.write`, `sessions.read` scopes
* Added: `rust-1.83` runtime
* Added: `backup-policy` to `AppwriteMigrationResource` enum
* Added: Plural `ResourceType` values (`projects`, `functions`, `sites`, `databases`, `buckets`, `providers`, `topics`, `subscribers`, `messages`)
* Added: Models for ephemeral keys, mock numbers, email templates, policies, and per-provider OAuth2 settings
* Updated: `X-Appwrite-Response-Format` header to `1.9.3`

## 11.0.0

* Breaking: Moved SMTP, email template, and all policy methods from `Projects` to `Project` service
* Breaking: Renamed policy methods with `*Policy` suffix (e.g. `updateAuthPasswordDictionary` → `updatePasswordDictionaryPolicy`, `updateSessionAlerts` → `updateSessionAlertPolicy`, `updateAuthSessionsLimit` → `updateSessionLimitPolicy`)
* Breaking: Replaced `updateProtocolStatus`/`updateServiceStatus` with `updateProtocol`/`updateService`
* Breaking: Removed all SMS template support — `SmsTemplate` model, related enums, and `get`/`update`/`delete` methods
* Breaking: Removed `deleteEmailTemplate` method
* Breaking: Renamed `SMTPSecure` enum to `Secure`
* Breaking: Renamed SMTP/email-template fields — `smtpReplyTo` → `smtpReplyToEmail`, `EmailTemplate.type` → `templateId`, `EmailTemplate.replyTo` → `replyToEmail`; added `smtpReplyToName` and `replyToName`
* Breaking: `Project.smtpPassword` is now write-only and always returned empty
* Breaking: Removed deprecated Deno runtimes (`deno-1.21`, `deno-1.24`, `deno-1.35`)
* Breaking: Rewrote Realtime protocol — slot-based mapping replaced with server-negotiated `subscribe`/`unsubscribe` lifecycle
* Added: `RealtimeSubscription.unsubscribe()` and `RealtimeSubscription.update()` for per-subscription teardown and in-place channel/query changes
* Added: New session and user policy methods (`updatePasswordPersonalDataPolicy`, `updateSessionDurationPolicy`, `updateSessionInvalidationPolicy`, `updateUserLimitPolicy`)
* Added: `DetectionRuntimeType` and `DetectionFrameworkType` enums, plus `type` field on detection and provider-repository-list models
* Added: `authMembershipsUserId`, `authMembershipsUserPhone` fields on `Project` model and `userPhone` on `Membership`
* Added: `templates.read` and `templates.write` scopes
* Updated: `X-Appwrite-Response-Format` header to `1.9.2`

## 10.0.0

* Added `DocumentsDB` service with full CRUD for document-based databases, collections, attributes, indexes, and documents
* Added `VectorsDB` service with full CRUD for vector databases, collections, attributes, indexes, and documents
* Added `DocumentsDBIndexType` and `VectorsDBIndexType` enums
* Added `DocumentsDB` and `VectorsDB` to `ServiceId` enum
* Added `Model` enum

## 9.1.0

* Added optional `secret` parameter to `webhooks.create()` and `webhooks.updateSecret()` methods
* Updated `Webhook.secret` field description to clarify it is only returned on creation and secret rotation
* Updated `taxId` parameter to optional in `setBillingTaxId` method

## 9.0.0

* Breaking: Moved `keys`, `labels`, and `platforms` methods from `Projects` to `Project` service
* Breaking: Replaced generic `Platform` model and `PlatformType` enum with typed models: `PlatformWeb`, `PlatformApple`, `PlatformAndroid`, `PlatformWindows`, `PlatformLinux`
* Breaking: Replaced generic `createPlatform`/`updatePlatform` with platform-specific methods: `createAndroidPlatform`, `createApplePlatform`, `createLinuxPlatform`, `createWebPlatform`, `createWindowsPlatform` (and corresponding update variants). The `key` parameter was renamed to platform-specific terminology, such as `bundleIdentifier` or `packageName`
* Breaking: Removed `PlatformList` model from `Projects` service (now returned by `Project` service)
* Breaking: Removed `updateApiStatus`, `updateApiStatusAll`, `updateAPIStatus`, `updateAPIStatusAll` methods and `Api`, `ApiService` enums from `Projects` service
* Breaking: Renamed webhook parameters `security` to `tls`, `httpUser` to `authUsername`, `httpPass` to `authPassword`, `signatureKey` to `secret`
* Breaking: Renamed `updateSignature` to `updateSecret` in `Webhooks` service
* Added `updateCanonicalEmails`, `updateDisposableEmails`, `updateFreeEmails` methods to `Project` service
* Added `updateProtocolStatus`, `updateServiceStatus` methods to `Project` service with new `ProtocolId` and `ServiceId` enums
* Added `purge` parameter to `databases.updateCollection` and `tablesDb.updateTable`
* Added `x` (Twitter/X) to `OAuthProvider` enum
* Added `keys.read`, `keys.write`, `platforms.read`, `platforms.write` to `Scopes` enum
* Added `userType` field to audit log model
* Added service and protocol status fields to `Project` model
* Added `supportsDisposableEmailValidation`, `supportsCanonicalEmailValidation`, `supportsFreeEmailValidation` fields to plan model
* Updated `ttl` parameter description for `databases.listDocuments` and `tablesDb.listRows` with detailed caching behaviour

## 8.3.0

* Added addon management methods to Organizations service: `listAddons`, `createBaaAddon`, `getAddon`, `deleteAddon`, `confirmAddonPayment`, `getAddonPrice`
* Added `Addon` enum and `BillingPlanSupportedAddons` model
* Added `templateRepository`, `templateOwner`, `templateRootDirectory`, `templateVersion` optional parameters to `functions.create`
* Added `authDisposableEmails`, `authCanonicalEmails`, `authFreeEmails` fields to Project model
* Added `supportForIntegerIds` field to database adapter model
* Added descriptions to `createJSONExport` and `createJSONImport` migration methods
* Updated `ProxyRule` field descriptions for clarity

## 8.2.0

* Added: `createJSONExport()` and `createJSONImport()` methods to Migrations service
* Added: `Dart311` and `Flutter341` runtime variants
* Updated: `reason` and `expiredAt` fields on `Block` model are now optional
* Updated: Improved `ProxyRule` field descriptions
* Updated: Rollup upgraded to v4 with ESM config, Node >= 18 engine requirement

## 8.1.1

* Fixed: Added `files` field to `package.json` to publish only built artifacts to npm

## 8.1.0

* Added: Added `getHeaders()` method to `Client` to expose current request headers
* Added: Added `ProviderRepositoryRuntime` and `ProviderRepositoryRuntimeList` models
* Updated: `createRepositoryDetection()` return type now includes `DetectionRuntime`
* Updated: `listRepositories()` return type now includes `ProviderRepositoryRuntimeList`
* Added: Added `package-lock.json` to track dependency lockfile in version control

## 8.0.0

* [BREAKING] Changed `$sequence` type from `number` to `string` for `Row` and `Document` models
* [BREAKING] Renamed `IndexType` enum: split into `DatabasesIndexType` (for Databases) and `TablesDBIndexType` (for TablesDB)
* [BREAKING] `Project.createVariable` now requires `variableId` as a new first parameter
* [BREAKING] Removed `ProviderRepositoryRuntime`, `ProviderRepositoryRuntimeList`, and `DetectionRuntime` models
* [BREAKING] Removed all RC (release candidate) runtime variants from `Runtime`, `Runtimes`, and `BuildRuntime` enums
* Added new `Domains.updateAutoRenewal()` method for enabling/disabling domain auto-renewal
* Added new `Users.updateImpersonator()` method for enabling/disabling user impersonation
* Added impersonation support: `setImpersonateUserId()`, `setImpersonateUserEmail()`, `setImpersonateUserPhone()` on `Client`
* Added `impersonator` and `impersonatorUserId` optional fields to `User` model
* Added `autoRenewal` optional parameter to `Domains.createPurchase()` and `Domains.createTransferIn()`
* Added optional `queries` and `total` parameters to `Project.listVariables()`
* Added `Documentsdb` and `Vectorsdb` values to `DatabaseType` and `BackupServices` enums
* Added new scopes: `Account`, `ProjectRead`, `ProjectWrite`, `PlatformsRead`, `PlatformsWrite`, `ProjectsRead`, `ProjectsWrite`, `KeysRead`, `KeysWrite`, `DevKeysRead`, `DevKeysWrite`
* Added VectorsDB and DocumentsDB usage metrics to `UsagePeriod` model
* Added embeddings metrics (`embeddingsText*`) to `UsagePeriod` model
* Added `realtimeMessages` and `realtimeBandwidth` fields to `Plan` model
* Changed `Project.updateVariable` `key` parameter from required to optional
* Updated `Log` model field descriptions to clarify impersonation behavior
* Updated `X-Appwrite-Response-Format` header to `1.9.0`
* Updated devDependencies: Rollup 2→3, TypeScript 4.7→5.7, and related plugin upgrades

## 6.0.0

* Breaking: Renamed `domains.confirmPurchase()` to `domains.updatePurchase()`
* Breaking: Renamed `domains.confirmTransferIn()` to `domains.updateTransferIn()`
* Breaking: Replaced `Projects` API with `Webhooks` for webhook usage
* Breaking: Renamed `updateWebhook()` to `update()` on `Webhooks`
* Breaking: Renamed `listWebhooks()` to `list()` on `Webhooks`
* Breaking: Replaced `DomainPurchasePaymentStatus` with `DomainPurchaseStatus`
* Breaking: Renamed `DomainTransferStatusStatus` to `DomainTransferStatus`
* Breaking: Removed `Deno121`, `Deno124`, `Deno135` from `BuildRuntime`/`Runtime` enums
* Updated README badge to API version `1.8.2`
* Added `queries` option to `webhooks.list()`

## 5.0.0

* Breaking: Functions and Sites now require `specification` parameter and support `deploymentRetention`
* Breaking: New `buildSpecification` and `runtimeSpecification` parameters for functions
* Added messaging migration resource types: Provider, Topic, Subscriber, Message
* Added new build runtimes and runtime versions
* Added domain purchase and transfer confirmation endpoints
* Added `DomainPurchasePaymentStatus` enum

## 4.0.0

* Breaking: Channel.collection() and Channel.table() now require explicit IDs
* Added migration resource enums: AppwriteMigrationResource, FirebaseMigrationResource, NHostMigrationResource, SupabaseMigrationResource
* Added DomainTransferStatusStatus enum
* Added ttl option to listDocuments and listRows
* Added new docs/examples across domains, health, migrations, projects, sites
* Updated docs and examples to reflect new resources and transfers

## 3.1.0

* Add support for `createPurchase` method in `Domains` service
* Fix very large double values (for example 1.7976931348623157e+308) from being expanded into giant integer literals

## 3.0.0

* Breaking: EmailTemplateType enum values renamed and updated (Magicsession -> MagicSession, Mfachallenge -> MfaChallenge, Sessionalert -> SessionAlert, Otpsession -> OtpSession) and their underlying string values changed accordingly, which may affect existing integrations.
* Breaking: OAuthProvider enum removed GithubImagine and GoogleImagine options, potentially breaking code that referenced those providers.
* New: Channel.upsert() method added to support upserting documents/rows with Channel.
* New: Expanded runtime support with additional runtimes across BuildRuntime, Runtime, and Runtimes enums (Node.js 23-25, PHP 8.4, Ruby 3.4/4.0, Python 3.13/3.14, Deno 2.5/2.6, Bun 1.2/1.3, Go 1.24-1.26, Java 25, Kotlin 2.3, Swift 6.2, etc.).
* Chore: Removed bignumber.js dependency; library now relies on json-bigint and native BigInt handling; ensure compatibility for users that depended on bignumber.js.
* Documentation: Update README to reflect Appwrite server compatibility 1.8.x.
* Maintenance: Changelog updated with 2.3.1 patch: Add missing `queries` parameter to new string type attributes (backward-compatible improvement).

## 2.3.1

* Add missing `queries` parameter to new string type attributes

## 2.3.0

* Add `queries` parameter to `Realtime.subscribe()` and `client.subscribe()` for server-side query filtering
* Add slot-based subscription management with subscription ID mappings from backend
* Add `subscriptions` field to `RealtimeResponseEvent` type
* Fix `Roles` enum removed from Teams service; `roles` parameter now accepts `string[]`
* Fix parameter detection in overloaded methods to check for optional params (Account, Avatars, Graphql)
* Fix WebSocket connection handling with stale connection guards and improved close/reconnect logic
* Fix doc examples wrapped in markdown code fences

## 2.2.0

* Add array-based enum parameters (e.g., `permissions: BrowserPermission[]`).
* Breaking change: `Output` enum has been removed; use `ImageFormat` instead.
* Add `Channel` helpers for Realtime.
* Add `getQueueAudits` support to `Health` service.
* Add longtext/mediumtext/text/varchar attribute and column helpers to `Databases` and `TablesDB` services.

## 2.1.3

Fix bigint and float parsing in SDK.

## 2.1.2

* Fix only use `bigint` for min, max and default attributes

## 2.1.1

* Allow `bigint` to be passed in all methods that previously only accepted `number`

## 2.1.0

* Add `setSelfSigned` and `setCookie` methods to `Client` class

## 2.0.0

* Update SDK examples to use object-based parameters instead of positional arguments
* Align all service method usage with the new API signature style
* Update publish workflow to use trusted publishing mechanism

## 1.10.0

* Fix autocompletion not working for `Document` model even when generic is passed
* Add `getAvailableCredits` and `updateProjects` methods to `Organizations` service
* Allow internal attributes to be passed in `data` parameter of `createDocument`, `updateDocument` etc. methods
* Formatting changes

## 1.9.0

* Add `1.8.x` support

## 1.8.0

* Add encrypt to string attribute model
* Add upsert document method
* Add max deployment size and max build size plan attributes
* Remove `templateRepository`, `templateOwner`, `templateRootDirectory`, `templateVersion` from `functions.create`

## 1.7.0

* Update docs to use `https://<REGION>.cloud.appwrite.io/v1` instead of `https://cloud.appwrite.io/v1`
* Add `1.7.x` support

## 1.6.0

* Updates checking and then setting of cloud and realtime endpoint.
* Remove unnecessary asyncs and awaits.
* Adds and fixes lot of the billing related models and return types.
* Fixes typing for attributes type in Collection class.
* Removes method.title for multiplexing of endpoints.
* Removes unnecessary adding of project to payload.
* Ensures AppwriteException's "response" attribute is always string
* Adds check for file not found in chuckedUpload method
* Adds figma oauth adapter

## 1.5.2

* Adds image transformations usages to project, bucket and org. usages.

## 1.5.1

* Add some model vars that were left out and were required for DB read & writes.

## 1.5.0

* Adds support for `databasesReadsTotal` and `databasesWritesTotal` attributes

