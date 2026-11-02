<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2;

use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Account\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Client\Client;
use Utopia\Config\Config;
use Utopia\Database\Document;
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
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $provider,
        string $success,
        string $failure,
        array $scopes,
        string $state,
        Request $request,
        Response $response,
        Document $project,
        array $platform,
        Client $clientForOAuth2,
        User $user,
    ): void {
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $port = $request->getPort();
        $callbackBase = $protocol . '://' . $request->getHostname();
        if ($protocol === 'https' && $port !== '443') {
            $callbackBase .= ':' . $port;
        } elseif ($protocol === 'http' && $port !== '80') {
            $callbackBase .= ':' . $port;
        }

        $callback = $callbackBase . '/v1/account/sessions/oauth2/callback/' . $provider . '/' . $project->getId();
        $providerEnabled = $project->getAttribute('oAuthProviders', [])[$provider . 'Enabled'] ?? false;

        if (!$providerEnabled) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please enable the provider from your ' . APP_NAME . ' console to continue.');
        }

        $appId = $project->getAttribute('oAuthProviders', [])[$provider . 'Appid'] ?? '';
        $appSecret = $project->getAttribute('oAuthProviders', [])[$provider . 'Secret'] ?? '{}';

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = System::getEnv('_APP_OPENSSL_KEY_V' . $appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        if (empty($appId) || empty($appSecret)) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please configure the provider app ID and app secret key from your ' . APP_NAME . ' console to continue.');
        }

        $oAuthProviders = Config::getParam('oAuthProviders') ?? [];
        $className = $oAuthProviders[$provider]['class'] ?? null;
        if ($className === null || !\class_exists($className)) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        $redirectBase = $platform['consoleUrl'] ?? '';
        // Only the derived console URL follows the request port; an explicit one carries its own
        if (empty(System::getEnv('_APP_CONSOLE_URL')) && $port !== ($protocol === 'https' ? '443' : '80')) {
            $redirectBase .= ':' . $port;
        }

        if (empty($success)) {
            $success = $redirectBase . self::OAUTH_DEFAULT_SUCCESS;
        }

        if (empty($failure)) {
            $failure = $redirectBase . self::OAUTH_DEFAULT_FAILURE;
        }

        // The provider echoes the state back untouched, so on its own it proves nothing about who
        // started the flow. The nonce also goes into a cookie only this browser holds, and the
        // callback accepts the state only when the two match. Host-only, because the callback
        // lands on this host; an hour covers signing in at the provider. A browser can have
        // several flows open at once (two tabs, the session and the token flow), so the cookie
        // keeps the newest few nonces rather than only the last one.
        $nonce = \bin2hex(\random_bytes(16));
        $nonces = \array_filter(\explode(',', $request->getCookie('a_oauth2_' . $project->getId())), fn (string $held) => \strlen($held) === 32 && \ctype_xdigit($held));
        $nonces = \array_slice([$nonce, ...$nonces], 0, 5);

        $oauth2 = new $className($clientForOAuth2, $appId, $appSecret, $callback, [
            'success' => $success,
            'failure' => $failure,
            'token' => false,
            'nonce' => $nonce,
            'binding' => \hash_hmac('sha256', $nonce . ':' . $user->getId(), System::getEnv('_APP_OPENSSL_KEY_V1', '')),
            'state' => $state,
        ], $scopes);

        $response
            ->addCookie('a_oauth2_' . $project->getId(), \implode(',', $nonces), \time() + 3600, '/', null, ('https' === $protocol), true, Response::COOKIE_SAMESITE_LAX)
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($oauth2->getLoginURL());
    }
}
