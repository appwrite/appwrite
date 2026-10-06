<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\MFA\Challenges;

use Appwrite\Auth\MFA\Type;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getUserMFAChallenge';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/users/:userId/mfa/challenges/:challengeId')
            ->desc('Get MFA challenge')
            ->groups(['api', 'users'])
            ->label('scope', 'users.read')
            ->label('usage.metric', 'users.{scope}.requests.read')
            ->label('sdk', [
                new Method(
                    namespace: 'users',
                    group: 'mfa',
                    name: 'getMFAChallenge',
                    description: 'Get a custom MFA challenge for a user, including the code to be delivered through your own channel.',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_MFA_CHALLENGE_SECRET,
                        ),
                    ]
                ),
            ])
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('challengeId', '', new Text(256), 'ID of the challenge.')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $challengeId, Response $response, Database $dbForProject): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $challenge = $dbForProject->getDocument('challenges', $challengeId);

        if (
            $challenge->isEmpty()
            || $challenge->getAttribute('userId') !== $userId
            || $challenge->getAttribute('type') !== Type::CUSTOM
            || $challenge->getAttribute('expire') < DateTime::formatTz(DateTime::now())
        ) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $response->dynamic($challenge, Response::MODEL_MFA_CHALLENGE_SECRET);
    }
}
