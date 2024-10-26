<?php

namespace Appwrite\Platform\Modules\Sites\Http\Sites;

use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\Sites\Validator\FrameworkSpecification;
use Appwrite\Utopia\Response;
use Executor\Executor;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Request;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub;

class UpdateSite extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateSite';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/sites/:siteId')
            ->desc('Update site')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('event', 'sites.[siteId].update')
            ->label('audits.event', 'sites.update')
            ->label('audits.resource', 'site/{response.$id}')
            ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
            ->label('sdk.namespace', 'sites')
            ->label('sdk.method', 'update')
            ->label('sdk.description', '/docs/references/sites/update-site.md')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_SITE)
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('name', '', new Text(128), 'Site name. Max length: 128 chars.')
            ->param('framework', '', new WhiteList(array_keys(Config::getParam('frameworks')), true), 'Sites framework.')
            ->param('enabled', true, new Boolean(), 'Is site enabled? When set to \'disabled\', users cannot access the site but Server SDKs with and API key can still access the site. No data is lost when this is toggled.', true) // TODO: Add logging param later
            ->param('timeout', 15, new Range(1, (int) System::getEnv('_APP_SITES_TIMEOUT', 900)), 'Maximum request time in seconds.', true)
            ->param('installCommand', '', new Text(8192, 0), 'Install Command.', true)
            ->param('buildCommand', '', new Text(8192, 0), 'Build Command.', true)
            ->param('outputDirectory', '', new Text(8192, 0), 'Output Directory for site.', true)
            ->param('fallbackRedirect', '', new Text(8192, 0), 'Fallback Redirect URL for site in case a route is not found.', true)
            ->param('scopes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of scopes allowed for API key auto-generated for every execution. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed.', true) //TODO: Update description of scopes
            ->param('installationId', '', new Text(128, 0), 'Appwrite Installation ID for VCS (Version Control System) deployment.', true)
            ->param('providerRepositoryId', '', new Text(128, 0), 'Repository ID of the repo linked to the site.', true)
            ->param('providerBranch', '', new Text(128, 0), 'Production branch for the repo linked to the site.', true)
            ->param('providerSilentMode', false, new Boolean(), 'Is the VCS (Version Control System) connection in silent mode for the repo linked to the site? In silent mode, comments will not be made on commits and pull requests.', true)
            ->param('providerRootDirectory', '', new Text(128, 0), 'Path to site code in the linked repo.', true)
            ->param('specification', APP_SITE_SPECIFICATION_DEFAULT, fn (array $plan) => new FrameworkSpecification(
                $plan,
                Config::getParam('framework-specifications', []),
                App::getEnv('_APP_SITES_CPUS', APP_SITE_CPUS_DEFAULT),
                App::getEnv('_APP_SITES_MEMORY', APP_SITE_MEMORY_DEFAULT)
            ), 'Framework specification for the site and builds.', true, ['plan'])
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('queueForBuilds')
            ->inject('dbForConsole')
            ->inject('gitHub')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $name, string $framework, bool $enabled, int $timeout, string $installCommand, string $buildCommand, string $outputDirectory, string $fallbackRedirect, array $scopes, string $installationId, ?string $providerRepositoryId, string $providerBranch, bool $providerSilentMode, string $providerRootDirectory, string $specification, Request $request, Response $response, Database $dbForProject, Document $project, Event $queueForEvents, Build $queueForBuilds, Database $dbForConsole, GitHub $github)
    {
        // TODO: If only branch changes, re-deploy
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $installation = $dbForConsole->getDocument('installations', $installationId);

        if (!empty($installationId) && $installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!empty($providerRepositoryId) && (empty($installationId) || empty($providerBranch))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'When connecting to VCS (Version Control System), you need to provide "installationId" and "providerBranch".');
        }

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        if (empty($framework)) {
            $framework = $site->getAttribute('framework');
        }

        $enabled ??= $site->getAttribute('enabled', true);

        $repositoryId = $site->getAttribute('repositoryId', '');
        $repositoryInternalId = $site->getAttribute('repositoryInternalId', '');

        $isConnected = !empty($site->getAttribute('providerRepositoryId', ''));

        // Git disconnect logic. Disconnecting only when providerRepositoryId is empty, allowing for continue updates without disconnecting git
        if ($isConnected && ($providerRepositoryId !== null && empty($providerRepositoryId))) {
            $repositories = $dbForConsole->find('repositories', [
                Query::equal('projectInternalId', [$project->getInternalId()]),
                Query::equal('resourceInternalId', [$site->getInternalId()]),
                Query::equal('resourceType', ['site']),
                Query::limit(100),
            ]);

            foreach ($repositories as $repository) {
                $dbForConsole->deleteDocument('repositories', $repository->getId());
            }

            $providerRepositoryId = '';
            $installationId = '';
            $providerBranch = '';
            $providerRootDirectory = '';
            $providerSilentMode = true;
            $repositoryId = '';
            $repositoryInternalId = '';
        }

        // Git connect logic
        if (!$isConnected && !empty($providerRepositoryId)) {
            $teamId = $project->getAttribute('teamId', '');

            $repository = $dbForConsole->createDocument('repositories', new Document([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::team(ID::custom($teamId))),
                    Permission::update(Role::team(ID::custom($teamId), 'owner')),
                    Permission::update(Role::team(ID::custom($teamId), 'developer')),
                    Permission::delete(Role::team(ID::custom($teamId), 'owner')),
                    Permission::delete(Role::team(ID::custom($teamId), 'developer')),
                ],
                'installationId' => $installation->getId(),
                'installationInternalId' => $installation->getInternalId(),
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getInternalId(),
                'providerRepositoryId' => $providerRepositoryId,
                'resourceId' => $site->getId(),
                'resourceInternalId' => $site->getInternalId(),
                'resourceType' => 'site',
                'providerPullRequestIds' => []
            ]));

            $repositoryId = $repository->getId();
            $repositoryInternalId = $repository->getInternalId();
        }

        $live = true;

        if (
            $site->getAttribute('name') !== $name ||
            $site->getAttribute('buildCommand') !== $buildCommand ||
            $site->getAttribute('installCommand') !== $installCommand ||
            $site->getAttribute('outputDirectory') !== $outputDirectory ||
            $site->getAttribute('fallbackRedirect') !== $fallbackRedirect ||
            $site->getAttribute('providerRootDirectory') !== $providerRootDirectory ||
            $site->getAttribute('framework') !== $framework
        ) {
            $live = false;
        }

        $spec = Config::getParam('framework-specifications')[$specification] ?? [];

        // Enforce Cold Start if spec limits change.
        if ($site->getAttribute('specification') !== $specification && !empty($site->getAttribute('deploymentId'))) {
            $executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));
            try {
                $executor->deleteRuntime($project->getId(), $site->getAttribute('deploymentId'));
            } catch (\Throwable $th) {
                // Don't throw if the deployment doesn't exist
                if ($th->getCode() !== 404) {
                    throw $th;
                }
            }
        }

        $site = $dbForProject->updateDocument('sites', $site->getId(), new Document(array_merge($site->getArrayCopy(), [
            'name' => $name,
            'framework' => $framework,
            'enabled' => $enabled,
            'live' => $live,
            'timeout' => $timeout,
            'installCommand' => $installCommand,
            'buildCommand' => $buildCommand,
            'outputDirectory' => $outputDirectory,
            'fallbackRedirect' => $fallbackRedirect,
            'scopes' => $scopes,
            'installationId' => $installation->getId(),
            'installationInternalId' => $installation->getInternalId(),
            'providerRepositoryId' => $providerRepositoryId,
            'repositoryId' => $repositoryId,
            'repositoryInternalId' => $repositoryInternalId,
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $providerRootDirectory,
            'providerSilentMode' => $providerSilentMode,
            'specification' => $specification,
            'search' => implode(' ', [$siteId, $name, $framework]),
        ])));

        // Redeploy logic
        if (!$isConnected && !empty($providerRepositoryId)) {
            $this->redeployVcsFunction($request, $site, $project, $installation, $dbForProject, $queueForBuilds, new Document(), $github);
        }

        $queueForEvents->setParam('siteId', $site->getId());

        $response->dynamic($site, Response::MODEL_SITE);
    }
}
