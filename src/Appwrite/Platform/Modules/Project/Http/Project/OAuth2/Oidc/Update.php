<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Oidc;

use Appwrite\Auth\OAuth2\Oidc;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\URL;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'oidc';
    }

    public static function getProviderClass(): string
    {
        return Oidc::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Oidc';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Oidc';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_OIDC;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'qibI2x0000000000000000000000000006L2YFoG';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'Ah68ed000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000003qpcHV';
    }

    public static function getParameters(): array
    {
        return \array_merge(parent::getParameters(), [
            [
                '$id' => 'wellKnownURL',
                'name' => 'Well-known URL',
                'example' => 'https://myoauth.com/.well-known/openid-configuration',
                'hint' => '',
            ],
            [
                '$id' => 'authorizationURL',
                'name' => 'Authorization URL',
                'example' => 'https://myoauth.com/oauth2/authorize',
                'hint' => '',
            ],
            [
                '$id' => 'tokenURL',
                'name' => 'Token URL',
                'example' => 'https://myoauth.com/oauth2/token',
                'hint' => '',
            ],
            [
                '$id' => 'userInfoURL',
                'name' => 'User Info URL',
                'example' => 'https://myoauth.com/oauth2/userinfo',
                'hint' => '',
            ],
        ]);
    }

    public function __construct()
    {
        $providerId = static::getProviderId();
        $providerLabel = static::getProviderLabel();

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/oauth2/' . $providerId)
            ->desc('Update project OAuth2 ' . $providerLabel)
            ->groups(['api', 'project'])
            ->label('scope', 'oauth2.write')
            ->label('event', 'oauth2.[providerId].update')
            ->label('audits.event', 'project.oauth2.[providerId].update')
            ->label('audits.resource', 'project.oauth2/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'oauth2',
                name: static::getProviderSDKMethod(),
                description: 'Update the project OAuth2 ' . $providerLabel . ' configuration.',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: static::getResponseModel(),
                    )
                ],
            ))
            ->param(static::getClientIdParamName(), null, new Nullable(new Text(256, 0)), static::getClientIdDescription(), optional: true)
            ->param(static::getClientSecretParamName(), null, new Nullable(new Text(512, 0)), static::getClientSecretDescription(), optional: true)
            ->param('wellKnownURL', null, new Nullable(new URL(allowEmpty: true)), 'OpenID Connect well-known configuration URL. When provided, authorization, token, and user info endpoints can be discovered automatically. For example: https://myoauth.com/.well-known/openid-configuration', optional: true)
            ->param('authorizationURL', null, new Nullable(new URL(allowEmpty: true)), 'OpenID Connect authorization endpoint URL. Required when wellKnownURL is not provided. For example: https://myoauth.com/oauth2/authorize', optional: true)
            ->param('tokenURL', null, new Nullable(new URL(allowEmpty: true)), 'OpenID Connect token endpoint URL. Required when wellKnownURL is not provided. For example: https://myoauth.com/oauth2/token', optional: true, aliases: ['tokenUrl'])
            ->param('userInfoURL', null, new Nullable(new URL(allowEmpty: true)), 'OpenID Connect user info endpoint URL. Required when wellKnownURL is not provided. For example: https://myoauth.com/oauth2/userinfo', optional: true, aliases: ['userInfoUrl'])
            ->param('enabled', null, new Nullable(new Boolean()), 'OAuth2 sign-in method status. Set to true to enable new session creation. Setting to true will trigger end-to-end credentials validation, and will throw if the credentials are invalid.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->handle(...));
    }

    public function buildReadResponse(Document $project): Document
    {
        $providerId = static::getProviderId();
        $oAuthProviders = $project->getAttribute('oAuthProviders', []);
        $decoded = $this->decodeStoredSecret($project);

        return new Document([
            '$id' => $providerId,
            'enabled' => $oAuthProviders[$providerId . 'Enabled'] ?? false,
            static::getClientIdParamName() => $oAuthProviders[$providerId . 'Appid'] ?? '',
            static::getClientSecretParamName() => '',
            'wellKnownURL' => $decoded['wellKnownEndpoint'] ?? '',
            'authorizationURL' => $decoded['authorizationEndpoint'] ?? '',
            'tokenURL' => $decoded['tokenEndpoint'] ?? '',
            'userInfoURL' => $decoded['userInfoEndpoint'] ?? '',
        ]);
    }

    /**
     * Custom callback used instead of the parent's `action()` because OIDC takes
     * a well-known URL plus three discovery URLs (authorization, token, user
     * info), all stored together with the client secret as JSON. The method is
     * named differently to avoid an LSP-incompatible override of Base::action().
     *
     * Enabling the provider requires either a non-empty `wellKnownEndpoint`,
     * or all three of `authorizationEndpoint`, `tokenEndpoint`, and
     * `userInfoEndpoint` to be set. The check considers the merged state of
     * existing stored values plus the new values from the request, so callers
     * can enable the provider in a single request without re-sending fields
     * that were configured previously.
     */
    public function handle(
        ?string $clientId,
        ?string $clientSecret,
        ?string $wellKnownURL,
        ?string $authorizationURL,
        ?string $tokenURL,
        ?string $userInfoURL,
        ?bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        QueueEvent $queueForEvents
    ): void {
        $providerId = static::getProviderId();
        $queueForEvents->setParam('providerId', $providerId);

        // The secret is stored as JSON
        // `{"clientSecret": "...", "wellKnownEndpoint": "...", "authorizationEndpoint": "...", "tokenEndpoint": "...", "userInfoEndpoint": "..."}`
        // so that the OIDC OAuth2 adapter can extract each endpoint individually.
        // Merge new values with what's already stored so that submitting only a
        // subset of fields leaves the others untouched.
        $storedRaw = $project->getAttribute('oAuthProviders', [])[$providerId . 'Secret'] ?? '';
        $existing = [];
        if (!empty($storedRaw)) {
            $existing = \json_decode($storedRaw, true) ?: [];
        }

        $merged = [
            'clientSecret' => $clientSecret ?? ($existing['clientSecret'] ?? ''),
            'wellKnownEndpoint' => $wellKnownURL ?? ($existing['wellKnownEndpoint'] ?? ''),
            'authorizationEndpoint' => $authorizationURL ?? ($existing['authorizationEndpoint'] ?? ''),
            'tokenEndpoint' => $tokenURL ?? ($existing['tokenEndpoint'] ?? ''),
            'userInfoEndpoint' => $userInfoURL ?? ($existing['userInfoEndpoint'] ?? ''),
        ];

        // When enabling, require either wellKnownEndpoint alone, or all three
        // discovery URLs (authorization, token, user info). Skip this check
        // when disabling or when leaving the enabled flag unchanged.
        if ($enabled === true) {
            $hasWellKnown = !empty($merged['wellKnownEndpoint']);
            $hasAllDiscovery = !empty($merged['authorizationEndpoint'])
                && !empty($merged['tokenEndpoint'])
                && !empty($merged['userInfoEndpoint']);

            if (!$hasWellKnown && !$hasAllDiscovery) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Enabling OpenID Connect requires either wellKnownURL, or all of authorizationURL, tokenURL, and userInfoURL.');
            }
        }

        $encodedSecret = \json_encode($merged);

        $project = $this->persistCredentials($project, $dbForPlatform, $authorization, $clientId, $encodedSecret, $enabled);

        // Reuse buildReadResponse to keep PATCH/GET shapes identical and
        // guarantee the clientSecret is write-only on every response path.
        $response->dynamic($this->buildReadResponse($project), static::getResponseModel());
    }
}
