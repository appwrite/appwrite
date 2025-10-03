<?php

namespace Appwrite\Platform\Modules\Payments\Http\Subscriptions;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getPaymentSubscription';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/subscriptions/:subscriptionId')
            ->groups(['api', 'payments'])
            ->desc('Get subscription')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'subscriptions',
                name: 'get',
                description: 'Get a subscription',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PAYMENT_SUBSCRIPTION,
                    )
                ]
            ))
            ->param('subscriptionId', '', new Text(128), 'Subscription ID')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $subscriptionId,
        Response $response,
        Database $dbForPlatform,
        Document $project
    )
    {
        $sub = $dbForPlatform->findOne('payments_subscriptions', [
            Query::equal('projectId', [$project->getId()]),
            Query::equal('subscriptionId', [$subscriptionId])
        ]);
        if ($sub === null || $sub->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND);
            $response->json(['message' => 'Subscription not found']);
            return;
        }
        $arr = $sub->getArrayCopy();
        $planId = (string) ($arr['planId'] ?? '');
        if ($planId !== '') {
            $plan = $dbForPlatform->findOne('payments_plans', [
                Query::equal('projectId', [$project->getId()]),
                Query::equal('planId', [$planId])
            ]);
            if ($plan) $arr['plan'] = $plan->getArrayCopy();
        }
        $response->dynamic(new Document($arr), Response::MODEL_PAYMENT_SUBSCRIPTION);
    }
}


