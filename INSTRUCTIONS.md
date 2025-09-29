This is Appwrite backend. I want to develop a new feature into it.

This feature is inspired by Clerk Billing and BetterAuth Payment Plugins, where your auth is associated with plans. You can add plans and set a price for them. The users can then just purchase the plan.

I want to add such functionality to the Auth product of Appwrite. The setup for a project should be to input a Stripe secret key, which will then set the stripe account up with necessary stuff, like webhooks, etc. Store this key in the DB, I don't know where such stuff is stored about the project, but find it.

Then, setup a webhook using the stripe API and point towards a webhook endpoint that you'll create. Process should be automatic and frictionless.

The the project owner should be able to set plans. Plan should have options like plan name, ID, default (true/false) which should be true for the first one and if default is true, everyone gets the plan if it's the free one. First plan should be the free one.

The products should immediately be created on Stripe. Keep track of product ID in internal DB.

Then user can add more plans. The currency should be auto fetched using the key.

Then add API routes for people to create checkout URLs to subscribe.

Make sure you have CRUD because this needs to later reflect on console. Every route you create, create a schema in a TEMP_SCHEMA.md file.

Write no comments in code.

No summaries at end of message

## IMPLEMENTATION PLAN

### Phase 1: Database Schema & Collections

1. **Extend Projects Collection**
   - Add subscription configuration to existing projects collection
   - New fields in `app/config/collections/projects.php`:
     - `authStripeSecretKey`: Encrypted Stripe secret key
     - `authStripePublishableKey`: Stripe publishable key
     - `authStripeWebhookSecret`: Webhook endpoint secret
     - `authStripeWebhookEndpointId`: Stripe webhook endpoint ID
     - `authStripeCurrency`: Default currency (auto-detected from Stripe account)
     - `authSubscriptionsEnabled`: Boolean flag

2. **Auth Plans Collection** (`auth_plans`)
   - Store plan definitions tied to auth
   - Fields:
     - `projectId`: Reference to project
     - `planId`: Unique plan identifier
     - `name`: Plan display name
     - `description`: Plan description
     - `stripePriceId`: Stripe price ID
     - `stripeProductId`: Stripe product ID
     - `price`: Amount in cents
     - `currency`: Currency code
     - `interval`: billing interval (month/year)
     - `features`: JSON array of features
     - `isDefault`: Boolean (first plan defaults to true)
     - `isFree`: Boolean (free tier flag)
     - `maxUsers`: User limit (null for unlimited)
     - `active`: Boolean status
     - `createdAt`, `updatedAt`: Timestamps

3. **Extend Users Collection**
   - Add subscription fields to existing users collection
   - New fields:
     - `planId`: Current plan ID
     - `stripeCustomerId`: Stripe customer ID
     - `stripeSubscriptionId`: Stripe subscription ID
     - `subscriptionStatus`: Status (active/canceled/past_due/trialing/none)
     - `subscriptionCurrentPeriodStart`: Period start
     - `subscriptionCurrentPeriodEnd`: Period end
     - `subscriptionCancelAtPeriodEnd`: Boolean for pending cancellation
     - `subscriptionTrialEnd`: Trial end date (if applicable)

### Phase 2: API Routes Structure (Integrated into Auth)

1. **Project Auth Configuration** (extend `/v1/projects/{projectId}`)
   - `PUT /auth/subscriptions` - Configure Stripe for auth subscriptions
   - `GET /auth/subscriptions` - Get subscription configuration
   - `DELETE /auth/subscriptions` - Remove subscription configuration

2. **Auth Plans Management** (`/v1/projects/{projectId}/auth/plans`)
   - `POST /` - Create new auth plan (auto-creates Stripe product/price)
   - `GET /` - List all auth plans
   - `GET /{planId}` - Get specific plan details
   - `PUT /{planId}` - Update plan (metadata only)
   - `DELETE /{planId}` - Deactivate plan

3. **User Auth Subscription** (extend `/v1/account` and `/v1/users`)
   - `GET /subscription` - Get current user's subscription
   - `POST /subscription/checkout` - Create Stripe checkout session URL
   - `POST /subscription/portal` - Create customer portal session URL
   - `PUT /subscription` - Update subscription (change plan)
   - `DELETE /subscription` - Cancel subscription

