<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\DB;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Pools\Group;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getDB';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/db')
            ->desc('Get DB')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'health',
                name: 'getDB',
                description: '/docs/references/health/get-db.md',
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
            ->inject('pools')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(Response $response, Group $pools, Authorization $authorization): void
    {
        $output = [];
        $failures = [];

        $configs = [
            'Console.DB' => Config::getParam('pools-console'),
            'Projects.DB' => Config::getParam('pools-database'),
        ];

        foreach ($configs as $key => $config) {
            foreach ($config as $database) {
                try {
                    $adapter = new DatabasePool($pools->get($database));
                    $adapter->setAuthorization($authorization);

                    $checkStart = \microtime(true);

                    if ($adapter->ping()) {
                        $output[] = new Document([
                            'name' => $key . " ($database)",
                            'status' => 'pass',
                            'ping' => \round((\microtime(true) - $checkStart) * 1000)
                        ]);
                    } else {
                        $failures[] = $database;
                    }
                } catch (\Throwable) {
                    $failures[] = $database;
                }
            }
        }

        // Only throw error if ALL databases failed (no successful pings)
        // This allows partial failures in environments where not all DBs are ready
        if (!empty($failures)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'DB failure on: ' . implode(", ", $failures));
        }

        $response->dynamic(new Document([
            'statuses' => $output,
            'total' => count($output),
        ]), Response::MODEL_HEALTH_STATUS_LIST);
    }
}
