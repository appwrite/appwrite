<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Apple;

use Appwrite\Auth\OAuth2\Apple;
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
        return 'apple';
    }

    public static function getProviderClass(): string
    {
        return Apple::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Apple';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Apple';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_APPLE;
    }

    public static function getClientIdParamName(): string
    {
        return 'serviceId';
    }

    public static function getClientIdName(): string
    {
        return 'Service ID';
    }

    public static function getClientIdExample(): string
    {
        return 'ip.appwrite.app.web';
    }

    public static function getClientSecretName(): string
    {
        // Apple does not use a single clientSecret param. Returning an empty
        // string causes the default getParameters() to skip it; the override
        // below adds the three real fields (keyId, teamId, p8File).
        return '';
    }

    public static function getClientSecretExample(): string
    {
        return '';
    }

    public static function getParameters(): array
    {
        return [
            [
                '$id' => static::getClientIdParamName(),
                'name' => static::getClientIdName(),
                'example' => static::getClientIdExample(),
                'hint' => '',
            ],
            [
                '$id' => 'keyId',
                'name' => 'Key ID',
                'example' => 'P4000000N8',
                'hint' => '',
            ],
            [
                '$id' => 'teamId',
                'name' => 'Team ID',
                'example' => 'D4000000R6',
                'hint' => '',
            ],
            [
                '$id' => 'p8File',
                'name' => 'P8 File',
                'example' => '-----BEGIN PRIVATE KEY-----MIGTAg...jy2Xbna-----END PRIVATE KEY-----',
                'hint' => '',
            ],
        ];
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
            ->param('keyId', null, new Nullable(new Text(256, 0)), '\'Key ID\' of Apple OAuth2 app. For example: P4000000N8', optional: true)
            ->param('teamId', null, new Nullable(new Text(256, 0)), '\'Team ID\' of Apple OAuth2 app. For example: D4000000R6', optional: true)
            ->param('p8File', null, new Nullable(new Text(4096, 0)), 'Contents of the Apple OAuth2 app .p8 private key file. The secret key wrapped by the PEM markers is 200 characters long. For example: -----BEGIN PRIVATE KEY-----MIGTAg...jy2Xbna-----END PRIVATE KEY-----', optional: true)
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
        $storedSecret = $this->decodeStoredSecret($project);

        return new Document([
            '$id' => $providerId,
            'enabled' => $oAuthProviders[$providerId . 'Enabled'] ?? false,
            static::getClientIdParamName() => $oAuthProviders[$providerId . 'Appid'] ?? '',
            'keyId' => $storedSecret['keyID'] ?? '',
            'teamId' => $storedSecret['teamID'] ?? '',
            'p8File' => '',
        ]);
    }

    /**
     * Custom callback used instead of the parent's `action()` because Apple's
     * client secret is composed of three fields (.p8 file contents, Key ID and
     * Team ID) that must be JSON-encoded to match the shape Apple's OAuth2
     * adapter expects in getAppSecret(). The method is named differently to
     * avoid an LSP-incompatible override of Base::action().
     */
    public function handle(
        ?string $serviceId,
        ?string $keyId,
        ?string $teamId,
        ?string $p8File,
        ?bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        QueueEvent $queueForEvents
    ): void {
        $providerId = static::getProviderId();
        $queueForEvents->setParam('providerId', $providerId);

        // The secret is stored as JSON `{"p8": "...", "keyID": "...", "teamID": "..."}`
        // to match the shape Apple's OAuth2 adapter expects in getAppSecret().
        // Merge new values with what's already stored so that submitting only
        // some of the fields leaves the rest untouched.
        $encodedSecret = null;
        if (!\is_null($keyId) || !\is_null($teamId) || !\is_null($p8File)) {
            $storedRaw = $project->getAttribute('oAuthProviders', [])[$providerId . 'Secret'] ?? '';
            $existing = [];
            if (!empty($storedRaw)) {
                $existing = \json_decode($storedRaw, true) ?: [];
            }
            $encodedSecret = \json_encode([
                'p8' => $p8File ?? ($existing['p8'] ?? ''),
                'keyID' => $keyId ?? ($existing['keyID'] ?? ''),
                'teamID' => $teamId ?? ($existing['teamID'] ?? ''),
            ]);
        }

        $project = $this->persistCredentials($project, $dbForPlatform, $authorization, $serviceId, $encodedSecret, $enabled);

        // Reuse buildReadResponse to keep PATCH/GET shapes identical and
        // guarantee keyId/teamId/p8File are write-only on every response path.
        $response->dynamic($this->buildReadResponse($project), static::getResponseModel());
    }
}
