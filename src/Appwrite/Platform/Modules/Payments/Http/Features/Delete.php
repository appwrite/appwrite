<?php

namespace Appwrite\Platform\Modules\Payments\Http\Features;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deletePaymentFeature';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/payments/features/:featureId')
            ->groups(['api', 'payments'])
            ->desc('Delete payment feature')
            ->label('scope', 'payments.write')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.features.[featureId].delete')
            ->label('audits.event', 'payments.feature.delete')
            ->label('audits.resource', 'payments/feature/{request.featureId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'features',
                name: 'delete',
                description: 'Delete a feature definition',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: []
            ))
            ->param('featureId', '', new Text(128), 'Feature ID')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $featureId,
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
        $dbForPlatform->deleteDocument('payments_features', $feature->getId());
        $response->noContent();
    }
}


