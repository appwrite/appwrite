<?php

namespace Appwrite\Platform\Modules\Storage\Http\Tokens\JWT;

use Ahc\Jwt\JWT;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getTokenJWT';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
        ->setHttpPath('/v1/tokens/:tokenId/jwt')
        ->desc('Get token as JWT')
        ->groups(['api', 'tokens'])
        ->label('scope', 'tokens.read')
        ->label('usage.metric', 'tokens.{scope}.requests.read')
        ->label('usage.params', ['tokenId:{request.tokenId}'])
        ->label('sdk', new Method(
            namespace: 'tokens',
            name: 'getJWT',
            description: '',
            auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_JWT,
                )
            ],
            contentType: ContentType::JSON
        ))
        ->param('tokenId', '', new UID(), 'File token ID.')
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

        // calculate maxAge based on expiry date
        $maxAge = PHP_INT_MAX;
        $expire = $token->getAttribute('expire');
        if ($expire != null) {
            $now = new \DateTime();
            $expiryDate = new \DateTime($expire);
            $maxAge = $expiryDate->getTimestamp() - $now->getTimestamp();
            ;
        }

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $maxAge, 10); // Instantiate with key, algo, maxAge and leeway.

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document(['jwt' => $jwt->encode([
                'resourceType' => $token->getAttribute('resourceType'),
                'resourceId' => $token->getAttribute('resourceId'),
                'resourceInternalId' => $token->getAttribute('resourceInternalId'),
                'tokenId' => $token->getId(),
                'secret' => $token->getAttribute('secret')
            ])]), Response::MODEL_JWT);
    }
}
