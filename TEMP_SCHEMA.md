# Appwrite Auth Subscriptions API Schema

## Project Auth Configuration Endpoints

### 1. Configure Stripe for Auth Subscriptions

`PUT /v1/projects/{projectId}/auth/subscriptions`

**Description:** Initialize Stripe integration for auth subscriptions. Automatically creates webhook endpoint in Stripe and stores configuration.

**Authentication:** Admin API Key

**Path Parameters:**
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |

**Request Body:**
| Field | Type | Description | Required | Validation |
|-------|------|-------------|----------|------------|
| stripeSecretKey | string | Stripe secret API key | Yes | Must match pattern: `sk_(test\|live)_[0-9a-zA-Z]{24,}` |

**Response (200 OK):**

```json
{
  "$id": "5e5ea5c16897e",
  "$createdAt": "2020-10-15T06:38:00.000+00:00",
  "$updatedAt": "2020-10-15T06:38:00.000+00:00",
  "name": "My Project",
  "teamId": "5e5ea5c16897f",
  // ... other project fields ...
  "authSubscriptionsEnabled": true,
  "authStripeSecretKey": "sk_test_...", // encrypted in DB
  "authStripePublishableKey": "pk_test_...",
  "authStripeWebhookSecret": "whsec_...", // encrypted in DB
  "authStripeWebhookEndpointId": "we_1234567890",
  "authStripeCurrency": "usd"
}
```

**Errors:**

- `400` - Invalid Stripe key format
- `401` - Unauthorized (invalid API key)
- `404` - Project not found
- `500` - Failed to connect to Stripe or create webhook

---

### 2. Get Auth Subscription Configuration

`GET /v1/projects/{projectId}/auth/subscriptions`

**Description:** Retrieve current auth subscription configuration for the project.

**Authentication:** Admin API Key

**Path Parameters:**
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |

**Response (200 OK):**

```json
{
  "enabled": true,
  "publishableKey": "pk_test_51ABC...",
  "currency": "usd"
}
```

**Errors:**

- `401` - Unauthorized
- `404` - Project not found

---

### 3. Remove Auth Subscription Configuration

`DELETE /v1/projects/{projectId}/auth/subscriptions`

**Description:** Remove Stripe configuration and disable auth subscriptions for the project.

**Authentication:** Admin API Key

**Path Parameters:**
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |

**Response (200 OK):** Updated project object with null subscription fields

**Errors:**

- `401` - Unauthorized
- `404` - Project not found

---

## Auth Plans Management Endpoints

### 4. Create Auth Plan

`POST /v1/projects/{projectId}/auth/plans`

**Description:** Create a new subscription plan. Automatically creates corresponding product and price in Stripe for paid plans.

**Authentication:** Admin API Key

**Path Parameters:**
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |

**Request Body:**
| Field | Type | Description | Required | Default | Validation |
|-------|------|-------------|----------|---------|------------|
| planId | string | Unique plan identifier | Yes | - | Max 128 chars, alphanumeric + hyphen/underscore |
| name | string | Plan display name | Yes | - | Max 128 chars |
| price | integer | Price in cents (e.g., 999 = $9.99) | Yes | 0 | Min 0 |
| currency | string | ISO 4217 currency code | Yes | - | 3 letter code (e.g., usd, eur) |
| interval | string | Billing interval | No | null | `month`, `year`, `week`, `day` |
| description | string | Plan description | No | "" | Max 256 chars |
| features | array | List of plan features | No | [] | Array of strings, max 256 chars each |
| maxUsers | integer | Max users allowed on plan | No | null | Min 1, null = unlimited |
| isDefault | boolean | Set as default plan for new users | No | false | Only one plan can be default |
| isFree | boolean | Mark as free tier | No | false | Free plans don't create Stripe products |

**Response (201 Created):**

