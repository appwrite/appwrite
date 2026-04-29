<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Microsoft;

use Appwrite\Auth\OAuth2\Microsoft;
use Appwrite\Event\Event as QueueEvent;
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

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'microsoft';
    }

    public static function getProviderClass(): string
    {
        return Microsoft::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Microsoft';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Microsoft';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_MICROSOFT;
    }

    public static function getClientIdParamName(): string
    {
        return 'applicationId';
    }

    public static function getClientSecretParamName(): string
    {
        return 'applicationSecret';
    }

    public static function getClientIdName(): string
    {
        return 'Entra ID Application ID, also known as Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '00001111-aaaa-2222-bbbb-3333cccc4444';
    }

    public static function getClientSecretName(): string
    {
        return 'Entra ID Application Secret, also known as Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'A1bC2dE3fH4iJ5kL6mN7oP8qR9sT0u';
    }

    public static function getParameters(): array
    {
        return \array_merge(parent::getParameters(), [
            [
                '$id' => 'tenant',
                'name' => 'Tenant',
                'example' => 'common',
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
            ->param('tenant', '', new Text(256, 1), 'Microsoft Entra ID tenant identifier. Use \'common\', \'organizations\', \'consumers\' or a specific tenant ID. For example: common', optional: false)
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
            'tenant' => $decoded['tenantID'] ?? '',
        ]);
    }

    /**
     * Custom callback used instead of the parent's `action()` because Microsoft
     * takes an additional required `tenant` parameter. The method is named
     * differently to avoid an LSP-incompatible override of Base::action().
     */
    public function handle(
        ?string $applicationId,
        ?string $applicationSecret,
        string $tenant,
        ?bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        QueueEvent $queueForEvents
    ): void {
        $providerId = static::getProviderId();
        $queueForEvents->setParam('providerId', $providerId);

        // The secret is stored as JSON `{"clientSecret": "...", "tenantID": "..."}`
        // to match the shape Microsoft's OAuth2 adapter expects (getTenantID()).
        // The `tenant` param is required on every call, so it's always written.
        // `applicationSecret` is optional; if omitted, the existing stored secret is preserved.
        $storedRaw = $project->getAttribute('oAuthProviders', [])[$providerId . 'Secret'] ?? '';
        $existing = [];
        if (!empty($storedRaw)) {
            $existing = \json_decode($storedRaw, true) ?: [];
        }
        $encodedSecret = \json_encode([
            'clientSecret' => $applicationSecret ?? ($existing['clientSecret'] ?? ''),
            'tenantID' => $tenant,
        ]);

        $project = $this->persistCredentials($project, $dbForPlatform, $authorization, $applicationId, $encodedSecret, $enabled);

        // Reuse buildReadResponse to keep PATCH/GET shapes identical and
        // guarantee the applicationSecret is write-only on every response path.
        $response->dynamic($this->buildReadResponse($project), static::getResponseModel());
    }
}
