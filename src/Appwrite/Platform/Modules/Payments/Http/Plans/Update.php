<?php

namespace Appwrite\Platform\Modules\Payments\Http\Plans;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Payments\Provider\ProviderState;
use Appwrite\Payments\Provider\Registry;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\JSON as JSONValidator;
use Utopia\Validator\Text;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updatePaymentPlan';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/payments/plans/:planId')
            ->groups(['api', 'payments'])
            ->desc('Update payment plan')
            ->label('scope', 'payments.write')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.plans.[planId].update')
            ->label('audits.event', 'payments.plan.update')
            ->label('audits.resource', 'payments/plan/{request.planId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'plans',
                name: 'update',
                description: 'Update a payment plan',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('planId', '', new Text(128), 'Plan ID')
            ->param('name', '', new Text(2048), 'Plan name.', true)
            ->param('description', '', new Text(8192, 0), 'Plan description.', true)
            ->param('isDefault', false, new Boolean(), 'Set as default plan for new users.', true)
            ->param('isFree', false, new Boolean(), 'Is the plan free.', true)
            ->param('pricing', [], new JSONValidator(), 'Pricing configuration array [{amount,currency,interval}]', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('registryPayments')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $planId,
        string $name,
        string $description,
        bool $isDefault,
        bool $isFree,
        array $pricing,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject,
        Registry $registryPayments,
        Document $project
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
        if ($name !== '') $plan->setAttribute('name', $name);
        if ($description !== '') $plan->setAttribute('description', $description);
        $plan->setAttribute('isDefault', $isDefault);
        $plan->setAttribute('isFree', $isFree);
        if (!empty($pricing)) $plan->setAttribute('pricing', $pricing);
        $plan = $dbForPlatform->updateDocument('payments_plans', $plan->getId(), $plan);

        // Update on providers if pricing changed or name/desc changed
        $payments = (array) $project->getAttribute('payments', []);
        $providerConfigs = (array) ($payments['providers'] ?? []);
        $providersMeta = (array) $plan->getAttribute('providers', []);
        foreach ($providerConfigs as $providerId => $providerConfig) {
            $state = new ProviderState((string) $providerId, (array) $providerConfig, (array) ($providerConfig['state'] ?? []));
            $adapter = $registryPayments->get((string) $providerId, (array) $providerConfig, $project, $dbForPlatform, $dbForProject);
            $existing = $providersMeta[$providerId] ?? [];
            $ref = new \Appwrite\Payments\Provider\ProviderPlanRef((string) ($existing['externalId'] ?? ''), (array) ($existing['metadata'] ?? []));
            $newRef = $adapter->updatePlan([
                'planId' => $planId,
                'name' => $name,
                'description' => $description,
                'pricing' => !empty($pricing) ? $pricing : ($plan->getAttribute('pricing') ?? [])
            ], $ref, $state);
            $providersMeta[$providerId] = [ 'externalId' => $newRef->externalPlanId, 'metadata' => $newRef->metadata ];
        }
        if (!empty($providersMeta)) {
            $plan->setAttribute('providers', $providersMeta);
            $plan = $dbForPlatform->updateDocument('payments_plans', $plan->getId(), $plan);
        }
        $response->json($plan->getArrayCopy());
    }
}


