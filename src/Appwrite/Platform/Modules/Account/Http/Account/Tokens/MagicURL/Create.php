<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Tokens\MagicURL;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createMagicURLToken';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/tokens/magic-url')
            ->httpAlias('/v1/account/sessions/magic-url')
            ->desc('Create magic URL token')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'sessions.write')
            ->label('auth.type', 'magic-url')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'tokens',
                name: 'createMagicURLToken',
                description: '/docs/references/account/create-token-magic-url.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TOKEN,
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->label('abuse-limit', 60)
            ->label('abuse-key', ['url:{url},email:{param-email}', 'url:{url},ip:{ip}'])
            ->param('userId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars. If the email address has never been used, a new account is created using the provided userId. Otherwise, if the email address is already attached to an account, the user ID is ignored.', false, ['dbForProject'])
            ->param('email', '', new EmailValidator(), 'User email.')
            ->param('url', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect the user back to your app from the magic URL login. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['redirectValidator'])
            ->param('phrase', false, new Boolean(), 'Toggle for security phrase. If enabled, email will be send with a randomly generated phrase and the phrase will also be included in the response. Confirming phrases match increases the security of your authentication flow.', true)
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('queueForEvents')
            ->inject('publisherForMails')
            ->inject('plan')
            ->inject('proofForPassword')
            ->inject('platform')
            ->inject('authorization')
            ->inject('apiKey');
    }
}
