<?php

namespace Appwrite\Platform\Modules\Payments\Http\PlanFeatures;

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
        return 'listPaymentPlanFeatures';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/plans/:planId/features')
            ->groups(['api', 'payments'])
            ->desc('List plan features')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'planFeatures',
                name: 'list',
                description: 'List features assigned to a plan',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
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
    )
    {
        $items = $dbForPlatform->find('payments_plan_features', [
            Query::equal('projectId', [$project->getId()]),
            Query::equal('planId', [$planId])
        ]);
        $response->json([
            'total' => count($items),
            'assignments' => array_map(fn ($d) => $d->getArrayCopy(), $items)
        ]);
    }
}


