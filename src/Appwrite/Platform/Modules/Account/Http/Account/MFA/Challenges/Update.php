<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\MFA\Challenges;

use Appwrite\Auth\MFA\Challenge;
use Appwrite\Auth\MFA\Type;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateMFAChallenge';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/mfa/challenges')
            ->httpAlias('/v1/account/mfa/challenge')
            ->desc('Update MFA challenge (confirmation)')
            ->groups(['api', 'account', 'mfa'])
            ->label('scope', 'account')
            ->label('event', 'users.[userId].sessions.[sessionId].create')
            ->label('audits.event', 'challenges.update')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', [
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'updateMfaChallenge',
                    description: '/docs/references/account/update-mfa-challenge.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_SESSION,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'account.updateMFAChallenge',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'updateMFAChallenge',
                    description: '/docs/references/account/update-mfa-challenge.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_SESSION,
                        )
                    ],
                    contentType: ContentType::JSON
                )
            ])
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},challengeId:{param-challengeId}')
            ->param('challengeId', '', new Text(256), 'ID of the challenge.')
            ->param('otp', '', new Text(256), 'Valid verification token.')
            ->inject('project')
            ->inject('response')
            ->inject('user')
            ->inject('session')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $challengeId,
        string $otp,
        Document $project,
        Response $response,
        Document $user,
        Document $session,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $challenge = $dbForProject->getDocument('challenges', $challengeId);

        if ($challenge->isEmpty()) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $type = $challenge->getAttribute('type');

        $recoveryCodeChallenge = function (Document $challenge, Document $user, string $otp) use ($dbForProject) {
            if (
                $challenge->isSet('type') &&
                $challenge->getAttribute('type') === Type::RECOVERY_CODE
            ) {
                $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);
                if (\in_array($otp, $mfaRecoveryCodes)) {
                    $mfaRecoveryCodes = \array_diff($mfaRecoveryCodes, [$otp]);
                    $mfaRecoveryCodes = \array_values($mfaRecoveryCodes);
                    $user->setAttribute('mfaRecoveryCodes', $mfaRecoveryCodes);
                    $dbForProject->updateDocument('users', $user->getId(), $user);

                    return true;
                }

                return false;
            }

            return false;
        };

        $success = (match ($type) {
            Type::TOTP => Challenge\TOTP::challenge($challenge, $user, $otp),
            Type::PHONE => Challenge\Phone::challenge($challenge, $user, $otp),
            Type::EMAIL => Challenge\Email::challenge($challenge, $user, $otp),
            Type::RECOVERY_CODE => $recoveryCodeChallenge($challenge, $user, $otp),
            default => false
        });

        if (!$success) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $dbForProject->deleteDocument('challenges', $challengeId);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $factors = $session->getAttribute('factors', []);
        $factors[] = $type;
        $factors = \array_values(\array_unique($factors));

        $session
            ->setAttribute('factors', $factors)
            ->setAttribute('mfaUpdatedAt', DateTime::now());

        $dbForProject->updateDocument('sessions', $session->getId(), $session);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId());

        $response->dynamic($session, Response::MODEL_SESSION);
    }
}
