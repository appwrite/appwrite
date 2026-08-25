<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Executions;

use Appwrite\Execution\Store;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getExecutions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/executions')
            ->desc('Get execution storage health')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->inject('response')
            ->inject('executionStore')
            ->callback($this->action(...));
    }

    public function action(Response $response, Store $executionStore): void
    {
        $health = $executionStore->healthCheck();
        if (($health['healthy'] ?? false) !== true
            || ($executionStore->isEnabled() && ($health['schemaReady'] ?? false) !== true)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Execution storage is not ready');
        }

        $status = new Document([
            'name' => $executionStore->isEnabled() ? 'Executions.ClickHouse' : 'Executions.ClickHouse (disabled)',
            'status' => 'pass',
            'ping' => 0,
        ]);
        $response->dynamic(new Document([
            'statuses' => [$status],
            'total' => 1,
        ]), Response::MODEL_HEALTH_STATUS_LIST);
    }
}
