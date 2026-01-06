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

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getPaymentFeature';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/features/:featureId')
            ->groups(['api', 'payments'])
            ->desc('Get payment feature')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'features',
                name: 'get',
                description: <<<EOT
                Get a feature by its unique ID.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PAYMENT_FEATURE,
                    )
                ]
            ))
            ->param('featureId', '', new Text(128), 'Feature ID')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $featureId,
        Response $response,
        Database $dbForProject
    ) {
        $feature = $dbForProject->findOne('payments_features', [
            Query::equal('featureId', [$featureId])
        ]);

        if ($feature === null || $feature->isEmpty()) {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::PAYMENT_FEATURE_NOT_FOUND);
        }

        $response->dynamic($feature, Response::MODEL_PAYMENT_FEATURE);
    }
}
