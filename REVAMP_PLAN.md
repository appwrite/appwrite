## Payments Revamp Plan

### 1. Background & Review Feedback

- PR [#10573](https://github.com/appwrite/appwrite/pull/10573) review (eldadfux) requires moving subscription logic into a dedicated `v1/payments` service, aligned with post-1.7 module architecture and REST plural conventions.
- Avoid extending `/v1/account` and prevent Stripe vendor lock-in; design must allow alternate providers ("UltraPayments" mention) and attach subscriptions to both users and teams.
- Internal usage tracking is still required for quota enforcement while syncing with external billing systems.

### 2. Current Draft Gaps (auth-subscriptions branch)

- Controllers: `app/controllers/api/account.php` and `api/auth-plans.php` house payments logic, diverging from modular HTTP action classes (`src/Appwrite/Platform/Modules/*/Http/...`).
- Data Coupling: `app/config/collections/platform.php` and `collections/common.php` now embed Stripe-specific columns (`authStripeSecretKey`, `stripeCustomerId`, etc.), making migration to other providers costly.
- Service Registry: `app/config/services.php` declares an `authPlans` controller entry rather than using a module-driven service (contrast with `functions`/`sites` entries that leave `controller` empty and rely on modules).
- User Records: Subscription status fields live directly on `users`, preventing team sharing and complicating multi-actor ownership.
- Tests & Specs: No module-style route tests or worker coverage; OpenAPI specs patched manually via controller paths.

### 3. Architectural Principles to Mirror

- **Module Composition:** Each platform module provides `Module.php` registering `Services/Http.php` (and optional `Services/Workers.php`) (e.g., `src/Appwrite/Platform/Modules/Functions` and `Sites`). Classes extend `Utopia\Platform\Service`, where HTTP service constructors call `addAction()` for each route class (`Create`, `Get`, `XList`, etc.).
- **Route Classes:** HTTP actions extend `Appwrite\Platform\Modules\Compute\Base` (where applicable), use the `HTTP` trait, describe metadata (scopes, events, SDK information), inject dependencies, and implement an `action()` method (see `Functions/Http/Functions/Create.php` and `Sites/Http/Deployments/Template/Create.php`).
- **Platform Bootstrap:** `src/Appwrite/Platform/Appwrite.php` registers modules. New services must follow this pattern so the factory loads them automatically.
- **Collections:** Core metadata uses JSON blobs for provider-specific config (e.g., `projects` collection attributes `services`, `auths`, `smtp` with `json` filter) instead of expanding columns per provider. Use this precedent when storing payments integrations.
- **Testing:** Route behavior is covered via module-specific tests under `tests/e2e/Services` and unit tests for validators. Payment routes must follow same structure.

### 4. Implementation Roadmap

#### Phase A – Module Bootstrapping

- Create `src/Appwrite/Platform/Modules/Payments/Module.php` mirroring `Functions\Module`/`Sites\Module`, registering an HTTP service and (if needed) worker service(s).
- Add `src/Appwrite/Platform/Modules/Payments/Services/Http.php`; register route action classes (Plans, Subscriptions, Usage, Configuration). Maintain naming parity: `Plans\Create.php`, `Plans\Update.php`, `Plans\XList.php`, etc.
- Wire module in `src/Appwrite/Platform/Appwrite.php` via `$this->addModule(new Payments\Module());`.
- Update DI autoload if required (Composer PSR-4 should already include `src/Appwrite`).

#### Phase B – REST Surface Definition

- Design plural endpoints under `/v1/payments/...` (e.g., `/v1/payments/plans`, `/v1/payments/subscriptions`, `/v1/payments/subscriptions/{subscriptionId}`, `/v1/payments/providers`).
- Each route should expose scope labels (new `payments.*` scopes) and SDK metadata similar to `Functions` routes. Provide responses using dedicated models (plan, subscription, usage event) under `src/Appwrite/Utopia/Response/Model`.
- Implement both project-admin endpoints (plan management) and authenticated actor endpoints (user/team subscription management). Accept `actorType` and `actorId` to allow teams or users.
- Remove or deprecate `/v1/account/subscription*` routes, replacing them with thin adapters or erroring with migration notice once clients are updated.

#### Phase C – Scope, Permissions & Service Registry

- Update `app/config/scopes.php` to introduce `payments.read`, `payments.write`, `payments.subscribe`, and console equivalents. Ensure console/admin scopes map to UI flows.
- Amend `app/config/services.php` to add a `payments` entry with empty `controller` (module-managed), similar to `functions` and `sites`, and expose docs metadata when available.
- Ensure new scopes appear in e2e scope tests (`tests/e2e/Scopes/`) and update SDK generation metadata if necessary.

#### Phase D – Data Model Restructuring

- Introduce `app/config/collections/payments.php` (or reuse existing file with new namespace) describing:
  - `payments_plans`: plan metadata, generic fields (name, price(s), billing cycle, features) plus `providers` JSON mapping provider IDs/handles.
  - `payments_features`: reusable feature definitions with type (`boolean`, `metered`), description, and provider metadata (if applicable).
  - `payments_plan_features`: join table storing feature assignments per plan with tiering/usage caps; provider-specific IDs live inside a `providers` JSON object.
  - `payments_subscriptions`: subscription records linking `projectId`, `actorType` (`user`/`team`), `actorInternalId`, `planId`, `status`, `lifecycle timestamps`, `quota progress`, and `providers` JSON (customer IDs, subscription IDs, invoice sync state).
  - `payments_usage_events`: internal usage ledger capturing increments, reconciliation status, and optional provider receipt IDs.
- Remove Stripe-specific attributes from `app/config/collections/platform.php` and `common.php`; replace with:
  - `projects.payments` JSON attribute storing provider configuration (`{"providers": {"stripe": {...}}}`) with `json` and `encrypt` filters for sensitive values.
  - Users/teams should no longer carry plan IDs or Stripe identifiers; instead, join via `payments_subscriptions`.
- Provide migration helpers to seed existing data into new collections (see Phase G).

#### Phase E – Provider Abstraction Layer

- Define `src/Appwrite/Payments/Provider/ProviderAdapterInterface` encapsulating operations used today (`ensureProduct`, `ensurePrice`, `ensureMeter`, `createCheckoutSession`, `createPortalSession`, `switchPlan`, `cancel`, `reportUsage`, etc.).
- Move `src/Appwrite/Auth/Subscription/StripeService.php` into `src/Appwrite/Payments/Provider/StripeAdapter.php`, implementing the new interface. Update namespaces and dependency injection accordingly.
- Introduce a provider registry/factory (e.g., `src/Appwrite/Payments/Provider/Registry.php`) to resolve adapters based on project configuration (`payments.providers` JSON). Support multiple providers by iterating adapters when needed.
- Relocate validator/exception classes under `src/Appwrite/Payments/` namespace (`Validator/StripeKey.php` → `Payments/Validator/StripeKey.php`) and replace Stripe-specific names with provider-neutral naming where possible.

#### Phase F – Business Logic Implementation

- Plan CRUD: Route classes should validate payloads, persist records in `payments_plans`, and delegate provider provisioning via the adapter interface (creating products/prices/meters for configured providers).
- Feature assignment: Manage assignments in `payments_plan_features`, including meter provisioning and tiered pricing via provider adapters.
- Subscription lifecycle: Implement routes for creating/updating/canceling subscriptions. Logic should:
  - Resolve actor (user/team) and existing subscription record.
  - Ensure plan compatibility and usage caps.
  - Call provider adapter to create/update external subscription IDs.
  - Persist status, period boundaries, and cancellation flags in `payments_subscriptions`.
- Usage enforcement: Provide endpoints/services to increment usage (`payments_usage_events`), compute current consumption, and determine cap breaches synchronously for feature gating. Expose summary endpoints to clients.
- Configuration flows: Rebuild project-level configuration endpoints (`/v1/projects/:projectId/payments/providers`) under the module, enabling/disabling providers and storing credentials in `projects.payments` JSON. Ensure `StripeAdapter` initialization replicates existing `initializeAccount()` behavior.

#### Phase G – Background Processing & Workers

- If asynchronous tasks are required (e.g., syncing usage to providers, handling webhooks), create `src/Appwrite/Platform/Modules/Payments/Services/Workers.php` and worker action classes (pattern from `Functions/Services/Workers.php`).
- Hook into existing queues (`queueForEvents`, `queueForStatsUsage`, etc.) where appropriate to emit events/audits similar to other services.
- Evaluate need for cron tasks to reconcile usage or handle grace periods.

#### Phase H – Migration & Backward Compatibility

- Author migration script (CLI task under `src/Appwrite/Platform/Tasks` or a dedicated maintenance script) to:
  - Read existing `auth_plans`, `auth_plan_features`, and user subscription fields.
  - Populate new `payments_*` collections and JSON config fields.
  - Update user/team documents to remove deprecated attributes.
- Provide database migration documentation and fallback strategy (e.g., how to disable payments before upgrade).
- For installations already using the draft branch, document manual steps to transfer customer data.

#### Phase I – Specification & SDK Updates

- Regenerate OpenAPI/Swagger specs via existing tooling (`bin/specs` / `src/Appwrite/Platform/Tasks/Specs.php`) after new routes are in place. Confirm console/client/server specs include `payments` namespace.
- Update SDK code generation inputs and ensure new endpoints appear in client/server SDKs with correct grouping.
- Adjust console UI integrations to consume the new endpoints and models (plan lists, subscription management, usage dashboards).

#### Phase J – Testing Strategy

- **Unit Tests:**
  - Adapter tests mocking Stripe client interactions.
  - Validators under `tests/unit/Payments/Validator/*`.
- **Module Route Tests:**
  - Add new classes in `tests/e2e/Services/Payments/` covering admin plan management, actor subscription flows, and usage queries for both users and teams.
  - Include negative cases (disabled provider, cap exceeded, missing permissions).
- **Integration Tests:**
  - Mock provider webhook handling and ensure idempotency.
  - Test migrations by seeding legacy data and verifying conversion results.
- Update benchmark/security CI workflows if the new module introduces additional containers or environment variables.

#### Phase K – Documentation & Developer Experience

- Draft developer docs (`/docs/services/payments.md`) describing configuration, APIs, and migration steps.
- Update console copy and CLI help text to match new terminology (plans, features, subscriptions, usage events).
- Provide sample scripts for common tasks (e.g., creating plans via CLI/SDK).

### 5. Open Questions / Follow-Up Items

- **Multi-provider Support:** Should multiple providers operate simultaneously per project or is it a single active provider? Decide how `providers` JSON is structured (array vs keyed map).
- **Team Billing Semantics:** Clarify how team-owned subscriptions map to team members—do all members share quotas automatically? Need UX decisions in console.
- **Usage Reconciliation:** Define authoritative source when provider usage differs from internal tracking; plan for dispute resolution.
- **Webhook Processing:** Determine whether payments module handles Stripe webhooks or leverages existing generic webhook processor; adjust architecture accordingly.
- **Legacy Clients:** Agree on deprecation timeline for `/v1/account/subscription*` endpoints and provide compatibility shims if required.

### 6. Next Steps

- Validate this plan with stakeholders (product + engineering) to confirm scope.
- Sequence implementation into incremental PRs (module scaffolding → data model → provider abstraction → endpoint migration → migrations/tests).
- Reserve time for console integration and documentation updates before GA release.
