<?php

namespace Tests\E2E\Services\Payments;

use Appwrite\Tests\Async;
use Tests\E2E\Client;

trait PaymentsBase
{
    use Async;

    protected function setupPlan(array $params = []): array
    {
        $defaults = [
            'planId' => 'test-plan-' . uniqid(),
            'name' => 'Test Plan',
            'description' => 'Test plan description',
            'isDefault' => false,
            'isFree' => false,
            'pricing' => []
        ];
        $params = array_merge($defaults, $params);

        $plan = $this->client->call(Client::METHOD_POST, '/payments/plans', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $params);

        $this->assertEquals(201, $plan['headers']['status-code'], 'Setup plan failed with status code: ' . $plan['headers']['status-code'] . ' and response: ' . json_encode($plan['body'], JSON_PRETTY_PRINT));

        return $plan['body'];
    }

    protected function cleanupPlan(string $planId): void
    {
        $plan = $this->client->call(Client::METHOD_DELETE, '/payments/plans/' . $planId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals(204, $plan['headers']['status-code']);
    }

    protected function createPlan(array $params = []): array
    {
        $plan = $this->client->call(Client::METHOD_POST, '/payments/plans', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $plan;
    }

    protected function getPlan(string $planId): array
    {
        $plan = $this->client->call(Client::METHOD_GET, '/payments/plans/' . $planId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $plan;
    }

    protected function listPlans(array $params = []): array
    {
        $plans = $this->client->call(Client::METHOD_GET, '/payments/plans', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $plans;
    }

    protected function updatePlan(string $planId, array $params = []): array
    {
        $plan = $this->client->call(Client::METHOD_PUT, '/payments/plans/' . $planId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $plan;
    }

    protected function deletePlan(string $planId): array
    {
        $plan = $this->client->call(Client::METHOD_DELETE, '/payments/plans/' . $planId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $plan;
    }

    protected function setupFeature(array $params = []): array
    {
        $defaults = [
            'featureId' => 'test-feature-' . uniqid(),
            'name' => 'Test Feature',
            'type' => 'boolean',
            'description' => 'Test feature description'
        ];
        $params = array_merge($defaults, $params);

        $feature = $this->client->call(Client::METHOD_POST, '/payments/features', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $params);

        $this->assertEquals(201, $feature['headers']['status-code'], 'Setup feature failed with status code: ' . $feature['headers']['status-code'] . ' and response: ' . json_encode($feature['body'], JSON_PRETTY_PRINT));

        return $feature['body'];
    }

    protected function cleanupFeature(string $featureId): void
    {
        $feature = $this->client->call(Client::METHOD_DELETE, '/payments/features/' . $featureId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals(204, $feature['headers']['status-code']);
    }

    protected function createFeature(array $params = []): array
    {
        $feature = $this->client->call(Client::METHOD_POST, '/payments/features', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $feature;
    }

    protected function getFeature(string $featureId): array
    {
        $feature = $this->client->call(Client::METHOD_GET, '/payments/features/' . $featureId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $feature;
    }

    protected function listFeatures(array $params = []): array
    {
        $features = $this->client->call(Client::METHOD_GET, '/payments/features', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $features;
    }

    protected function updateFeature(string $featureId, array $params = []): array
    {
        $feature = $this->client->call(Client::METHOD_PUT, '/payments/features/' . $featureId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $feature;
    }

    protected function deleteFeature(string $featureId): array
    {
        $feature = $this->client->call(Client::METHOD_DELETE, '/payments/features/' . $featureId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $feature;
    }

    protected function setupSubscription(string $planId, array $params = []): array
    {
        $defaults = [
            'actorType' => 'user',
            'actorId' => $this->getUser()['$id'],
            'planId' => $planId,
            'successUrl' => 'https://example.com/success',
            'cancelUrl' => 'https://example.com/cancel'
        ];
        $params = array_merge($defaults, $params);

        $subscription = $this->client->call(Client::METHOD_POST, '/payments/subscriptions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $params);

        $this->assertEquals(201, $subscription['headers']['status-code'], 'Setup subscription failed with status code: ' . $subscription['headers']['status-code'] . ' and response: ' . json_encode($subscription['body'], JSON_PRETTY_PRINT));

        return $subscription['body'];
    }

    protected function cleanupSubscription(string $subscriptionId): void
    {
        $subscription = $this->client->call(Client::METHOD_POST, '/payments/subscriptions/' . $subscriptionId . '/cancel', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'endAtPeriodEnd' => false
        ]);

        $this->assertEquals(204, $subscription['headers']['status-code']);
    }

    protected function createSubscription(array $params = []): array
    {
        $subscription = $this->client->call(Client::METHOD_POST, '/payments/subscriptions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $subscription;
    }

    protected function getSubscription(string $subscriptionId): array
    {
        $subscription = $this->client->call(Client::METHOD_GET, '/payments/subscriptions/' . $subscriptionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $subscription;
    }

    protected function listSubscriptions(array $params = []): array
    {
        $subscriptions = $this->client->call(Client::METHOD_GET, '/payments/subscriptions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $subscriptions;
    }

    protected function updateSubscription(string $subscriptionId, array $params = []): array
    {
        $subscription = $this->client->call(Client::METHOD_PUT, '/payments/subscriptions/' . $subscriptionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $subscription;
    }

    protected function cancelSubscription(string $subscriptionId, array $params = []): array
    {
        $subscription = $this->client->call(Client::METHOD_POST, '/payments/subscriptions/' . $subscriptionId . '/cancel', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $subscription;
    }

    protected function resumeSubscription(string $subscriptionId): array
    {
        $subscription = $this->client->call(Client::METHOD_POST, '/payments/subscriptions/' . $subscriptionId . '/resume', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $subscription;
    }

    protected function getSubscriptionPortal(string $subscriptionId, array $params = []): array
    {
        $portal = $this->client->call(Client::METHOD_GET, '/payments/subscriptions/' . $subscriptionId . '/portal', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $portal;
    }

    protected function previewUpgrade(string $subscriptionId, array $params = []): array
    {
        $preview = $this->client->call(Client::METHOD_GET, '/payments/subscriptions/' . $subscriptionId . '/preview-upgrade', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $preview;
    }

    protected function listInvoices(array $params = []): array
    {
        $invoices = $this->client->call(Client::METHOD_GET, '/payments/invoices', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $invoices;
    }

    protected function getUsage(array $params = []): array
    {
        $usage = $this->client->call(Client::METHOD_GET, '/payments/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $usage;
    }

    protected function createUsage(array $params = []): array
    {
        $usage = $this->client->call(Client::METHOD_POST, '/payments/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $usage;
    }

    protected function listUsageEvents(array $params = []): array
    {
        $events = $this->client->call(Client::METHOD_GET, '/payments/usage/events', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $events;
    }

    protected function getProvider(string $providerId): array
    {
        $provider = $this->client->call(Client::METHOD_GET, '/payments/providers/' . $providerId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $provider;
    }

    protected function updateProvider(string $providerId, array $params = []): array
    {
        $provider = $this->client->call(Client::METHOD_PUT, '/payments/providers/' . $providerId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $provider;
    }

    protected function assignPlanFeature(string $planId, array $params = []): array
    {
        $planFeature = $this->client->call(Client::METHOD_POST, '/payments/plans/' . $planId . '/features', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $planFeature;
    }

    protected function listPlanFeatures(string $planId, array $params = []): array
    {
        $planFeatures = $this->client->call(Client::METHOD_GET, '/payments/plans/' . $planId . '/features', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $planFeatures;
    }

    protected function removePlanFeature(string $planId, string $featureId): array
    {
        $planFeature = $this->client->call(Client::METHOD_DELETE, '/payments/plans/' . $planId . '/features/' . $featureId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $planFeature;
    }

    protected function getActorFeatures(array $params = []): array
    {
        $actorFeatures = $this->client->call(Client::METHOD_GET, '/payments/actor-features', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $actorFeatures;
    }
}
