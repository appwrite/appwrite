<?php

namespace Appwrite\Platform\Modules\Console\Http\Scopes\Organization;

use Appwrite\Config\Config;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listConsoleOrganizationScopes';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/console/scopes/organization')
            ->desc('List organization scopes')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('sdk', new Method(
                namespace: 'console',
                group: 'console',
                name: 'listOrganizationScopes',
                description: 'List all scopes available for organization API keys, along with a description for each scope.',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_CONSOLE_KEY_SCOPE_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(bool $includeTotal, Response $response): void
    {
        $scopesConfig = Config::getParam('organizationScopes', []);

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
            'total' => $includeTotal ? \count($scopes) : 0,
            'scopes' => $scopes,
        ]), Response::MODEL_CONSOLE_KEY_SCOPE_LIST);
    }
}
