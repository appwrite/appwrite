<?php

namespace Appwrite\Platform\Modules\Payments\Http\Plans;

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

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deletePaymentPlan';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/payments/plans/:planId')
            ->groups(['api', 'payments'])
            ->desc('Delete payment plan')
            ->label('scope', 'payments.write')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.plans.[planId].delete')
            ->label('audits.event', 'payments.plan.delete')
            ->label('audits.resource', 'payments/plan/{request.planId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'plans',
                name: 'delete',
                description: 'Delete a payment plan',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: []
            ))
            ->param('planId', '', new Text(128), 'Plan ID')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $planId,
        Response $response,
        Database $dbForPlatform,
        Document $project
    ) {
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
        $dbForPlatform->deleteDocument('payments_plans', $plan->getId());
        $response->noContent();
    }
}
