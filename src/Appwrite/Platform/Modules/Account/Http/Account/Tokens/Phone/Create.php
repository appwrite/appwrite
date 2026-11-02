<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Tokens\Phone;

use Appwrite\Auth\Validator\Phone;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createPhoneToken';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/tokens/phone')
            ->httpAlias('/v1/account/sessions/phone')
            ->desc('Create phone token')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'sessions.write')
            ->label('auth.type', 'phone')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'tokens',
                name: 'createPhoneToken',
                description: '/docs/references/account/create-token-phone.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TOKEN,
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', ['url:{url},phone:{param-phone}', 'url:{url},ip:{ip}'])
            ->param('userId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars. If the phone number has never been used, a new account is created using the provided userId. Otherwise, if the phone number is already attached to an account, the user ID is ignored.', false, ['dbForProject'])
            ->param('phone', '', new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.')
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('publisherForMessaging')
            ->inject('locale')
            ->inject('usage')
            ->inject('plan')
            ->inject('store')
            ->inject('proofForCode')
            ->inject('authorization')
            ->inject('apiKey');
    }
}
