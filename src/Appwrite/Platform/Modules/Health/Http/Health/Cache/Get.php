<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Cache;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

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
                        model: Response::MODEL_HEALTH_STATUS_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(Response $response, Cache $cache): void
    {
        $output = [];

        $checkStart = \microtime(true);

        try {
            $ok = $cache->ping();
        } catch (\Throwable) {
            $ok = false;
        }

        if (!$ok) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Cache failure on: cache');
        }

        $output[] = new Document([
            'name' => 'Cache',
            'status' => 'pass',
            'ping' => \round((\microtime(true) - $checkStart) * 1000),
        ]);

        $response->dynamic(new Document([
            'statuses' => $output,
            'total' => \count($output),
        ]), Response::MODEL_HEALTH_STATUS_LIST);
    }
}
