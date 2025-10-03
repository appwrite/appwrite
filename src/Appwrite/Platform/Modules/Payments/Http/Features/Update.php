<?php

namespace Appwrite\Platform\Modules\Payments\Http\Features;

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

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updatePaymentFeature';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/payments/features/:featureId')
            ->groups(['api', 'payments'])
            ->desc('Update payment feature')
            ->label('scope', 'payments.write')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.features.[featureId].update')
            ->label('audits.event', 'payments.feature.update')
            ->label('audits.resource', 'payments/feature/{request.featureId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'features',
                name: 'update',
                description: 'Update a feature definition',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('featureId', '', new Text(128), 'Feature ID')
            ->param('name', '', new Text(2048), 'Feature name', true)
            ->param('type', 'boolean', new Text(32), 'Feature type', true)
            ->param('description', '', new Text(8192, 0), 'Description', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $featureId,
        string $name,
        string $type,
        string $description,
        Response $response,
        Database $dbForPlatform,
        Document $project
    )
    {
        // Feature flag: block if payments disabled for project
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
            $response->json(['message' => 'Payments feature is disabled for this project']);
            return;
        }

        $feature = $dbForPlatform->findOne('payments_features', [
            Query::equal('projectId', [$project->getId()]),
            Query::equal('featureId', [$featureId])
        ]);
        if ($feature === null || $feature->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND);
            $response->json(['message' => 'Feature not found']);
            return;
        }
        if ($name !== '') $feature->setAttribute('name', $name);
        if ($type !== '') $feature->setAttribute('type', $type);
        if ($description !== '') $feature->setAttribute('description', $description);
        $feature = $dbForPlatform->updateDocument('payments_features', $feature->getId(), $feature);
        $response->json($feature->getArrayCopy());
    }
}


