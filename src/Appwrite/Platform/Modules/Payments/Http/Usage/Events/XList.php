<?php

namespace Appwrite\Platform\Modules\Payments\Http\Usage\Events;

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

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listPaymentUsageEvents';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/payments/usage/events')
            ->groups(['api', 'payments'])
            ->desc('List usage events')
            ->label('scope', 'payments.read')
            ->label('resourceType', RESOURCE_TYPE_PAYMENTS)
            ->label('event', 'payments.usage.events.list')
            ->label('audits.event', 'payments.usage.events.list')
            ->label('audits.resource', 'payments/usage/events')
            ->label('sdk', new Method(
                namespace: 'payments',
                group: 'usage',
                name: 'listEvents',
                description: 'List usage events',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ]
            ))
            ->param('subscriptionId', '', new Text(128, 0), 'Filter by subscription ID', true)
            ->param('featureId', '', new Text(128, 0), 'Filter by feature ID', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $subscriptionId,
        string $featureId,
        Response $response,
        Database $dbForPlatform,
        Document $project
    ) {
        $filters = [ Query::equal('projectId', [$project->getId()]) ];
        if ($subscriptionId !== '') {
            $filters[] = Query::equal('subscriptionId', [$subscriptionId]);
        }
        if ($featureId !== '') {
            $filters[] = Query::equal('featureId', [$featureId]);
        }
        $list = $dbForPlatform->find('payments_usage_events', $filters);
        $response->json([
            'total' => count($list),
            'events' => array_map(fn ($d) => $d->getArrayCopy(), $list)
        ]);
    }
}
