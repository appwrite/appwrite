<?php

namespace Appwrite\Platform\Modules\Payments\Http\Features;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createPaymentFeature';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/payments/features')
            ->groups(['api', 'payments'])
            ->desc('Create payment feature')
            ->label('scope', 'payments.write')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.features.[featureId].create')
            ->label('audits.event', 'payments.feature.create')
            ->label('audits.resource', 'payments/feature/{request.featureId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'features',
                name: 'create',
                description: 'Create a feature definition',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('featureId', '', new Text(128), 'Feature ID')
            ->param('name', '', new Text(2048), 'Feature name')
            ->param('type', 'boolean', new Text(32), 'Feature type')
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
    ) {
        // Feature flag: block if payments disabled for project
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            $response->setStatusCode(Response::STATUS_CODE_FORBIDDEN);
            $response->json(['message' => 'Payments feature is disabled for this project']);
            return;
        }

        $doc = new Document([
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'featureId' => $featureId,
            'name' => $name,
            'type' => $type,
            'description' => $description,
            'providers' => []
        ]);
        $created = $dbForPlatform->createDocument('payments_features', $doc);
        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->json($created->getArrayCopy());
    }
}
