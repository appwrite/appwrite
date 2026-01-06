<?php

namespace Appwrite\Platform\Modules\Payments\Http\Features;

use Appwrite\Event\Event;
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
                name: 'deleteFeature',
                description: <<<EOT
                Delete a feature by its unique ID.
                EOT,
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: []
            ))
            ->param('featureId', '', new Text(128), 'Feature ID')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $featureId,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents
    ) {
        // Feature flag: block if payments disabled for project
        $projDoc = $dbForPlatform->getDocument('projects', $project->getId());
        $paymentsCfg = (array) $projDoc->getAttribute('payments', []);
        if (isset($paymentsCfg['enabled']) && $paymentsCfg['enabled'] === false) {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::GENERAL_ACCESS_FORBIDDEN, 'Payments feature is disabled for this project');
        }

        $feature = $dbForProject->findOne('payments_features', [
            Query::equal('featureId', [$featureId])
        ]);
        if ($feature === null || $feature->isEmpty()) {
            throw new \Appwrite\AppwriteException(\Appwrite\Extend\Exception::PAYMENT_FEATURE_NOT_FOUND);
        }
        $dbForProject->deleteDocument('payments_features', $feature->getId());

        $queueForEvents
            ->setEvent('payments.[featureId].delete')
            ->setParam('featureId', $featureId)
            ->setPayload(['featureId' => $featureId]);

        $response->noContent();
    }
}
