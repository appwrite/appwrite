<?php

namespace Appwrite\Platform\Modules\Payments\Http\Plans;

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
        return 'listPaymentPlans';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/plans')
            ->groups(['api', 'payments'])
            ->desc('List payment plans')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'plans',
                name: 'list',
                description: 'List payment plans',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PAYMENT_PLAN_LIST,
                    )
                ]
            ))
            ->param('search', '', new Text(256), 'Search term.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $search,
        Response $response,
        Database $dbForPlatform,
        Document $project
    )
    {
        $filters = [ Query::equal('projectId', [$project->getId()]) ];
        if ($search !== '') {
            $filters[] = Query::search('search', $search);
        }
        $plans = $dbForPlatform->find('payments_plans', $filters);
        $payload = [
            'total' => count($plans),
            'plans' => array_map(fn ($d) => $d->getArrayCopy(), $plans)
        ];
        $response->dynamic(new Document($payload), Response::MODEL_PAYMENT_PLAN_LIST);
    }
}


