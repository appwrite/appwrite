<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Discord;

use Appwrite\Auth\OAuth2\Discord;
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
use Utopia\Platform\Enum;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'discord';
    }

    public static function getProviderClass(): string
    {
        return Discord::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Discord';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Discord';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_DISCORD;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '950722000000343754';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'YmPXnM000000000000000000002zFg5D';
    }

    public static function getParameters(): array
    {
        return \array_merge(parent::getParameters(), [
            [
                '$id' => 'prompt',
                'name' => 'Prompt',
                'example' => '["none"]',
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
            ->param(static::getClientIdParamName(), null, new Nullable(new Text(256, 0)), static::getClientIdDescription(), optional: true)
            ->param(static::getClientSecretParamName(), null, new Nullable(new Text(512, 0)), static::getClientSecretDescription(), optional: true)
            ->param('prompt', null, new Nullable(new ArrayList(new WhiteList(['none', 'consent'], true), 1)), 'Array with at most one Discord OAuth2 prompt value. "none" means: skip the authorization screen for users who already authorized the app with the requested scopes. "consent" means: ask users who already authorized the app to approve it again. Pass an empty array to use Discord\'s default.', optional: true, enum: new Enum(name: 'ProjectOAuth2DiscordPrompt'))
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
            'prompt' => $decoded['prompt'] ?? [],
        ]);
    }

    /**
     * Custom callback used instead of the parent's `action()` because Discord
     * takes an additional optional `prompt` parameter. The method is named
     * differently to avoid an LSP-incompatible override of Base::action().
     */
    public function handle(
        ?string $clientId,
        ?string $clientSecret,
        ?array $prompt,
        ?bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        QueueEvent $queueForEvents
    ): void {
        $providerId = static::getProviderId();
        $queueForEvents->setParam('providerId', $providerId);

        $encodedSecret = null;

        if ($clientSecret !== null || $prompt !== null) {
            $storedRaw = $project->getAttribute('oAuthProviders', [])[$providerId . 'Secret'] ?? '';
            $existing = $this->decodeStoredSecret($project);

            // Backwards compatibility: secrets stored before the prompt feature
            // were saved as plain strings. Treat the raw value as clientSecret.
            if (!empty($storedRaw) && empty($existing)) {
                $existing = ['clientSecret' => $storedRaw];
            }

            $secret = [
                'clientSecret' => $clientSecret ?? ($existing['clientSecret'] ?? ''),
                'prompt' => $prompt ?? ($existing['prompt'] ?? []),
            ];

            // Keep an empty secret empty, so enabling still requires a client secret.
            $encodedSecret = empty($secret['clientSecret']) && empty($secret['prompt']) ? '' : \json_encode($secret);
        }

        $project = $this->persistCredentials($project, $dbForPlatform, $authorization, $clientId, $encodedSecret, $enabled);

        $response->dynamic($this->buildReadResponse($project), static::getResponseModel());
    }
}
