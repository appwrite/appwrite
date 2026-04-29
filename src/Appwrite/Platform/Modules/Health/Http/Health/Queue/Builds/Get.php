<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Queue\Builds;

use Appwrite\Event\Build;
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
        return 'getQueueBuilds';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/queue/builds')
            ->desc('Get builds queue')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'queue',
                name: 'getQueueBuilds',
                description: '/docs/references/health/get-queue-builds.md',
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
            ->inject('queueForBuilds')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(int|string $threshold, Build $queueForBuilds, Response $response): void
    {
        $threshold = (int) $threshold;

        $size = $queueForBuilds->getSize();

        $this->assertQueueThreshold($size, $threshold);

        $response->dynamic(new Document(['size' => $size]), Response::MODEL_HEALTH_QUEUE);
    }
}
