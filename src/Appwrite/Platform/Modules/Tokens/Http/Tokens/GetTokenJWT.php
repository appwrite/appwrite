<?php

namespace Appwrite\Platform\Modules\Tokens\Http\Tokens;

use Ahc\Jwt\JWT;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class GetTokenJWT extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getTokenJWT';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
        ->setHttpPath('/v1/storage/buckets/:bucketId/files/:fileId/tokens/:tokenId/jwt')
        ->desc('Get file token jwt')
        ->groups(['api', 'storage'])
        ->label('scope', 'files.read')
        ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
        ->label('usage.metric', 'fileTokens.{scope}.requests.read')
        ->label('usage.params', ['bucketId:{request.bucketId}','fileId:{request.fileId}'])
        ->label('sdk.namespace', 'storage')
        ->label('sdk.method', 'getFileTokenJWT')
        ->label('sdk.description', '/docs/references/storage/get-file-token-jwt.md')
        ->label('sdk.response.code', Response::STATUS_CODE_OK)
        ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
        ->label('sdk.response.model', Response::MODEL_JWT)
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