4. **Webhook Handler** (`/v1/webhooks/stripe/auth`)
   - `POST /` - Handle Stripe webhook events for auth subscriptions
   - Events to handle:
     - `checkout.session.completed`
     - `customer.subscription.created`
     - `customer.subscription.updated`
     - `customer.subscription.deleted`
     - `invoice.payment_failed`
     - `invoice.payment_succeeded`

### Phase 3: Core Implementation Components

1. **Auth Subscription Service** (`src/Appwrite/Auth/Subscription/StripeService.php`)
   - Manage Stripe API interactions for auth subscriptions
   - Methods:
     - `initializeAccount()` - Setup webhook, validate key
     - `createProduct()` - Create Stripe product
     - `createPrice()` - Create Stripe price
     - `createCheckoutSession()` - Generate checkout URL
     - `createPortalSession()` - Generate customer portal URL
     - `handleWebhook()` - Process webhook events
     - `syncSubscriptionStatus()` - Update user subscription state

2. **Auth Subscription Validators**
   - `src/Appwrite/Auth/Validator/StripeKey.php` - Validate Stripe keys
   - `src/Appwrite/Auth/Validator/PlanData.php` - Validate plan creation
   - `src/Appwrite/Auth/Validator/SubscriptionStatus.php` - Validate status updates

3. **Permission & Access Control**
   - Plan management: Project owners/admins only
   - Subscription viewing: User can view own subscription
   - Checkout creation: Authenticated users
   - Webhook processing: Stripe signature verification

### Phase 4: Integration Points

1. **User Authentication Flow**
   - Check subscription status on login
   - Assign default free plan on user registration if no plan exists
   - Apply plan-based permissions and limits
   - Include plan details in JWT tokens

2. **Account Endpoints Integration**
   - Extend `/v1/account` response to include subscription details
   - Add subscription info to user sessions
   - Plan-based rate limiting

3. **User Management Integration**
   - Show subscription status in user details
   - Allow admins to view/manage user subscriptions
   - Bulk subscription operations for teams

### Phase 5: Security Considerations

1. **Encryption**
   - Stripe keys encrypted at rest using project encryption key
   - Webhook secrets stored encrypted
   - No sensitive data in logs

2. **Validation**
   - Webhook signature verification mandatory
   - Rate limiting on checkout creation
   - CSRF protection on auth subscription endpoints

3. **Permissions**
   - Project-level isolation of subscription data
   - User can only manage own subscriptions
   - Admin override capabilities

### Phase 6: Error Handling & Recovery

1. **Webhook Failures**
   - Implement retry mechanism
   - Dead letter queue for failed events
   - Manual sync capability

2. **Subscription Failures**
   - Grace period implementation
   - Notification system integration
   - Automatic retry logic

3. **Plan Migration**
   - Handle upgrades/downgrades
   - Proration calculation
   - Feature access transitions

### Phase 7: Testing Strategy

1. **Unit Tests**
   - Stripe service methods
   - Validators
   - Database operations

2. **Integration Tests**
   - Webhook processing
   - Checkout flow
   - Subscription lifecycle

3. **End-to-End Tests**
   - Complete signup with subscription
   - Plan changes
   - Cancellation flow

### Implementation Order

1. Database schema creation (collections)
2. Stripe service class implementation
3. Project auth subscription configuration endpoints
4. Plan management endpoints
5. Webhook handler implementation
6. User subscription endpoints
7. Integration with auth system
8. Testing and validation
9. Documentation and examples

### File Structure

```
app/
  config/
    collections/
      (extend projects.php - add auth subscription fields)
      auth_plans.php (new - auth plans collection)
  controllers/
    api/
      (extend account.php - add subscription endpoints)
      (extend users.php - add subscription management)
      (extend projects.php - add auth/plans endpoints)

src/
  Appwrite/
    Auth/
      Subscription/
        StripeService.php
        Exception/
          SubscriptionException.php
      Validator/
        StripeKey.php
        PlanData.php
        SubscriptionStatus.php
```

### Environment Variables

```
_APP_AUTH_SUBSCRIPTIONS_ENABLED=true
_APP_AUTH_STRIPE_WEBHOOK_URL=https://[domain]/v1/webhooks/stripe/auth
_APP_ENCRYPTION_KEY=[existing project encryption key]
```
