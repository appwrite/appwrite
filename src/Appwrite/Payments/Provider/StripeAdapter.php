<?php

namespace Appwrite\Payments\Provider;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;

class StripeAdapter implements Adapter
{
    public function __construct(
        private readonly array $config,
        private readonly Document $project,
        private readonly Database $dbForProject,
        private readonly Database $dbForPlatform,
    ) {
    }

    public function getIdentifier(): string
    {
        return 'stripe';
    }

    public function configure(array $config, Document $project): ProviderState
    {
        $apiKey = (string) ($config['secretKey'] ?? '');
        $accountResponse = $this->request($apiKey, 'GET', '/account');
        $account = $this->decodeResponse($accountResponse);
        $rawDomain = (string) System::getEnv('_APP_DOMAIN', '');
        $domain = \trim($rawDomain);
        if ($domain === '' || \in_array(\strtolower($domain), ['localhost', '127.0.0.1'], true)) {
            throw new \RuntimeException('Appwrite domain is not configured. Set _APP_DOMAIN to a publicly accessible hostname before configuring Stripe.');
        }
        $domain = (string) \preg_replace('/^\s*https?:\/\//i', '', $domain);
        $domain = \ltrim($domain, '/');
        $domain = \rtrim($domain, '/');
        $forceHttps = System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'enabled') !== 'disabled';
        $scheme = $forceHttps ? 'https' : 'http';
        $webhookUrl = $scheme . '://' . $domain . '/v1/payments/webhooks/stripe/' . $project->getId();
        $endpointResponse = $this->request($apiKey, 'POST', '/webhook_endpoints', [
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
            'description' => 'Appwrite Payments Webhook for Project ' . $project->getId()
        ]);

        $endpointData = $this->decodeResponse($endpointResponse);

