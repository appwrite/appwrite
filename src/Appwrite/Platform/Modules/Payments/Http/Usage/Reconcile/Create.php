<?php

namespace Appwrite\Platform\Modules\Payments\Http\Usage\Reconcile;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\Utopia\Response;
use Appwrite\Payments\Provider\Registry as ProviderRegistry;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createPaymentUsageReconcile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/payments/usage/reconcile')
            ->groups(['api', 'payments'])
            ->desc('Trigger usage reconciliation')
            ->label('scope', 'payments.write')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.usage.reconcile')
            ->label('audits.event', 'payments.usage.reconcile')
            ->label('audits.resource', 'payments/usage/reconcile')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'usage',
                name: 'reconcile',
                description: 'Trigger usage reconciliation',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: []
            ))
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('registryPayments')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(Response $response, Database $dbForPlatform, Database $dbForProject, ProviderRegistry $registryPayments, Document $project)
    {
        // Feature flag: block if payments disabled for project
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
            $response->json(['message' => 'Payments feature is disabled for this project']);
            return;
        }

        $payments = (array) $project->getAttribute('payments', []);
        $providers = (array) ($payments['providers'] ?? []);
        $primary = array_key_first($providers);
        $config = $primary ? (array) ($providers[$primary] ?? []) : [];
        $subs = $dbForPlatform->find('payments_subscriptions', [
            Query::equal('projectId', [$project->getId()])
        ]);
        foreach ($subs as $sub) {
            // Aggregate internal usage summary
            $events = $dbForPlatform->find('payments_usage_events', [
                Query::equal('projectId', [$project->getId()]),
                Query::equal('subscriptionId', [$sub->getAttribute('subscriptionId')])
            ]);
            $totals = [];
            foreach ($events as $e) {
                $fid = (string) $e->getAttribute('featureId', '');
                $totals[$fid] = ($totals[$fid] ?? 0) + (int) $e->getAttribute('quantity', 0);
            }
            $sub->setAttribute('usageSummary', $totals);
            $dbForPlatform->updateDocument('payments_subscriptions', $sub->getId(), $sub);

            // Optionally invoke provider sync for parity
            if ($primary) {
                $provMap = (array) $sub->getAttribute('providers', []);
                $subscriptionRef = (string) ((array) ($provMap[(string) $primary] ?? []))['subscriptionId'] ?? '';
                if ($subscriptionRef !== '') {
                    $state = new \Appwrite\Payments\Provider\ProviderState((string) $primary, $config, (array) ($config['state'] ?? []));
                    $adapter = $registryPayments->get((string) $primary, $config, $project, $dbForPlatform, $dbForProject);
                    $adapter->syncUsage(new \Appwrite\Payments\Provider\ProviderSubscriptionRef($subscriptionRef), $state);
                }
            }
        }
        $response->noContent();
    }
}


