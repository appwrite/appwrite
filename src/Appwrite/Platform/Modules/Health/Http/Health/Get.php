<?php

namespace Appwrite\Platform\Modules\Health\Http\Health;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'get';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health')
            ->desc('Get HTTP')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $response->dynamic(new Document([
            'name' => 'http',
            'status' => 'pass',
            'ping' => 0,
        ]), Response::MODEL_HEALTH_STATUS);
    }
}
