<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Passkeys;

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
        return 'updatePasskey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/passkeys/:passkeyId')
            ->desc('Update passkey')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('audits.event', 'passkey.update')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'passkeys',
                name: 'updatePasskey',
                description: <<<EOT
                Rename a passkey of the currently logged in user.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PASSKEY,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('passkeyId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Passkey ID.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Passkey name. Max length: 128 chars.')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $passkeyId,
        string $name,
        Response $response,
        Document $user,
        Database $dbForProject,
    ): void {
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
            'data' => \array_merge($passkey->getAttribute('data', []), ['name' => $name]),
        ]));

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $response->dynamic($passkey, Response::MODEL_PASSKEY);
    }
}
