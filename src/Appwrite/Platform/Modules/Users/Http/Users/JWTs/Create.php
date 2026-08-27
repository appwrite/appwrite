<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\JWTs;

use Ahc\Jwt\JWT;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\KeywordId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Range;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createUserJWT';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/users/:userId/jwts')
            ->desc('Create user JWT')
            ->groups(['api', 'users'])
            ->label('scope', 'users.write')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'sessions',
                name: 'createJWT',
                description: '/docs/references/users/create-user-jwt.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_JWT,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('sessionId', 'recent()', fn (Database $dbForProject) => new KeywordId('recent()', $dbForProject->getAdapter()->getMaxUIDLength()), 'Session ID. Use the string \'recent()\' to use the most recent session, which is also the default.', true, ['dbForProject'], example: 'recent()')
            ->param('duration', 900, new Range(0, 3600), 'Time in seconds before JWT expires. Default duration is 900 seconds, and maximum is 3600 seconds.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $sessionId, int $duration, Response $response, Database $dbForProject): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $sessions = $user->getAttribute('sessions', []);
        $session = new Document();

        if ($sessionId === 'recent()') {
            // Get most recent
            $session = \count($sessions) > 0 ? $sessions[\count($sessions) - 1] : new Document();
        } else {
            // Find by ID
            foreach ($sessions as $loopSession) {
                /** @var Document $loopSession */
                if ($loopSession->getId() == $sessionId) {
                    $session = $loopSession;
                    break;
                }
            }
        }

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $duration, 0);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document(['jwt' => $jwt->encode([
                'userId' => $user->getId(),
                'sessionId' => $session->isEmpty() ? '' : $session->getId()
            ])]), Response::MODEL_JWT);
    }
}
