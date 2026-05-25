<?php

namespace Appwrite\Platform\Modules\Console\Http\Scopes\Project;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listConsoleProjectScopes';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/console/scopes/project')
            ->desc('List project scopes')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('sdk', new Method(
                namespace: 'console',
                group: 'console',
                name: 'listProjectScopes',
                description: 'List all scopes available for project API keys, along with a description for each scope.',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_CONSOLE_KEY_SCOPE_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $scopesConfig = Config::getParam('projectScopes', []);

        $scopes = [];
        foreach ($scopesConfig as $scopeId => $scope) {
            $scopes[] = new Document([
                '$id' => $scopeId,
                'description' => $scope['description'] ?? '',
                'category' => $scope['category'] ?? '',
                'deprecated' => $scope['deprecated'] ?? false,
            ]);
        }

        $response->dynamic(new Document([
            'total' => \count($scopes),
            'scopes' => $scopes,
        ]), Response::MODEL_CONSOLE_KEY_SCOPE_LIST);
    }
}
