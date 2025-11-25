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
        $pending = $dbForProject->find('payments_usage_events', [
            Query::equal('providerSyncState', ['pending']),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        $payments = (array) $project->getAttribute('payments', []);
        $providers = (array) ($payments['providers'] ?? []);
        $primary = array_key_first($providers);
        if (!$primary) {
            return;
        }
        $config = (array) ($providers[$primary] ?? []);
        $stateBase = (array) ($config['state'] ?? []);

        $adapter = $registryPayments->get((string) $primary, $config, $project, $dbForPlatform, $dbForProject);

        foreach ($pending as $event) {
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
                    // Mark failed with reason
                    $meta = (array) $event->getAttribute('metadata', []);
                    $meta['error'] = 'Subscription not found';
                    $event->setAttribute('metadata', $meta);
                    $event->setAttribute('providerSyncState', 'failed');
                    $dbForProject->updateDocument('payments_usage_events', $event->getId(), $event);
                    continue;
                }
                $provMap = (array) $sub->getAttribute('providers', []);
                $providerSubId = (string) ((array) ($provMap[(string) $primary] ?? []))['subscriptionId'] ?? '';
                if ($providerSubId === '') {
                    $meta = (array) $event->getAttribute('metadata', []);
                    $meta['error'] = 'Provider subscription missing';
                    $event->setAttribute('metadata', $meta);
                    $event->setAttribute('providerSyncState', 'failed');
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
                $meta['error'] = $e->getMessage();
                $meta['lastTriedAt'] = date('c');
                $meta['retries'] = (int) ($meta['retries'] ?? 0) + 1;
                $event->setAttribute('metadata', $meta);
                $event->setAttribute('providerSyncState', 'failed');
                $dbForProject->updateDocument('payments_usage_events', $event->getId(), $event);
            }
        }
    }
}