```json
{
  "$id": "64a5f8e7c3d2a",
  "$createdAt": "2024-01-15T10:30:00.000+00:00",
  "$updatedAt": "2024-01-15T10:30:00.000+00:00",
  "projectInternalId": "64a5f8e7c3d2b",
  "projectId": "my-project",
  "planId": "premium",
  "name": "Premium Plan",
  "description": "Our best plan with all features",
  "stripeProductId": "prod_ABC123",
  "stripePriceId": "price_DEF456",
  "price": 2999,
  "currency": "usd",
  "interval": "month",
  "features": ["Unlimited users", "Priority support", "Advanced analytics"],
  "isDefault": false,
  "isFree": false,
  "maxUsers": null,
  "active": true,
  "search": "premium Premium Plan Our best plan with all features"
}
```

**Errors:**

- `400` - Auth subscriptions not configured for project
- `400` - Invalid plan data
- `401` - Unauthorized
- `404` - Project not found
- `409` - Plan ID already exists
- `500` - Failed to create Stripe product/price

---

### 5. List Auth Plans

`GET /v1/projects/{projectId}/auth/plans`

**Description:** List all subscription plans for the project. Each plan embeds `features` resolved from `auth_plan_features` (including `usageCap` for metered features).

**Authentication:** Admin API Key

**Path Parameters:**
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |

**Query Parameters:**
| Parameter | Type | Description | Required | Default |
|-----------|------|-------------|----------|---------|
| queries[] | array | Query filters | No | [] |

**Available Query Filters:**

- `Query::equal('active', [true])` - Only active plans
- `Query::equal('isFree', [true])` - Only free plans
- `Query::equal('isDefault', [true])` - Only default plan
- `Query::orderAsc('price')` - Sort by price ascending
- `Query::orderDesc('price')` - Sort by price descending
- `Query::limit(25)` - Limit results
- `Query::offset(0)` - Pagination offset

**Response (200 OK):**

```json
{
  "total": 2,
  "plans": [
    {
      "$id": "64a5f8e7c3d2a",
      "planId": "free",
      "name": "Free Plan",
      "price": 0,
      "currency": "usd",
      "interval": "month",
      "isFree": true,
      "isDefault": true,
      "features": [
        { "featureId": "custom-domains", "type": "boolean", "enabled": true }
      ]
    },
    {
      "$id": "64a5f8e7c3d2b",
      "planId": "basic",
      "name": "Basic Plan",
      "price": 999,
      "currency": "usd",
      "interval": "month",
      "features": [
        {
          "featureId": "premium-api-calls",
          "type": "metered",
          "currency": "usd",
          "interval": "month",
          "includedUnits": 20,
          "tiersMode": "graduated",
          "tiers": [
            { "up_to": 20, "unit_amount": 0 },
            { "up_to": "inf", "unit_amount": 100 }
          ],
          "stripePriceId": "price_123"
        }
      ]
    }
  ]
}
```

**Errors:**

- `401` - Unauthorized
- `404` - Project not found

---

### 6. Get Auth Plan

`GET /v1/projects/{projectId}/auth/plans/{planId}`

**Description:** Get details of a specific subscription plan. Response embeds `features` identical in shape to the List endpoint (including `usageCap` for metered features).

**Authentication:** Admin API Key

**Path Parameters:**
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |
| planId | string | Plan ID | Yes |

**Response (200 OK):** Plan object with embedded `features`

**Errors:**

- `401` - Unauthorized
- `404` - Project not found
- `404` - Plan not found

---

### 7. Update Auth Plan

`PUT /v1/projects/{projectId}/auth/plans/{planId}`

**Description:** Update plan metadata. Note: Cannot update price, currency, or interval after creation.

When `features` is provided, it is treated as the full source of truth for the plan's assignments and the backend reconciles as follows:

- Upsert: Each provided feature assignment is created or updated in `auth_plan_features`
- Soft-delete: Any existing assignment not present in the payload is marked `active=false`
- Stripe cleanup: For removed metered assignments, the associated Stripe price is deactivated

**Authentication:** Admin API Key

**Path Parameters:**
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |
| planId | string | Plan ID | Yes |

**Request Body:**
| Field | Type | Description | Required |
|-------|------|-------------|----------|
| name | string | Plan display name | No |
| description | string | Plan description | No |
| features | array<object> | Full list of current feature assignments | No |
| maxUsers | integer | Max users allowed | No |
| isDefault | boolean | Set as default plan | No |

**Response (200 OK):** Updated plan object (features are read from GET/LIST)

**Errors:**

