# Payments Revamp Stepbook

This stepbook sequences the end-to-end implementation of the payments revamp. Each step is agent-friendly, with explicit prerequisites, work instructions, deliverables, and verification guidance. Follow the steps in order unless a dependency explicitly states otherwise.

## Legend

- **Prereqs**: Conditions or artifacts required before tackling the step.
- **Core Tasks**: Ordered instructions for human/AI agents.
- **Outputs**: Expected tangible results (code, docs, configs).
- **Verification**: Commands or checks that confirm completion.
- **Handoff**: Follow-up steps or consumers of the outputs.

---

## Step 1 – Module Scaffolding

- **Prereqs**
  - Workspace prep complete.
- **Core Tasks**
  1. Create module namespace: `src/Appwrite/Platform/Modules/Payments/`.
  2. Add `Module.php` mirroring structure of `Functions\Module`.
  3. Create `Services/Http.php` (and placeholder `Services/Workers.php` if background work planned) extending `Utopia\Platform\Service`.
  4. Register module within `src/Appwrite/Platform/Appwrite.php` constructor via `$this->addModule(new Payments\Module());`.
  5. Ensure Composer autoload already covers `src/Appwrite` (confirm `composer.json` PSR-4 mapping). If gaps exist, patch accordingly.
- **Outputs**
  - Module skeleton files committed with minimal placeholder logic.
- **Verification**
  - `composer dump-autoload` executes without errors.
  - `vendor/bin/phpunit --filter Payments` (should report zero tests but no failures).
  - `php -l` on new files passes.
- **Handoff**
  - Step 4 uses module skeleton to register HTTP actions.

## Step 4 – HTTP Route Class Framework

- **Prereqs**
  - Module skeleton registered; app boots without fatal errors.
- **Core Tasks**
  1. Design folder structure under `src/Appwrite/Platform/Modules/Payments/Http/` matching endpoint domains (e.g., `Plans`, `Features`, `PlanFeatures`, `Subscriptions`, `Usage`, `Providers`, `Webhooks`).
  2. For each endpoint group, create base action classes extending `Appwrite\Platform\Modules\Compute\Base` (or appropriate lightweight variant) and `use HTTP` trait.
  3. In `Services/Http.php`, call `addAction(new Plans\Create())`, etc., for every planned route.
  4. Stub methods: set `setName`, `setHttpMethod`, `setHttpPath`, `setDescription`, `setGroup`, placeholder `->param` definitions.
  5. Add TODO comments referencing Step 8/9 tasks for actual business logic.
- **Outputs**
  - Skeleton route classes with metadata scaffolding.
- **Verification**
  - `php -l` on all HTTP files.
  - Application boot (via `php -d detect_unicode=0 app/init.php`) logs no missing class errors.
- **Handoff**
  - Step 5 introduces scope/SDK metadata consumed by these classes.

## Step 5 – Scope & SDK Metadata Wiring

- **Prereqs**
  - Route classes stubbed.
- **Core Tasks**
  1. Update `app/config/scopes.php` to define `payments.read`, `payments.write`, `payments.subscribe` (client/server/console mappings per blueprint).
  2. Add console/admin scope entries and ensure scope descriptions reflect usage.
  3. In each route class, register SDK metadata via `$this->label('sdk', new Method(...))` specifying group `payments`, auth types, and method name.
  4. Annotate routes with `->label('scope', 'payments.read')` etc.; include `resourceType`, `event`, and `audits.*` labels where applicable.
  5. Update scope tests under `tests/e2e/Scopes` (add cases ensuring new scopes exist and map to correct roles).
  6. Regenerate scope documentation if automation expects (`bin/specs scopes`).
- **Outputs**
  - Scopes defined and linked; routes enriched with metadata.
- **Verification**
  - `vendor/bin/phpunit tests/e2e/Scopes` passes (or targeted test command).
  - `bin/specs` (or relevant generator) succeeds without missing scope errors.
- **Handoff**
  - Step 6 relies on new scopes for permission configuration.

## Step 6 – Data Model Definition

- **Prereqs**
  - Scope wiring complete; DBA consulted for index strategy (if required).
- **Core Tasks**
  1. Create `app/config/collections/payments.php` describing all new collections:
     - `payments_plans`
     - `payments_features`
     - `payments_plan_features`
     - `payments_subscriptions`
     - `payments_usage_events`
     - optional `payments_provider_logs`
  2. For each collection, define attributes (types, filters, required flags) exactly as blueprint specifies (IDs, JSON fields, indexes, encryption flags).
  3. Update `app/config/collections.php` to include the new config file.
  4. Remove/flag legacy Stripe-specific attributes from `app/config/collections/platform.php` and `app/config/collections/common.php`; add `projects.payments` JSON attribute with `encrypt` filter for secrets.
  5. Draft migration notes describing how new collections replace old fields (feeds into Step 11).
