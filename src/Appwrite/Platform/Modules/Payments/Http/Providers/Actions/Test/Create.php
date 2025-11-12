<?php

namespace Appwrite\Platform\Modules\Payments\Http\Providers\Actions\Test;

use Appwrite\Payments\Provider\Registry;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'testPaymentProvider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/payments/providers/:providerId/actions/test')
            ->groups(['api', 'payments'])
            ->desc('Test payments provider credentials')
            ->label('scope', 'payments.write')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'providers',
                name: 'test',
                description: 'Test payments provider credentials',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('providerId', '', new UID(), 'Provider ID')
            ->inject('response')
            ->inject('registryPayments')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(string $providerId, Response $response, Registry $registryPayments, \Utopia\Database\Database $dbForPlatform, \Utopia\Database\Database $dbForProject, Document $project)
    {
        // Load provider config from project.payments
        $payments = (array) $project->getAttribute('payments', []);
        $providers = (array) ($payments['providers'] ?? []);
        $config = (array) ($providers[$providerId] ?? []);
        $result = $registryPayments->get($providerId, $config, $project, $dbForPlatform, $dbForProject)->testConnection($config);
        $response->json(['success' => $result->success, 'message' => $result->message]);
    }
}
