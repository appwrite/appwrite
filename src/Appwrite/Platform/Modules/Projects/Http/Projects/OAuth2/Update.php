<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects\OAuth2;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Projects\Http\Projects\Action;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/**
 * Backwards compatibility
 */
class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateProjectOAuth2';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/projects/:projectId/oauth2')
            ->desc('Update project OAuth2')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.write')
            ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Project unique ID.', false, ['dbForPlatform'])
            ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'Provider Name')
            ->param('appId', null, new Nullable(new Text(256)), 'Provider app ID. Max length: 256 chars.', true)
            ->param('secret', null, new Nullable(new Text(512)), 'Provider secret key. Max length: 512 chars.', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Provider status. Set to \'false\' to disable new session creation.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $provider, ?string $appId, ?string $secret, ?bool $enabled, Response $response, Database $dbForPlatform): void
    {
        $project = $dbForPlatform->withTransaction(function () use ($dbForPlatform, $projectId, $provider, $appId, $secret, $enabled) {
            $project = $dbForPlatform->getDocument('projects', $projectId, forUpdate: true);

            if ($project->isEmpty()) {
                throw new Exception(Exception::PROJECT_NOT_FOUND);
            }

            $providers = $project->getAttribute('oAuthProviders', []);

            if ($appId !== null) {
                $providers[$provider . 'Appid'] = $appId;
            }

            if ($secret !== null) {
                $providers[$provider . 'Secret'] = $secret;
            }

            if ($enabled !== null) {
                $providers[$provider . 'Enabled'] = $enabled;
            }

            return $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                'oAuthProviders' => $providers,
            ]));
        });

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
