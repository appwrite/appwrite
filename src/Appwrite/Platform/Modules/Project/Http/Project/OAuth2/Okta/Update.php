<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Okta;

use Appwrite\Auth\OAuth2\Okta;
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
use Utopia\Validator\Domain as ValidatorDomain;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'okta';
    }

    public static function getProviderClass(): string
    {
        return Okta::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Okta';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Okta';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_OKTA;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '0oa00000000000000698';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'Kiq0000000000000000000000000000000000000-00000000000H2L5-3SJ-vRV';
    }

    public static function getParameters(): array
    {
        return \array_merge(parent::getParameters(), [
            [
                '$id' => 'domain',
                'name' => 'Domain',
                'example' => 'trial-6400025.okta.com',
                'hint' => 'Example of wrong value: trial-6400025-admin.okta.com, or https://trial-6400025.okta.com/',
            ],
            [
                '$id' => 'authorizationServerId',
                'name' => 'Authorization Server ID',
                'example' => 'aus000000000000000h7z',
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
            ->param('domain', null, new Nullable(new ValidatorDomain(allowEmpty: true)), 'Okta company domain. Required when enabling the provider. For example: trial-6400025.okta.com. Example of wrong value: trial-6400025-admin.okta.com, or https://trial-6400025.okta.com/', optional: true)
            ->param('authorizationServerId', null, new Nullable(new Text(256, 0)), 'Custom Authorization Servers. Optional, can be left empty or unconfigured. For example: aus000000000000000h7z', optional: true)
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
            'domain' => $decoded['oktaDomain'] ?? '',
            'authorizationServerId' => $decoded['authorizationServerId'] ?? '',
        ]);
    }

    /**
     * Custom callback used instead of the parent's `action()` because Okta
     * takes additional optional `domain` and `authorizationServerId` parameters.
     * The method is named differently to avoid an LSP-incompatible override of
     * Base::action().
     */
    public function handle(
        ?string $clientId,
        ?string $clientSecret,
        ?string $domain,
        ?string $authorizationServerId,
        ?bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        QueueEvent $queueForEvents
    ): void {
        $providerId = static::getProviderId();
        $queueForEvents->setParam('providerId', $providerId);

        // The secret is stored as JSON `{"clientSecret": "...", "oktaDomain": "...", "authorizationServerId": "..."}`
        // to match the shape Okta's OAuth2 adapter expects.
        // Merge new values with existing storage so that submitting only some of
        // the parameters leaves the others untouched.
        $storedRaw = $project->getAttribute('oAuthProviders', [])[$providerId . 'Secret'] ?? '';
        $existing = [];
        if (!empty($storedRaw)) {
            $existing = \json_decode($storedRaw, true) ?: [];
        }

        $encodedSecret = null;
        if (!\is_null($clientSecret) || !\is_null($domain) || !\is_null($authorizationServerId)) {
            $encodedSecret = \json_encode([
                'clientSecret' => $clientSecret ?? ($existing['clientSecret'] ?? ''),
                'oktaDomain' => $domain ?? ($existing['oktaDomain'] ?? ''),
                'authorizationServerId' => $authorizationServerId ?? ($existing['authorizationServerId'] ?? ''),
            ]);
        }

        // Domain is required when enabling the provider, since Okta builds its
        // authorization, token and userinfo URLs from it.
        if ($enabled === true) {
            $effectiveDomain = $domain ?? ($existing['oktaDomain'] ?? '');
            if (empty($effectiveDomain)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Domain is required when enabling Okta OAuth2 provider.');
            }
        }

        $project = $this->persistCredentials($project, $dbForPlatform, $authorization, $clientId, $encodedSecret, $enabled);

        // Reuse buildReadResponse to keep PATCH/GET shapes identical and
        // guarantee the clientSecret is write-only on every response path.
        $response->dynamic($this->buildReadResponse($project), static::getResponseModel());
    }
}
