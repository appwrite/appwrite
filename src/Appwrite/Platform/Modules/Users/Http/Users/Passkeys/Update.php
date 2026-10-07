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
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateUserPasskey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/users/:userId/passkeys/:passkeyId')
            ->desc('Update user passkey')
            ->groups(['api', 'users'])
            ->label('scope', 'users.write')
            ->label('audits.event', 'passkey.update')
            ->label('audits.resource', 'user/{request.userId}')
            ->label('audits.userId', '{request.userId}')
            ->label('usage.metric', 'users.{scope}.requests.update')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'passkeys',
                name: 'updatePasskey',
                description: <<<EOT
                Rename a passkey of a user.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PASSKEY,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('passkeyId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Passkey ID.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Passkey name. Max length: 128 chars.')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        string $passkeyId,
        string $name,
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
            || !$passkey->getAttribute('verified')
        ) {
            throw new Exception(Exception::USER_PASSKEY_NOT_FOUND);
        }

        $passkey = $dbForProject->updateDocument('authenticators', $passkeyId, new Document([
            'name' => $name,
        ]));

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $response->dynamic($passkey, Response::MODEL_PASSKEY);
    }
}
