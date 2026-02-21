<?php

namespace Appwrite\Platform\Modules\Health\Http\Health;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
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
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'health',
                name: 'get',
                description: '/docs/references/health/get.md',
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
