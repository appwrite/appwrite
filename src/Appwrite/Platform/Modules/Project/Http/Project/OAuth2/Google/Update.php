<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Google;

use Appwrite\Auth\OAuth2\Google;
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
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'google';
    }

    public static function getProviderClass(): string
    {
        return Google::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Google';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Google';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_GOOGLE;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '120000000095-92ifjb00000000000000000000g7ijfb.apps.googleusercontent.com';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'GOCSPX-2k8gsR0000000000000000VNahJj';
    }

    public static function getParameters(): array
    {
        return \array_merge(parent::getParameters(), [
            [
                '$id' => 'prompt',
                'name' => 'Prompt',
                'example' => '["consent"]',
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
            ->param('prompt', null, new Nullable(new ArrayList(new WhiteList(['none', 'consent', 'select_account'], true), 3)), 'Array of Google OAuth2 prompt values. If "none" is included, it must be the only element. "none" means: don\'t display any authentication or consent screens. Must not be specified with other values. "consent" means: prompt the user for consent. "select_account" means: prompt the user to select an account.', optional: true)
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
            'prompt' => $decoded['prompt'] ?? ['consent'],
        ]);
    }

    /**
     * Custom callback used instead of the parent's `action()` because Google
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

        if ($prompt !== null) {
            if (empty($prompt)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Prompt array cannot be empty.');
            }

            if (\in_array('none', $prompt) && \count($prompt) > 1) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'When "none" is used as a prompt value, it must be the only element in the array.');
            }
        }

        $storedRaw = $project->getAttribute('oAuthProviders', [])[$providerId . 'Secret'] ?? '';
        $existing = $this->decodeStoredSecret($project);

        // Backwards compatibility: secrets stored before the prompt feature
        // were saved as plain strings. Treat the raw value as clientSecret.
        if (!empty($storedRaw) && empty($existing)) {
            $existing = ['clientSecret' => $storedRaw];
        }

        $encodedSecret = \json_encode([
            'clientSecret' => $clientSecret ?? ($existing['clientSecret'] ?? ''),
            'prompt' => $prompt ?? ($existing['prompt'] ?? ['consent']),
        ]);

        $project = $this->persistCredentials($project, $dbForPlatform, $authorization, $clientId, $encodedSecret, $enabled);

        $response->dynamic($this->buildReadResponse($project), static::getResponseModel());
    }
}