- `401` - Unauthorized
- `404` - Project not found
- `404` - Plan not found

---

### 8. Delete Auth Plan

`DELETE /v1/projects/{projectId}/auth/plans/{planId}`

**Description:** Soft delete a plan (sets active=false). Users on this plan keep it but new users can't subscribe.

**Authentication:** Admin API Key

**Path Parameters:**
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |
| planId | string | Plan ID | Yes |

**Response (204 No Content):** Empty response

**Errors:**

- `401` - Unauthorized
- `404` - Project not found
- `404` - Plan not found

---

## Auth Features Management Endpoints

### 8.a Create Auth Feature

`POST /v1/projects/{projectId}/auth/features`

Description: Create a reusable feature definition at the project level. Features can be assigned to plans.

Authentication: Admin API Key

Path Parameters:
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |

Request Body:
| Field | Type | Description | Required |
|-------|------|-------------|----------|
| featureId | string | Unique feature ID | Yes |
| name | string | Feature display name | Yes |
| type | string | Feature type (`boolean` or `metered`) | Yes |
| description | string | Description | No |

Response (201 Created): Feature object

Errors:

- 401 - Unauthorized
- 404 - Project not found
- 409 - Feature already exists

---

### 8.b List Auth Features

`GET /v1/projects/{projectId}/auth/features`

Description: List all active features for the project.

Authentication: Admin API Key

Path Parameters:
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |

Response (200 OK): Document list of features

Errors:

- 401 - Unauthorized
- 404 - Project not found

---

### 8.c Update Auth Feature

`PUT /v1/projects/{projectId}/auth/features/{featureId}`

Description: Update a feature definition.

Authentication: Admin API Key

Path Parameters:
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |
| featureId | string | Feature unique ID | Yes |

Request Body:
| Field | Type | Description | Required |
|-------|------|-------------|----------|
| name | string | Feature name | No |
| type | string | `boolean` or `metered` | No |
| description | string | Description | No |
| active | boolean | Active state | No |

Response (200 OK): Updated feature object

Errors:

- 401 - Unauthorized
- 404 - Project or Feature not found

---

### 8.d Delete Auth Feature

`DELETE /v1/projects/{projectId}/auth/features/{featureId}`

Description: Delete a feature.

Authentication: Admin API Key

Path Parameters:
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |
| featureId | string | Feature unique ID | Yes |

Response (204 No Content): Empty

Errors:

- 401 - Unauthorized
- 404 - Project or Feature not found

---

## Plan Feature Assignment Endpoints

### 8.e Assign Features to Plan

`POST /v1/projects/{projectId}/auth/plans/{planId}/features`

Description: Assign features to a plan. Creates Stripe metered tiered prices for metered features and links them.

Authentication: Admin API Key

Path Parameters:
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |
| planId | string | Plan ID | Yes |

Request Body:
| Field | Type | Description | Required |
|-------|------|-------------|----------|
| features | array<object> | List of feature assignments | Yes |

Feature assignment object (two shapes):

- Boolean feature:
  {
  "featureId": "custom-domains",
  "type": "boolean",
  "enabled": true
  }

- Metered feature:
  {
  "featureId": "seats",
  "type": "metered",
  "currency": "usd",
  "interval": "month",
  "includedUnits": 100,
  "tiersMode": "graduated",
  "tiers": [
  { "to": 500, "unitAmount": 100 },
  { "to": 1000, "unitAmount": 200 },
  { "to": "inf", "unitAmount": 300 }
  ]
  }

Notes:

- includedUnits are applied as a free tier in Stripe (unit_amount=0 up to includedUnits)
- Stripe price uses billing_scheme=tiered, usage_type=metered, tiers_mode=graduated|volume, and references a Stripe Meter (required by Stripe versions >= 2025-03-31.basil)
- Amounts are accepted in major units (e.g., USD dollars); backend converts to minor units (cents), handling zero-decimal currencies
- `tiers` sent to Stripe are in `{ up_to, unit_amount }` with the last tier `up_to = "inf"`
- Price nickname is set to "Feature: {feature.name}" and metadata includes `feature_id`, `plan_id`, `project_id`
- Optional `usageCap` (integer or null) can be provided on metered features to cap ingestion per billing period. Ingestion beyond the cap is rejected and not sent to Stripe.

