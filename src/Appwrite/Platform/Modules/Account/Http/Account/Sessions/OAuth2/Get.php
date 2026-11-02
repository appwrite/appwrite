<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2;

use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Account\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createOAuth2Session';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/sessions/oauth2/:provider')
            ->desc('Create OAuth2 session')
            ->groups(['api', 'account'])
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->label('scope', 'sessions.write')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'createOAuth2Session',
                description: '/docs/references/account/create-session-oauth2.md',
                type: MethodType::WEBAUTH,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_MOVED_PERMANENTLY,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::HTML,
                // Preview SDK builds show the whole surface, so they do not hide.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') === 'enabled' ? false : [APP_SDK_PLATFORM_SERVER],
            ))
            ->label('abuse-limit', 50)
            ->label('abuse-key', 'ip:{ip}')
            ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'OAuth2 Provider. Currently, supported providers are: ' . \implode(', ', \array_keys(\array_filter(Config::getParam('oAuthProviders'), fn ($node) => (!$node['mock'])))) . '.', enum: new Enum(name: 'OAuthProvider', exclude: ['mock', 'mock-unverified', 'mock-no-email']))
            ->param('success', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to your app after a successful login attempt.  Only URLs from hostnames in your project\'s platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['redirectValidator'])
            ->param('failure', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to your app after a failed login attempt.  Only URLs from hostnames in your project\'s platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['redirectValidator'])
            ->param('scopes', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'A list of custom OAuth2 scopes. Check each provider internal docs for a list of supported scopes. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->param('state', '', new Text(256, 0, \array_map(\chr(...), \range(0x20, 0x7E))), 'An opaque value your app generates and keeps, for example in a cookie. It is returned unchanged as the `state` query parameter on the success and failure URLs, so your app can check that the sign-in it receives is one it started. Printable ASCII only (RFC 6749 Appendix A.5). Max length: 256 chars.', true)
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->inject('clientForOAuth2')
            ->inject('user');
    }
}
