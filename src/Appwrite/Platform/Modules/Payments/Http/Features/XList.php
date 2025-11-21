<?php

namespace Appwrite\Platform\Modules\Payments\Http\Features;

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

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listPaymentFeatures';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/features')
            ->groups(['api', 'payments'])
            ->desc('List payment features')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'features',
                name: 'list',
                description: 'List feature definitions',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('search', '', new Text(256), 'Search term.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $search,
        Response $response,
        Database $dbForProject
    ) {
        $filters = [];
        if ($search !== '') {
            $filters[] = Query::search('name', $search);
        }
        $list = $dbForProject->find('payments_features', $filters);
        $response->json([
            'total' => count($list),
            'features' => array_map(fn ($d) => $d->getArrayCopy(), $list)
        ]);
    }
}
