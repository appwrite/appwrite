<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Native;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateOAuth2Native';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/mock/tests/general/oauth2/native')
            ->desc('Configure native ID token sign-in for a mock provider')
            ->groups(['mock', 'api', 'projects'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('projectId', '', new UID(), 'Project ID.')
            ->param('enabled', false, new Boolean(), 'Accept ID tokens minted by the mock provider.')
            ->param('provider', 'mock', new WhiteList(\array_keys(\array_filter(Config::getParam('oAuthProviders', []), fn ($node) => !empty($node['mock']) && !empty($node['idToken']))), true), 'Mock provider to configure.', true)
            ->param('appId', null, new Nullable(new Text(256, 0)), 'App ID to store for the provider. Null leaves it untouched, an empty string clears it.', true)
            ->param('clientIds', [], new ArrayList(new Text(256, 0), 20), 'Native client IDs accepted as ID token audiences next to the app ID.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, bool $enabled, string $provider, ?string $appId, array $clientIds, Response $response, Database $dbForPlatform): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        // The mock providers have no console form of their own; this is the
        // only way a test can reach the switches the real providers expose.
        $providers = $project->getAttribute('oAuthProviders', []);
        $providers[$provider . 'NativeEnabled'] = $enabled;
        $providers[$provider . 'ClientIds'] = \array_values($clientIds);
        if (!\is_null($appId)) {
            $providers[$provider . 'Appid'] = $appId;
        }

        $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'oAuthProviders' => $providers,
        ]));
        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response->noContent();
    }
}
