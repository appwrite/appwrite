<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\PHPass;

use Appwrite\Auth\Validator\Password;
use Appwrite\Hooks\Hooks;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Users\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Auth\Hashes\PHPass;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Create extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createPHPassUser';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/users/phpass')
            ->desc('Create user with PHPass password')
            ->groups(['api', 'users'])
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.create')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'createPHPassUser',
                description: '/docs/references/users/create-phpass-user.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_USER,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'User ID. Choose a custom ID or pass the string `ID.unique()`to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('email', '', new EmailValidator(), 'User email.')
            ->param('password', '', new Password(), 'User password hashed using PHPass.')
            ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('hooks')
            ->inject('plan')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $email, string $password, ?string $name, Response $response, Document $project, Database $dbForProject, Hooks $hooks, array $plan): void
    {
        $phpass = new PHPass();

        $user = $this->createUser($phpass, $userId, $email, $password, null, $name, $project, $dbForProject, $hooks, $plan);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    }
}
