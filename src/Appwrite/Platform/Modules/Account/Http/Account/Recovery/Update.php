<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Recovery;

use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordStrength;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\AllOf;
use Utopia\Validator\Text;
use Utopia\Validator\Validator;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateRecovery';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/recovery')
            ->desc('Update password recovery (confirmation)')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('event', 'users.[userId].recovery.[tokenId].update')
            ->label('audits.event', 'recovery.update')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'recovery',
                name: 'updateRecovery',
                description: '/docs/references/account/update-recovery.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TOKEN,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},userId:{param-userId}')
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'User ID.', false, ['dbForProject'])
            ->param('secret', '', new Text(256), 'Valid reset token.')
            ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordFormat(new AllOf([new PasswordStrength($project->getAttribute('auths', [])['passwordStrength'] ?? []), new PasswordDictionary($passwordsDictionary, enabled: $project->getAttribute('auths', [])['passwordDictionary'] ?? false, denyList: $project->getAttribute('auths', [])['passwordDictionaryDenyList'] ?? [])], Validator::TYPE_STRING)), 'New user password. Must be between 8 and 256 chars.', false, ['project', 'passwordsDictionary'])
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('hooks')
            ->inject('proofForPassword')
            ->inject('proofForToken')
            ->inject('authorization')
            ->inject('pwnedPasswords')
            ->inject('mqtt')
            ->inject('queueForRealtime');
    }
}
