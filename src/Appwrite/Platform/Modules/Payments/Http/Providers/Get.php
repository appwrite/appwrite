<?php

namespace Appwrite\Platform\Modules\Payments\Http\Providers;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getPaymentProviders';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/providers')
            ->groups(['api', 'payments'])
            ->desc('Get payments providers config')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'providers',
                name: 'get',
                description: 'Get payments providers configuration',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(Response $response, Database $dbForPlatform, Document $project)
    {
        $projectDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $payments = (array) $projectDoc->getAttribute('payments', []);
        $providers = (array) ($payments['providers'] ?? []);
        foreach ($providers as $pid => &$cfg) {
            if (isset($cfg['secretKey'])) $cfg['secretKey'] = '***';
            if (isset($cfg['webhookSecret'])) $cfg['webhookSecret'] = '***';
        }
        $payments['providers'] = $providers;
        $response->json(['payments' => $payments]);
    }
}


