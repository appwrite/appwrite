<?php

namespace Appwrite\Platform\Modules\Payments\Http\PlanFeatures;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Payments\Provider\ProviderState;
use Appwrite\Payments\Provider\Registry;
use Appwrite\Utopia\Response;
use Appwrite\Event\Event;
use Appwrite\Event\Audit;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\Integer;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;

class Assign extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'assignPaymentPlanFeature';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/payments/plans/:planId/features')
            ->groups(['api', 'payments'])
            ->desc('Assign feature to plan')
            ->label('scope', 'payments.write')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.plans.[planId].features.[featureId].assign')
            ->label('audits.event', 'payments.plan.feature.assign')
            ->label('audits.resource', 'payments/plan/{request.planId}/feature/{request.featureId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'planFeatures',
                name: 'assign',
                description: 'Assign feature to plan',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('planId', '', new Text(128), 'Plan ID')
            ->param('featureId', '', new Text(128), 'Feature ID')
            ->param('currency', '', new Text(8, 0), 'Currency code', true)
            ->param('interval', '', new Text(16, 0), 'Billing interval', true)
            ->param('includedUnits', 0, new Integer(), 'Included units', true)
            ->param('tiersMode', null, new Text(32, 0), 'Tiers mode (graduated or volume)', true)
            ->param('tiers', [], new ArrayList(new Assoc(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Pricing tiers', true)
            ->param('usageCap', null, new Integer(), 'Usage cap', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('registryPayments')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('queueForAudits')
            ->callback($this->action(...));
    }

    public function action(
        string $planId,
        string $featureId,
        string $currency,
        string $interval,
        int $includedUnits,
        ?string $tiersMode,
        array $tiers,
        ?int $usageCap,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject,
        Registry $registryPayments,
        Document $project,
        Event $queueForEvents,
        Audit $queueForAudits
    )
    {
        // Feature flag: block if payments disabled for project
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
            $response->json(['message' => 'Payments feature is disabled for this project']);
            return;
        }

        $plan = $dbForPlatform->findOne('payments_plans', [
            Query::equal('projectId', [$project->getId()]),
            Query::equal('planId', [$planId])
        ]);
        if ($plan === null || $plan->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND);
            $response->json(['message' => 'Plan not found']);
            return;
        }

        // Infer feature type from existing feature document
        $feature = $dbForPlatform->findOne('payments_features', [
            Query::equal('projectId', [$project->getId()]),
            Query::equal('featureId', [$featureId])
        ]);
        if ($feature === null || $feature->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND);
            $response->json(['message' => 'Feature not found']);
            return;
        }
        $type = (string) strtolower((string) $feature->getAttribute('type', 'boolean'));

        $doc = new Document([
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'planId' => $planId,
            'featureId' => $featureId,
            'type' => $type,
            'enabled' => true,
            'currency' => $currency,
            'interval' => $interval,
            'includedUnits' => $includedUnits,
            'tiersMode' => $tiersMode,
            'tiers' => $tiers,
            'usageCap' => $usageCap,
            'overagePrice' => null,
            'providers' => [],
            'metadata' => []
        ]);
        $created = $dbForPlatform->createDocument('payments_plan_features', $doc);

        // Provision feature with each provider and persist refs (metered only)
        $planProviders = (array) $plan->getAttribute('providers', []);
        $payments = (array) $project->getAttribute('payments', []);
        $providerConfigs = (array) ($payments['providers'] ?? []);
        $providersMeta = [];
        if ($type === 'metered') {
            foreach ($providerConfigs as $providerId => $providerConfig) {
                $state = new ProviderState((string) $providerId, (array) $providerConfig, (array) ($providerConfig['state'] ?? []));
                $adapter = $registryPayments->get((string) $providerId, (array) $providerConfig, $project, $dbForPlatform, $dbForProject);
                $planRef = new \Appwrite\Payments\Provider\ProviderPlanRef((string) ($planProviders[$providerId]['externalId'] ?? ''), (array) ($planProviders[$providerId]['metadata'] ?? []));
                $featRef = $adapter->ensureFeature([
                    'featureId' => $featureId,
                    'name' => $featureId,
                    'currency' => $currency,
                    'interval' => $interval,
                    'includedUnits' => $includedUnits,
                    'planId' => $planId,
                    'type' => $type,
                    'tiersMode' => $tiersMode,
                    'tiers' => $tiers,
                    'usageCap' => $usageCap,
                ], $planRef, $state);
                $providersMeta[$providerId] = [ 'priceId' => $featRef->metadata['priceId'] ?? null, 'meterId' => $featRef->metadata['meterId'] ?? null ];
            }
        }
        if (!empty($providersMeta)) {
            $created->setAttribute('providers', $providersMeta);
            $created = $dbForPlatform->updateDocument('payments_plan_features', $created->getId(), $created);
        }

        $queueForEvents
            ->setProject($project)
            ->setEvent('payments.[planId].features.[featureId].assign')
            ->setParam('planId', $planId)
            ->setParam('featureId', $featureId)
            ->setPayload($created->getArrayCopy());

        $queueForAudits
            ->setProject($project)
            ->setPayload($created->getArrayCopy());

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->json($created->getArrayCopy());
    }
}


