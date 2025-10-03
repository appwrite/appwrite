## Payments Revamp Detailed Blueprint

### Executive Summary

- Replace draft `/v1/account/subscription*` endpoints with a dedicated `/v1/payments` service implemented as an Appwrite platform module, satisfying Eldad’s review.
- Deliver provider-agnostic APIs and data model so multiple payment vendors can plug in without breaking clients.
- Introduce robust internal usage controls and enable subscriptions for both users and teams, matching multi-tenant requirements.

### 1. Service Architecture

1. **Module Registration**

   - Add `Appwrite\Platform\Modules\Payments\Module` mirroring existing modules (`Functions`, `Sites`).
   - Register in `Appwrite\Platform\Appwrite::__construct` via `$this->addModule(new Payments\Module());`.
   - Provide `Services/Http.php` and optional `Services/Workers.php` classes inheriting `Utopia\Platform\Service`.
   - Use registrar pattern if route count grows (similar to `Databases\Services\Registry`).

2. **Route Classes (per Endpoint)**

   - Location: `src/Appwrite/Platform/Modules/Payments/Http/...`.
   - Each class extends `Appwrite\Platform\Modules\Compute\Base` or a lighter abstract if no compute needs, uses `use HTTP;`, configures metadata (`setHttpMethod`, `setHttpPath`, `desc`, `groups`, `label`, `param`).
   - Inject dependencies via `->inject()` matching established pattern (response, db, queue, project, user/team documents, adapter registry).
   - Return models defined in `src/Appwrite/Utopia/Response/Model` (new models for payments entities).

3. **SDK & Metadata Integration**
   - Add `->label('sdk', new Method(...))` for each route to define namespace/group (`payments`) and auth types.
   - Define new permission scopes (`payments.read`, `payments.write`, `payments.subscribe`) and map in `app/config/scopes.php` for client, server, console contexts.
   - Ensure each action sets `resourceType`, `event`, `audits.*` labels where relevant to integrate with events/auditing infrastructure.

### 2. API Surface

Below is the proposed REST contract (subject to iterative refinement). All endpoints live under `/v1/payments` unless noted.

| Endpoint                                            | Method | Description                               | Auth scopes                              | Notes                                                                                     |
| --------------------------------------------------- | ------ | ----------------------------------------- | ---------------------------------------- | ----------------------------------------------------------------------------------------- |
| `/v1/payments/plans`                                | POST   | Create plan                               | `payments.write` (project admin)         | Accepts plan metadata, pricing options, feature definitions. Returns plan model.          |
| `/v1/payments/plans`                                | GET    | List plans                                | `payments.read`                          | Supports queries/filtering; returns paginated list.                                       |
| `/v1/payments/plans/:planId`                        | GET    | Get single plan                           | `payments.read`                          |                                                                                           |
| `/v1/payments/plans/:planId`                        | PUT    | Update plan                               | `payments.write`                         | Provider-specific updates routed through adapters while preserving API stability.         |
| `/v1/payments/plans/:planId`                        | DELETE | Archive/deactivate plan                   | `payments.write`                         | Soft-delete toggles `active=false`.                                                       |
| `/v1/payments/plans/:planId/features`               | POST   | Assign/Reconfigure features               | `payments.write`                         | Handles metered tiers, usage caps.                                                        |
| `/v1/payments/plans/:planId/features`               | GET    | List assigned features                    | `payments.read`                          |                                                                                           |
| `/v1/payments/plans/:planId/features/:featureId`    | DELETE | Remove feature                            | `payments.write`                         |                                                                                           |
| `/v1/payments/features`                             | POST   | Create reusable feature definition        | `payments.write`                         | Feature types: `boolean`, `metered`, potential future types.                              |
| `/v1/payments/features`                             | GET    | List features                             | `payments.read`                          |                                                                                           |
| `/v1/payments/features/:featureId`                  | PUT    | Update feature                            | `payments.write`                         |                                                                                           |
| `/v1/payments/features/:featureId`                  | DELETE | Archive feature                           | `payments.write`                         |                                                                                           |
| `/v1/payments/providers`                            | GET    | Get project payments configuration        | `payments.read` (admin)                  | Returns provider statuses, capabilities.                                                  |
| `/v1/payments/providers`                            | PUT    | Configure providers                       | `payments.write` (admin)                 | Accepts provider credentials/options; triggers adapter bootstrap (webhooks, products).    |
| `/v1/payments/providers/:providerId/actions/test`   | POST   | Validate provider credentials             | `payments.write`                         | Optional sanity check endpoint per provider.                                              |
| `/v1/payments/subscriptions`                        | POST   | Create subscription                       | `payments.subscribe` (actor)             | Body contains `actorType` (`user`/`team`), `actorId`, `planId`, optional payment options. |
| `/v1/payments/subscriptions`                        | GET    | List subscriptions                        | `payments.read`                          | Filters: actor, plan, status, provider.                                                   |
| `/v1/payments/subscriptions/:subscriptionId`        | GET    | Retrieve subscription                     | `payments.read`                          | Validates access (actor membership, admin).                                               |
| `/v1/payments/subscriptions/:subscriptionId`        | PATCH  | Update subscription (plan switch, cancel) | `payments.subscribe` or `payments.write` | Handles upgrade/downgrade, cancellation, metadata updates.                                |
| `/v1/payments/subscriptions/:subscriptionId/cancel` | POST   | Explicit cancel endpoint                  | `payments.subscribe`                     | Sets cancel at period end or immediate cancel.                                            |
| `/v1/payments/subscriptions/:subscriptionId/resume` | POST   | Resume cancelled subscription             | `payments.subscribe`                     |                                                                                           |
| `/v1/payments/subscriptions/:subscriptionId/usage`  | GET    | Get detailed usage summary                | `payments.read`                          | Combines internal events with provider sync status.                                       |
| `/v1/payments/subscriptions/:subscriptionId/usage`  | POST   | Report usage event                        | `payments.subscribe` (server key)        | For services to emit usage increments (e.g., rate limiting). Supports batching.           |
| `/v1/payments/usage/events`                         | GET    | Query raw usage events                    | `payments.read`                          | For analytics/monitoring.                                                                 |
| `/v1/payments/usage/reconcile`                      | POST   | Trigger reconciliation with provider      | `payments.write`                         | Optional admin action to force sync.                                                      |
| `/v1/payments/webhooks/:providerId`                 | POST   | Provider webhook endpoint (internal)      | Auth via signature                       | Each provider adapter registers handler. Routed outside public docs.                      |

