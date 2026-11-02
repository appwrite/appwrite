<?php

namespace Appwrite\Platform\Modules\Account\Http\Account;

use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordStrength;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\AllOf;
use Utopia\Validator\Text;
use Utopia\Validator\Validator;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'create';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account')
            ->desc('Create account')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'sessions.write')
            ->label('auth.type', 'email-password')
            ->label('audits.event', 'user.create')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'create',
                description: '/docs/references/account/create.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->param('userId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('email', '', new EmailValidator(), 'User email.')
            ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordFormat(new AllOf([new PasswordStrength($project->getAttribute('auths', [])['passwordStrength'] ?? []), new PasswordDictionary($passwordsDictionary, enabled: $project->getAttribute('auths', [])['passwordDictionary'] ?? false, denyList: $project->getAttribute('auths', [])['passwordDictionaryDenyList'] ?? [])], Validator::TYPE_STRING)), 'New user password. Must be between 8 and 256 chars.', false, ['project', 'passwordsDictionary'])
            ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('hooks')
            ->inject('plan')
            ->inject('pwnedPasswords')
            ->inject('proofForPassword');
    }
}
