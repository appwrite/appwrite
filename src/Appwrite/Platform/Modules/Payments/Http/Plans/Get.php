<?php

namespace Appwrite\Platform\Modules\Payments\Http\Plans;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getPaymentPlan';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/plans/:planId')
            ->groups(['api', 'payments'])
            ->desc('Get payment plan')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'plans',
                name: 'get',
                description: 'Get a payment plan',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PAYMENT_PLAN,
                    )
                ]
            ))
            ->param('planId', '', new Text(128), 'Plan ID')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $planId,
        Response $response,
        Database $dbForProject
    ) {
        $plan = $dbForProject->findOne('payments_plans', [
            Query::equal('planId', [$planId])
        ]);

        if ($plan === null || $plan->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND);
            $response->json(['message' => 'Plan not found']);
            return;
        }

        $response->dynamic($plan, Response::MODEL_PAYMENT_PLAN);
    }
}
