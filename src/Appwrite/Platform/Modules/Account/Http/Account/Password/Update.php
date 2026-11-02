<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Password;

use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordStrength;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\AllOf;
use Utopia\Validator\Text;
use Utopia\Validator\Validator;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePassword';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/password')
            ->desc('Update password')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.password')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'updatePassword',
                description: '/docs/references/account/update-password.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordFormat(new AllOf([new PasswordStrength($project->getAttribute('auths', [])['passwordStrength'] ?? []), new PasswordDictionary($passwordsDictionary, enabled: $project->getAttribute('auths', [])['passwordDictionary'] ?? false, denyList: $project->getAttribute('auths', [])['passwordDictionaryDenyList'] ?? [])], Validator::TYPE_STRING)), 'New user password. Must be at least 8 chars.', false, ['project', 'passwordsDictionary'])
            ->param('oldPassword', '', new PasswordFormat(new Text(256, 0)), 'Current user password. Max length: 256 chars.', true)
            ->inject('response')
            ->inject('user')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('hooks')
            ->inject('proofForPassword')
            ->inject('pwnedPasswords')
            ->inject('session')
            ->inject('mqtt');
    }
}
