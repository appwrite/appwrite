<?php

namespace Appwrite\Auth\Subscription;

use Appwrite\Auth\Subscription\Exception\SubscriptionException;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

class StripeService
{
    private string $apiKey;
    private string $apiUrl = 'https://api.stripe.com/v1';
    private Database $database; // Project DB
    private ?Database $platformDb = null; // Platform (console) DB for cross-project collections
    private Document $project;

    public function __construct(string $apiKey, Database $database, Document $project, ?Database $platformDb = null)
    {
        $this->apiKey = $apiKey;
        $this->database = $database;
        $this->project = $project;
        $this->platformDb = $platformDb;
    }

    /**
     * Initialize Stripe account for the project
     * @throws SubscriptionException
     */
    public function initializeAccount(): array
    {
        $account = $this->getAccount();

        $currency = $account['default_currency'] ?? 'usd';

        $webhookEndpoint = $this->createWebhookEndpoint();

        return [
            'currency' => $currency,
            'webhookEndpointId' => $webhookEndpoint['id'],
            'webhookSecret' => $webhookEndpoint['secret'],
            'publishableKey' => $this->extractPublishableKey()
        ];
    }

    /**
     * Get Stripe account details
     * @throws SubscriptionException
     */
    private function getAccount(): array
    {
        $response = $this->makeRequest('GET', '/account');

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to get Stripe account');
        }