**Compatibility:**

- Terminate legacy `/v1/account/subscription*` routes after transitional period. Provide compatibility wrappers that call `payments` module while returning same payload shape to avoid immediate SDK breakage.

### 3. Provider Plug-in System

1. **Core Concepts**

   - Interface: `src/Appwrite/Payments/Provider/Adapter.php` (or `ProviderAdapterInterface`) defines actions consumed by module.
   - Responsibilities: product/price lifecycle, customer management, subscription lifecycle, metered usage reporting, invoice retrieval, webhook verification, credential validation.

2. **Adapter Interface Sketch**

```php
<?php
namespace Appwrite\Payments\Provider;

use Utopia\Database\Document;

interface Adapter
{
    public function getIdentifier(): string; // e.g., "stripe"

    public function configure(array $config, Document $project): ProviderState;

    public function ensurePlan(array $planData, ProviderState $state): ProviderPlanRef;

    public function updatePlan(array $planData, ProviderPlanRef $reference, ProviderState $state): ProviderPlanRef;

    public function deletePlan(ProviderPlanRef $reference, ProviderState $state): void;

    public function ensureFeature(array $featureData, ProviderPlanRef $plan, ProviderState $state): ProviderFeatureRef;

    public function ensureSubscription(Document $actor, array $subscriptionData, ProviderState $state): ProviderSubscriptionRef;

    public function updateSubscription(ProviderSubscriptionRef $subscription, array $changes, ProviderState $state): ProviderSubscriptionRef;

    public function cancelSubscription(ProviderSubscriptionRef $subscription, bool $atPeriodEnd, ProviderState $state): ProviderSubscriptionRef;

    public function resumeSubscription(ProviderSubscriptionRef $subscription, ProviderState $state): ProviderSubscriptionRef;

    public function createCheckoutSession(Document $actor, array $planContext, ProviderState $state, array $options = []): ProviderCheckoutSession;

    public function createPortalSession(Document $actor, ProviderState $state, array $options = []): ProviderPortalSession;

    public function reportUsage(ProviderSubscriptionRef $subscription, string $featureId, int $quantity, \DateTimeInterface $timestamp, ProviderState $state): void;

    public function syncUsage(ProviderSubscriptionRef $subscription, ProviderState $state): ProviderUsageReport;

    public function handleWebhook(array $payload, ProviderState $state): ProviderWebhookResult;

    public function testConnection(array $config): ProviderTestResult;
}
```

- `ProviderState`, `ProviderPlanRef`, `ProviderFeatureRef`, `ProviderSubscriptionRef`, etc., are small value objects storing provider IDs and metadata. They help hide vendor specifics from the rest of the system.
- `Document` references represent Appwrite entities (project, user, team) fetched prior to calling adapter.

3. **Registry & Resolution**

   - Introduce `src/Appwrite/Payments/Provider/Registry.php` to map provider identifiers (e.g., `stripe`, `ultra`) to adapter instances. Similar to `Messaging` using adapter classes.
   - Registry loads adapters lazily and caches per project if necessary.
   - Module obtains provider configuration from `project` document (see data model) and uses registry to instantiate adapters with project-specific credentials.

