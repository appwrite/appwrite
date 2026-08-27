<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Cloudflare;

use Appwrite\Auth\OAuth2\Cloudflare;
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
        return 'cloudflare';
    }

    public static function getProviderClass(): string
    {
        return Cloudflare::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Cloudflare';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Cloudflare';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_CLOUDFLARE;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '8c33c3da9e8f392k71m1f9dc1a190cb3707ad27ba4d19bff45c900e6dfet1f4a';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '2d106b111a390d9692ab9a8a295ac05668632b17bbb342d149209aaaaa100000';
    }

    /**
     * Cloudflare requires a third "team" parameter alongside clientId/clientSecret.
     *
     * @return array
     */
    public static function getParameters(): array
    {
        return [
            [
                '$id' => 'clientId',
                'name' => 'Client ID',
                'example' => self::getClientIdExample(),
                'hint' => '',
            ],
            [
                '$id' => 'clientSecret',
                'name' => 'Client Secret',
                'example' => self::getClientSecretExample(),
                'hint' => '',
            ],
            [
                '$id' => 'team',
                'name' => 'Team',
                'example' => 'acme',
                'hint' => 'Your Cloudflare Zero Trust team name (the subdomain of cloudflareaccess.com).',
            ],
        ];
    }

    /**
     * @param Document $project
     *
     * @return Document
     */
    public function buildReadResponse(Document $project): Document
    {
        $providerId = static::getProviderId();
        $oAuthProviders = $project->getAttribute('oAuthProviders', []);
        $decoded = $this->decodeStoredSecret($project);

        return new Document([
            '$id' => $providerId,
            'enabled' => $oAuthProviders[$providerId . 'Enabled'] ?? false,
            'clientId' => $oAuthProviders[$providerId . 'Appid'] ?? '',
            'clientSecret' => '',
            'team' => $decoded['team'] ?? '',
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
            ->label('scope', 'project.oauth2.write')
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
            ->param('clientId', null, new Nullable(new Text(256, 0)), static::getClientIdDescription(), optional: true)
            ->param('clientSecret', null, new Nullable(new Text(512, 0)), static::getClientSecretDescription(), optional: true)
            ->param('team', null, new Nullable(new Text(256, 0)), 'Cloudflare Zero Trust team name (subdomain of cloudflareaccess.com). For example: acme', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'OAuth2 sign-in method status. Set to true to enable new session creation. Setting to true will trigger end-to-end credentials validation, and will throw if the credentials are invalid.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->handle(...));
    }

    /**
     * Custom callback used instead of the parent's action() because Cloudflare
     * takes an additional required "team" parameter. The method is named
     * differently to avoid an LSP-incompatible override of Base::action().
     */
    public function handle(
        ?string $clientId,
        ?string $clientSecret,
        ?string $team,
        ?bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        QueueEvent $queueForEvents
    ): void {
        $providerId = static::getProviderId();
        $queueForEvents->setParam('providerId', $providerId);

        // The secret is stored as JSON {"clientSecret": "...", "team": "..."} so Cloudflare::getTeam() can read it back.
        $existing = $this->decodeStoredSecret($project);
        $encodedSecret = \json_encode([
            'clientSecret' => $clientSecret ?? ($existing['clientSecret'] ?? ''),
            'team' => $team ?? ($existing['team'] ?? ''),
        ]);

        $project = $this->persistCredentials($project, $dbForPlatform, $authorization, $clientId, $encodedSecret, $enabled);

        $response->dynamic($this->buildReadResponse($project), static::getResponseModel());
    }
}
