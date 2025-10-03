<?php

namespace Appwrite\Platform\Modules\Payments\Http\Usage;

use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Integer as IntValidator;
use Utopia\Validator\Text;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'reportPaymentSubscriptionUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/payments/subscriptions/:subscriptionId/usage')
            ->groups(['api', 'payments'])
            ->desc('Report usage for subscription')
            ->label('scope', 'payments.subscribe')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.subscriptions.usage.report')
            ->label('audits.event', 'payments.usage.report')
            ->label('audits.resource', 'payments/subscription/{request.subscriptionId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'usage',
                name: 'report',
                description: 'Report usage for subscription',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('subscriptionId', '', new Text(128), 'Subscription ID')
            ->param('featureId', '', new Text(128), 'Feature ID')
            ->param('quantity', 0, new IntValidator(), 'Usage quantity')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $subscriptionId,
        string $featureId,
        int $quantity,
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

        $sub = $dbForPlatform->findOne('payments_subscriptions', [
            Query::equal('projectId', [$project->getId()]),
            Query::equal('subscriptionId', [$subscriptionId])
        ]);
        if ($sub === null || $sub->isEmpty()) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['message' => 'Invalid subscriptionId']);
            return;
        }
        $event = new Document([
            '$id' => ID::unique(),
            'projectId' => $project->getId(),
            'subscriptionId' => $subscriptionId,
            'actorType' => $sub->getAttribute('actorType'),
            'actorId' => $sub->getAttribute('actorId'),
            'planId' => $sub->getAttribute('planId'),
            'featureId' => $featureId,
            'quantity' => $quantity,
            'timestamp' => date('c'),
            'providerSyncState' => 'pending',
            'providerEventId' => null,
            'metadata' => []
        ]);
        $created = $dbForPlatform->createDocument('payments_usage_events', $event);
        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->json($created->getArrayCopy());
    }
}


