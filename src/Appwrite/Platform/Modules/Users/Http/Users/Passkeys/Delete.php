<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Passkeys;

use Appwrite\Auth\Passkey\Ceremony;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteUserPasskey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/users/:userId/passkeys/:passkeyId')
            ->desc('Delete user passkey')
            ->groups(['api', 'users'])
            ->label('scope', 'users.write')
            ->label('audits.event', 'passkey.delete')
            ->label('audits.resource', 'user/{request.userId}')
            ->label('audits.userId', '{request.userId}')
            ->label('usage.metric', 'users.{scope}.requests.update')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'passkeys',
                name: 'deletePasskey',
                description: <<<EOT
                Delete a passkey from a user, for example when a device is lost. The passkey can no longer be used to sign in.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'User ID.', false, ['dbForProject'])
            ->param('passkeyId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Passkey ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        string $passkeyId,
        Response $response,
        Database $dbForProject,
    ): void {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $passkey = $dbForProject->getDocument('authenticators', $passkeyId);

        if (
            $passkey->isEmpty()
            || $passkey->getAttribute('type') !== Ceremony::TYPE
            || $passkey->getAttribute('userInternalId') !== $user->getSequence()
        ) {
            throw new Exception(Exception::USER_PASSKEY_NOT_FOUND);
        }

        $dbForProject->deleteDocument('authenticators', $passkeyId);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $response->noContent();
    }
}
