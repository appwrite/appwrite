<?php

namespace Appwrite\Platform\Modules\Payments\Workers;

use Appwrite\Payments\Provider\ProviderState;
use Appwrite\Payments\Provider\ProviderSubscriptionRef;
use Appwrite\Payments\Provider\Registry as ProviderRegistry;
use Appwrite\Platform\Action;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

class UsageSync extends Action
{
    public static function getName(): string
    {
        return 'payments-usage-sync';
    }

    public function __construct()
    {
        $this
            ->desc('Sync payments usage events to providers')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('registryPayments')
            ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, Database $dbForProject, Document $project, ProviderRegistry $registryPayments): void
    {
        // Query for both pending and retry_pending events
        $pending = $dbForProject->find('payments_usage_events', [
            Query::equal('providerSyncState', ['pending']),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        // Query for retry_pending events that are ready to be retried
        $now = time();
        $retryPending = $dbForProject->find('payments_usage_events', [
            Query::equal('providerSyncState', ['retry_pending']),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        // Filter retry_pending events to only include those whose nextRetryAt has passed
        $retryReady = [];
        foreach ($retryPending as $event) {
            $meta = (array) $event->getAttribute('metadata', []);
            $nextRetryAt = (int) ($meta['nextRetryAt'] ?? 0);
            if ($nextRetryAt <= $now) {
                $retryReady[] = $event;
            }
        }

        // Merge pending and retry-ready events
        $allEvents = array_merge($pending, $retryReady);

        $payments = (array) $project->getAttribute('payments', []);
        $providers = (array) ($payments['providers'] ?? []);
        $primary = array_key_first($providers);
        if (!$primary) {
            return;
        }
        $config = (array) ($providers[$primary] ?? []);
        $stateBase = (array) ($config['state'] ?? []);

        $adapter = $registryPayments->get((string) $primary, $config, $project, $dbForPlatform, $dbForProject);

        foreach ($allEvents as $event) {
            try {
                $subscriptionId = (string) $event->getAttribute('subscriptionId', '');
                $featureId = (string) $event->getAttribute('featureId', '');
                $quantity = (int) $event->getAttribute('quantity', 0);
                $timestampStr = (string) $event->getAttribute('timestamp', date('c'));
                $timestamp = new \DateTimeImmutable($timestampStr);

                $sub = $dbForProject->findOne('payments_subscriptions', [
                    Query::equal('subscriptionId', [$subscriptionId])
                ]);
                if ($sub === null || $sub->isEmpty()) {
                    // Mark as failed_permanent for non-retryable errors
                    $meta = (array) $event->getAttribute('metadata', []);
                    $meta['error'] = 'Subscription not found';
                    $event->setAttribute('metadata', $meta);
                    $event->setAttribute('providerSyncState', 'failed_permanent');
                    $dbForProject->updateDocument('payments_usage_events', $event->getId(), $event);
                    continue;
                }
                $provMap = (array) $sub->getAttribute('providers', []);
                $providerData = (array) ($provMap[(string) $primary] ?? []);
                $providerSubId = (string) ($providerData['providerSubscriptionId'] ?? '');
                if ($providerSubId === '') {
                    $meta = (array) $event->getAttribute('metadata', []);
                    $meta['error'] = 'Provider subscription missing';
                    $event->setAttribute('metadata', $meta);
                    $event->setAttribute('providerSyncState', 'failed_permanent');
                    $dbForProject->updateDocument('payments_usage_events', $event->getId(), $event);
                    continue;
                }

                // Enrich state with planId for meter event name compatibility
                $enrichedState = $stateBase;
                $enrichedState['planId'] = (string) $sub->getAttribute('planId', '');
                $state = new ProviderState((string) $primary, $config, $enrichedState);
                $adapter->reportUsage(new ProviderSubscriptionRef($providerSubId), $featureId, $quantity, $timestamp, $state);

                $event->setAttribute('providerSyncState', 'synced');
                $dbForProject->updateDocument('payments_usage_events', $event->getId(), $event);
            } catch (\Throwable $e) {
                $meta = (array) $event->getAttribute('metadata', []);
                $retries = (int) ($meta['retries'] ?? 0);
                $retries++;

                $meta['error'] = $e->getMessage();
                $meta['lastTriedAt'] = date('c');
                $meta['retries'] = $retries;

                // Implement exponential backoff: min(pow(2, retries) * 60, 3600) seconds
                $backoffSeconds = min(pow(2, $retries) * 60, 3600);
                $meta['nextRetryAt'] = $now + (int) $backoffSeconds;

                $event->setAttribute('metadata', $meta);

                // Check if max retries (5) exceeded
                if ($retries >= 5) {
                    // Mark as failed_permanent after max retries
                    $event->setAttribute('providerSyncState', 'failed_permanent');
                } else {
                    // Mark as retry_pending for future retry
                    $event->setAttribute('providerSyncState', 'retry_pending');
                }

                $dbForProject->updateDocument('payments_usage_events', $event->getId(), $event);
            }
        }
    }
}
