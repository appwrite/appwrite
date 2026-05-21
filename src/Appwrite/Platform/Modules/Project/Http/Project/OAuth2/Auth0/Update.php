<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Auth0;

use Appwrite\Auth\OAuth2\Auth0;
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
        return 'auth0';
    }

    public static function getProviderClass(): string
    {
        return Auth0::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Auth0';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Auth0';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_AUTH0;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'OaOkIA000000000000000000005KLSYq';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'zXz0000-00000000000000000000000000000-00000000000000000000PJafnF';
    }

    public static function getParameters(): array
    {
        return \array_merge(parent::getParameters(), [
            [
                '$id' => 'endpoint',
                'name' => 'Domain',
                'example' => 'example.us.auth0.com',
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
            ->param('endpoint', null, new Nullable(new Text(256, 0)), 'Domain of Auth0 instance. For example: example.us.auth0.com', optional: true)
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
            'endpoint' => $decoded['auth0Domain'] ?? '',
        ]);
    }

    /**
     * Custom callback used instead of the parent's `action()` because Auth0
     * takes an additional optional `endpoint` parameter. The method is named
     * differently to avoid an LSP-incompatible override of Base::action().
     */
    public function handle(
        ?string $clientId,
        ?string $clientSecret,
        ?string $endpoint,
        ?bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        QueueEvent $queueForEvents
    ): void {
        $providerId = static::getProviderId();
        $queueForEvents->setParam('providerId', $providerId);

        // The secret is stored as JSON `{"clientSecret": "...", "auth0Domain": "..."}`
        // to match the shape Auth0's OAuth2 adapter expects (getAuth0Domain()).
        // Merge new values with existing storage so that submitting only one of
        // `clientSecret`/`endpoint` leaves the other untouched.
        $encodedSecret = null;
        if (!\is_null($clientSecret) || !\is_null($endpoint)) {
            $storedRaw = $project->getAttribute('oAuthProviders', [])[$providerId . 'Secret'] ?? '';
            $existing = [];
            if (!empty($storedRaw)) {
                $existing = \json_decode($storedRaw, true) ?: [];
            }
            $encodedSecret = \json_encode([
                'clientSecret' => $clientSecret ?? ($existing['clientSecret'] ?? ''),
                'auth0Domain' => $endpoint ?? ($existing['auth0Domain'] ?? ''),
            ]);
        }

        $project = $this->persistCredentials($project, $dbForPlatform, $authorization, $clientId, $encodedSecret, $enabled);

        // Reuse buildReadResponse to keep PATCH/GET shapes identical and
        // guarantee the clientSecret is write-only on every response path.
        $response->dynamic($this->buildReadResponse($project), static::getResponseModel());
    }
}
