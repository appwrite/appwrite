<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\SHA;

use Appwrite\Auth\Validator\Password;
use Appwrite\Hooks\Hooks;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Users\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Auth\Hashes\Sha;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createSHAUser';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/users/sha')
            ->desc('Create user with SHA password')
            ->groups(['api', 'users'])
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.create')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'createSHAUser',
                description: '/docs/references/users/create-sha-user.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_USER,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('email', '', new EmailValidator(), 'User email.')
            ->param('password', '', new Password(), 'User password hashed using SHA.')
            ->param('passwordVersion', '', new WhiteList(['sha1', 'sha224', 'sha256', 'sha384', 'sha512/224', 'sha512/256', 'sha512', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512']), "Optional SHA version used to hash password. Allowed values are: 'sha1', 'sha224', 'sha256', 'sha384', 'sha512/224', 'sha512/256', 'sha512', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512'", true, enum: new Enum(name: 'PasswordHash'))
            ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('hooks')
            ->inject('plan')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $email, string $password, string $passwordVersion, ?string $name, Response $response, Document $project, Database $dbForProject, Hooks $hooks, array $plan): void
    {
        $sha = new Sha();
        if (!empty($passwordVersion)) {
            $sha->setVersion($passwordVersion);
        }

        $user = $this->createUser($sha, $userId, $email, $password, null, $name, $project, $dbForProject, $hooks, $plan);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    }
}