- **Outputs**
  - Payments collections configuration file with indexes and attribute metadata.
  - Legacy collection definitions cleaned up.
- **Verification**
  - Run `php -d detect_unicode=0 app/init.php` to ensure config loads.
  - `vendor/bin/phpunit tests/unit/Database/Collections` (or relevant) passes.
- **Handoff**
  - Step 7 implements provider abstractions referencing new schema.

## Step 7 – Provider Abstraction Layer

- **Prereqs**
  - Data model defined; requirements for provider interactions clarified.
- **Core Tasks**
  1. Create namespace `src/Appwrite/Payments/Provider/`.
  2. Implement `Adapter` interface (or `ProviderAdapterInterface`) as per blueprint signature (plan management, subscriptions, usage, webhooks, testing).
  3. Define supporting value objects (`ProviderState`, `ProviderPlanRef`, `ProviderFeatureRef`, `ProviderSubscriptionRef`, `ProviderCheckoutSession`, `ProviderPortalSession`, `ProviderUsageReport`, `ProviderWebhookResult`, `ProviderTestResult`).
  4. Build `Registry` class to resolve adapters by identifier, instantiate with project-specific config, and cache where appropriate.
  5. Migrate existing Stripe logic (`src/Appwrite/Auth/Subscription/StripeService.php`) into `Payments\Provider\StripeAdapter` implementing interface.
  6. Relocate validators and exceptions into `src/Appwrite/Payments/Validator` and `src/Appwrite/Payments/Exception\PaymentException.php`.
  7. Ensure adapter error handling maps provider-specific issues to standardized `PaymentException` codes (`PROVIDER_AUTH_FAILED`, `PLAN_CONFLICT`, etc.).
- **Outputs**
  - Provider interface and registry.
  - Stripe adapter aligned with new abstractions.
- **Verification**
  - Unit tests for registry and adapter contract (`tests/unit/Payments/Provider/`) passing.
  - Static analysis (`vendor/bin/phpstan analyse src/Appwrite/Payments`).
- **Handoff**
  - Step 8 integrates registry into route actions.

## Step 8 – Business Logic Integration (Plans & Features)

- **Prereqs**
  - Provider layer functional; data model available.
- **Core Tasks**
  1. Implement `Plans` routes (`Create`, `Get`, `List`, `Update`, `Delete`) to validate payloads (`Payments\Validator\PlanPayload`), persist documents in `payments_plans`, and invoke provider adapters for provisioning.
  2. Build `Features` routes for reusable feature definitions, including create/update/delete flows with provider interactions (`ensureFeature`).
  3. Implement `PlanFeatures` routes (`Assign`, `List`, `Remove`) to link plans and features, manage tiered pricing, and update plan summaries.
  4. Ensure events/audits triggered (`payments.plans.{planId}.create`, etc.).
  5. Write unit tests for validators and repository interactions; add e2e tests covering positive/negative plan scenarios.
- **Outputs**
  - Fully functioning plan and feature endpoints with provider provisioning.
- **Verification**
  - `vendor/bin/phpunit tests/unit/Payments/Plans` and `tests/e2e/Services/Payments/Plans`.
  - Manual API smoke tests via `docs/scripts` or HTTP client verifying CRUD operations.
- **Handoff**
  - Step 9 builds subscription lifecycle using same abstractions.

## Step 9 – Business Logic Integration (Subscriptions & Usage)

- **Prereqs**
  - Plans/features endpoints operational; provider adapter tested.
- **Core Tasks**
  1. Implement subscription routes:
     - `POST /v1/payments/subscriptions`
     - `GET /v1/payments/subscriptions`
     - `GET /v1/payments/subscriptions/:subscriptionId`
     - `PATCH /v1/payments/subscriptions/:subscriptionId`
     - `POST /v1/payments/subscriptions/:subscriptionId/cancel`
     - `POST /v1/payments/subscriptions/:subscriptionId/resume`
  2. Logic should:
     - Resolve actor documents (user/team) with permission checks.
     - Handle free vs paid plan flows (internal-only vs provider interactions).
     - Persist subscription state in `payments_subscriptions` with lifecycle timestamps.
     - Trigger events/audits and queue initialization jobs.
  3. Implement usage endpoints:
     - `GET /v1/payments/subscriptions/:subscriptionId/usage`
     - `POST /v1/payments/subscriptions/:subscriptionId/usage`
     - `GET /v1/payments/usage/events`
     - `POST /v1/payments/usage/reconcile`
  4. Wire usage reporting to adapters (`reportUsage`, `syncUsage`) and internal ledger (`payments_usage_events`).
  5. Add permission checks for `payments.subscribe` vs `payments.write`; ensure team role enforcement (`owner`, `billing`).
  6. Unit + e2e tests covering actor scenarios, cancellations, resumes, usage reporting, reconciliation.
