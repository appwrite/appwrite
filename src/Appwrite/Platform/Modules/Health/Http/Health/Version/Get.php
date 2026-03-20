<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Version;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getVersion';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/version')
            ->desc('Get version')
            ->groups(['api', 'health'])
            ->label('scope', 'public')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $response->dynamic(new Document(['version' => APP_VERSION_STABLE]), Response::MODEL_HEALTH_VERSION);
    }
}