        $meta = [
            'currency' => $account['default_currency'] ?? 'usd',
            'webhookEndpointId' => $endpointData['id'] ?? null,
            'webhookSecret' => $endpointData['secret'] ?? null,
        ];
        return new ProviderState($this->getIdentifier(), $config, $meta);
    }

    public function ensurePlan(array $planData, ProviderState $state): ProviderPlanRef
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $name = (string) ($planData['name'] ?? '');
        $description = (string) ($planData['description'] ?? '');
        $pricing = (array) ($planData['pricing'] ?? []);

        $providerPlanRaw = $planData['providers']['stripe']['planId'] ?? '';
        $providerPlanDecoded = \is_string($providerPlanRaw) ? \json_decode($providerPlanRaw, true) : $providerPlanRaw;
        $providerPlanId = '';
        if (\is_array($providerPlanDecoded)) {
            $providerPlanId = (string) ($providerPlanDecoded['id'] ?? '');
        } elseif (\is_string($providerPlanDecoded)) {
            $providerPlanId = $providerPlanDecoded;
        }

        if ($providerPlanId !== '') {
            $existingProduct = $this->request($apiKey, 'GET', '/products/' . $providerPlanId);
            if (($existingProduct['status'] ?? 0) === 200) {
                $data = $this->decodeResponse($existingProduct);
                $productId = (string) ($data['id'] ?? '');
                $priceMap = $this->mapStripePricesForPlan(
                    $apiKey,
                    $productId,
                    $pricing
                );
                return new ProviderPlanRef(
                    externalPlanId: $productId,
                    metadata: [
                        'productId' => $productId,
                        'prices' => $priceMap,
                    ]
                );
            }
        }

        $product = $this->request($apiKey, 'POST', '/products', [
            'name' => $name,
            'description' => $description,
            'metadata' => [
                'project_id' => $this->project->getId(),
                'plan_id' => (string) ($planData['planId'] ?? '')
            ]
        ]);
        $productData = $this->decodeResponse($product);
        $productId = (string) ($productData['id'] ?? '');
        $priceMap = [];
        foreach ($pricing as $price) {
            if (!is_array($price)) {
                continue;
            }
            $internalPriceId = (string) ($price['priceId'] ?? '');
            if ($internalPriceId === '') {
                continue;
            }
            $amount = (int) ($price['amount'] ?? 0);
            $currency = (string) ($price['currency'] ?? ($state->metadata['currency'] ?? 'usd'));
            $interval = (string) ($price['interval'] ?? 'month');
            $res = $this->request($apiKey, 'POST', '/prices', [
                'product' => $productId,
                'unit_amount' => $amount,
                'currency' => $currency,
                'recurring' => [ 'interval' => $interval ],
                'metadata' => [
                    'plan_id' => (string) ($planData['planId'] ?? ''),
                    'type' => 'payments_plan_price',
                    'internal_price_id' => $internalPriceId,
                ]
            ]);
            $resData = $this->decodeResponse($res);
            $providerPriceId = (string) ($resData['id'] ?? '');
            if ($providerPriceId !== '') {
                $priceMap[$internalPriceId] = $providerPriceId;
            }
        }
        return new ProviderPlanRef(
            externalPlanId: $productId,
            metadata: [
                'productId' => $productId,
                'prices' => $priceMap,
            ]
        );
    }

    /**
     * @param array<int|string, mixed> $pricing
     * @return array<string,string>
     */
    private function mapStripePricesForPlan(string $apiKey, string $productId, array $pricing): array
    {
        if ($productId === '') {
            return [];
        }

        $pricingIndex = [];
        foreach ($pricing as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $internalId = (string) ($entry['priceId'] ?? '');
            if ($internalId === '') {
                continue;
            }
            $pricingIndex[$internalId] = [
                'amount' => (int) ($entry['amount'] ?? 0),
                'currency' => \strtolower((string) ($entry['currency'] ?? '')),
                'interval' => (string) ($entry['interval'] ?? ''),
            ];
        }

        $priceMap = [];
        try {
            $response = $this->request($apiKey, 'GET', '/prices', [
                'product' => $productId,
                'limit' => 100,
            ]);
            $data = $this->decodeResponse($response);
        } catch (\Throwable $_) {
            return [];
        }

        $assigned = [];
        if (isset($data['data']) && \is_array($data['data'])) {
            foreach ($data['data'] as $price) {
                if (!\is_array($price)) {
                    continue;
                }
                $providerPriceId = (string) ($price['id'] ?? '');
                if ($providerPriceId === '') {
                    continue;
                }
                $internalId = (string) ($price['metadata']['internal_price_id'] ?? '');
                if ($internalId !== '') {
                    $priceMap[$internalId] = $providerPriceId;
                    $assigned[$internalId] = true;
                }
            }

            foreach ($data['data'] as $price) {
                if (!\is_array($price)) {
                    continue;
                }
                $providerPriceId = (string) ($price['id'] ?? '');
                if ($providerPriceId === '') {
                    continue;
                }
                $internalId = (string) ($price['metadata']['internal_price_id'] ?? '');
                if ($internalId !== '') {
                    continue;
                }
                $amount = (int) ($price['unit_amount'] ?? 0);
                $currency = \strtolower((string) ($price['currency'] ?? ''));
                $interval = (string) ($price['recurring']['interval'] ?? '');

                foreach ($pricingIndex as $candidateId => $details) {
                    if (isset($assigned[$candidateId])) {
                        continue;
                    }
                    if ($details['amount'] === $amount && $details['currency'] === $currency && $details['interval'] === $interval) {
                        $priceMap[$candidateId] = $providerPriceId;
                        $assigned[$candidateId] = true;
                        break;
                    }
                }
            }
        }

        return $priceMap;
    }

    public function updatePlan(array $planData, ProviderPlanRef $reference, ProviderState $state): ProviderPlanRef
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');

        // Update product name/description if provided
        $updates = [];
        if (isset($planData['name']) && $planData['name'] !== '') {
            $updates['name'] = (string) $planData['name'];
        }
        if (isset($planData['description'])) {
            $updates['description'] = (string) $planData['description'];
        }
        if (!empty($updates) && $reference->externalPlanId !== '') {
            $this->request($apiKey, 'POST', '/products/' . $reference->externalPlanId, $updates);
        }

        // Reconcile prices: create new prices for provided pricing entries; deactivate orphaned
        $newPricing = (array) ($planData['pricing'] ?? []);
        $productId = $reference->externalPlanId !== '' ? $reference->externalPlanId : (string) ($reference->metadata['productId'] ?? '');
        $existingPrices = (array) ($reference->metadata['prices'] ?? []);
        if (empty($existingPrices) && $productId !== '') {
            $existingPrices = $this->mapStripePricesForPlan($apiKey, $productId, $newPricing);
        }

        $sanitizedExisting = [];
        $existingDetails = [];
        foreach ($existingPrices as $internalId => $providerPriceIdRaw) {
            $providerPriceId = \is_array($providerPriceIdRaw) ? (string) ($providerPriceIdRaw['id'] ?? '') : (string) $providerPriceIdRaw;
            if ($providerPriceId === '') {
                continue;
            }
            $sanitizedExisting[$internalId] = $providerPriceId;
            try {
                $price = $this->request($apiKey, 'GET', '/prices/' . $providerPriceId);
                $existingDetails[$internalId] = $this->decodeResponse($price);
            } catch (\Throwable $_) {
                $existingDetails[$internalId] = null;
            }
        }

        $remainingExisting = $sanitizedExisting;
        $newMap = [];
        foreach ($newPricing as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $internalPriceId = (string) ($entry['priceId'] ?? '');
            if ($internalPriceId === '') {
                continue;
            }
            $amount = (int) ($entry['amount'] ?? 0);
            $currency = \strtolower((string) ($entry['currency'] ?? ($state->metadata['currency'] ?? 'usd')));
            $interval = (string) ($entry['interval'] ?? 'month');

            $reuseExisting = false;
            if (isset($existingDetails[$internalPriceId]) && \is_array($existingDetails[$internalPriceId])) {
                $current = $existingDetails[$internalPriceId];
                $currentAmount = (int) ($current['unit_amount'] ?? 0);
                $currentCurrency = \strtolower((string) ($current['currency'] ?? ''));
                $currentInterval = (string) ($current['recurring']['interval'] ?? '');
                if ($currentAmount === $amount && $currentCurrency === $currency && $currentInterval === $interval) {
                    $newMap[$internalPriceId] = $remainingExisting[$internalPriceId];
                    unset($remainingExisting[$internalPriceId]);
                    $reuseExisting = true;
                }
            }

            if ($reuseExisting) {
                continue;
            }

            if ($productId === '') {
                throw new \RuntimeException('Stripe product ID missing for plan update');
            }

            $res = $this->request($apiKey, 'POST', '/prices', [
                'product' => $productId,
                'unit_amount' => $amount,
                'currency' => $currency,
                'recurring' => [ 'interval' => $interval ],
                'metadata' => [
                    'plan_id' => (string) ($planData['planId'] ?? ''),
                    'type' => 'payments_plan_price',
                    'internal_price_id' => $internalPriceId,
                ]
            ]);
            $resData = $this->decodeResponse($res);
            $providerPriceId = (string) ($resData['id'] ?? '');
            if ($providerPriceId !== '') {
                $newMap[$internalPriceId] = $providerPriceId;
            }
        }

        // Deactivate any existing price not in desired set
        foreach ($remainingExisting as $providerPriceId) {
            if ($providerPriceId !== '') {
                $this->request($apiKey, 'POST', '/prices/' . $providerPriceId, ['active' => 'false']);
            }
        }

        return new ProviderPlanRef(
            externalPlanId: $productId,
            metadata: [
                'productId' => $productId,
                'prices' => $newMap,
            ]
        );
    }

    public function deletePlan(ProviderPlanRef $reference, ProviderState $state): void
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $meta = $reference->metadata;
        $priceRefs = (array) ($meta['prices'] ?? []);
        foreach ($priceRefs as $entry) {
            $priceId = \is_array($entry) ? (string) ($entry['id'] ?? '') : (string) $entry;
            if ($priceId !== '') {
                $this->request($apiKey, 'POST', '/prices/' . $priceId, ['active' => 'false']);
            }
        }
        if (!empty($reference->externalPlanId)) {
            $this->request($apiKey, 'POST', '/products/' . $reference->externalPlanId, ['active' => 'false']);
        }
    }

    public function ensureFeature(array $featureData, ProviderPlanRef $plan, ProviderState $state): ProviderFeatureRef
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $currency = (string) ($featureData['currency'] ?? ($state->metadata['currency'] ?? 'usd'));
        $interval = (string) ($featureData['interval'] ?? 'month');
        $featureId = (string) ($featureData['featureId'] ?? '');
        $featureName = (string) ($featureData['name'] ?? $featureId);
        $meter = $this->ensureMeter($apiKey, $featureName, $this->project->getId(), (string) ($featureData['planId'] ?? ''), $featureId);

        $priceParams = [
            'product' => $plan->externalPlanId,
            'currency' => $currency,
            'recurring' => [ 'interval' => $interval, 'usage_type' => 'metered', 'meter' => $meter ],
            'metadata' => [ 'feature_id' => $featureId, 'type' => 'payments_plan_feature_price' ]
        ];

        // Handle tiered pricing
        $tiers = $featureData['tiers'] ?? [];
        $tiersMode = $featureData['tiersMode'] ?? null;
        $includedUnits = (int) ($featureData['includedUnits'] ?? 0);

        if (!empty($tiers) && $tiersMode) {
            $priceParams['billing_scheme'] = 'tiered';
            $priceParams['tiers_mode'] = $tiersMode;

            // If includedUnits is set, prepend a free tier
            if ($includedUnits > 0) {
                array_unshift($tiers, [
                    'up_to' => $includedUnits,
                    'unit_amount' => 0
                ]);
            }

            $priceParams['tiers'] = $tiers;
        } else {
            $priceParams['unit_amount'] = 0;
        }

        $price = $this->request($apiKey, 'POST', '/prices', $priceParams);
        $priceData = $this->decodeResponse($price);
        return new ProviderFeatureRef(
            externalFeatureId: (string) ($priceData['id'] ?? ''),
            metadata: [
                'priceId' => $priceData['id'] ?? null,
                'meterId' => $meter,
            ]
        );
    }

    public function deleteFeature(ProviderFeatureRef $feature, ProviderPlanRef $plan, ProviderState $state): void
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $priceId = (string) ($feature->metadata['priceId'] ?? '');
        if ($priceId !== '') {
            $this->request($apiKey, 'POST', '/prices/' . $priceId, ['active' => 'false']);
        }
        // Stripe meters typically continue to exist; no deletion API needed for now
    }

    public function updateSubscription(ProviderSubscriptionRef $subscription, array $changes, ProviderState $state): ProviderSubscriptionRef
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $newPriceId = (string) ($changes['priceId'] ?? '');
        if ($newPriceId !== '') {
            $sub = $this->request($apiKey, 'GET', '/subscriptions/' . $subscription->externalSubscriptionId);
            $subData = $this->decodeResponse($sub);
            $itemId = $subData['items']['data'][0]['id'] ?? '';
            if ($itemId !== '') {
                $this->request($apiKey, 'POST', '/subscriptions/' . $subscription->externalSubscriptionId, [
                    'items' => [ [ 'id' => $itemId, 'price' => $newPriceId ] ],
                    'proration_behavior' => 'create_prorations'
                ]);
            }
        }
        return $subscription;
    }

    public function cancelSubscription(ProviderSubscriptionRef $subscription, bool $atPeriodEnd, ProviderState $state): ProviderSubscriptionRef
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $this->request($apiKey, 'DELETE', '/subscriptions/' . $subscription->externalSubscriptionId, [ 'cancel_at_period_end' => $atPeriodEnd ? 'true' : 'false' ]);
        return $subscription;
    }

    public function resumeSubscription(ProviderSubscriptionRef $subscription, ProviderState $state): ProviderSubscriptionRef
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $this->request($apiKey, 'POST', '/subscriptions/' . $subscription->externalSubscriptionId, [ 'cancel_at_period_end' => 'false' ]);
        return $subscription;
    }

    public function createCheckoutSession(Document $actor, array $planContext, ProviderState $state, array $options = []): ProviderCheckoutSession
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $successUrl = (string) ($options['successUrl'] ?? '');
        $cancelUrl = (string) ($options['cancelUrl'] ?? '');
        $priceId = (string) ($planContext['priceId'] ?? '');
        $params = [
            'mode' => 'subscription',
            'line_items' => [ [ 'price' => $priceId, 'quantity' => 1 ] ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $actor->getId(),
            'metadata' => [ 'project_id' => $this->project->getId(), 'actor_id' => $actor->getId() ]
        ];
        $sessionResponse = $this->request($apiKey, 'POST', '/checkout/sessions', $params);
        $sessionData = $this->decodeResponse($sessionResponse);
        return new ProviderCheckoutSession(
            url: (string) ($sessionData['url'] ?? ''),
            metadata: [
                'id' => (string) ($sessionData['id'] ?? ''),
                'subscriptionId' => (string) ($sessionData['subscription'] ?? ''),
                'customerId' => (string) ($sessionData['customer'] ?? ''),
            ]
        );
    }

    public function createPortalSession(Document $actor, ProviderState $state, array $options = []): ProviderPortalSession
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $returnUrl = (string) ($options['returnUrl'] ?? '');
        $customerId = $this->ensureCustomer($apiKey, $actor);
        $sessionResponse = $this->request($apiKey, 'POST', '/billing_portal/sessions', [ 'customer' => $customerId, 'return_url' => $returnUrl ]);
        $sessionData = $this->decodeResponse($sessionResponse);
        return new ProviderPortalSession(url: (string) ($sessionData['url'] ?? ''));
    }

    public function reportUsage(ProviderSubscriptionRef $subscription, string $featureId, int $quantity, \DateTimeInterface $timestamp, ProviderState $state): void
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $eventName = 'appwrite.payments.feature.usage.' . $this->project->getId() . '.' . ($state->metadata['planId'] ?? '') . '.' . $featureId;
        $stripeSubId = (string) $subscription->externalSubscriptionId;
        $customerId = '';
        if ($stripeSubId !== '') {
            try {
                $sub = $this->request($apiKey, 'GET', '/subscriptions/' . $stripeSubId);
                $subData = $this->decodeResponse($sub);
                $customerId = (string) ($subData['customer'] ?? '');
            } catch (\Throwable $_) {
                $customerId = '';
            }
        }
        $params = [
            'event_name' => $eventName,
            'payload' => array_filter([
                'value' => (string) $quantity,
                'stripe_customer_id' => $customerId,
            ]),
            'timestamp' => (string) $timestamp->getTimestamp(),
        ];
        $this->request($apiKey, 'POST', '/billing/meter_events', $params);
    }

    public function syncUsage(ProviderSubscriptionRef $subscription, ProviderState $state): ProviderUsageReport
    {
        // Not implemented in full due to Stripe API specifics; return empty aggregate
        return new ProviderUsageReport(totals: []);
    }

    public function handleWebhook(array $payload, ProviderState $state): ProviderWebhookResult
    {
        $signature = (string) ($payload['_signature'] ?? '');
        $raw = (string) ($payload['_raw'] ?? '');
        $secret = (string) ($state->metadata['webhookSecret'] ?? '');
        if ($secret !== '' && $signature !== '' && $raw !== '') {
            $parts = [];
            foreach (explode(',', $signature) as $part) {
                [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
                if ($k !== '') {
                    $parts[$k] = $v;
                }
            }
            $ts = (string) ($parts['t'] ?? '');
            $v1 = (string) ($parts['v1'] ?? '');
            if ($ts === '' || $v1 === '') {
                return new ProviderWebhookResult(status: 'invalid_signature', changes: []);
            }
            $signedPayload = $ts . '.' . $raw;
            $expected = hash_hmac('sha256', $signedPayload, $secret);
            if (!hash_equals($expected, $v1)) {
                return new ProviderWebhookResult(status: 'invalid_signature', changes: []);
            }
        }

        $type = (string) ($payload['type'] ?? '');
        $changes = [];
        $apiKey = (string) ($state->config['secretKey'] ?? '');

        if (str_starts_with($type, 'customer.subscription.')) {
            $stripeSubId = (string) ($payload['data']['object']['id'] ?? '');
            if ($stripeSubId === '') {
                return new ProviderWebhookResult(status: 'ignored', changes: []);
            }

            try {
                $response = $this->request($apiKey, 'GET', '/subscriptions/' . $stripeSubId);
                $subData = $this->decodeResponse($response);
            } catch (\Throwable $e) {
                return new ProviderWebhookResult(status: 'error', changes: ['error' => $e->getMessage()]);
            }

            /** @var Document|null $subscription */
            $subscription = Authorization::skip(fn () => $this->dbForPlatform->findOne('payments_subscriptions', [
                Query::equal('providerSubscriptionId', [$stripeSubId]),
            ]));

            if ((!$subscription instanceof Document || $subscription->isEmpty()) && $type === 'customer.subscription.created') {
                try {
                    $sessionsResponse = $this->request($apiKey, 'GET', '/checkout/sessions', [
                        'subscription' => $stripeSubId,
                        'limit' => 1,
                    ]);
                    $sessionsData = $this->decodeResponse($sessionsResponse);
                    $sessionId = (string) ($sessionsData['data'][0]['id'] ?? '');
                    if ($sessionId !== '') {
                        $subscription = Authorization::skip(fn () => $this->dbForPlatform->findOne('payments_subscriptions', [
                            Query::equal('providerCheckoutId', [$sessionId]),
                        ]));
                    }
                } catch (\Throwable $_) {
                    $subscription = null;
                }
            }

            if (!$subscription instanceof Document || $subscription->isEmpty()) {
                return new ProviderWebhookResult(status: 'not_found', changes: []);
            }

            $stripeStatus = strtolower((string) ($subData['status'] ?? ''));
            $statusMap = [
                'active' => 'active',
                'trialing' => 'trialing',
                'canceled' => 'canceled',
                'unpaid' => 'past_due',
                'past_due' => 'past_due',
                'incomplete' => 'pending',
                'incomplete_expired' => 'canceled',
                'paused' => 'paused',
            ];
            $internalStatus = $statusMap[$stripeStatus] ?? 'active';
            $periodStart = isset($subData['current_period_start']) ? date('c', (int) $subData['current_period_start']) : null;
            $periodEnd = isset($subData['current_period_end']) ? date('c', (int) $subData['current_period_end']) : null;

            $providers = (array) $subscription->getAttribute('providers', []);
            $providerEntry = (array) ($providers['stripe'] ?? []);
            $providerEntry['providerSubscriptionId'] = $stripeSubId;
            $providers['stripe'] = $providerEntry;

            $subscription->setAttribute('status', $internalStatus);
            if ($periodStart) {
                $subscription->setAttribute('currentPeriodStart', $periodStart);
            }
            if ($periodEnd) {
                $subscription->setAttribute('currentPeriodEnd', $periodEnd);
            }
            $subscription->setAttribute('providers', $providers);

            Authorization::skip(fn () => $this->dbForPlatform->updateDocument('payments_subscriptions', $subscription->getId(), $subscription));
            $changes['subscription'] = $subscription->getId();
            $changes['status'] = $internalStatus;
            return new ProviderWebhookResult(status: 'ok', changes: $changes);
        }

        return new ProviderWebhookResult(status: 'ok', changes: $changes);
    }

    public function testConnection(array $config): ProviderTestResult
    {
        $apiKey = (string) ($config['secretKey'] ?? '');
        try {
            $this->request($apiKey, 'GET', '/account');
            return new ProviderTestResult(success: true, message: 'ok');
        } catch (\Throwable $e) {
            return new ProviderTestResult(success: false, message: $e->getMessage());
        }
    }

    private function ensureMeter(string $apiKey, string $displayName, string $projectId, string $planId, string $featureId): string
    {
        $eventName = 'appwrite.payments.feature.usage.' . $projectId . '.' . $planId . '.' . $featureId;
        $list = $this->request($apiKey, 'GET', '/billing/meters', ['limit' => 100]);
        $listData = $this->decodeResponse($list);
        if (isset($listData['data']) && is_array($listData['data'])) {
            foreach ($listData['data'] as $m) {
                if ((string) ($m['event_name'] ?? '') === $eventName) {
                    $id = (string) ($m['id'] ?? '');
                    if ($id !== '' && (($m['active'] ?? true) === false)) {
                        $this->request($apiKey, 'POST', '/billing/meters/' . $id, ['active' => 'true']);
                    }
                    return $id;
                }
            }
        }
        $meter = $this->request($apiKey, 'POST', '/billing/meters', [
            'display_name' => $displayName . ' usage',
            'event_name' => $eventName,
            'default_aggregation' => [ 'formula' => 'sum' ],
            'value_settings' => [ 'event_payload_key' => 'value' ],
            'customer_mapping' => [ 'type' => 'by_id', 'event_payload_key' => 'stripe_customer_id' ],
        ]);
        $meterData = $this->decodeResponse($meter);
        return (string) ($meterData['id'] ?? '');
    }

    private function ensureCustomer(string $apiKey, Document $actor): string
    {
        // Persist per-actor customer id in platform DB cache (users/teams) with dedicated attribute
        $existing = (string) $actor->getAttribute('stripeCustomerId', '');
        if ($existing !== '') {
            return $existing;
        }
        $customer = $this->request($apiKey, 'POST', '/customers', [
            'email' => $actor->getAttribute('email', ''),
            'metadata' => [ 'project_id' => $this->project->getId(), 'actor_id' => $actor->getId() ]
        ]);
        $customerData = $this->decodeResponse($customer);
        $customerId = (string) ($customerData['id'] ?? '');
        try {
            $actor->setAttribute('stripeCustomerId', $customerId);
            $collection = $actor->getAttribute('kind', '') === 'team' ? 'teams' : 'users';
            $this->dbForProject->updateDocument($collection, $actor->getId(), $actor);
        } catch (\Throwable $e) {
            // ignore persistence issues
        }
        return $customerId;
    }

    /**
     * @return array{status:int,body:string,decoded:mixed}
     */
    public function request(string $apiKey, string $method, string $path, array $params = []): array
    {
        // Construct full Stripe API URL
        $baseUrl = 'https://api.stripe.com/v1';
        $url = $baseUrl . $path;

        // Build headers
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded'
        ];

        // Handle GET requests - append params as query string
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        // Build form data for POST/DELETE requests
        $body = '';
        if (!empty($params) && ($method === 'POST' || $method === 'DELETE')) {
            $body = $this->buildFormData($params);
        }

        $ch = \curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialise Stripe curl handle');
        }

        $curlOptions = [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_TIMEOUT => 30,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($body !== '') {
            $curlOptions[\CURLOPT_POSTFIELDS] = $body;
        }

        if (!\curl_setopt_array($ch, $curlOptions)) {
            $error = \curl_error($ch);
            \curl_close($ch);
            throw new \RuntimeException('Failed to configure Stripe curl request: ' . $error);
        }

        $responseBody = \curl_exec($ch);
        if ($responseBody === false) {
            $error = \curl_error($ch);
            \curl_close($ch);
            throw new \RuntimeException('Stripe curl request failed: ' . $error);
        }

        $statusCode = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($statusCode >= 400) {
            $responseData = json_decode($responseBody, true);
            $message = is_array($responseData) && isset($responseData['error']['message'])
                ? (string) $responseData['error']['message']
                : 'Stripe API error';
            throw new \RuntimeException($message, $statusCode);
        }
        return [
            'status' => $statusCode,
            'body' => $responseBody,
            'decoded' => \json_decode($responseBody, true),
        ];
    }

    /**
     * Safely extract decoded JSON from a Stripe response array.
     *
     * @param array{status:int,body:string,decoded:mixed} $response
     * @return array<string,mixed>
     */
    private function decodeResponse(array $response): array
    {
        $decoded = $response['decoded'] ?? null;
        if (\is_array($decoded)) {
            return $decoded;
        }

        $decoded = \json_decode($response['body'] ?? '', true);
        return \is_array($decoded) ? $decoded : [];
    }

    private function buildFormData(array $params, string $prefix = ''): string
    {
        $data = [];
        foreach ($params as $key => $value) {
            $k = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                $data[] = $this->buildFormData($value, $k);
            } else {
                $data[] = urlencode($k) . '=' . urlencode((string) $value);
            }
        }
        return implode('&', $data);
    }
}