Response (200 OK): Document list of created/updated assignments

Errors:

- 400 - Invalid feature assignment
- 401 - Unauthorized
- 404 - Project, Plan or Feature not found
- 500 - Stripe error for metered features

---

### 8.f List Plan Features

`GET /v1/projects/{projectId}/auth/plans/{planId}/features`

Description: List active features assigned to a plan.

Authentication: Admin API Key

Response (200 OK): Document list of assignments

---

### 8.g Delete Plan Feature

`DELETE /v1/projects/{projectId}/auth/plans/{planId}/features/{featureId}`

Description: Soft-delete a single feature assignment from a plan. For metered assignments, deactivates the Stripe price.

Authentication: Admin API Key

Response (204 No Content)

Errors:

- 401 - Unauthorized
- 404 - Project or Plan Feature not found

---

### 8.h Delete Multiple Plan Features

`DELETE /v1/projects/{projectId}/auth/plans/{planId}/features`

Description: Bulk remove feature assignments from a plan by IDs. Deactivates Stripe prices for removed metered features.

Authentication: Admin API Key

Request Body:
| Field | Type | Description | Required |
|-------|------|-------------|----------|
| featureIds | array<string> | Feature IDs to remove | Yes |

Response (200 OK): Document list of updated assignments

Errors:

- 401 - Unauthorized
- 404 - Project not found

---

## User Subscription Endpoints

### 9. Get Account Subscription

`GET /v1/account/subscription`

**Description:** Get current user's subscription details, including assigned features and current-period usage for metered features.

**Authentication:** Session or JWT

**Response (200 OK):**

```json
{
  "planId": "premium",
  "planName": "Premium Plan",
  "status": "active",
  "currentPeriodStart": "2024-01-01T00:00:00.000+00:00",
  "currentPeriodEnd": "2024-02-01T00:00:00.000+00:00",
  "cancelAtPeriodEnd": false,
  "trialEnd": null,
  "features": [
    {
      "featureId": "requests",
      "type": "metered",
      "enabled": true,
      "currency": "usd",
      "interval": "month",
      "includedUnits": 100000,
      "tiersMode": "graduated",
      "tiers": [
        { "to": 100000, "unitAmount": 0 },
        { "to": "inf", "unitAmount": 0.5 }
      ],
      "usage": 12345,
      "usageCap": 200000
    },
    { "featureId": "team-members", "type": "boolean", "enabled": true }
  ]
}
```

**Status Values:**

- `none` - No subscription
- `active` - Subscription is active
- `canceled` - Subscription canceled (may still be active until period end)
- `incomplete` - First payment pending
- `incomplete_expired` - First payment failed
- `past_due` - Payment failed, retrying
- `trialing` - In trial period
- `unpaid` - Subscription suspended due to non-payment
- `paused` - Subscription paused

**Errors:**

- `401` - Unauthorized

---

### 10. Create Checkout Session

`POST /v1/account/subscription/checkout`

**Description:** Create a Stripe Checkout session to subscribe to a plan.

**Authentication:** Session or JWT

**Request Body:**
| Field | Type | Description | Required | Validation |
|-------|------|-------------|----------|------------|
| planId | string | Plan to subscribe to | Yes | Must be active plan |
| successUrl | string | URL to redirect after success | Yes | Valid URL |
| cancelUrl | string | URL to redirect on cancel | Yes | Valid URL |

**Response (200 OK):**

```json
{
  "checkoutUrl": "https://checkout.stripe.com/c/pay/cs_test_a1b2c3..."
}

Notes:
- Assigned metered feature prices, if any, are added as additional subscription line items in the checkout session.
```

**Errors:**

- `400` - Subscriptions not enabled for project
- `400` - Cannot checkout for free plan
- `401` - Unauthorized
- `404` - Plan not found
- `500` - Failed to create checkout session

---

### 10.a Ingest Usage

`POST /v1/usage/ingest`

**Description:** Ingest usage events for metered features. Sends usage to Stripe Billing Meters.

**Authentication:**

- Admin API Key (server-to-server), or
- Session/JWT (client SDK)
- Scope: `account`

**Behavior:**

