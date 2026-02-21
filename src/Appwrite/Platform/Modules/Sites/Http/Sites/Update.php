<?php

namespace Appwrite\Platform\Modules\Sites\Http\Sites;

use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\Platform\Modules\Compute\Validator\Specification;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Executor\Executor;
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
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub;

class Update extends Base
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
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('event', 'sites.[siteId].update')
            ->label('audits.event', 'sites.update')
            ->label('audits.resource', 'site/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'sites',
                name: 'update',
                description: <<<EOT
                Update site by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SITE,
                    )
                ]
            ))
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('name', '', new Text(128), 'Site name. Max length: 128 chars.')
            ->param('framework', '', new WhiteList(\array_keys(Config::getParam('frameworks')), true), 'Sites framework.')
            ->param('enabled', true, new Boolean(), 'Is site enabled? When set to \'disabled\', users cannot access the site but Server SDKs with and API key can still access the site. No data is lost when this is toggled.', true)
            ->param('logging', true, new Boolean(), 'When disabled, request logs will exclude logs and errors, and site responses will be slightly faster.', true)
            ->param('timeout', 30, new Range(1, (int) System::getEnv('_APP_SITES_TIMEOUT', 30)), 'Maximum request time in seconds.', true)
            ->param('installCommand', '', new Text(8192, 0), 'Install Command.', true)
            ->param('buildCommand', '', new Text(8192, 0), 'Build Command.', true)
            ->param('outputDirectory', '', new Text(8192, 0), 'Output Directory for site.', true)
            ->param('buildRuntime', '', new WhiteList(array_keys(Config::getParam('runtimes')), true), 'Runtime to use during build step.', true)
            ->param('adapter', '', new WhiteList(['static', 'ssr']), 'Framework adapter defining rendering strategy. Allowed values are: static, ssr', true)
            ->param('fallbackFile', '', new Text(255, 0), 'Fallback file for single page application sites.', true)
            ->param('installationId', '', new Text(128, 0), 'Appwrite Installation ID for VCS (Version Control System) deployment.', true)
            ->param('providerRepositoryId', '', new Text(128, 0), 'Repository ID of the repo linked to the site.', true)
            ->param('providerBranch', '', new Text(128, 0), 'Production branch for the repo linked to the site.', true)
            ->param('providerSilentMode', false, new Boolean(), 'Is the VCS (Version Control System) connection in silent mode for the repo linked to the site? In silent mode, comments will not be made on commits and pull requests.', true)
            ->param('providerRootDirectory', '', new Text(128, 0), 'Path to site code in the linked repo.', true)
            ->param('specification', fn (array $plan) => $this->getDefaultSpecification($plan), fn (array $plan) => new Specification(
                $plan,
                Config::getParam('specifications', []),
                System::getEnv('_APP_COMPUTE_CPUS', 0),
                System::getEnv('_APP_COMPUTE_MEMORY', 0)
            ), 'Framework specification for the site and builds.', true, ['plan'])
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('queueForBuilds')
            ->inject('dbForPlatform')
            ->inject('gitHub')
            ->inject('executor')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $name,
        string $framework,
        bool $enabled,
        bool $logging,
        int $timeout,
        string $installCommand,
        string $buildCommand,
        string $outputDirectory,
        string $buildRuntime,
        string $adapter,
        string $fallbackFile,
        string $installationId,
        ?string $providerRepositoryId,
        string $providerBranch,
        bool $providerSilentMode,
        string $providerRootDirectory,
        string $specification,
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        Build $queueForBuilds,
        Database $dbForPlatform,
        GitHub $github,
        Executor $executor
    ) {
        if (!empty($adapter)) {
            $configFramework = Config::getParam('frameworks')[$framework] ?? [];
            $adapters = \array_keys($configFramework['adapters'] ?? []);
            $validator = new WhiteList($adapters, true);
            if (!$validator->isValid($adapter)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Adapter not supported for the selected framework.');
            }
        }

        // TODO: If only branch changes, re-deploy
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $installation = $dbForPlatform->getDocument('installations', $installationId);

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
            $repositories = $dbForPlatform->find('repositories', [
                Query::equal('projectInternalId', [$project->getSequence()]),
                Query::equal('resourceInternalId', [$site->getSequence()]),
                Query::equal('resourceType', ['site']),
                Query::limit(100),
            ]);

            foreach ($repositories as $repository) {
                $dbForPlatform->deleteDocument('repositories', $repository->getId());
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

            $repository = $dbForPlatform->createDocument('repositories', new Document([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::team(ID::custom($teamId))),
                    Permission::update(Role::team(ID::custom($teamId), 'owner')),
                    Permission::update(Role::team(ID::custom($teamId), 'developer')),
                    Permission::delete(Role::team(ID::custom($teamId), 'owner')),
                    Permission::delete(Role::team(ID::custom($teamId), 'developer')),
                ],
                'installationId' => $installation->getId(),
                'installationInternalId' => $installation->getSequence(),
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getSequence(),
                'providerRepositoryId' => $providerRepositoryId,
                'resourceId' => $site->getId(),
                'resourceInternalId' => $site->getSequence(),
                'resourceType' => 'site',
                'providerPullRequestIds' => []
            ]));

            $repositoryId = $repository->getId();
            $repositoryInternalId = $repository->getSequence();
        }

        $live = true;

        if (
            $site->getAttribute('name') !== $name ||
            $site->getAttribute('buildCommand') !== $buildCommand ||
            $site->getAttribute('installCommand') !== $installCommand ||
            $site->getAttribute('outputDirectory') !== $outputDirectory ||
            $site->getAttribute('providerRootDirectory') !== $providerRootDirectory ||
            $site->getAttribute('framework') !== $framework
        ) {
            $live = false;
        }

        // Enforce Cold Start if spec limits change.
        if ($site->getAttribute('specification') !== $specification && !empty($site->getAttribute('deploymentId'))) {
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
            'logging' => $logging,
            'live' => $live,
            'timeout' => $timeout,
            'installCommand' => $installCommand,
            'buildCommand' => $buildCommand,
            'outputDirectory' => $outputDirectory,
            'installationId' => $installation->getId(),
            'installationInternalId' => $installation->getSequence(),
            'providerRepositoryId' => $providerRepositoryId,
            'repositoryId' => $repositoryId,
            'repositoryInternalId' => $repositoryInternalId,
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $providerRootDirectory,
            'providerSilentMode' => $providerSilentMode,
            'specification' => $specification,
            'search' => implode(' ', [$siteId, $name, $framework]),
            'buildRuntime' => $buildRuntime,
            'adapter' => $adapter,
            'fallbackFile' => $fallbackFile,
        ])));

        // Redeploy logic
        if (!$isConnected && !empty($providerRepositoryId)) {
            $this->redeployVcsFunction($request, $site, $project, $installation, $dbForProject, $queueForBuilds, new Document(), $github, true);
        }

        $queueForEvents->setParam('siteId', $site->getId());

        $response->dynamic($site, Response::MODEL_SITE);
    }
}
