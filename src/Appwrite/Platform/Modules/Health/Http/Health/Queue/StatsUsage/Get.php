<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Queue\StatsUsage;

use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Validator\Integer;

class Get extends Base
{
    public static function getName(): string
    {
        return 'getQueueUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/queue/stats-usage')
            ->desc('Get stats usage queue')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'queue',
                name: 'getQueueUsage',
                description: '/docs/references/health/get-queue-stats-usage.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_HEALTH_QUEUE,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
            ->inject('publisherForUsage')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(int|string $threshold, UsagePublisher $publisherForUsage, Response $response): void
    {
        $threshold = (int) $threshold;

        $size = $publisherForUsage->getSize();

        $this->assertQueueThreshold($size, $threshold);

        $response->dynamic(new Document(['size' => $size]), Response::MODEL_HEALTH_QUEUE);
    }
}
