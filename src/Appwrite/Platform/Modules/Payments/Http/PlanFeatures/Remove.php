<?php

namespace Appwrite\Platform\Modules\Payments\Http\PlanFeatures;

use Appwrite\Event\Audit;
use Appwrite\Event\Event;
use Appwrite\Payments\Provider\ProviderState;
use Appwrite\Payments\Provider\Registry;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Remove extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'removePaymentPlanFeature';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/payments/plans/:planId/features/:featureId')
            ->groups(['api', 'payments'])
            ->desc('Remove feature from plan')
            ->label('scope', 'payments.write')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.plans.[planId].features.[featureId].remove')
            ->label('audits.event', 'payments.plan.feature.remove')
            ->label('audits.resource', 'payments/plan/{request.planId}/feature/{request.featureId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'planFeatures',
                name: 'removePlanFeature',
                description: <<<EOT
                Remove a feature from a plan.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: []
            ))
            ->param('planId', '', new Text(128), 'Plan ID')
            ->param('featureId', '', new Text(128), 'Feature ID')
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
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject,
        Registry $registryPayments,
        Document $project,
        Event $queueForEvents,
        Audit $queueForAudits
    ) {
        // Feature flag: block if payments disabled for project
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_ACCESS_FORBIDDEN, 'Payments feature is disabled for this project');
        }

        $assignment = $dbForProject->findOne('payments_plan_features', [
            Query::equal('planId', [$planId]),
            Query::equal('featureId', [$featureId])
        ]);
        if ($assignment === null || $assignment->isEmpty()) {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_NOT_FOUND, 'Assignment not found');
        }
        // Attempt deprovision: deactivate provider price if tracked
        $plan = $dbForProject->findOne('payments_plans', [
            Query::equal('planId', [$planId])
        ]);
        $planProviders = (array) ($plan?->getAttribute('providers', []) ?? []);
        $providersMeta = (array) $assignment->getAttribute('providers', []);
        $payments = (array) $project->getAttribute('payments', []);
        $providerConfigs = (array) ($payments['providers'] ?? []);
        foreach ($providerConfigs as $providerId => $providerConfig) {
            $state = new ProviderState((string) $providerId, (array) $providerConfig, (array) ($providerConfig['state'] ?? []));
            $adapter = $registryPayments->get((string) $providerId, (array) $providerConfig, $project, $dbForPlatform, $dbForProject);
            $providerPlan = (array) ($planProviders[$providerId] ?? []);
            $providerAssignment = (array) ($providersMeta[$providerId] ?? []);
            $planRef = new \Appwrite\Payments\Provider\ProviderPlanRef((string) ($providerPlan['externalId'] ?? ''), (array) ($providerPlan['metadata'] ?? []));
            $featRef = new \Appwrite\Payments\Provider\ProviderFeatureRef((string) ($providerAssignment['priceId'] ?? ''), (array) $providerAssignment);
            $adapter->deleteFeature($featRef, $planRef, $state);
        }
        $dbForProject->deleteDocument('payments_plan_features', $assignment->getId());

        $queueForEvents
            ->setProject($project)
            ->setEvent('payments.[planId].features.[featureId].remove')
            ->setParam('planId', $planId)
            ->setParam('featureId', $featureId)
            ->setPayload(['planId' => $planId, 'featureId' => $featureId]);

        $queueForAudits
            ->setProject($project)
            ->setPayload(['planId' => $planId, 'featureId' => $featureId]);

        $response->noContent();
    }
}
