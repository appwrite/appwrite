<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps;

use Appwrite\Distribution\Framework;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Permission;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationRepositories;
use Appwrite\Vcs\RepositoryWebhooks;
use Appwrite\Vcs\Validator\Directory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;
    use Permission;

    public static function getName(): string
    {
        return 'createApp';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/apps')
            ->desc('Create app')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].create')
            ->label('audits.event', 'distribution.app.create')
            ->label('audits.resource', 'distributionApp/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'apps',
                name: 'createApp',
                description: <<<EOT
                Create a new app. An app is the mobile project you build, sign and submit to the app stores. Add a target for each platform the app ships to. Pass an installation, repository and production branch to build the app from a GitHub, GitLab, Gitea or Bitbucket repository.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DISTRIBUTION_APP,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'App ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'App name. Max length: 128 chars.')
            ->param('framework', '', new WhiteList(Framework::values(), true), 'Framework the app is built with.', enum: new Enum(name: 'DistributionFramework'))
            ->param('enabled', true, new Boolean(), 'Whether the app is enabled. A disabled app ignores pushes and rejects new builds.', true)
            ->param('installationId', '', new Text(128, 0), 'Appwrite Installation ID for VCS (Version Control System) builds.', true)
            ->param('providerRepositoryId', '', new Text(128, 0), 'Repository ID of the repo linked to the app.', true)
            ->param('providerBranch', '', new Text(128, 0), 'Production branch for the repo linked to the app.', true)
            ->param('providerSilentMode', false, new Boolean(), 'Is the VCS (Version Control System) connection in silent mode for the repo linked to the app? In silent mode, no checks are posted to commits.', true)
            ->param('providerRootDirectory', '', new Directory(), 'Path to the app code in the linked repo.', true)
            ->param('providerBranches', [], new ArrayList(new Text(128), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of branch name patterns that trigger automatic builds. Supports wildcards. Leave empty to build on all branches.', true)
            ->param('providerPaths', [], new ArrayList(new Text(128), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of file path patterns that trigger automatic builds. Supports wildcards. Leave empty to build on all file changes.', true)
            ->param('providerTags', [], new ArrayList(new Text(128), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of tag name patterns that trigger automatic builds. Supports wildcards. Leave empty to build no tags.', true)
            ->param('providerComments', true, new Boolean(), 'Post a build summary on pull requests for the repo linked to the app. Distribution posts no pull request comments today, so the value is only stored.', true)
            ->param('providerDeployments', true, new Boolean(), 'Build every push and tag that matches the branch, path and tag rules for the repo linked to the app. When disabled, build manually.', true)
            ->param('providerStatuses', true, new Boolean(), 'Report the build status as a check on each commit for the repo linked to the app. Ignored in silent mode.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('vcsFactory')
            ->inject('installationRepositories')
            ->inject('repositoryWebhooks')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $providerBranches
     * @param array<string> $providerPaths
     * @param array<string> $providerTags
     */
    public function action(
        string $appId,
        string $name,
        string $framework,
        bool $enabled,
        string $installationId,
        string $providerRepositoryId,
        string $providerBranch,
        bool $providerSilentMode,
        string $providerRootDirectory,
        array $providerBranches,
        array $providerPaths,
        array $providerTags,
        bool $providerComments,
        bool $providerDeployments,
        bool $providerStatuses,
        Response $response,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        Database $dbForPlatform,
        VcsFactory $vcsFactory,
        InstallationRepositories $installationRepositories,
        RepositoryWebhooks $repositoryWebhooks,
    ): void {
        $appId = $appId === 'unique()' ? Id::unique() : $appId;

        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if (!empty($installationId) && ($installation->isEmpty() || $installation->getAttribute('projectId') !== $project->getId())) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!empty($providerRepositoryId) && (empty($installationId) || empty($providerBranch))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'When connecting to VCS (Version Control System), you need to provide "installationId" and "providerBranch".');
        }

        if (!empty($providerRepositoryId)) {
            $vcs = $vcsFactory->fromInstallation($installation);

            if (!$vcs->supportsRepositoryArchives()) {
                throw new Exception(Exception::DISTRIBUTION_REPOSITORY_MISSING, 'This Git provider offers no repository archives, which Distribution builds from. Connect a GitHub, GitLab, Gitea or Bitbucket repository.');
            }

            [$owner, $repositoryName] = $installationRepositories->resolve($vcs, $installation, $providerRepositoryId);
        }

        try {
            $app = $dbForProject->createDocument('distributionApps', new Document([
                '$id' => $appId,
                'name' => $name,
                'framework' => $framework,
                'enabled' => $enabled,
                'installationId' => $installation->getId(),
                'installationInternalId' => $installation->getSequence() ?? '',
                'providerRepositoryId' => $providerRepositoryId,
                'repositoryId' => '',
                'repositoryInternalId' => '',
                'providerBranch' => $providerBranch,
                'providerRootDirectory' => $providerRootDirectory,
                'providerBranches' => $providerBranches,
                'providerPaths' => $providerPaths,
                'providerTags' => $providerTags,
                'providerSilentMode' => $providerSilentMode,
                'providerComments' => $providerComments,
                'providerDeployments' => $providerDeployments,
                'providerStatuses' => $providerStatuses,
                'search' => \implode(' ', [$appId, $name, $framework]),
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::DISTRIBUTION_APP_ALREADY_EXISTS);
        }

        if (!empty($providerRepositoryId)) {
            $repository = new Document();

            try {
                $repository = $dbForPlatform->createDocument('repositories', new Document([
                    '$id' => Id::unique(),
                    '$permissions' => $this->getPermissions($project->getAttribute('teamId', ''), $project->getId()),
                    'installationId' => $installation->getId(),
                    'installationInternalId' => $installation->getSequence(),
                    'projectId' => $project->getId(),
                    'projectInternalId' => $project->getSequence(),
                    'providerRepositoryId' => $providerRepositoryId,
                    'resourceId' => $app->getId(),
                    'resourceInternalId' => $app->getSequence(),
                    'resourceType' => RESOURCE_TYPE_DISTRIBUTION_APPS,
                    'providerPullRequestIds' => [],
                ]));

                $repositoryWebhooks->ensure($vcs, $installation, $dbForPlatform, $providerRepositoryId, $owner, $repositoryName);

                $app = $dbForProject->updateDocument('distributionApps', $app->getId(), new Document([
                    'repositoryId' => $repository->getId(),
                    'repositoryInternalId' => $repository->getSequence(),
                ]));
            } catch (\Throwable $error) {
                if (!$repository->isEmpty()) {
                    $dbForPlatform->deleteDocument('repositories', $repository->getId());
                }
                $dbForProject->deleteDocument('distributionApps', $app->getId());

                throw $error;
            }
        }

        $queueForEvents->setParam('appId', $app->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($app, Response::MODEL_DISTRIBUTION_APP);
    }
}
