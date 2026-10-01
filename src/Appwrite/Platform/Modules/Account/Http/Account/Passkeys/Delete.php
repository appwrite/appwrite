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

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deletePasskey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/account/passkeys/:passkeyId')
            ->desc('Delete passkey')
            ->groups(['api', 'account', 'recentSession'])
            ->label('scope', 'account')
            ->label('audits.event', 'passkey.delete')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'passkeys',
                name: 'deletePasskey',
                description: <<<EOT
                Delete a passkey from the currently logged in user. The passkey can no longer be used to sign in, although it may remain stored on the user's device. The session must have signed in or completed an MFA challenge within the last 10 minutes.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('passkeyId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Passkey ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $passkeyId,
        Response $response,
        Document $user,
        Database $dbForProject,
    ): void {
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
