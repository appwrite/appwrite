<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps;

use Appwrite\Distribution\Framework;
use Appwrite\Distribution\Platform;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Permission;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationRepositories;
use Appwrite\Vcs\RepositoryWebhooks;
use Appwrite\Vcs\Validator\Directory;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git;

class Update extends Action
{
    use HTTP;
    use Permission;

    public static function getName(): string
    {
        return 'updateApp';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/apps/:appId')
            ->desc('Update app')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].update')
            ->label('audits.event', 'distribution.app.update')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'apps',
                name: 'updateApp',
                description: <<<EOT
                Update an app by its unique ID. Only the parameters you pass are changed. The framework can only change to one that supports every platform the app already has a target for. Pass `installationId`, `providerRepositoryId` and `providerBranch` to connect or replace the repository, or an empty `providerRepositoryId` to disconnect it.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_APP,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('name', null, new Nullable(new Text(128)), 'App name. Max length: 128 chars.', true)
            ->param('framework', null, new Nullable(new WhiteList(Framework::values(), true)), 'Framework the app is built with.', true, enum: new Enum(name: 'DistributionFramework'))
            ->param('enabled', null, new Nullable(new Boolean()), 'Whether the app is enabled. A disabled app ignores pushes and rejects new builds.', true)
            ->param('installationId', null, new Nullable(new Text(128, 0)), 'Appwrite Installation ID for VCS (Version Control System) builds.', true)
            ->param('providerRepositoryId', null, new Nullable(new Text(128, 0)), 'Repository ID of the repo linked to the app. Pass an empty value to disconnect the repository.', true)
            ->param('providerBranch', null, new Nullable(new Text(128, 0)), 'Production branch for the repo linked to the app.', true)
            ->param('providerSilentMode', null, new Nullable(new Boolean()), 'Is the VCS (Version Control System) connection in silent mode for the repo linked to the app? In silent mode, no checks are posted to commits.', true)
            ->param('providerRootDirectory', null, new Nullable(new Directory()), 'Path to the app code in the linked repo.', true)
            ->param('providerBranches', null, new Nullable(new ArrayList(new Text(128), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'List of branch name patterns that trigger automatic builds. Supports wildcards. Leave empty to build only the production branch.', true)
            ->param('providerPaths', null, new Nullable(new ArrayList(new Text(128), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'List of file path patterns that trigger automatic builds. Supports wildcards. Leave empty to build on all file changes.', true)
            ->param('providerTags', null, new Nullable(new ArrayList(new Text(128), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'List of tag name patterns that trigger automatic builds. Supports wildcards. Leave empty to build no tags.', true)
            ->param('providerComments', null, new Nullable(new Boolean()), 'Post a build summary on pull requests for the repo linked to the app. Distribution posts no pull request comments today, so the value is only stored.', true)
            ->param('providerDeployments', null, new Nullable(new Boolean()), 'Build every push and tag that matches the branch, path and tag rules for the repo linked to the app. When disabled, build manually.', true)
            ->param('providerStatuses', null, new Nullable(new Boolean()), 'Report the build status as a check on each commit for the repo linked to the app. Ignored in silent mode.', true)
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
     * @param array<string>|null $providerBranches
     * @param array<string>|null $providerPaths
     * @param array<string>|null $providerTags
     */
    public function action(
        string $appId,
        ?string $name,
        ?string $framework,
        ?bool $enabled,
        ?string $installationId,
        ?string $providerRepositoryId,
        ?string $providerBranch,
        ?bool $providerSilentMode,
        ?string $providerRootDirectory,
        ?array $providerBranches,
        ?array $providerPaths,
        ?array $providerTags,
        ?bool $providerComments,
        ?bool $providerDeployments,
        ?bool $providerStatuses,
        Response $response,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        Database $dbForPlatform,
        VcsFactory $vcsFactory,
        InstallationRepositories $installationRepositories,
        RepositoryWebhooks $repositoryWebhooks,
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $settings = \array_filter([
            'name' => $name,
            'enabled' => $enabled,
            'providerBranch' => $providerBranch,
            'providerSilentMode' => $providerSilentMode,
            'providerRootDirectory' => $providerRootDirectory,
            'providerBranches' => $providerBranches,
            'providerPaths' => $providerPaths,
            'providerTags' => $providerTags,
            'providerComments' => $providerComments,
            'providerDeployments' => $providerDeployments,
            'providerStatuses' => $providerStatuses,
        ], fn (mixed $value): bool => !\is_null($value));

        if ($settings === [] && \is_null($framework) && \is_null($installationId) && \is_null($providerRepositoryId)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'At least one parameter to update is required.');
        }

        $updates = new Document($settings);

        if (!\is_null($framework) && $framework !== $app->getAttribute('framework')) {
            $this->assertTargetsSupported($dbForProject, $app, $framework);
            $updates->setAttribute('framework', $framework);
        }

        if (!\is_null($name) || $updates->isSet('framework')) {
            $updates->setAttribute('search', \implode(' ', [
                $app->getId(),
                $name ?? $app->getAttribute('name'),
                $framework ?? $app->getAttribute('framework'),
            ]));
        }

        $isConnected = !empty($app->getAttribute('providerRepositoryId', ''));

        if ($providerBranch === '' && $isConnected && $providerRepositoryId !== '') {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'A connected app needs a production branch. Provide a branch name in "providerBranch", or pass an empty "providerRepositoryId" to disconnect the repository.');
        }

        if ($providerRepositoryId === '' && $isConnected) {
            $this->unlink($dbForPlatform, $project, $app);

            foreach ([
                'installationId' => '',
                'installationInternalId' => '',
                'providerRepositoryId' => '',
                'providerBranch' => '',
                'providerSilentMode' => false,
                'providerRootDirectory' => '',
                'providerBranches' => [],
                'providerPaths' => [],
                'providerTags' => [],
                'repositoryId' => '',
                'repositoryInternalId' => '',
            ] as $key => $value) {
                $updates->setAttribute($key, $value);
            }
        } elseif (!empty($providerRepositoryId)) {
            if (empty($installationId) || empty($providerBranch)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'When connecting to VCS (Version Control System), you need to provide "installationId" and "providerBranch".');
            }

            $installation = $this->installation($dbForPlatform, $project, $installationId);
            $vcs = $vcsFactory->fromInstallation($installation);

            if (!$vcs->supportsRepositoryArchives()) {
                throw new Exception(Exception::DISTRIBUTION_REPOSITORY_MISSING, 'This Git provider offers no repository archives, which Distribution builds from. Connect a GitHub, GitLab, Gitea or Bitbucket repository.');
            }

            [$owner, $repositoryName] = $installationRepositories->resolve($vcs, $installation, $providerRepositoryId);

            $repository = $isConnected
                ? $dbForPlatform->getDocument('repositories', $app->getAttribute('repositoryId', ''))
                : new Document();

            if (!$repository->isEmpty() && $repository->getAttribute('providerRepositoryId') === $providerRepositoryId) {
                if ($repository->getAttribute('installationInternalId') !== $installation->getSequence()) {
                    $repositoryWebhooks->ensure($vcs, $installation, $dbForPlatform, $providerRepositoryId, $owner, $repositoryName);
                    $dbForPlatform->updateDocument('repositories', $repository->getId(), new Document([
                        'installationId' => $installation->getId(),
                        'installationInternalId' => $installation->getSequence(),
                    ]));
                }
            } else {
                $repository = $this->link($dbForPlatform, $project, $app, $installation, $vcs, $repositoryWebhooks, $providerRepositoryId, $owner, $repositoryName);

                if ($isConnected) {
                    $this->unlink($dbForPlatform, $project, $app, $repository->getId());
                }
            }

            $updates
                ->setAttribute('installationId', $installation->getId())
                ->setAttribute('installationInternalId', $installation->getSequence())
                ->setAttribute('providerRepositoryId', $providerRepositoryId)
                ->setAttribute('repositoryId', $repository->getId())
                ->setAttribute('repositoryInternalId', $repository->getSequence());
        } elseif (!\is_null($installationId) && $installationId !== $app->getAttribute('installationId', '')) {
            if ($isConnected) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'To move the repository to another installation, also provide "providerRepositoryId" and "providerBranch".');
            }

            $installation = $installationId === '' ? new Document() : $this->installation($dbForPlatform, $project, $installationId);

            $updates
                ->setAttribute('installationId', $installation->getId())
                ->setAttribute('installationInternalId', $installation->getSequence() ?? '');
        }

        $app = $dbForProject->updateDocument('distributionApps', $app->getId(), $updates);

        $queueForEvents->setParam('appId', $app->getId());

        $response->dynamic($app, Response::MODEL_DISTRIBUTION_APP);
    }

    private function installation(Database $dbForPlatform, Document $project, string $installationId): Document
    {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty() || $installation->getAttribute('projectId') !== $project->getId()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        return $installation;
    }

    private function link(Database $dbForPlatform, Document $project, Document $app, Document $installation, Git $vcs, RepositoryWebhooks $repositoryWebhooks, string $providerRepositoryId, string $owner, string $repositoryName): Document
    {
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

        try {
            $repositoryWebhooks->ensure($vcs, $installation, $dbForPlatform, $providerRepositoryId, $owner, $repositoryName);
        } catch (\Throwable $error) {
            $dbForPlatform->deleteDocument('repositories', $repository->getId());
            throw $error;
        }

        return $repository;
    }

    private function unlink(Database $dbForPlatform, Document $project, Document $app, string $keep = ''): void
    {
        $queries = [
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::equal('resourceInternalId', [$app->getSequence()]),
            Query::equal('resourceType', [RESOURCE_TYPE_DISTRIBUTION_APPS]),
            Query::limit(100),
        ];

        if ($keep !== '') {
            $queries[] = Query::notEqual('$id', [$keep]);
        }

        foreach ($dbForPlatform->find('repositories', $queries) as $repository) {
            $dbForPlatform->deleteDocument('repositories', $repository->getId());
        }
    }

    private function assertTargetsSupported(Database $dbForProject, Document $app, string $framework): void
    {
        $platforms = Config::getParam('distribution')['frameworks'][$framework];

        $targets = $dbForProject->find('distributionTargets', [
            Query::equal('appInternalId', [$app->getSequence()]),
            Query::limit(\count(Platform::cases())),
        ]);

        foreach ($targets as $target) {
            $platform = $target->getAttribute('platform');

            if (!\array_key_exists($platform, $platforms)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Framework '{$framework}' does not support the app's '{$platform}' target. Delete the target first.");
            }
        }
    }
}
