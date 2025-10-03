## Payments Revamp Test Plan (Step-by-step, with sample bodies)

Variables used below:

- BASE: https://your-appwrite-endpoint
- PROJECT_ID: your project ID
- API_KEY: Admin API key with payments.write/read/subscribe
- USER_ID: existing user ID (payer for user subscriptions and team payer)
- TEAM_ID: existing team ID (for team subscriptions)

Common headers (Admin key):

```
-H "X-Appwrite-Project: PROJECT_ID" \
-H "X-Appwrite-Key: API_KEY" \
-H "Content-Type: application/json"
```

### 1) Configure provider (Stripe) and enable payments

Request:

```bash
curl -X PUT "$BASE/v1/payments/providers" \
  -H "X-Appwrite-Project: PROJECT_ID" \
  -H "X-Appwrite-Key: API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "config": {
      "enabled": true,
      "providers": {
        "stripe": {
          "secretKey": "sk_test_xxx",
          "publishableKey": "pk_test_xxx"
        }
      }
    }
  }'
```

Verify (secrets masked):

```bash
curl -X GET "$BASE/v1/payments/providers" \
  -H "X-Appwrite-Project: PROJECT_ID" \
  -H "X-Appwrite-Key: API_KEY"
```

Optional: test credentials

```bash
curl -X POST "$BASE/v1/payments/providers/stripe/actions/test" \
  -H "X-Appwrite-Project: PROJECT_ID" \
  -H "X-Appwrite-Key: API_KEY"
```

### 2) Create feature definitions

2a. Boolean feature (e.g., builds):

```bash
curl -X POST "$BASE/v1/payments/features" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{
    "featureId": "builds",
    "name": "Builds",
    "type": "boolean",
    "description": "Ability to run builds"
  }'
```

2b. Metered feature (e.g., requests):

```bash
curl -X POST "$BASE/v1/payments/features" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{
    "featureId": "requests",
    "name": "Requests",
    "type": "metered",
    "description": "Metered API requests"
  }'
```

List features:

```bash
curl -X GET "$BASE/v1/payments/features" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

### 3) Create a plan with pricing

```bash
curl -X POST "$BASE/v1/payments/plans" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{
    "planId": "pro",
    "name": "Pro",
    "description": "Pro plan",
    "isDefault": false,
    "isFree": false,
    "pricing": [
      { "amount": 1999, "currency": "usd", "interval": "month" },
      { "amount": 19900, "currency": "usd", "interval": "year" }
    ]
  }'
```

Get plan:

```bash
curl -X GET "$BASE/v1/payments/plans/pro" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

### 4) Assign features to the plan

4a. Assign boolean feature (type inferred from feature):

```bash
curl -X POST "$BASE/v1/payments/plans/pro/features" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{
    "featureId": "builds",
    "currency": "usd",
    "interval": "month",
    "includedUnits": 0
  }'
```

4b. Assign metered feature (type inferred from feature):

```bash
curl -X POST "$BASE/v1/payments/plans/pro/features" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{
    "featureId": "requests",
    "currency": "usd",
    "interval": "month",
    "includedUnits": 10000
  }'
```

4c. Tiered (graduated) pricing (note):

- The current route accepts the documented fields: `featureId`, `currency`, `interval`, `includedUnits`. Type is inferred from the feature definition.
- Keys like `tiersMode`, `tiers`, `usageCap`, `overagePrice` are not read by the current implementation and will be ignored if sent.
- Below is a future-facing example body for reference only (not applied by current code):

```bash
curl -X POST "$BASE/v1/payments/plans/pro/features" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{
    "featureId": "requests",
    "type": "metered",
    "currency": "usd",
    "interval": "month",
    "includedUnits": 10000,
    "tiersMode": "graduated",
    "tiers": [
      { "up_to": 10000, "unit_amount": 0 },
      { "up_to": 100000, "unit_amount": 10 },
      { "up_to": "inf", "unit_amount": 8 }
    ],
    "usageCap": null,
    "overagePrice": null
  }'
```

List plan features:

```bash
curl -X GET "$BASE/v1/payments/plans/pro/features" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

### 5) Create subscriptions

5a. User subscription:

```bash
curl -X POST "$BASE/v1/payments/subscriptions" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{
    "actorType": "user",
    "actorId": "USER_ID",
    "planId": "pro"
  }'
