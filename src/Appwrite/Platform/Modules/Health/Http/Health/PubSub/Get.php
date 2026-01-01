<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\PubSub;

use Appwrite\Extend\Exception;
use Appwrite\PubSub\Adapter\Pool as PubSubPool;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Pools\Group;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getPubSub';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/pubsub')
            ->desc('Get pubsub')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'health',
                name: 'getPubSub',
                description: '/docs/references/health/get-pubsub.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_HEALTH_STATUS,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->inject('response')
            ->inject('pools')
            ->callback($this->action(...));
    }

    public function action(Response $response, Group $pools): void
    {
        $output = [];
        $failures = [];

        $configs = [
            'PubSub' => Config::getParam('pools-pubsub'),
        ];

        foreach ($configs as $key => $config) {
            foreach ($config as $pubsub) {
                try {
                    $adapter = new PubSubPool($pools->get($pubsub));

                    $checkStart = \microtime(true);

                    if ($adapter->ping()) {
                        $output[] = new Document([
                            'name' => $key . " ($pubsub)",
                            'status' => 'pass',
                            'ping' => \round((\microtime(true) - $checkStart) * 1000),
                        ]);
                    } else {
                        $failures[] = $pubsub;
                    }
                } catch (\Throwable) {
                    $failures[] = $pubsub;
                }
            }
        }

        if (!empty($failures)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Pubsub failure on: ' . \implode(', ', $failures));
        }

        $response->dynamic(new Document([
            'statuses' => $output,
            'total' => \count($output),
        ]), Response::MODEL_HEALTH_STATUS_LIST);
    }
}
