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
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listUserPasskeys';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/users/:userId/passkeys')
            ->desc('List user passkeys')
            ->groups(['api', 'users'])
            ->label('scope', 'users.read')
            ->label('usage.metric', 'users.{scope}.requests.read')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'passkeys',
                name: 'listPasskeys',
                description: <<<EOT
                Get the list of verified passkeys registered by a user.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PASSKEY_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        Response $response,
        Database $dbForProject,
    ): void {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $passkeys = $dbForProject->find('authenticators', [
            Query::equal('userInternalId', [$user->getSequence()]),
            Query::equal('type', [Ceremony::TYPE]),
            Query::equal('verified', [true]),
            Query::orderDesc('$createdAt'),
            Query::limit(APP_LIMIT_USER_PASSKEYS),
        ]);

        $response->dynamic(new Document([
            'passkeys' => $passkeys,
            'total' => \count($passkeys),
        ]), Response::MODEL_PASSKEY_LIST);
    }
}
