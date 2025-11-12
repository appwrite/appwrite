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

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listPaymentSubscriptions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/subscriptions')
            ->groups(['api', 'payments'])
            ->desc('List subscriptions')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'subscriptions',
                name: 'list',
                description: 'List subscriptions',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PAYMENT_SUBSCRIPTION_LIST,
                    )
                ]
            ))
            ->param('actorType', '', new Text(16, 0), 'Filter by actor type', true)
            ->param('actorId', '', new Text(128, 0), 'Filter by actor ID', true)
            ->param('status', '', new Text(32, 0), 'Filter by status', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $actorType,
        string $actorId,
        string $status,
        Response $response,
        Database $dbForPlatform,
        Document $project
    ) {
        $filters = [ Query::equal('projectId', [$project->getId()]) ];
        if ($actorType !== '') {
            $filters[] = Query::equal('actorType', [$actorType]);
        }
        if ($actorId !== '') {
            $filters[] = Query::equal('actorId', [$actorId]);
        }
        if ($status !== '') {
            $filters[] = Query::equal('status', [$status]);
        }
        $list = $dbForPlatform->find('payments_subscriptions', $filters);
        $plansById = [];
        foreach ($list as $sub) {
            $planId = (string) $sub->getAttribute('planId', '');
            if ($planId !== '' && !isset($plansById[$planId])) {
                $plan = $dbForPlatform->findOne('payments_plans', [
                    Query::equal('projectId', [$project->getId()]),
                    Query::equal('planId', [$planId])
                ]);
                if ($plan) {
                    $plansById[$planId] = $plan;
                }
            }
        }
        $subs = [];
        foreach ($list as $sub) {
            $arr = $sub->getArrayCopy();
            $planId = (string) ($arr['planId'] ?? '');
            if ($planId !== '' && isset($plansById[$planId])) {
                $arr['plan'] = $plansById[$planId]->getArrayCopy();
            }
            $subs[] = $arr;
        }
        $response->dynamic(new Document(['total' => count($subs), 'subscriptions' => $subs]), Response::MODEL_PAYMENT_SUBSCRIPTION_LIST);
    }
}
