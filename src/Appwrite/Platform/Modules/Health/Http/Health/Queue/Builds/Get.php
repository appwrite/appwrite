<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Queue\Builds;

use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Base;
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
            ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
            ->inject('publisherForBuilds')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(int|string $threshold, BuildPublisher $publisherForBuilds, Response $response): void
    {
        $threshold = (int) $threshold;

        $size = $publisherForBuilds->getSize();

        $this->assertQueueThreshold($size, $threshold);

        $response->dynamic(new Document(['size' => $size]), Response::MODEL_HEALTH_QUEUE);
    }
}