4. **Project Configuration Structure**

```json
{
  "providers": {
    "stripe": {
      "secretKey": "...",
      "publishableKey": "...",
      "webhookSecret": "...",
      "webhookEndpointId": "...",
      "currency": "usd",
      "defaultTaxRates": [],
      "capabilities": {
        "meteredBilling": true,
        "checkout": true
      }
    },
    "ultra": {
      "apiKey": "...",
      "region": "us-east",
      "capabilities": { ... }
    }
  },
  "defaults": {
    "currency": "usd",
    "trialDays": 14
  }
}
```

- Stored under `projects.payments` attribute (JSON, with `encrypt` filter for secrets). Project update endpoints manage serialization.

5. **Adapter Lifecycle**

   - **Configure**: Called when admin enables provider. Adapter sets up webhook endpoints or necessary products where required. Returns sanitized state (IDs, statuses).
   - **Plan Management**: Core module persists generic plan data; adapter ensures provider artifacts (product IDs, price IDs) exist and stores references in plan document.
   - **Subscription Flow**: Module constructs canonical subscription request, adapter handles vendor-specific operations (creating customers, scheduling invoices), returns provider subscription key stored alongside Appwrite subscription record.
   - **Usage Reporting**: Module records usage in internal ledger immediately; adapter may asynchronously forward to provider (e.g., Stripe metered billing). Worker jobs or sync endpoints ensure eventual consistency.
   - **Webhooks**: Module registers provider-specific webhook endpoints; incoming webhooks use registry to locate appropriate adapter and update `payments_subscriptions` or usage data.

6. **Error Handling**
   - Create `src/Appwrite/Payments/Exception/PaymentException.php` with standardized codes (e.g., `PROVIDER_AUTH_FAILED`, `PLAN_CONFLICT`, `USAGE_REPORT_FAILED`).
   - Adapter implementations throw provider-specific exceptions mapped to `PaymentException` with actionable messages.

### 4. Database Schema

Create new configuration file `app/config/collections/payments.php`. Proposed collections:

1. **`payments_plans`**

   - `projectId`, `projectInternalId` (key indexes).
   - `planId` (custom ID exposed to clients).
   - `name`, `description`, `pricing` array (support multiple billing intervals/currencies), `isDefault`, `isFree`, `status`, `migrationVersion`.
   - `providers` JSON: map provider identifier -> { productId, priceIds, metadata }.
   - `features` summary JSON for quick lookup.
   - Indexes: unique (`projectId`, `planId`), key on `status`, fulltext `search` combining name/description.

2. **`payments_features`**

   - Definition list available for assignment to plans.
   - Attributes: `featureId`, `name`, `type`, `description`, `defaultIncludedUnits`, `defaultTiers`, etc.
   - `providers` JSON storing mapping to external meter IDs or feature codes.
   - Indexes: unique (`projectId`, `featureId`), key on `type`.

3. **`payments_plan_features`**

   - Join table linking plans to feature configs.
   - Attributes: `planId`, `featureId`, `type`, `enabled`, `currency`, `interval`, `includedUnits`, `tiersMode`, `tiers`, `usageCap`, `overagePrice`, `providers` JSON (price/meter references), `metadata` JSON.
   - Indexes: unique (`projectId`, `planId`, `featureId`), key on `type`, `active` flag.

4. **`payments_subscriptions`**

   - Tracks active/past subscriptions.
   - Attributes: `subscriptionId` (Appwrite ID), `projectId`, `actorType` (`user`/`team`), `actorId`, `actorInternalId`, `planId`, `status`, `trialEndsAt`, `currentPeriodStart`, `currentPeriodEnd`, `cancelAtPeriodEnd`, `canceledAt`, `providerStatus`, `providers` JSON (customerId, subscriptionId, invoiceId, lastSyncedAt), `usageSummary` JSON, `tags` array, `search` field.
   - Indexes: key on (`projectId`, `actorType`, `actorId`), key on `status`, key on `planId`, fulltext search.

5. **`payments_usage_events`**

   - Internal usage ledger.
   - Attributes: `projectId`, `subscriptionId`, `actorType`, `actorId`, `planId`, `featureId`, `quantity`, `timestamp`, `providerSyncState`, `providerEventId`, `metadata`.
   - Indexes: key on (`projectId`, `subscriptionId`, `featureId`, `timestamp`), key on `providerSyncState`.

6. **`payments_provider_logs`** (optional) – store provider interactions/webhook payloads for debugging.

**Migration Strategy**

- Update `app/config/collections.php` to load `payments.php` similar to other modules.

### 5. Business Logic Flow

