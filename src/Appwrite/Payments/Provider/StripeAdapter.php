<?php

namespace Appwrite\Payments\Provider;

use Swoole\Coroutine\Http\ClientProxy;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\System\System;

use function Swoole\Coroutine\Http\request;

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
        $account = $this->request($apiKey, 'GET', '/account');
        $domain = System::getEnv('_APP_DOMAIN', 'localhost');
        $scheme = System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'enabled') === 'disabled' ? 'http' : 'https';
        $webhookUrl = $scheme . '://' . $domain . '/v1/payments/webhooks/stripe/' . $project->getId();
        $endpoint = $this->request($apiKey, 'POST', '/webhook_endpoints', [
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

        $endpointData = json_decode($endpoint->getBody(), true);

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

        $providerPlanId = json_decode($planData['providers']['stripe']['planId'] ?? '', true);

        // Check if product already exists
        $existingProduct = $this->request($apiKey, 'GET', '/products/' . $providerPlanId);

        if ($existingProduct->getStatusCode() === 200) {
            $data = json_decode($existingProduct->getBody(), true);
            return new ProviderPlanRef(externalPlanId: $data['id'], metadata: ['productId' => $data['id']]);
        }

        $product = $this->request($apiKey, 'POST', '/products', [
            'name' => $name,
            'description' => $description,
            'metadata' => [
                'project_id' => $this->project->getId(),
                'plan_id' => (string) ($planData['planId'] ?? '')
            ]
        ]);
        $productData = json_decode($product->getBody(), true);
        $productId = (string) ($productData['id'] ?? '');
        $refs = ['productId' => $productId, 'prices' => []];
        $pricing = (array) ($planData['pricing'] ?? []);
        foreach ($pricing as $price) {
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
                    'type' => 'payments_plan_price'
                ]
            ]);
            $refs['prices'][] = $res['id'] ?? null;
        }
        return new ProviderPlanRef(externalPlanId: $productId, metadata: $refs);
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
        $existingPrices = (array) ($reference->metadata['prices'] ?? []);

        // Fetch details for existing price ids
        $existingMap = []; // key => priceId
        foreach ($existingPrices as $pid) {
            if (!$pid) {
                continue;
            }
            $price = $this->request($apiKey, 'GET', '/prices/' . $pid);
            $priceData = json_decode($price->getBody(), true);
            $currency = (string) ($priceData['currency'] ?? '');
            $interval = (string) ($priceData['recurring']['interval'] ?? '');
            $amount = (int) ($priceData['unit_amount'] ?? 0);
            $key = $currency . ':' . $interval . ':' . $amount;
            $existingMap[$key] = (string) $pid;
        }

        $keptPriceIds = [];
        $desiredKeys = [];
        foreach ($newPricing as $entry) {
            $amount = (int) ($entry['amount'] ?? 0);
            $currency = (string) ($entry['currency'] ?? ($state->metadata['currency'] ?? 'usd'));
            $interval = (string) ($entry['interval'] ?? 'month');
            $key = $currency . ':' . $interval . ':' . $amount;
            $desiredKeys[$key] = true;

            if (isset($existingMap[$key])) {
                // keep current price
                $keptPriceIds[] = $existingMap[$key];
                continue;
            }

            // create new price
            $res = $this->request($apiKey, 'POST', '/prices', [
                'product' => $reference->externalPlanId,
                'unit_amount' => $amount,
                'currency' => $currency,
                'recurring' => [ 'interval' => $interval ],
                'metadata' => [
                    'plan_id' => (string) ($planData['planId'] ?? ''),
                    'type' => 'payments_plan_price'
                ]
            ]);
            $resData = json_decode($res->getBody(), true);
            $keptPriceIds[] = (string) ($resData['id'] ?? '');
        }

        // Deactivate any existing price not in desired set
        foreach ($existingMap as $key => $pid) {
            if (!isset($desiredKeys[$key])) {
                $this->request($apiKey, 'POST', '/prices/' . $pid, ['active' => 'false']);
            }
        }

        return new ProviderPlanRef(
            externalPlanId: $reference->externalPlanId,
            metadata: ['prices' => array_values(array_filter($keptPriceIds))]
        );
    }

    public function deletePlan(ProviderPlanRef $reference, ProviderState $state): void
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $meta = $reference->metadata;
        if (!empty($meta['prices'])) {
            foreach ($meta['prices'] as $priceId) {
                if ($priceId) {
                    $this->request($apiKey, 'POST', '/prices/' . $priceId, ['active' => 'false']);
                }
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
        return new ProviderFeatureRef(externalFeatureId: (string) ($price['id'] ?? ''), metadata: ['priceId' => $price['id'] ?? null, 'meterId' => $meter]);
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

    public function ensureSubscription(Document $actor, array $subscriptionData, ProviderState $state): ProviderSubscriptionRef
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $customerId = $this->ensureCustomer($apiKey, $actor);
        $planRefs = (array) ($subscriptionData['planProviders'] ?? []);
        $priceId = '';
        if (!empty($planRefs['stripe']['prices'][0] ?? null)) {
            $priceId = (string) $planRefs['stripe']['prices'][0];
        }
        if ($priceId === '') {
            return new ProviderSubscriptionRef(externalSubscriptionId: '');
        }
        $resp = $this->request($apiKey, 'POST', '/subscriptions', [
            'customer' => $customerId,
            'items' => [ [ 'price' => $priceId ] ],
            'payment_behavior' => 'default_incomplete',
            'metadata' => [ 'project_id' => $this->project->getId(), 'actor_id' => $actor->getId() ]
        ]);

        $respData = json_decode($resp->getBody(), true);

        // Map Stripe status to internal status
        $stripeStatus = (string) ($respData['status'] ?? 'incomplete');
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
        $internalStatus = $statusMap[$stripeStatus] ?? 'pending';

        return new ProviderSubscriptionRef(
            externalSubscriptionId: (string) ($respData['id'] ?? ''),
            metadata: ['status' => $internalStatus]
        );
    }

    public function updateSubscription(ProviderSubscriptionRef $subscription, array $changes, ProviderState $state): ProviderSubscriptionRef
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $newPriceId = (string) ($changes['priceId'] ?? '');
        if ($newPriceId !== '') {
            $sub = $this->request($apiKey, 'GET', '/subscriptions/' . $subscription->externalSubscriptionId);
            $subData = json_decode($sub->getBody(), true);
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
            'line_items' => [ [ 'price' => $priceId ] ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $actor->getId(),
            'metadata' => [ 'project_id' => $this->project->getId(), 'actor_id' => $actor->getId() ]
        ];
        $sessionResponse = $this->request($apiKey, 'POST', '/checkout/sessions', $params);
        $sessionData = json_decode($sessionResponse->getBody(), true);
        return new ProviderCheckoutSession(url: (string) ($sessionData['url'] ?? ''));
    }

    public function createPortalSession(Document $actor, ProviderState $state, array $options = []): ProviderPortalSession
    {
        $apiKey = (string) ($state->config['secretKey'] ?? '');
        $returnUrl = (string) ($options['returnUrl'] ?? '');
        $customerId = $this->ensureCustomer($apiKey, $actor);
        $sessionResponse = $this->request($apiKey, 'POST', '/billing_portal/sessions', [ 'customer' => $customerId, 'return_url' => $returnUrl ]);
        $sessionData = json_decode($sessionResponse->getBody(), true);
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
                $subData = json_decode($sub->getBody(), true);
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
        if (str_starts_with($type, 'customer.subscription.')) {
            /** @var array<string,mixed> $obj */
            $obj = (array) ($payload['data']['object'] ?? []);
            $stripeSubId = (string) ($obj['id'] ?? '');
            $stripeStatus = (string) ($obj['status'] ?? '');
            $periodStart = isset($obj['current_period_start']) ? date('c', (int) $obj['current_period_start']) : null;
            $periodEnd = isset($obj['current_period_end']) ? date('c', (int) $obj['current_period_end']) : null;

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

            $subs = $this->dbForPlatform->find('payments_subscriptions', [
                Query::equal('projectId', [$this->project->getId()])
            ]);
            foreach ($subs as $sub) {
                /** @var Document $sub */
                $providerMap = (array) $sub->getAttribute('providers', []);
                $prov = (array) ($providerMap['stripe'] ?? []);
                if ((string) ($prov['subscriptionId'] ?? '') === $stripeSubId) {
                    $sub->setAttribute('status', $internalStatus);
                    if ($periodStart) {
                        $sub->setAttribute('currentPeriodStart', $periodStart);
                    }
                    if ($periodEnd) {
                        $sub->setAttribute('currentPeriodEnd', $periodEnd);
                    }
                    $this->dbForPlatform->updateDocument('payments_subscriptions', $sub->getId(), $sub);
                    $changes['subscription'] = $sub->getId();
                    $changes['status'] = $internalStatus;
                    break;
                }
            }
        }
        if ($type === 'invoice.payment_succeeded' || $type === 'invoice.payment_failed') {
            /** @var array<string,mixed> $obj */
            $obj = (array) ($payload['data']['object'] ?? []);
            $stripeSubId = (string) ($obj['subscription'] ?? '');
            $internalStatus = $type === 'invoice.payment_succeeded' ? 'active' : 'past_due';
            $subs = $this->dbForPlatform->find('payments_subscriptions', [
                Query::equal('projectId', [$this->project->getId()])
            ]);
            foreach ($subs as $sub) {
                /** @var Document $sub */
                $prov = (array) ((array) $sub->getAttribute('providers', []))['stripe'] ?? [];
                if ((string) ($prov['subscriptionId'] ?? '') === $stripeSubId) {
                    $sub->setAttribute('status', $internalStatus);
                    $this->dbForPlatform->updateDocument('payments_subscriptions', $sub->getId(), $sub);
                    $changes['subscription'] = $sub->getId();
                    $changes['status'] = $internalStatus;
                    break;
                }
            }
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
        $listData = json_decode($list->getBody(), true);
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
        $meterData = json_decode($meter->getBody(), true);
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
        $customerData = json_decode($customer->getBody(), true);
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

    public function request(string $apiKey, string $method, string $path, array $params = []): ClientProxy
    {
        // $ch = \curl_init();
        // $url = 'https://api.stripe.com/v1' . $path;
        // $headers = [ 'Authorization: Bearer ' . $apiKey, 'Content-Type: application/x-www-form-urlencoded' ];
        // \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // switch ($method) {
        //     case 'GET':
        //         if (!empty($params)) { $url .= '?' . http_build_query($params); }
        //         break;
        //     case 'POST':
        //         \curl_setopt($ch, CURLOPT_POST, true);
        //         if (!empty($params)) { \curl_setopt($ch, CURLOPT_POSTFIELDS, $this->buildFormData($params)); }
        //         break;
        //     case 'DELETE':
        //         \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        //         if (!empty($params)) { \curl_setopt($ch, CURLOPT_POSTFIELDS, $this->buildFormData($params)); }
        //         break;
        // }
        // \curl_setopt($ch, CURLOPT_URL, $url);
        // $response = \curl_exec($ch);
        // $httpCode = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // \curl_close($ch);
        // if ($response === false) { throw new \RuntimeException('Stripe API request failed'); }
        // $data = \json_decode($response, true);
        // if ($httpCode >= 400) {
        //     $message = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : 'Stripe API error';
        //     throw new \RuntimeException($message, $httpCode);
        // }
        // return is_array($data) ? $data : [];
        $response = request($method, $path, $params, ['headers' => [ 'Authorization: Bearer ' . $apiKey, 'Content-Type: application/x-www-form-urlencoded' ]]);
        return $response;
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