- With API key: `userId` is required and honored.
- With Session/JWT: `userId` is ignored; the logged-in user is used.

**Request Body:**
| Field | Type | Description | Required |
|-------|------|-------------|----------|
| planId | string | Plan ID to attribute usage to | Yes |
| featureId | string | Feature ID to attribute usage to | Yes |
| value | integer | Usage value to ingest | Yes |
| userId | string | User ID (ignored on client SDK) | No |
| timestamp | integer | Unix timestamp for the usage event | No |
| identifier | string | Idempotency identifier for the event | No |

**Response (200 OK):**

```json
{
  "success": true,
  "id": "mevt_123"
}
```

**Errors:**

- `400` - Subscriptions not enabled / Feature not metered / Missing userId for API key mode
- `401` - Unauthorized
- `404` - Feature assignment not found
- `500` - Stripe ingestion error

---

### 11. Create Customer Portal Session

`POST /v1/account/subscription/portal`

**Description:** Create a Stripe Customer Portal session for billing management.

**Authentication:** Session or JWT

**Request Body:**
| Field | Type | Description | Required |
|-------|------|-------------|----------|
| returnUrl | string | URL to return to after portal | Yes |

**Response (200 OK):**

```json
{
  "portalUrl": "https://billing.stripe.com/p/session/test_YWNjdF8..."
}
```

**Errors:**

- `400` - Subscriptions not enabled
- `400` - No active subscription found
- `401` - Unauthorized
- `500` - Failed to create portal session

---

### 11.a Assign Plan to User (Admin)

`POST /v1/users/{userId}/plan`

**Description:** Manually assign a plan to a user from the admin panel. Optionally creates a complimentary Stripe subscription for one billing interval and auto-cancels at period end. The plan is applied immediately without payment.

**Authentication:** Admin API Key

**Path Parameters:**
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| userId | string | User ID | Yes |

**Request Body:**
| Field | Type | Description | Required | Default |
|-------|------|-------------|----------|---------|
| planId | string | Plan ID to assign | Yes | - |
| complimentary | boolean | Create complimentary Stripe subscription for one billing interval | No | true |

**Behavior:**

- Sets `user.planId` and subscription fields immediately:
  - `subscriptionStatus: 'active'`
  - `subscriptionCurrentPeriodStart`: now
  - `subscriptionCurrentPeriodEnd`: now + plan interval
  - `subscriptionCancelAtPeriodEnd: true`
  - `subscriptionTrialEnd`: end of the complimentary period
- If `complimentary=true`, plan is paid, and subscriptions are enabled:
  - Ensures Stripe customer exists
  - Creates trial subscription for one interval and schedules cancel at period end

**Response (200 OK):** Updated user object (includes embedded `plan`)

**Errors:**

- `400` - Subscriptions not enabled (only for paid complimentary)
- `401` - Unauthorized
  |- `404` - User or Plan not found
- `500` - Stripe error while creating complimentary subscription

---

### 12. Update Subscription

`PUT /v1/account/subscription`

**Description:** Change subscription plan (upgrade/downgrade).

**Authentication:** Session or JWT

**Request Body:**
| Field | Type | Description | Required |
|-------|------|-------------|----------|
| planId | string | New plan ID | Yes |

**Response (200 OK):**

```json
{
  "success": true
}
```

**Notes:**

- Upgrades happen immediately with proration
- Downgrades happen at period end by default
- Cannot change to/from free plans via this endpoint

**Errors:**

- `400` - Subscriptions not enabled
- `400` - No active subscription
- `401` - Unauthorized
- `404` - Plan not found
- `500` - Failed to update subscription

---

### 13. Cancel Subscription

`DELETE /v1/account/subscription`

**Description:** Cancel current subscription.

**Authentication:** Session or JWT

**Query Parameters:**
| Parameter | Type | Description | Required | Default |
|-----------|------|-------------|----------|---------|
| atPeriodEnd | boolean | Cancel at period end vs immediately | No | true |

**Response (200 OK):**

```json
{
  "success": true,
  "cancelAtPeriodEnd": true
}
```

**Errors:**

- `400` - Subscriptions not enabled
- `400` - No active subscription
- `401` - Unauthorized
- `500` - Failed to cancel subscription

