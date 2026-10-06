<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Prefs;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getUserPrefs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/users/:userId/prefs')
            ->desc('Get user preferences')
            ->groups(['api', 'users'])
            ->label('scope', 'users.read')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'getPrefs',
                description: '/docs/references/users/get-user-prefs.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PREFERENCES,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $userId, Response $response, Database $dbForProject): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $prefs = $user->getAttribute('prefs', []);

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    }
}