- **Outputs**
  - Subscription and usage endpoints with provider interoperability.
- **Verification**
  - `vendor/bin/phpunit tests/unit/Payments/Subscriptions` & `Usage` suites.
  - `tests/e2e/Services/Payments/Subscriptions` scenarios.
  - Manual smoke test (create plan, assign feature, create subscription, report usage, reconcile).
- **Handoff**
  - Step 10 handles provider configuration endpoints and webhooks.

## Step 10 – Provider Configuration & Webhooks

- **Prereqs**
  - Adapter registry in place; subscription flows implemented.
- **Core Tasks**
  1. Build provider management routes:
     - `GET /v1/payments/providers`
     - `PUT /v1/payments/providers`
     - `POST /v1/payments/providers/:providerId/actions/test`
  2. Persist provider configuration in `projects.payments` JSON (encrypted fields) with validation per provider.
  3. Implement webhook handler `POST /v1/payments/webhooks/:providerId` with signature validation delegating to adapter `handleWebhook`.
  4. Ensure webhook updates subscription status, invoices, usage sync states, and emits relevant events.
  5. Add unit tests for configuration validators and webhook processing; e2e tests simulating webhook payloads (mock provider).
- **Outputs**
  - Provider configuration endpoints and webhook infrastructure.
- **Verification**
  - `vendor/bin/phpunit tests/unit/Payments/Providers`.
  - `tests/e2e/Services/Payments/Providers` including webhook scenarios.
- **Handoff**
  - Step 11 introduces background workers for async tasks.

## Step 11 – Background Workers & Queues

- **Prereqs**
  - Provider webhooks implemented; need for async operations confirmed.
- **Core Tasks**
  1. If required, implement `Services/Workers.php` under payments module registering worker actions (usage sync, webhook replay, reconciliation, notifications).
  2. Connect workers to relevant queues (`queueForEvents`, `queueForStatsUsage`, etc.).
  3. Implement worker handlers for:
     - Processing unsynced usage events in batches.
     - Retrying failed provider interactions.
     - Emitting audit logs/notifications.
  4. Add configuration toggles/cron definitions if periodic jobs required.
  5. Write worker unit/integration tests (simulate queue payloads, ensure idempotency) and update CI to execute them.
- **Outputs**
  - Worker services handling async tasks with tests.
- **Verification**
  - `vendor/bin/phpunit tests/unit/Payments/Workers`.
  - Manual queue dry-run via `php bin/queue --capture payments-usage-sync` (or equivalent) shows successful execution.
- **Handoff**
  - Step 12 manages migrations and data backfill.

## Step 12 – Data Migration & Compatibility Layer

- **Prereqs**
  - Core functionality ready; legacy schema inventoried.
- **Core Tasks**
  1. Develop migration CLI (e.g., `src/Appwrite/Platform/Tasks/MigratePayments.php`) to read legacy `auth_*` collections and user fields, populate new `payments_*` collections.
  2. Handle user-level Stripe data conversion into subscriptions (`actorType=user`) and generate team records as needed.
  3. Provide dry-run mode producing migration report.
  4. Implement compatibility wrappers for legacy `/v1/account/subscription*` endpoints (if transitional period required) calling new module while preserving response shape.
  5. Document manual fallback steps (disable module, rollback) and update upgrade scripts (`bin/upgrade`, `bin/migrate`).
- **Outputs**
  - Migration CLI + documentation.
  - Optional compatibility endpoints bridging legacy clients.
- **Verification**
  - `php bin/migrate payments --dry-run` (or equivalent) generates expected report.
  - Integration test seeding legacy data and validating converted records.
- **Handoff**
  - Step 13 regenerates specifications and SDKs.

## Step 13 – Specification & SDK Updates

- **Prereqs**
  - API routes finalized; migration plan approved.
- **Core Tasks**
  1. Update OpenAPI spec generation tooling (`bin/specs`, `src/Appwrite/Platform/Tasks/Specs.php`) to include `/v1/payments` endpoints with accurate schemas/models.
  2. Create new response models under `src/Appwrite/Utopia/Response/Model` (Plan, PlanList, Feature, Subscription, UsageEvent, etc.) and ensure routes reference them.
  3. Regenerate SDKs (`bin/sdks generate all`) and verify new payments namespace appears across client/server SDKs.
  4. Audit console web client to ensure TypeScript models integrate new endpoints (update `public/sdk-console` if required).
- **Outputs**
  - Updated specs, generated SDK artifacts, and console types.
- **Verification**
  - `git diff` shows updated spec JSON + SDK source.
  - Run sample SDK script invoking payments endpoints successfully (smoke test).
