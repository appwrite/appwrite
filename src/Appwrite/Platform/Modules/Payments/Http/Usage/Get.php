<?php

namespace Appwrite\Platform\Modules\Payments\Http\Usage;

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

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getPaymentSubscriptionUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/subscriptions/:subscriptionId/usage')
            ->groups(['api', 'payments'])
            ->desc('Get usage for subscription')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.subscriptions.usage.get')
            ->label('audits.event', 'payments.usage.get')
            ->label('audits.resource', 'payments/subscription/{request.subscriptionId}')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'usage',
                name: 'get',
                description: 'Get usage summary for subscription',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('subscriptionId', '', new Text(128), 'Subscription ID')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $subscriptionId,
        Response $response,
        Database $dbForPlatform,
        Document $project
    ) {
        $events = $dbForPlatform->find('payments_usage_events', [
            Query::equal('projectId', [$project->getId()]),
            Query::equal('subscriptionId', [$subscriptionId])
        ]);
        $total = 0;
        foreach ($events as $e) {
            $total += (int) $e->getAttribute('quantity', 0);
        }
        $response->json([
            'subscriptionId' => $subscriptionId,
            'total' => $total,
            'events' => array_map(fn ($d) => $d->getArrayCopy(), $events)
        ]);
    }
}
