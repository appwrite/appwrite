<?php

namespace Appwrite\Platform\Modules\Tokens\Http\Tokens;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class GetToken extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getToken';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
        ->setHttpPath('/v1/tokens/:tokenId')
        ->desc('Get token')
        ->groups(['api', 'tokens'])
        ->label('scope', 'tokens.read')
        ->label('usage.metric', 'tokens.{scope}.requests.read')
        ->label('usage.params', ['tokenId:{request.tokenId}'])
        ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
        ->label('sdk.namespace', 'tokens')
        ->label('sdk.method', 'get')
        ->label('sdk.description', '/docs/references/tokens/get.md')
        ->label('sdk.response.code', Response::STATUS_CODE_OK)
        ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
        ->label('sdk.response.model', Response::MODEL_RESOURCE_TOKEN)
        ->param('tokenId', '', new UID(), 'Token ID.')
        ->inject('response')
        ->inject('dbForProject')
        ->callback(fn ($tokenId, $response, $dbForProject) => $this->action($tokenId, $response, $dbForProject));
    }

    public function action(string $tokenId, Response $response, Database $dbForProject)
    {
        $token = $dbForProject->getDocument('resourceTokens', $tokenId);

        if ($token->isEmpty()) {
            throw new Exception(Exception::TOKEN_NOT_FOUND);
        }

        $response->dynamic($token, Response::MODEL_RESOURCE_TOKEN);
    }
}
