<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Verifications\Phone;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Code as ProofsCode;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePhoneVerification';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/verifications/phone')
            ->httpAlias('/v1/account/verification/phone')
            ->desc('Update phone verification (confirmation)')
            ->groups(['api', 'account'])
            ->label('scope', 'public')
            ->label('event', 'users.[userId].verification.[tokenId].update')
            ->label('audits.event', 'verification.update')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'verification',
                name: 'updatePhoneVerification',
                description: '/docs/references/account/update-phone-verification.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TOKEN,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'userId:{param-userId}')
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'User ID.', false, ['dbForProject'])
            ->param('secret', '', new Text(256), 'Valid verification token.')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('proofForCode')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        string $secret,
        Response $response,
        User $user,
        Database $dbForProject,
        Event $queueForEvents,
        ProofsCode $proofForCode,
        Authorization $authorization,
    ): void {
        /** @var User  $profile */
        $profile = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $verifiedToken = $profile->tokenVerify(TOKEN_TYPE_PHONE, $secret, $proofForCode);

        if (!$verifiedToken) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $authorization->addRole(Role::user($profile->getId())->toString());

        $verificationDocument = $dbForProject->getDocument('tokens', $verifiedToken->getId());

        // Claim the token under the user row lock a phone change takes, which deletes tokens sent to the old number
        $profile = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $profile, $verifiedToken) {
            $authorization->skip(fn () => $dbForProject->getDocument('users', $profile->getId(), forUpdate: true));

            if (!$dbForProject->deleteDocument('tokens', $verifiedToken->getId())) {
                throw new Exception(Exception::USER_INVALID_TOKEN);
            }

            return $dbForProject->updateDocument('users', $profile->getId(), new Document(['phoneVerification' => true]));
        });

        $user->setAttributes($profile->getArrayCopy());
        $dbForProject->purgeCachedDocument('users', $profile->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verificationDocument->getId())
        ;

        $response->dynamic($verificationDocument, Response::MODEL_TOKEN);
    }
}
