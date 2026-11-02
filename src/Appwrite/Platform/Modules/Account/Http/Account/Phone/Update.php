<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Phone;

use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePhone';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/phone')
            ->desc('Update phone')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.phone')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'updatePhone',
                description: '/docs/references/account/update-phone.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('phone', '', new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.')
            ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('hooks')
            ->inject('proofForPassword')
            ->inject('authorization')
            ->inject('passwordsDictionary')
            ->inject('pwnedPasswords');
    }
}