1. **Plan Creation**

   - Validate request (IDs, pricing structure) using new validators (`Payments\Validator\PlanPayload`).
   - Persist plan document in `payments_plans` (without provider references initially).
   - For each enabled provider, call adapter->ensurePlan. Merge returned references into `providers` JSON and persist.
   - Emit events/audits (`payments.plans.[planId].create`).

2. **Feature Assignment**

   - Validate feature definitions exist and types are allowed.
   - Persist in `payments_plan_features`, call adapter->ensureFeature where required (e.g., create metered price). Update plan document summary.

3. **Subscription Lifecycle**

   - `POST /subscriptions`: Determine actor document (fetch user or team). If team, ensure membership + permissions.
   - Check existing active subscription; depending on config, allow multiple or enforce single.
   - For free plans: create internal subscription record without provider interaction.
   - For paid plans: adapter->ensureSubscription (create customer if needed, attach payment method, start subscription). Store provider subscription ID and status.
   - Update `payments_subscriptions` with internal status (`active`, `trialing`, `past_due`, etc.).
   - Trigger usage initialization jobs and optionally send welcome events.

4. **Usage Tracking**

   - Services emit usage via `POST /subscriptions/:id/usage` (server key or internal worker). Each entry stored in `payments_usage_events` and aggregated for quick reads.
   - Cron/worker picks unsynced events, groups by provider, calls adapter->reportUsage or other relevant API.
   - `GET /subscriptions/:id/usage` returns aggregated totals (internal vs provider synced) plus detail entries.

5. **Provider Webhooks**

   - Webhook endpoint verifies signature (adapter->handleWebhook). Update subscription statuses (e.g., `past_due`, `canceled`), record invoice IDs, mark usage sync states.
   - Fire events to notify console or automation flows.

6. **Project Configuration**
   - `PUT /payments/providers`: Accept provider configs, run validations (fields required per provider). Adapter->configure may create webhook endpoints, check API keys. Persist sanitized config in `project.payments` JSON and update `projects` document.
   - Provide GET endpoint returning provider status for console (enabled, last sync, warnings).

### 6. Security & Permissions

- Scope mapping:
  - `payments.read`: Admins or service keys retrieving plan/subscription data.
  - `payments.write`: Admin actions to manage plans, providers, migrations.
  - `payments.subscribe`: Actors initiating subscription changes (users/teams). Distinguish between user session vs team owner in access control.
- Authorization logic uses existing role helpers (e.g., `Role::user`, `Role::team`). Ensure team endpoints check membership + `owner` or `billing` roles.
- Sensitive provider credentials stored encrypted; route responses never expose secrets.
- Webhook endpoints use provider-specific secret validation; avoid exposing under public documentation.

### 7. Testing & QA

- **Unit Tests:**
  - Provider adapter contract (mock HTTP clients) verifying plan creation, subscription lifecycle, error propagation.
  - Validators for plan payloads, provider configs.
- **E2E Tests:**
  - Add `tests/e2e/Services/Payments/` with scenarios: plan CRUD, subscription flow (user + team), usage reporting, provider configuration failure.
  - Use mock provider adapter for deterministic responses.
- **Integration Tests:**
  - Worker tests simulating usage reconciliation.
  - Migration tests verifying data transformation from legacy schemas.
- **Benchmarks:**
  - Evaluate overhead of usage events and plan operations under load to adjust indexes.

### 8. Migration & Rollout

1. **Phase Gate**

   - Ship module behind feature flag (`project.payments.enabled`). Default disabled until admin configures provider.
   - Provide CLI to enable module per project post-migration.

2. **Data Migration**

   - CLI script reads existing `auth_*` collections and populates new schema.
   - Migrate user-level `planId`, `stripeCustomerId`, etc., into `payments_subscriptions` records with `actorType=user`.
   - Generate default team subscriptions if teams had equivalent data (if none, start blank).

3. **Documentation & Console Update**
   - Update console to consume `/v1/payments` endpoints (plan management UI, subscription views, usage dashboards).
   - Provide developer docs describing multi-actor support, provider configuration, usage APIs, error handling.

### 9. Open Issues & Decisions Needed

- **Team billing semantics**: define allowed team roles for subscription management, and how consumption is aggregated across team members.
- **Multiple providers simultaneously**: Determine whether a project can use multiple providers concurrently (failover vs multi-market). Data model supports multiple by design; confirm product direction.
- **Self-hosted provider**: Consider built-in “Manual” provider for on-prem setups wanting internal invoicing without external gateway.
- **Plan versioning**: Decide if plan updates create new versions or mutate in place; consider locking for existing subscriptions.
- **Grace periods**: Align with Stripe-style grace or immediate suspension rules; ensure configurable per provider/project.

### 10. Next Steps

1. Validate blueprint with stakeholders (engineering leads, product).
2. Break implementation into milestones: module skeleton → data schema → provider abstraction → endpoints → migrations/tests.
3. Allocate time for console integration and documentation once backend stabilizes.
