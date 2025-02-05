<?php

namespace Appwrite\Platform\Modules\Storage\Http\Tokens;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
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
        ->label('sdk', new Method(
            namespace: 'tokens',
            name: 'get',
            description: '',
            auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_RESOURCE_TOKEN,
                )
            ],
            contentType: ContentType::JSON
        ))
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