```

5b. Team subscription (payer must be team member with owner/billing):

```bash
curl -X POST "$BASE/v1/payments/subscriptions" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{
    "actorType": "team",
    "actorId": "TEAM_ID",
    "planId": "pro",
    "payerUserId": "USER_ID"
  }'
```

List subscriptions (optional filters):

```bash
curl -X GET "$BASE/v1/payments/subscriptions?actorType=user&actorId=USER_ID" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

Get subscription by ID:

```bash
curl -X GET "$BASE/v1/payments/subscriptions/SUBSCRIPTION_ID" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

### 6) Update, cancel, resume

6a. Update plan (switch):

```bash
curl -X PATCH "$BASE/v1/payments/subscriptions/SUBSCRIPTION_ID" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{ "planId": "pro" }'
```

6b. Cancel at period end (explicit cancel endpoint):

```bash
curl -X POST "$BASE/v1/payments/subscriptions/SUBSCRIPTION_ID/cancel" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

Alternative using PATCH (sets cancelAtPeriodEnd):

```bash
curl -X PATCH "$BASE/v1/payments/subscriptions/SUBSCRIPTION_ID" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{ "cancelAtPeriodEnd": true }'
```

6c. Resume:

```bash
curl -X POST "$BASE/v1/payments/subscriptions/SUBSCRIPTION_ID/resume" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

### 7) Report usage (metered features)

Emit usage events (e.g., requests):

```bash
curl -X POST "$BASE/v1/payments/subscriptions/SUBSCRIPTION_ID/usage" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{
    "featureId": "requests",
    "quantity": 2500
  }'
```

Get usage summary:

```bash
curl -X GET "$BASE/v1/payments/subscriptions/SUBSCRIPTION_ID/usage" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

List raw usage events:

```bash
curl -X GET "$BASE/v1/payments/usage/events?subscriptionId=SUBSCRIPTION_ID&featureId=requests" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

Reconcile (aggregate summary + optional provider sync):

```bash
curl -X POST "$BASE/v1/payments/usage/reconcile" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

### 7.1) Optional: Update plan and feature definitions

Update plan metadata/pricing (only send fields you want to change):

```bash
curl -X PUT "$BASE/v1/payments/plans/pro" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{
    "name": "Pro Updated",
    "description": "Updated desc",
    "isDefault": false,
    "isFree": false,
    "pricing": [ { "amount": 2499, "currency": "usd", "interval": "month" } ]
  }'
```

Delete plan:

```bash
curl -X DELETE "$BASE/v1/payments/plans/pro" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

Update feature:

```bash
curl -X PUT "$BASE/v1/payments/features/requests" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{ "name": "Requests (updated)", "description": "Updated" }'
```

Delete feature:

```bash
curl -X DELETE "$BASE/v1/payments/features/requests" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY"
```

### 8) Webhooks (Stripe)

Endpoint:

```
POST $BASE/v1/payments/webhooks/stripe/PROJECT_ID
Headers:
  Content-Type: application/json
  Stripe-Signature: t=timestamp,v1=signature
Body: (Stripe event payload, e.g., customer.subscription.updated)
```

Example minimal payload (adjust to Stripe test fixtures):

```bash
curl -X POST "$BASE/v1/payments/webhooks/stripe/PROJECT_ID" \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: t=1700000000,v1=REPLACE_WITH_VALID_SIGNATURE" \
  -d '{
    "type": "customer.subscription.updated",
    "data": { "object": { "id": "sub_123", "status": "active", "current_period_start": 1700000000, "current_period_end": 1702592000 } }
  }'
```

Note: Use the `webhookSecret` returned in providers state to compute a valid Stripe signature for real tests.

### 9) Feature flag toggle (block writes when disabled)

Disable payments and verify write routes return 403:

```bash
curl -X PUT "$BASE/v1/payments/providers" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{ "enabled": false, "providers": {} }'
```

Then try, for example, creating a plan (should fail with 403):

```bash
curl -X POST "$BASE/v1/payments/plans" \
  -H "X-Appwrite-Project: PROJECT_ID" -H "X-Appwrite-Key: API_KEY" -H "Content-Type: application/json" \
  -d '{ "planId": "test", "name": "Test", "pricing": [] }'
```

### 10) (Optional) Worker & scheduler

- Start usage sync worker: `php app/worker.php payments-usage-sync`
- Periodic scheduler (enqueue usage sync for active projects): `php app/cli.php schedule-payments-usage`
