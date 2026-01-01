<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Cache;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Cache\Adapter\Pool as CachePool;
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
        return 'getCache';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/cache')
            ->desc('Get cache')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'health',
                name: 'getCache',
                description: '/docs/references/health/get-cache.md',
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
            'Cache' => Config::getParam('pools-cache'),
        ];

        foreach ($configs as $key => $config) {
            foreach ($config as $cache) {
                try {
                    $adapter = new CachePool($pools->get($cache));

                    $checkStart = \microtime(true);

                    if ($adapter->ping()) {
                        $output[] = new Document([
                            'name' => $key . " ($cache)",
                            'status' => 'pass',
                            'ping' => \round((\microtime(true) - $checkStart) * 1000),
                        ]);
                    } else {
                        $failures[] = $cache;
                    }
                } catch (\Throwable) {
                    $failures[] = $cache;
                }
            }
        }

        if (!empty($failures)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Cache failure on: ' . \implode(', ', $failures));
        }

        $response->dynamic(new Document([
            'statuses' => $output,
            'total' => \count($output),
        ]), Response::MODEL_HEALTH_STATUS_LIST);
    }
}
