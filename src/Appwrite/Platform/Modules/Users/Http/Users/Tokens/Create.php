<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Tokens;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Token;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createUserToken';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/users/:userId/tokens')
            ->desc('Create token')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].tokens.[tokenId].create')
            ->label('scope', 'users.write')
            ->label('audits.event', 'tokens.create')
            ->label('audits.resource', 'user/{request.userId}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'sessions',
                name: 'createToken',
                description: '/docs/references/users/create-token.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TOKEN,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('length', 6, new Range(4, 128), 'Token length in characters. The default length is 6 characters', true)
            ->param('expire', TOKEN_EXPIRATION_GENERIC, new Range(60, TOKEN_EXPIRATION_LOGIN_LONG), 'Token expiration period in seconds. The default expiration is 15 minutes.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $userId, int $length, int $expire, Request $request, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $proofForToken = new Token($length);
        $proofForToken->setHash(new Sha());
        $secret = $proofForToken->generate();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $expire));

        $token = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => TOKEN_TYPE_GENERIC,
            'secret' => $proofForToken->hash($secret),
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP()
        ]);

        $token = $dbForProject->createDocument('tokens', $token);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $token->setAttribute('secret', $secret);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $token->getId())
            ->setPayload($response->output($token, Response::MODEL_TOKEN));

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($token, Response::MODEL_TOKEN);
    }
}