- **Handoff**
  - Step 14 focuses on testing strategy.

## Step 14 – Testing & Quality Assurance

- **Prereqs**
  - Functional code complete; tests implemented alongside earlier steps.
- **Core Tasks**
  1. Ensure unit, e2e, integration, and worker tests cover:
     - Plan/feature CRUD
     - Subscription lifecycle (user/team)
     - Usage reporting & reconciliation
     - Provider configuration + webhooks
     - Migration scripts
  2. Add benchmark tests if usage events need performance validation.
  3. Execute full test matrix: `vendor/bin/phpunit`, `npm run test` (if console updated), `bin/bench` (if available).
  4. Collect coverage report and ensure thresholds met (update `phpunit.xml` if needed).
  5. Document manual QA scenarios (console UI walk-through, webhook replay).
- **Outputs**
  - Comprehensive passing test suite and QA checklist.
- **Verification**
  - CI pipeline green across all stages.
  - QA sign-off recorded in tracker.
- **Handoff**
  - Step 15 handles documentation and developer experience.

## Step 15 – Documentation & Developer Experience

- **Prereqs**
  - Testing complete; product sign-off on functionality.
- **Core Tasks**
  1. Author `docs/services/payments.md` covering configuration, endpoints, usage reporting, migration notes.
  2. Update console documentation and copy (billing UI, error messages).
  3. Refresh developer tutorials (CLI scripts for plan creation, sample usage reporting).
  4. Update CHANGELOG/RELEASE notes summarizing payments revamp.
  5. Provide sample automation scripts in `docs/examples` demonstrating typical flows.
- **Outputs**
  - Updated documentation stack.
- **Verification**
  - Docs build (if applicable) passes (`npm run docs:build` or similar).
  - Technical writers/product review completed.
- **Handoff**
  - Step 16 manages rollout and feature flagging.

## Step 16 – Rollout & Feature Flag Management

- **Prereqs**
  - Documentation ready; QA sign-off.
- **Core Tasks**
  1. Implement feature flag gating (`project.payments.enabled`) with default disabled; ensure configuration endpoint toggles it.
  2. Prepare rollout plan (beta projects, staged enablement, monitoring dashboards).
  3. Update CLI tooling or admin UI to toggle flag per project post-migration.
  4. Coordinate with support to craft customer communication, fallback procedures.
  5. Monitor metrics (errors, webhook failures, subscription churn) during rollout.
- **Outputs**
  - Feature flag controls, rollout schedule, monitoring plan.
- **Verification**
  - Dry-run enabling flag on staging succeeds end-to-end.
  - Monitoring alerts configured with thresholds.
- **Handoff**
  - Step 17 covers legacy deprecation.

## Step 17 – Legacy Endpoint Deprecation

- **Prereqs**
  - Rollout underway; compatibility shims deployed.
- **Core Tasks**
  1. Announce deprecation timeline for `/v1/account/subscription*` endpoints.
  2. Implement logging/metrics to detect lingering usage of legacy endpoints.
  3. Provide migration guidance to SDK consumers (release notes, upgrade guides).
  4. Schedule removal window; when ready, retire compatibility shims and delete legacy controllers/config.
  5. Update specs/SDKs to remove legacy endpoints once EOL date passes.
- **Outputs**
  - Legacy endpoints officially deprecated and removed per schedule.
- **Verification**
  - No production traffic to legacy routes (dashboards confirm zero hits).
  - Codebase free of legacy controllers/paths.
- **Handoff**
  - Step 18 finalizes project closure.

## Step 18 – Project Closure & Retrospective

- **Prereqs**
  - Legacy endpoints removed; new payments module stable in production.
- **Core Tasks**
  1. Conduct retrospective documenting successes, challenges, follow-up actions.
  2. Archive decision log updates, migration scripts outcomes, and open issues resolved/deferred.
  3. Confirm tracker tasks closed and post-rollout monitoring handed to operations.
  4. Celebrate with team acknowledgement (optional but recommended!).
- **Outputs**
  - Retrospective report, cleaned tracker, knowledge captured.
- **Verification**
  - All project artifacts stored in agreed knowledge base.
  - Stakeholders acknowledge completion.
- **Handoff**
  - None; project concluded.

---

### Supporting References

- `REVAMP_PLAN_DETAILED.md` – Deep blueprint for architecture, API, adapters, data model.
- `REVAMP_PLAN.md` – High-level roadmap and phase breakdown.
- Existing module patterns: `src/Appwrite/Platform/Modules/Functions`, `Sites`.
- Provider integration precedents: `src/Appwrite/Messaging/Provider`, `src/Appwrite/Auth/Subscription/StripeService.php` (legacy).

Use this stepbook as the authoritative sequencing for agents and humans collaborating on the payments revamp. Update the document if scope adjustments or new decisions arise.