---

## Webhook Handler

### 14. Stripe Webhook Handler

`POST /v1/webhooks/stripe/auth`

**Description:** Handle Stripe webhook events for subscription lifecycle.

**Authentication:** Stripe webhook signature

**Headers:**
| Header | Description | Required |
|--------|-------------|----------|
| stripe-signature | Stripe webhook signature for verification | Yes |

**Request Body:** Raw Stripe event JSON

**Response (200 OK):**

```json
{
  "success": true
}
```

**Handled Events:**

| Event                           | Description              | Actions                                            |
| ------------------------------- | ------------------------ | -------------------------------------------------- |
| `checkout.session.completed`    | Checkout successful      | Create customer, update user with subscription IDs |
| `customer.subscription.created` | New subscription created | Sync subscription status to user                   |
| `customer.subscription.updated` | Subscription changed     | Update user's plan and status                      |
| `customer.subscription.deleted` | Subscription canceled    | Remove subscription, assign default free plan      |
| `invoice.payment_failed`        | Payment failed           | Update status to `past_due`                        |
| `invoice.payment_succeeded`     | Payment successful       | Update status to `active`                          |

**Errors:**

- `400` - Missing Stripe signature
- `400` - Invalid webhook payload
- `403` - Invalid signature
- `404` - Project not found
- `500` - Processing error

---

## Database Schema

### Projects Collection Extensions

| Field                       | Type    | Description                          | Encrypted |
| --------------------------- | ------- | ------------------------------------ | --------- |
| authSubscriptionsEnabled    | boolean | Whether subscriptions are enabled    | No        |
| authStripeSecretKey         | string  | Stripe secret API key                | Yes       |
| authStripePublishableKey    | string  | Stripe publishable key               | No        |
| authStripeWebhookSecret     | string  | Webhook endpoint secret              | Yes       |
| authStripeWebhookEndpointId | string  | Stripe webhook endpoint ID           | No        |
| authStripeCurrency          | string  | Default currency from Stripe account | No        |

### Auth Plans Collection

| Field             | Type        | Description                   | Index    | Unique         |
| ----------------- | ----------- | ----------------------------- | -------- | -------------- |
| $id               | string      | Document ID                   | Primary  | Yes            |
| projectInternalId | string      | Internal project reference    | Yes      | No             |
| projectId         | string      | Project ID                    | Yes      | No             |
| planId            | string      | Plan identifier               | Yes      | With projectId |
| name              | string(128) | Plan display name             | No       | No             |
| description       | string(256) | Plan description              | No       | No             |
| stripeProductId   | string(256) | Stripe product ID             | No       | No             |
| stripePriceId     | string(256) | Stripe price ID               | No       | No             |
| price             | integer     | Price in cents                | No       | No             |
| currency          | string(3)   | ISO 4217 currency code        | No       | No             |
| interval          | string(10)  | Billing interval              | No       | No             |
| isDefault         | boolean     | Default plan flag             | Yes      | No             |
| isFree            | boolean     | Free tier flag                | No       | No             |
| maxUsers          | integer     | User limit (null = unlimited) | No       | No             |
| active            | boolean     | Active status                 | Yes      | No             |
| search            | string      | Full-text search field        | Fulltext | No             |
| $createdAt        | datetime    | Creation timestamp            | No       | No             |
| $updatedAt        | datetime    | Last update timestamp         | No       | No             |

### Auth Features Collection

| Field             | Type        | Description                | Index    | Unique         |
| ----------------- | ----------- | -------------------------- | -------- | -------------- |
| $id               | string      | Document ID                | Primary  | Yes            |
| projectInternalId | string      | Internal project reference | Yes      | No             |
| projectId         | string      | Project ID                 | Yes      | No             |
| featureId         | string      | Feature identifier         | Yes      | With projectId |
| name              | string(128) | Feature name               | No       | No             |
| type              | string(16)  | `boolean` or `metered`     | Yes      | No             |
| description       | string(256) | Feature description        | No       | No             |
| active            | boolean     | Active status              | Yes      | No             |
| search            | string      | Full-text search           | Fulltext | No             |

Indexes:

- \_key_project: projectId
- \_key_project_featureId: projectId + featureId (unique)
- \_fulltext_search: search

### Auth Plan Features Collection

| Field             | Type        | Description                      | Index   | Unique                   |
| ----------------- | ----------- | -------------------------------- | ------- | ------------------------ |
| $id               | string      | Document ID                      | Primary | Yes                      |
| projectInternalId | string      | Internal project reference       | Yes     | No                       |
| projectId         | string      | Project ID                       | Yes     | No                       |
| planId            | string      | Plan ID                          | Yes     | With projectId+featureId |
| featureId         | string      | Feature ID                       | Yes     | With projectId+planId    |
| type              | string(16)  | `boolean` or `metered`           | Yes     | No                       |
| enabled           | boolean     | For boolean features             | No      | No                       |
| currency          | string(3)   | For metered features             | No      | No                       |
| interval          | string(10)  | For metered features             | No      | No                       |
| includedUnits     | integer     | Free included units              | No      | No                       |
| tiersMode         | string(16)  | `graduated` or `volume`          | No      | No                       |
| tiers             | array       | Tier definitions                 | No      | No                       |
| stripePriceId     | string(256) | Stripe price for metered feature | No      | No                       |
| stripeMeterId     | string(256) | Stripe meter backing the price   | No      | No                       |
| active            | boolean     | Active status                    | Yes     | No                       |
| $createdAt        | datetime    | Creation timestamp               | No      | No                       |
| $updatedAt        | datetime    | Last update timestamp            | No      | No                       |

**Indexes:**

- `_key_project`: projectId
- `_key_project_plan`: projectId + planId
- `_key_project_plan_feature` (unique): projectId + planId + featureId
- `_key_active`: active

### Users Collection Extensions

| Field                          | Type        | Description                  | Index |
| ------------------------------ | ----------- | ---------------------------- | ----- |
| planId                         | string      | Current plan ID              | Yes   |
| stripeCustomerId               | string(256) | Stripe customer ID           | Yes   |
| stripeSubscriptionId           | string(256) | Stripe subscription ID       | No    |
| subscriptionStatus             | string(20)  | Subscription status          | Yes   |
| subscriptionCurrentPeriodStart | datetime    | Current billing period start | No    |
| subscriptionCurrentPeriodEnd   | datetime    | Current billing period end   | No    |
| subscriptionCancelAtPeriodEnd  | boolean     | Pending cancellation flag    | No    |
| subscriptionTrialEnd           | datetime    | Trial end date               | No    |

**Indexes:**

- `_key_planId`: planId
- `_key_stripeCustomerId`: stripeCustomerId
- `_key_subscriptionStatus`: subscriptionStatus

---

## Implementation Notes

### Security Considerations

1. **API Key Storage**: Stripe secret keys are encrypted using project encryption key
2. **Webhook Verification**: All webhook requests verified using Stripe signature
3. **Permission Checks**:
   - Project endpoints require admin API key
   - User endpoints require authenticated session
   - Webhook endpoint validates signature only

### Rate Limiting

- Checkout session creation: 10 per minute per user
- Portal session creation: 5 per minute per user
- Subscription updates: 10 per hour per user

### Error Handling

All errors follow Appwrite's standard error format:

```json
{
  "message": "Human readable error message",
  "code": 400,
  "type": "general_bad_request",
  "version": "1.5.0"
}
```

### Stripe Integration Flow

1. **Setup**: Admin configures Stripe key → Webhook created automatically
2. **Plan Creation**: Admin creates plan → Stripe product/price created
3. **Assign Features**: Admin assigns features → For metered features, backend creates/links Stripe Meter and tiered metered price
4. **User Subscription**: User initiates checkout → Redirected to Stripe; additional line items include metered feature prices
5. **Webhook Processing**: Stripe sends events → User record updated
6. **Management**: User uses portal or API → Subscription modified in Stripe

### Migration Considerations

- Existing users get `subscriptionStatus: 'none'` by default
- First plan marked as default automatically assigns to new users
- Free plan recommended as first/default plan

### Testing

Test mode keys (`sk_test_*`) recommended for development:

- Use Stripe test cards for checkout
- Webhook events can be simulated via Stripe CLI
- Test and live keys stored separately