        return $response;
    }

    /**
     * Extract publishable key from secret key
     */
    private function extractPublishableKey(): string
    {
        if (strpos($this->apiKey, 'sk_test_') === 0) {
            return 'pk_test_' . substr($this->apiKey, 8);
        } elseif (strpos($this->apiKey, 'sk_live_') === 0) {
            return 'pk_live_' . substr($this->apiKey, 8);
        }

        throw new SubscriptionException('Invalid Stripe secret key format');
    }

    /**
     * Create webhook endpoint
     * @throws SubscriptionException
     */
    private function createWebhookEndpoint(): array
    {
        $base = getenv('_APP_AUTH_STRIPE_WEBHOOK_URL');
        if (!$base) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $base = 'https://' . $host;
        }
        if (strpos($base, '{projectId}') !== false || strpos($base, ':projectId') !== false) {
            $webhookUrl = str_replace(['{projectId}', ':projectId'], $this->project->getId(), $base);
        } else {
            $normalized = rtrim($base, '/');
            if (str_ends_with($normalized, '/v1/webhooks/stripe/auth')) {
                $webhookUrl = $normalized . '/' . $this->project->getId();
            } else {
                $webhookUrl = $normalized . '/v1/webhooks/stripe/auth/' . $this->project->getId();
            }
        }

        $params = [
            'url' => $webhookUrl,
            'enabled_events' => [
                'checkout.session.completed',
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted',
                'invoice.payment_failed',
                'invoice.payment_succeeded',
                'product.updated',
                'product.deleted',
                'price.updated',
                'price.deleted'
            ],
            'description' => 'Appwrite Auth Subscription Webhook for Project ' . $this->project->getId()
        ];

        $response = $this->makeRequest('POST', '/webhook_endpoints', $params);

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to create webhook endpoint');
        }

        return $response;
    }

    public function deleteWebhookEndpoint(string $endpointId): array
    {
        return $this->makeRequest('DELETE', '/webhook_endpoints/' . $endpointId);
    }

    /**
     * Create Stripe product
     * @throws SubscriptionException
     */
    public function createProduct(string $name, string $description = '', ?string $planId = null): array
    {
        $params = [
            'name' => $name,
            'description' => $description,
            'metadata' => [
                'project_id' => $this->project->getId(),
                'plan_id' => $planId,
                'type' => 'auth_plan'
            ]
        ];

        $response = $this->makeRequest('POST', '/products', $params);

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to create product');
        }

        return $response;
    }

    /**
     * Create Stripe price
     * @throws SubscriptionException
     */
    public function createPrice(string $productId, int $amount, string $currency, ?string $interval = null, ?string $planId = null): array
    {
        $params = [
            'product' => $productId,
            'unit_amount' => $amount,
            'currency' => $currency,
            'metadata' => [
                'project_id' => $this->project->getId(),
                'plan_id' => $planId,
                'type' => 'auth_plan_price'
            ]
        ];

        if ($interval) {
            $params['recurring'] = ['interval' => $interval];
        }

        $response = $this->makeRequest('POST', '/prices', $params);

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to create price');
        }

        return $response;
    }

    public function createMeteredTieredPrice(
        string $productId,
        string $currency,
        ?string $interval,
        int $includedUnits,
        string $tiersMode,
        array $tiers,
        ?string $planId,
        ?string $featureId,
        ?string $featureName
    ): array {
        $meterId = $this->ensureMeterForFeature($planId, $featureId, $featureName);
        $stripeTiers = [];

        $remainingIncluded = max(0, $includedUnits);
        if ($remainingIncluded > 0) {
            $stripeTiers[] = [
                'up_to' => $remainingIncluded,
                'unit_amount' => 0,
            ];
        }

        foreach ($tiers as $tier) {
            $to = $tier['to'] ?? null; // integer or 'inf'
            $unitAmountMajor = (int) ($tier['unitAmount'] ?? 0);
            $unitAmount = $this->toMinorUnits($unitAmountMajor, $currency);
            $flatAmount = isset($tier['flatAmount']) ? (int) $tier['flatAmount'] : null;

            $entry = [
                'up_to' => $to === null ? 'inf' : $to,
            ];
            if ($unitAmount > 0) {
                $entry['unit_amount'] = $unitAmount;
            } else {
                $entry['unit_amount'] = 0;
            }
            if ($flatAmount !== null) {
                $entry['flat_amount'] = $this->toMinorUnits($flatAmount, $currency);
            }
            $stripeTiers[] = $entry;
        }

        $params = [
            'product' => $productId,
            'currency' => $currency,
            'billing_scheme' => 'tiered',
            'tiers_mode' => $tiersMode === 'volume' ? 'volume' : 'graduated',
            'tiers' => $stripeTiers,
            'recurring' => array_filter([
                'interval' => $interval,
                'usage_type' => 'metered',
                'meter' => $meterId,
            ]),
            'nickname' => $featureName ? ('Feature: ' . $featureName) : null,
            'metadata' => array_filter([
                'project_id' => $this->project->getId(),
                'plan_id' => $planId,
                'feature_id' => $featureId,
                'type' => 'auth_plan_feature_price'
            ])
        ];

        $response = $this->makeRequest('POST', '/prices', $params);

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to create metered price');
        }

        return $response;
    }

    private function toMinorUnits(int $major, string $currency): int
    {
        $currency = strtolower($currency);
        $zeroDecimal = [
            'bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf'
        ];
        if (in_array($currency, $zeroDecimal, true)) {
            return $major;
        }
        return $major * 100;
    }

    public function ensureMeterForFeature(?string $planId, ?string $featureId, ?string $featureName): string
    {
        if ($this->platformDb === null || !$planId || !$featureId) {
            return '';
        }
        $existing = $this->platformDb->findOne('auth_plan_features', [
            Query::equal('projectId', [$this->project->getId()]),
            Query::equal('planId', [$planId]),
            Query::equal('featureId', [$featureId])
        ]);
        if ($existing && (string)$existing->getAttribute('stripeMeterId', '') !== '') {
            return (string)$existing->getAttribute('stripeMeterId');
        }
        $eventName = 'appwrite.auth.feature.usage.' . $this->project->getId() . '.' . $planId . '.' . $featureId;

        $list = $this->makeRequest('GET', '/billing/meters', [ 'limit' => 100 ]);
        if (isset($list['data']) && is_array($list['data'])) {
            foreach ($list['data'] as $m) {
                if (($m['event_name'] ?? '') === $eventName) {
                    $id = (string) ($m['id'] ?? '');
                    if ($id !== '' && ($m['active'] ?? true) === false) {
                        $this->makeRequest('POST', '/billing/meters/' . $id, ['active' => 'true']);
                    }
                    return $id;
                }
            }
        }

        $meter = $this->makeRequest('POST', '/billing/meters', [
            'display_name' => ($featureName ? ($featureName . ' usage') : 'Auth feature usage'),
            'event_name' => $eventName,
            'default_aggregation' => [ 'formula' => 'sum' ],
            'value_settings' => [ 'event_payload_key' => 'value' ],
            'customer_mapping' => [ 'type' => 'by_id', 'event_payload_key' => 'stripe_customer_id' ]
        ]);

        if (isset($meter['error'])) {
            throw new SubscriptionException($meter['error']['message'] ?? 'Failed to create meter');
        }

        return (string)($meter['id'] ?? '');
    }

    /**
     * Deactivate a Stripe price
     * @throws SubscriptionException
     */
    public function deactivatePrice(string $priceId): array
    {
        return $this->makeRequest('POST', '/prices/' . $priceId, ['active' => 'false']);
    }

    /**
     * Get a Stripe price
     * @throws SubscriptionException
     */
    public function getPrice(string $priceId): array
    {
        return $this->makeRequest('GET', '/prices/' . $priceId);
    }

    /**
     * Deactivate a Stripe product
     * @throws SubscriptionException
     */
    public function deactivateProduct(string $productId): array
    {
        return $this->makeRequest('POST', '/products/' . $productId, ['active' => 'false']);
    }

    /**
     * Create checkout session
     * @throws SubscriptionException
     */
    public function createCheckoutSession(Document $user, string $priceId, string $successUrl, string $cancelUrl, ?string $planId = null, array $additionalItems = []): array
    {
        $mainItem = [ 'price' => $priceId ];
        try {
            $price = $this->getPrice($priceId);
            $usageType = (string)($price['recurring']['usage_type'] ?? '');
            if ($usageType !== 'metered') {
                $mainItem['quantity'] = 1;
            }
        } catch (SubscriptionException $e) {
            $mainItem['quantity'] = 1;
        }

        $lineItems = [
            'mode' => 'subscription',
            'line_items' => [ $mainItem ],
        ];

        foreach ($additionalItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entry = [];
            if (!empty($item['price'])) {
                $entry['price'] = $item['price'];
            }
            if (!empty($item['quantity'])) {
                $entry['quantity'] = (int) $item['quantity'];
            }
            if (!empty($entry)) {
                $lineItems['line_items'][] = $entry;
            }
        }

        $params = $lineItems + [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $user->getAttribute('email'),
            'client_reference_id' => $user->getId(),
            'metadata' => array_filter([
                'user_id' => $user->getId(),
                'project_id' => $this->project->getId(),
                'plan_id' => $planId,
            ]),
            'subscription_data' => [
                'metadata' => array_filter([
                    'user_id' => $user->getId(),
                    'project_id' => $this->project->getId(),
                    'plan_id' => $planId,
                ])
            ]
        ];

        if ($user->getAttribute('stripeCustomerId')) {
            $params['customer'] = $user->getAttribute('stripeCustomerId');
            unset($params['customer_email']);
        }

        $response = $this->makeRequest('POST', '/checkout/sessions', $params);

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to create checkout session');
        }

        return $response;
    }

    public function buildAdditionalLineItemsForPlan(string $planId): array
    {
        if ($this->platformDb === null) {
            return [];
        }

        $items = [];
        $assigned = Authorization::skip(fn () => $this->platformDb->find('auth_plan_features', [
            Query::equal('projectId', [$this->project->getId()]),
            Query::equal('planId', [$planId]),
            Query::equal('active', [true])
        ]));

        foreach ($assigned as $af) {
            $type = (string) $af->getAttribute('type');
            $priceId = (string) $af->getAttribute('stripePriceId');
            if ($priceId === '') {
                continue;
            }
            if ($type === 'metered') {
                $items[] = [ 'price' => $priceId ];
                continue;
            }
            $items[] = [ 'price' => $priceId, 'quantity' => 1 ];
        }

        return $items;
    }

    /**
     * Create customer portal session
     * @throws SubscriptionException
     */
    public function createPortalSession(string $customerId, string $returnUrl): array
    {
        $params = [
            'customer' => $customerId,
            'return_url' => $returnUrl
        ];

        $response = $this->makeRequest('POST', '/billing_portal/sessions', $params);

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to create portal session');
        }

        return $response;
    }

    /**
     * Ensure Stripe customer exists for user, return customer ID
     */
    public function ensureCustomer(Document $user): string
    {
        $customerId = (string) $user->getAttribute('stripeCustomerId', '');
        if ($customerId !== '') {
            return $customerId;
        }

        $params = [
            'email' => $user->getAttribute('email'),
            'metadata' => [
                'project_id' => $this->project->getId(),
                'user_id' => $user->getId()
            ]
        ];
        $response = $this->makeRequest('POST', '/customers', $params);
        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to create customer');
        }
        return (string) ($response['id'] ?? '');
    }

    /**
     * Ingest usage for a metered feature
     * @throws SubscriptionException
     */
    public function ingestUsage(int $value, string $userId, string $planId, string $featureId, ?int $timestamp = null, ?string $identifier = null): array
    {
        // Resolve user and ensure Stripe customer id
        $user = Authorization::skip(fn () => $this->database->getDocument('users', $userId));
        if ($user->isEmpty()) {
            throw new SubscriptionException('User not found');
        }

        $customerId = (string) $user->getAttribute('stripeCustomerId', '');
        if ($customerId === '') {
            // Create customer if missing
            $customerId = $this->ensureCustomer($user);
            if ($customerId === '') {
                throw new SubscriptionException('Failed to ensure Stripe customer for user');
            }
            $user = $user->setAttribute('stripeCustomerId', $customerId);
            Authorization::skip(fn () => $this->database->updateDocument('users', $userId, $user));
        }

        // Determine window timestamps
        $periodStart = $user->getAttribute('subscriptionCurrentPeriodStart');
        $periodEnd = $user->getAttribute('subscriptionCurrentPeriodEnd');
        $startTs = $periodStart ? strtotime((string)$periodStart) : 0;
        $endTs = $periodEnd ? strtotime((string)$periodEnd) : time();

        // Enforce usage caps if defined on the plan feature assignment (prefer local DB total to avoid Stripe latency)
        if ($this->platformDb) {
            $assignment = Authorization::skip(fn () => $this->platformDb->findOne('auth_plan_features', [
                Query::equal('projectId', [$this->project->getId()]),
                Query::equal('planId', [$planId]),
                Query::equal('featureId', [$featureId]),
                Query::equal('active', [true])
            ]));
            if ($assignment && !$assignment->isEmpty()) {
                $cap = $assignment->getAttribute('usageCap');
                if ($cap !== null) {
                    // First, try local DB sum
                    $current = 0;
                    try {
                        $current = $this->getFeatureUsageTotalLocal($user->getId(), $planId, $featureId, $startTs, $endTs);
                    } catch (\Throwable $_) {
                    }
                    // Fallback to Stripe if local gives 0 and we have a customer
                    if ($current === 0) {
                        try {
                            $current = $this->getFeatureUsageTotal($customerId, $planId, $featureId, $startTs, $endTs);
                        } catch (\Throwable $_) {
                        }
                    }
                    if ($current + $value > (int)$cap) {
                        throw new SubscriptionException('Usage cap exceeded for feature: ' . $featureId);
                    }
                }
            }
        }

        // Build event name based on the provisioned meter convention
        $eventName = 'appwrite.auth.feature.usage.' . $this->project->getId() . '.' . $planId . '.' . $featureId;

        $params = [
            'event_name' => $eventName,
            'payload' => [
                'value' => (string) $value,
                'stripe_customer_id' => $customerId,
            ],
        ];
        if (!empty($identifier)) {
            $params['identifier'] = $identifier;
        }
        if (!empty($timestamp)) {
            $params['timestamp'] = (string) $timestamp;
        }

        // Write usage locally for fast reads and idempotency checks
        $eventDocument = new Document([
            '$id' => ID::unique(),
            'projectInternalId' => $this->project->getSequence(),
            'projectId' => $this->project->getId(),
            'userId' => $user->getId(),
            'planId' => $planId,
            'featureId' => $featureId,
            'value' => $value,
            'identifier' => $identifier,
            'timestamp' => isset($timestamp)
                ? DatabaseDateTime::formatTz(gmdate('c', (int)$timestamp))
                : DatabaseDateTime::formatTz(gmdate('c')),
        ]);
        try {
            Authorization::skip(fn () => $this->platformDb?->createDocument('auth_usage_events', $eventDocument));
        } catch (\Throwable $e) {
            // best-effort; do not block ingestion if local write fails
        }

        // Send to Stripe (async on Stripe side)
        $response = $this->makeRequest('POST', '/billing/meter_events', $params);

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to ingest usage');
        }

        return $response;
    }

    /**
     * Get total usage for a feature from local DB within a time window
     */
    public function getFeatureUsageTotalLocal(string $userId, string $planId, string $featureId, int $startTime, int $endTime): int
    {
        if ($this->platformDb === null) {
            return 0;
        }
        // Sum within current billing window; if invalid window, approximate using feature interval (default month)
        if ($startTime <= 0 || $endTime <= 0) {
            // Approximate: last 30 days
            $endTime = time();
            $startTime = $endTime - (86400 * 30);
        }
        $fromStr = DatabaseDateTime::formatTz(gmdate('c', (int)$startTime));
        $toStr = DatabaseDateTime::formatTz(gmdate('c', (int)$endTime));
        $filters = [
            Query::equal('projectId', [$this->project->getId()]),
            Query::equal('userId', [$userId]),
            Query::equal('planId', [$planId]),
            Query::equal('featureId', [$featureId]),
            Query::greaterThanEqual('timestamp', $fromStr),
            Query::lessThanEqual('timestamp', $toStr),
            Query::limit(100000),
        ];
        $events = Authorization::skip(fn () => $this->platformDb->find('auth_usage_events', $filters));
        $total = 0;
        foreach ($events as $e) {
            $total += (int) $e->getAttribute('value', 0);
        }
        return $total;
    }

    /**
     * Get total usage for a feature for a customer within a time window
     * Returns integer total (sum) if available, otherwise 0.
     * @throws SubscriptionException
     */
    public function getFeatureUsageTotal(string $customerId, string $planId, string $featureId, int $startTime, int $endTime): int
    {
        if ($customerId === '' || $startTime <= 0 || $endTime <= 0) {
            return 0;
        }

        // Find meter id from platform DB
        if ($this->platformDb === null) {
            return 0;
        }
        $assigned = Authorization::skip(fn () => $this->platformDb->findOne('auth_plan_features', [
            Query::equal('projectId', [$this->project->getId()]),
            Query::equal('planId', [$planId]),
            Query::equal('featureId', [$featureId]),
            Query::equal('active', [true])
        ]));
        if (!$assigned || $assigned->isEmpty()) {
            return 0;
        }
        $meterId = (string) $assigned->getAttribute('stripeMeterId', '');
        if ($meterId === '') {
            return 0;
        }

        $params = [
            'meter' => $meterId,
            'customer' => $customerId,
            'start_time' => (string) $startTime,
            'end_time' => (string) $endTime,
        ];

        // Stripe may expose this endpoint as meter_event_summaries
        $summary = $this->makeRequest('GET', '/billing/meter_event_summaries', $params);
        if (isset($summary['error'])) {
            return 0;
        }
        // Attempt to parse common shapes
        if (isset($summary['data']) && is_array($summary['data'])) {
            $total = 0;
            foreach ($summary['data'] as $row) {
                $val = (int) ($row['value'] ?? 0);
                $total += $val;
            }
            return $total;
        }
        if (isset($summary['total'])) {
            return (int) $summary['total'];
        }
        if (isset($summary['value'])) {
            return (int) $summary['value'];
        }
        return 0;
    }

    /**
     * Create a complimentary subscription (trial for one interval, auto-cancel)
     */
    public function createComplimentarySubscription(string $customerId, string $priceId, string $interval, ?string $userId = null): array
    {
        $now = time();
        $seconds = 0;
        switch ($interval) {
            case 'day':
                $seconds = 86400;
                break;
            case 'week':
                $seconds = 86400 * 7;
                break;
            case 'year':
                $seconds = 86400 * 365;
                break;
            case 'month':
            default:
                $seconds = 86400 * 30;
                break;
        }
        $trialEnd = $now + $seconds;

        $params = [
            'customer' => $customerId,
            'items' => [[ 'price' => $priceId ]],
            'trial_end' => $trialEnd,
            'cancel_at' => $trialEnd,
            'payment_behavior' => 'default_incomplete',
            'metadata' => [
                'project_id' => $this->project->getId(),
                'user_id' => $userId
            ]
        ];

        $response = $this->makeRequest('POST', '/subscriptions', $params);

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to create subscription');
        }

        return $response;
    }

    /**
     * Cancel active subscription immediately or at period end
     */
    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = true): array
    {
        $params = [ 'cancel_at_period_end' => $atPeriodEnd ? 'true' : 'false' ];
        $response = $this->makeRequest('DELETE', '/subscriptions/' . $subscriptionId, $params);

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to cancel subscription');
        }

        return $response;
    }

    /**
     * Find an active (or trialing) subscription ID for a customer
     */
    public function findActiveSubscriptionId(string $customerId): ?string
    {
        $params = [
            'customer' => $customerId,
            'limit' => 100
        ];

        $response = $this->makeRequest('GET', '/subscriptions', $params);

        if (!isset($response['data']) || !is_array($response['data'])) {
            return null;
        }

        foreach ($response['data'] as $sub) {
            $status = (string) ($sub['status'] ?? '');
            if (in_array($status, ['active', 'trialing', 'past_due', 'unpaid'], true)) {
                return (string) ($sub['id'] ?? '');
            }
        }

        return null;
    }

    /**
     * Get subscription details
     * @throws SubscriptionException
     */
    public function getSubscription(string $subscriptionId): array
    {
        $response = $this->makeRequest('GET', '/subscriptions/' . $subscriptionId);

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to get subscription');
        }

        return $response;
    }

    // duplicate removed

    /**
     * Update subscription (change plan)
     * @throws SubscriptionException
     */
    public function updateSubscription(string $subscriptionId, string $newPriceId): array
    {
        $subscription = $this->getSubscription($subscriptionId);

        $params = [
            'items' => [
                [
                    'id' => $subscription['items']['data'][0]['id'],
                    'price' => $newPriceId
                ]
            ],
            'proration_behavior' => 'create_prorations'
        ];

        $response = $this->makeRequest('POST', '/subscriptions/' . $subscriptionId, $params);

        if (isset($response['error'])) {
            throw new SubscriptionException($response['error']['message'] ?? 'Failed to update subscription');
        }

        return $response;
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        $timestamp = null;
        $v1Signatures = [];

        foreach (explode(',', (string) $signature) as $segment) {
            $segment = trim($segment);
            $parts = explode('=', $segment, 2);
            if (count($parts) !== 2) {
                continue;
            }
            if ($parts[0] === 't') {
                $timestamp = $parts[1];
            }
            if ($parts[0] === 'v1') {
                $v1Signatures[] = $parts[1];
            }
        }

        if (!$timestamp || empty($v1Signatures)) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, trim((string) $secret));

        foreach ($v1Signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle webhook event
     * @throws SubscriptionException
     */
    public function handleWebhook(array $event): void
    {
        switch ($event['type']) {
            case 'checkout.session.completed':
                $this->handleCheckoutCompleted($event['data']['object']);
                break;

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdate($event['data']['object']);
                break;

            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($event['data']['object']);
                break;

            case 'invoice.payment_failed':
                $this->handlePaymentFailed($event['data']['object']);
                break;

            case 'invoice.payment_succeeded':
                $this->handlePaymentSucceeded($event['data']['object']);
                break;

                // Keep Appwrite in sync with Stripe-side changes
            case 'product.updated':
                $this->handleProductUpdated($event['data']['object']);
                break;
            case 'product.deleted':
                $this->handleProductDeleted($event['data']['object']);
                break;
            case 'price.updated':
                $this->handlePriceUpdated($event['data']['object']);
                break;
            case 'price.deleted':
                $this->handlePriceDeleted($event['data']['object']);
                break;
        }
    }

    /**
     * Product updated
     */
    private function handleProductUpdated(array $product): void
    {
        if ($this->platformDb === null) {
            return;
        }
        $stripeProductId = $product['id'] ?? '';
        if (empty($stripeProductId)) {
            return;
        }
        $plan = $this->platformDb->findOne('auth_plans', [
            Query::equal('stripeProductId', [$stripeProductId]),
            Query::equal('projectId', [$this->project->getId()])
        ]);
        if ($plan->isEmpty()) {
            return;
        }
        $plan->setAttribute('name', $product['name'] ?? $plan->getAttribute('name'));
        if (isset($product['active']) && $product['active'] === false) {
            // If product deactivated, mark plan inactive
            $plan->setAttribute('active', false);
        }
        $this->platformDb->updateDocument('auth_plans', $plan->getId(), $plan);
    }

    /**
     * Product deleted
     */
    private function handleProductDeleted(array $product): void
    {
        if ($this->platformDb === null) {
            return;
        }
        $stripeProductId = $product['id'] ?? '';
        if (empty($stripeProductId)) {
            return;
        }
        $plan = $this->platformDb->findOne('auth_plans', [
            Query::equal('stripeProductId', [$stripeProductId]),
            Query::equal('projectId', [$this->project->getId()])
        ]);
        if (!$plan->isEmpty()) {
            $this->platformDb->deleteDocument('auth_plans', $plan->getId());
        }
    }

    /**
     * Price updated
     */
    private function handlePriceUpdated(array $price): void
    {
        if ($this->platformDb === null) {
            return;
        }
        $stripePriceId = $price['id'] ?? '';
        if (empty($stripePriceId)) {
            return;
        }
        $plan = $this->platformDb->findOne('auth_plans', [
            Query::equal('stripePriceId', [$stripePriceId]),
            Query::equal('projectId', [$this->project->getId()])
        ]);
        if ($plan->isEmpty()) {
            return;
        }
        if (isset($price['active']) && $price['active'] === false) {
            $plan->setAttribute('active', false);
        }
        if (isset($price['unit_amount'])) {
            $plan->setAttribute('price', (int)$price['unit_amount']);
        }
        if (isset($price['currency'])) {
            $plan->setAttribute('currency', $price['currency']);
        }
        if (isset($price['recurring']['interval'])) {
            $plan->setAttribute('interval', $price['recurring']['interval']);
        }
        $this->platformDb->updateDocument('auth_plans', $plan->getId(), $plan);
    }

    /**
     * Price deleted
     */
    private function handlePriceDeleted(array $price): void
    {
        if ($this->platformDb === null) {
            return;
        }
        $stripePriceId = $price['id'] ?? '';
        if (empty($stripePriceId)) {
            return;
        }
        $plan = $this->platformDb->findOne('auth_plans', [
            Query::equal('stripePriceId', [$stripePriceId]),
            Query::equal('projectId', [$this->project->getId()])
        ]);
        if (!$plan->isEmpty()) {
            $this->platformDb->deleteDocument('auth_plans', $plan->getId());
        }
    }

    /**
     * Handle checkout completed
     */
    private function handleCheckoutCompleted(array $session): void
    {
        $userId = $session['metadata']['user_id'] ?? null;
        if (!$userId) {
            return;
        }

        $user = Authorization::skip(fn () => $this->database->getDocument('users', $userId));
        if ($user->isEmpty()) {
            return;
        }

        $updated = $user
            ->setAttribute('stripeCustomerId', $session['customer'])
            ->setAttribute('stripeSubscriptionId', $session['subscription']);
        Authorization::skip(fn () => $this->database->updateDocument('users', $userId, $updated));

        if ($session['subscription']) {
            $subscription = $this->getSubscription($session['subscription']);
            $this->syncSubscriptionStatus($userId, $subscription);
        }
    }

    /**
     * Handle subscription update
     */
    private function handleSubscriptionUpdate(array $subscription): void
    {
        $userId = $subscription['metadata']['user_id'] ?? null;
        if (!$userId) {
            return;
        }

        $full = isset($subscription['id']) ? $this->getSubscription($subscription['id']) : $subscription;
        $this->syncSubscriptionStatus($userId, $full);
    }

    /**
     * Handle subscription deleted
     */
    private function handleSubscriptionDeleted(array $subscription): void
    {
        $userId = $subscription['metadata']['user_id'] ?? null;
        if (!$userId) {
            return;
        }

        $user = Authorization::skip(fn () => $this->database->getDocument('users', $userId));
        if ($user->isEmpty()) {
            return;
        }

        $defaultPlan = null;
        if ($this->platformDb) {
            $defaultPlan = $this->platformDb->findOne('auth_plans', [
                Query::equal('projectId', [$this->project->getId()]),
                Query::equal('isDefault', [true]),
                Query::equal('isFree', [true])
            ]);
        }

        $updated = $user
            ->setAttribute('stripeSubscriptionId', null)
            ->setAttribute('subscriptionStatus', 'none')
            ->setAttribute('subscriptionCurrentPeriodStart', null)
            ->setAttribute('subscriptionCurrentPeriodEnd', null)
            ->setAttribute('subscriptionCancelAtPeriodEnd', false)
            ->setAttribute('planId', $defaultPlan ? $defaultPlan->getAttribute('planId') : null);
        Authorization::skip(fn () => $this->database->updateDocument('users', $userId, $updated));
    }

    /**
     * Handle payment failed
     */
    private function handlePaymentFailed(array $invoice): void
    {
        $subscriptionId = $invoice['subscription'] ?? null;
        if (!$subscriptionId) {
            return;
        }

        $subscription = $this->getSubscription($subscriptionId);
        $userId = $subscription['metadata']['user_id'] ?? null;

        if ($userId) {
            $user = Authorization::skip(fn () => $this->database->getDocument('users', $userId));
            if (!$user->isEmpty()) {
                $updated = $user->setAttribute('subscriptionStatus', 'past_due');
                Authorization::skip(fn () => $this->database->updateDocument('users', $userId, $updated));
            }
        }
    }

    /**
     * Handle payment succeeded
     */
    private function handlePaymentSucceeded(array $invoice): void
    {
        $subscriptionId = $invoice['subscription'] ?? null;
        if (!$subscriptionId) {
            return;
        }

        $subscription = $this->getSubscription($subscriptionId);
        $userId = $subscription['metadata']['user_id'] ?? null;

        if ($userId) {
            $this->syncSubscriptionStatus($userId, $subscription);
        }
    }

    /**
     * Sync subscription status to user
     */
    public function syncSubscriptionStatus(string $userId, array $subscription): void
    {
        $user = Authorization::skip(fn () => $this->database->getDocument('users', $userId));
        if ($user->isEmpty()) {
            return;
        }

        $priceId = $subscription['items']['data'][0]['price']['id'] ?? null;
        $status = (string) ($subscription['status'] ?? '');
        $cancelAtPeriodEnd = (bool) ($subscription['cancel_at_period_end'] ?? false);
        $periodStart = isset($subscription['current_period_start']) ? date('c', $subscription['current_period_start']) : null;
        $periodEndTs = $subscription['current_period_end'] ?? null;
        $periodEnd = $periodEndTs ? date('c', $periodEndTs) : null;

        $plan = null;
        if ($priceId && $this->platformDb) {
            $plan = Authorization::skip(fn () => $this->platformDb->findOne('auth_plans', [
                Query::equal('stripePriceId', [$priceId]),
                Query::equal('projectId', [$this->project->getId()])
            ]));
        }
        if (!$plan && $this->platformDb) {
            $productId = $subscription['items']['data'][0]['price']['product'] ?? null;
            if (!empty($productId)) {
                $fallback = Authorization::skip(fn () => $this->platformDb->findOne('auth_plans', [
                    Query::equal('stripeProductId', [$productId]),
                    Query::equal('projectId', [$this->project->getId()])
                ]));
                if ($fallback) {
                    $plan = $fallback;
                }
            }
        }
        if (!$plan && $this->platformDb) {
            $metaPlanId = $subscription['metadata']['plan_id'] ?? null;
            if (!empty($metaPlanId)) {
                $fallback = Authorization::skip(fn () => $this->platformDb->findOne('auth_plans', [
                    Query::equal('planId', [$metaPlanId]),
                    Query::equal('projectId', [$this->project->getId()])
                ]));
                if ($fallback) {
                    $plan = $fallback;
                }
            }
        }


        $fallbackToFree = ($status === 'canceled' || $status === 'incomplete_expired' || ($cancelAtPeriodEnd && $periodEndTs && time() >= (int) $periodEndTs));
        $defaultPlan = null;
        if ($fallbackToFree && $this->platformDb) {
            $defaultPlan = Authorization::skip(fn () => $this->platformDb->findOne('auth_plans', [
                Query::equal('projectId', [$this->project->getId()]),
                Query::equal('isDefault', [true]),
                Query::equal('isFree', [true])
            ]));
        }

        $updated = $user
            ->setAttribute('planId', $fallbackToFree && $defaultPlan ? $defaultPlan->getAttribute('planId') : ($plan ? $plan->getAttribute('planId') : null))
            ->setAttribute('subscriptionStatus', $status)
            ->setAttribute('subscriptionCurrentPeriodStart', $periodStart)
            ->setAttribute('subscriptionCurrentPeriodEnd', $periodEnd)
            ->setAttribute('subscriptionCancelAtPeriodEnd', $cancelAtPeriodEnd)
            ->setAttribute('subscriptionTrialEnd', isset($subscription['trial_end']) ? date('c', $subscription['trial_end']) : null);
        Authorization::skip(fn () => $this->database->updateDocument('users', $userId, $updated));
    }

    /**
     * Make request to Stripe API
     */
    private function makeRequest(string $method, string $path, array $params = []): array
    {
        $ch = curl_init();

        $url = $this->apiUrl . $path;


        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        switch ($method) {
            case 'GET':
                if (!empty($params)) {
                    $url .= '?' . http_build_query($params);
                }
                break;

            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if (!empty($params)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $this->buildFormData($params));
                }
                break;

            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        curl_setopt($ch, CURLOPT_URL, $url);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false) {
            throw new SubscriptionException('Failed to connect to Stripe API');
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            if (isset($data['error'])) {
                throw new SubscriptionException($data['error']['message'] ?? 'Stripe API error', $httpCode);
            }
            throw new SubscriptionException('Stripe API request failed', $httpCode);
        }

        return $data;
    }

    /**
     * Build form data for nested arrays
     */
    private function buildFormData(array $params, string $prefix = ''): string
    {
        $data = [];

        foreach ($params as $key => $value) {
            $key = $prefix ? "{$prefix}[{$key}]" : $key;

            if (is_array($value)) {
                if (isset($value[0])) {
                    foreach ($value as $index => $item) {
                        if (is_array($item)) {
                            $data[] = $this->buildFormData($item, "{$key}[{$index}]");
                        } else {
                            $data[] = urlencode("{$key}[{$index}]") . '=' . urlencode($item);
                        }
                    }
                } else {
                    $data[] = $this->buildFormData($value, $key);
                }
            } else {
                $data[] = urlencode($key) . '=' . urlencode($value);
            }
        }

        return implode('&', $data);
    }
}
