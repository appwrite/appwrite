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

**Description:** List all subscription plans for the project.

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
  "total": 3,
  "plans": [
    {
      "$id": "64a5f8e7c3d2a",
      "planId": "free",
      "name": "Free Plan",
      "price": 0,
      "isFree": true,
      "isDefault": true
      // ... other plan fields
    },
    {
      "$id": "64a5f8e7c3d2b",
      "planId": "basic",
      "name": "Basic Plan",
      "price": 999
      // ... other plan fields
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

**Description:** Get details of a specific subscription plan.

**Authentication:** Admin API Key

**Path Parameters:**
| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| projectId | string | Project unique ID | Yes |
| planId | string | Plan ID | Yes |

**Response (200 OK):** Plan object (same as create response)

**Errors:**

- `401` - Unauthorized
- `404` - Project not found
- `404` - Plan not found

---

### 7. Update Auth Plan

`PUT /v1/projects/{projectId}/auth/plans/{planId}`

**Description:** Update plan metadata. Note: Cannot update price, currency, or interval after creation.

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
| features | array | List of plan features | No |
| maxUsers | integer | Max users allowed | No |
| isDefault | boolean | Set as default plan | No |

**Response (200 OK):** Updated plan object

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

## User Subscription Endpoints

### 9. Get Account Subscription

`GET /v1/account/subscription`

**Description:** Get current user's subscription details.

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
  "trialEnd": null
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
```

**Errors:**

- `400` - Subscriptions not enabled for project
- `400` - Cannot checkout for free plan
- `401` - Unauthorized
- `404` - Plan not found
- `500` - Failed to create checkout session

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
| features          | array       | JSON array of features        | No       | No             |
| isDefault         | boolean     | Default plan flag             | Yes      | No             |
| isFree            | boolean     | Free tier flag                | No       | No             |
| maxUsers          | integer     | User limit (null = unlimited) | No       | No             |
| active            | boolean     | Active status                 | Yes      | No             |
| search            | string      | Full-text search field        | Fulltext | No             |
| $createdAt        | datetime    | Creation timestamp            | No       | No             |
| $updatedAt        | datetime    | Last update timestamp         | No       | No             |

**Indexes:**

- `_key_project`: projectId
- `_key_project_planId`: projectId + planId (unique)
- `_key_project_default`: projectId + isDefault
- `_key_project_active`: projectId + active
- `_fulltext_search`: search

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
3. **User Subscription**: User initiates checkout → Redirected to Stripe
4. **Webhook Processing**: Stripe sends events → User record updated
5. **Management**: User uses portal or API → Subscription modified in Stripe

### Migration Considerations

- Existing users get `subscriptionStatus: 'none'` by default
- First plan marked as default automatically assigns to new users
- Free plan recommended as first/default plan

### Testing

Test mode keys (`sk_test_*`) recommended for development:

- Use Stripe test cards for checkout
- Webhook events can be simulated via Stripe CLI
- Test and live keys stored separately
