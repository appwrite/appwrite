<?php

namespace Appwrite\Platform\Modules\Payments\Http\Plans;

use Appwrite\AppwriteException;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception as ExtendException;
use Appwrite\Payments\Provider\ProviderState;
use Appwrite\Payments\Provider\Registry;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\JSON as JSONValidator;
use Utopia\Validator\Text;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createPaymentPlan';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/payments/plans')
            ->groups(['api', 'payments'])
            ->desc('Create payment plan')
            ->label('scope', 'payments.write')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'plans.[planId].create')
            ->label('audits.event', 'payments.plan.create')
            ->label('audits.resource', 'payments/plan/{request.planId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'plans',
                name: 'create',
                description: 'Create a payment plan',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('planId', '', new CustomId(), 'Plan ID. Choose a custom ID or generate a random ID with `ID.unique()`.')
            ->param('name', '', new Text(2048), 'Plan name.')
            ->param('description', '', new Text(8192, 0), 'Plan description.', true)
            ->param('isDefault', false, new Boolean(), 'Set as default plan for new users.', true)
            ->param('isFree', false, new Boolean(), 'Is the plan free.', true)
            ->param('pricing', [], new JSONValidator(), 'Pricing configuration array [{amount,currency,interval}]', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('registryPayments')
            ->inject('queueForEvents')
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
        Event $queueForEvents,
        Document $project
    ) {
        $document = new Document([
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'planId' => $planId,
            'name' => $name,
            'description' => $description,
            'pricing' => $pricing,
            'isDefault' => $isDefault,
            'isFree' => $isFree,
            'status' => 'active',
            'providers' => [],
            'features' => [],
            'search' => implode(' ', [$planId, $name])
        ]);

        // Feature flag: block if payments disabled for project
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
            $response->json(['message' => 'Payments feature is disabled for this project']);
            return;
        }

        // Check if plan already exists
        $existingPlan = $dbForPlatform->findOne('payments_plans', [
            Query::equal('projectId', [$project->getId()]),
            Query::equal('planId', [$planId])
        ]);
        if ($existingPlan !== null && !$existingPlan->isEmpty()) {
            // TODO: create a custom exception for this
            return new AppwriteException(ExtendException::RESOURCE_ALREADY_EXISTS);
        }

        $created = $dbForPlatform->createDocument('payments_plans', $document);
        
        $queueForEvents->setParam('planId', $planId);

        // Provision on configured providers
        $payments = (array) $project->getAttribute('payments', []);
        $providerConfigs = (array) ($payments['providers'] ?? []);
        $providersMeta = [];
        foreach ($providerConfigs as $providerId => $providerConfig) {
            $state = new ProviderState((string) $providerId, (array) $providerConfig, (array) ($providerConfig['state'] ?? []));
            $adapter = $registryPayments->get((string) $providerId, (array) $providerConfig, $project, $dbForPlatform, $dbForProject);
            $ref = $adapter->ensurePlan([
                'planId' => $planId,
                'name' => $name,
                'description' => $description,
                'pricing' => $pricing,
            ], $state);
            $meta = $ref->metadata;
            $providersMeta[$providerId] = [
                'externalId' => $ref->externalPlanId,
                'metadata' => $meta,
                'prices' => (array) ($meta['prices'] ?? [])
            ];
        }

        if (!empty($providersMeta)) {
            $created->setAttribute('providers', $providersMeta);
            $created = $dbForPlatform->updateDocument('payments_plans', $created->getId(), $created);
        }

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->json($created->getArrayCopy());
    }
}
