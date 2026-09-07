<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Usage;

use Appwrite\Extend\Exception;
use Appwrite\Usage\Connection;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/usage')
            ->desc('Get usage storage health')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->inject('response')
            ->inject('usageConnection')
            ->callback($this->action(...));
    }

    public function action(Response $response, Connection $usageConnection): void
    {
        try {
            $health = $usageConnection->healthCheck();
        } catch (\Throwable $th) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Usage storage failure: ' . $th->getMessage());
        }

        if (($health['healthy'] ?? false) !== true) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Usage storage is not ready');
        }

        $status = new Document([
            'name' => $usageConnection->isEnabled() ? 'Usage.ClickHouse' : 'Usage.ClickHouse (disabled)',
            'status' => 'pass',
            'ping' => 0,
        ]);
        $response->dynamic(new Document([
            'statuses' => [$status],
            'total' => 1,
        ]), Response::MODEL_HEALTH_STATUS_LIST);
    }
}
