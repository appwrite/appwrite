<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\JWTs;

use Ahc\Jwt\JWT;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Auth\Store;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Range;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createJWT';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/jwts')
            ->httpAlias('/v1/account/jwt')
            ->desc('Create JWT')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'account')
            ->label('auth.type', 'jwt')
            ->label('impersonation', 'allow')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'tokens',
                name: 'createJWT',
                description: '/docs/references/account/create-jwt.md',
                auth: [AuthType::ADMIN, AuthType::SESSION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_JWT,
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->param('duration', 900, new Range(0, 3600), 'Time in seconds before JWT expires. Default duration is 900 seconds, and maximum is 3600 seconds.', true)
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('abuse-key', 'url:{url},userId:{userId}')
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('store')
            ->inject('proofForToken')
            ->inject('project')
            ->inject('console')
            ->inject('mode')
            ->callback($this->action(...));
    }

    public function action(
        int $duration,
        Request $request,
        Response $response,
        User $user,
        Store $store,
        ProofsToken $proofForToken,
        Document $project,
        Document $console,
        string $mode,
    ): void {
        if (!empty($request->getHeaderLine('x-appwrite-jwt', ''))) {
            throw new Exception(Exception::USER_JWT_CREATION_DENIED);
        }

        $sessionsInvalidatedAt = $mode === APP_MODE_ADMIN
            ? ($console->getAttribute('auths', [])['sessionsInvalidatedAt'] ?? null)
            : ($project->getAttribute('auths', [])['sessionsInvalidatedAt'] ?? null);

        $sessionId = $user->sessionVerify($store->getProperty('secret', ''), $proofForToken, $sessionsInvalidatedAt);

        if (!$sessionId) {
            throw new Exception(Exception::USER_SESSION_NOT_FOUND);
        }

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $duration, 0);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document([
                'jwt' => $jwt->encode([
                    // In admin mode the session is a console session, whatever project is being managed.
                    'projectId' => $mode === APP_MODE_ADMIN ? 'console' : $project->getId(),
                    'userId' => $user->getId(),
                    'sessionId' => $sessionId,
                ])
            ]), Response::MODEL_JWT);
    }
}
