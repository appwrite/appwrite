<?php

namespace Appwrite\Payments\Provider;

use Utopia\Database\Document;

interface Adapter
{
    public function getIdentifier(): string;

    public function configure(array $config, Document $project): ProviderState;

    public function ensurePlan(array $planData, ProviderState $state): ProviderPlanRef;

    public function updatePlan(array $planData, ProviderPlanRef $reference, ProviderState $state): ProviderPlanRef;

    public function deletePlan(ProviderPlanRef $reference, ProviderState $state): void;

    public function ensureFeature(array $featureData, ProviderPlanRef $plan, ProviderState $state): ProviderFeatureRef;

    public function deleteFeature(ProviderFeatureRef $feature, ProviderPlanRef $plan, ProviderState $state): void;

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
