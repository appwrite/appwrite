<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Passkeys;

use Appwrite\Auth\Passkey\Ceremony;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Scope\HTTP;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listPasskeys';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/passkeys')
            ->desc('List passkeys')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'passkeys',
                name: 'listPasskeys',
                description: <<<EOT
                Get the list of verified passkeys registered by the currently logged in user.
                EOT,
                auth: [AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PASSKEY_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Document $user,
        Database $dbForProject,
    ): void {
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
