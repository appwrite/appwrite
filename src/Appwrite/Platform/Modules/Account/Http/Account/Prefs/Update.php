<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Prefs;

use Appwrite\Event\Event;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Assoc;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePrefs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/prefs')
            ->desc('Update preferences')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.prefs')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'updatePrefs',
                description: '/docs/references/account/update-prefs.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('prefs', [], new Assoc(), 'Prefs key-value JSON object.', example: '{"language":"en","timezone":"UTC","darkTheme":true}')
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        array $prefs,
        ?\DateTime $requestTimestamp,
        Response $response,
        Document $user,
        Database $dbForProject,
        Event $queueForEvents,
    ): void {
        $user->setAttribute('prefs', $prefs);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    }
}
