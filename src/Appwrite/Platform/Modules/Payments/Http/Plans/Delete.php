<?php

namespace Appwrite\Platform\Modules\Payments\Http\Plans;

use Appwrite\Event\Event;
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
                description: <<<EOT
                Delete a payment plan by its unique ID. This action cannot be undone.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: []
            ))
            ->param('planId', '', new Text(128), 'Plan ID')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $planId,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents
    ) {
        // Feature flag: block if payments disabled for project
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_ACCESS_FORBIDDEN, 'Payments feature is disabled for this project');
        }

        $plan = $dbForProject->findOne('payments_plans', [
            Query::equal('planId', [$planId])
        ]);
        if ($plan === null || $plan->isEmpty()) {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::PAYMENT_PLAN_NOT_FOUND);
        }
        $dbForProject->deleteDocument('payments_plans', $plan->getId());

        $queueForEvents
            ->setEvent('payments.[planId].delete')
            ->setParam('planId', $planId)
            ->setPayload(['planId' => $planId]);

        $response->noContent();
    }
}
