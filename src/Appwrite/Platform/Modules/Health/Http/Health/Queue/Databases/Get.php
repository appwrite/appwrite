<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Queue\Databases;

use Appwrite\Event\Database;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;

class Get extends Base
{
    public static function getName(): string
    {
        return 'getQueueDatabases';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/queue/databases')
            ->desc('Get databases queue')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'queue',
                name: 'getQueueDatabases',
                description: '/docs/references/health/get-queue-databases.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_HEALTH_QUEUE,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('name', 'database_db_main-0', new Text(256), 'Queue name for which to check the queue size', true)
            ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
            ->inject('queueForDatabase')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $name, int|string $threshold, Database $queueForDatabase, Response $response): void
    {
        $threshold = (int) $threshold;
        $size = $queueForDatabase->setQueue($name)->getSize();

        $this->assertQueueThreshold($size, $threshold);

        $response->dynamic(new Document(['size' => $size]), Response::MODEL_HEALTH_QUEUE);
    }
}
