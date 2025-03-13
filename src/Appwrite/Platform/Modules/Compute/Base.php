<?php

namespace Appwrite\Platform\Modules\Compute;

use Appwrite\Event\Build;
use Appwrite\Extend\Exception;
use Appwrite\Query;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Swoole\Request;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\RepositoryNotFound;

class Base extends Action
{
    public function redeployVcsFunction(Request $request, Document $function, Document $project, Document $installation, Database $dbForProject, Build $queueForBuilds, Document $template, GitHub $github, bool $activate, string $referenceType = 'branch', string $reference = ''): Document
    {
        $deploymentId = ID::unique();
        $entrypoint = $function->getAttribute('entrypoint', '');
        $providerInstallationId = $installation->getAttribute('providerInstallationId', '');
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
        $owner = $github->getOwnerName($providerInstallationId);
        $providerRepositoryId = $function->getAttribute('providerRepositoryId', '');
        try {
            $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        // TODO: Support tag and commit in future
        if ($referenceType === 'branch') {
            $providerBranch = empty($reference) ? $function->getAttribute('providerBranch', 'main') : $reference;
        }

        $authorUrl = "https://github.com/$owner";
        $repositoryUrl = "https://github.com/$owner/$repositoryName";
        $branchUrl = "https://github.com/$owner/$repositoryName/tree/$providerBranch";

        $commitDetails = [];
        if ($template->isEmpty()) {
            try {
                $commitDetails = $github->getLatestCommit($owner, $repositoryName, $providerBranch);
            } catch (\Throwable $error) {
                Console::warning('Failed to get latest commit details');
                Console::warning($error->getMessage());
                Console::warning($error->getTraceAsString());
            }
        }

        $deployment = $dbForProject->createDocument('deployments', new Document([
            '$id' => $deploymentId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceId' => $function->getId(),
            'resourceInternalId' => $function->getInternalId(),
            'resourceType' => 'functions',
            'entrypoint' => $entrypoint,
            'buildCommands' => $function->getAttribute('commands', ''),
            'type' => 'vcs',
            'installationId' => $installation->getId(),
            'installationInternalId' => $installation->getInternalId(),
            'providerRepositoryId' => $providerRepositoryId,
            'repositoryId' => $function->getAttribute('repositoryId', ''),
            'repositoryInternalId' => $function->getAttribute('repositoryInternalId', ''),
            'providerBranchUrl' => $branchUrl,
            'providerRepositoryName' => $repositoryName,
            'providerRepositoryOwner' => $owner,
            'providerRepositoryUrl' => $repositoryUrl,
            'providerCommitHash' => $commitDetails['commitHash'] ?? '',
            'providerCommitAuthorUrl' => $authorUrl,
            'providerCommitAuthor' => $commitDetails['commitAuthor'] ?? '',
            'providerCommitMessage' => mb_strimwidth($commitDetails['commitMessage'] ?? '', 0, 255, '...'),
            'providerCommitUrl' => $commitDetails['commitUrl'] ?? '',
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $function->getAttribute('providerRootDirectory', ''),
            'search' => implode(' ', [$deploymentId, $entrypoint]),
            'activate' => $activate,
        ]));

        $queueForBuilds
            ->setType(BUILD_TYPE_DEPLOYMENT)
            ->setResource($function)
            ->setDeployment($deployment)
            ->setTemplate($template);

        return $deployment;
    }

    public function redeployVcsSite(Request $request, Document $site, Document $project, Document $installation, Database $dbForProject, Database $dbForPlatform, Build $queueForBuilds, Document $template, GitHub $github, bool $activate, string $referenceType = 'branch', string $reference = ''): Document
    {
        $deploymentId = ID::unique();
        $providerInstallationId = $installation->getAttribute('providerInstallationId', '');
        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
        $owner = $github->getOwnerName($providerInstallationId);
        $providerRepositoryId = $site->getAttribute('providerRepositoryId', '');
        try {
            $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }

        $commitDetails = [];
        $branchUrl = "";
        $providerBranch = "";

        // TODO: Support tag in future
        if ($referenceType === 'branch') {
            $providerBranch = empty($reference) ? $site->getAttribute('providerBranch', 'main') : $reference;
            $branchUrl = "https://github.com/$owner/$repositoryName/tree/$providerBranch";
            try {
                $commitDetails = $github->getLatestCommit($owner, $repositoryName, $providerBranch);
            } catch (\Throwable $error) {
                // Ignore; deployment can continue
            }
        } elseif ($referenceType === 'commit') {
            try {
                $commitDetails = $github->getCommit($owner, $repositoryName, $reference);
            } catch (\Throwable $error) {
                // Ignore; deployment can continue
            }
        }

        $authorUrl = "https://github.com/$owner";
        $repositoryUrl = "https://github.com/$owner/$repositoryName";

        $commands = [];
        if (!empty($site->getAttribute('installCommand', ''))) {
            $commands[] = $site->getAttribute('installCommand', '');
        }
        if (!empty($site->getAttribute('buildCommand', ''))) {
            $commands[] = $site->getAttribute('buildCommand', '');
        }

        $deployment = $dbForProject->createDocument('deployments', new Document([
            '$id' => $deploymentId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceId' => $site->getId(),
            'resourceInternalId' => $site->getInternalId(),
            'resourceType' => 'sites',
            'buildCommands' => implode(' && ', $commands),
            'buildOutput' => $site->getAttribute('outputDirectory', ''),
            'type' => 'vcs',
            'installationId' => $installation->getId(),
            'installationInternalId' => $installation->getInternalId(),
            'providerRepositoryId' => $providerRepositoryId,
            'repositoryId' => $site->getAttribute('repositoryId', ''),
            'repositoryInternalId' => $site->getAttribute('repositoryInternalId', ''),
            'providerBranchUrl' => $branchUrl,
            'providerRepositoryName' => $repositoryName,
            'providerRepositoryOwner' => $owner,
            'providerRepositoryUrl' => $repositoryUrl,
            'providerCommitHash' => $commitDetails['commitHash'] ?? '',
            'providerCommitAuthorUrl' => $authorUrl,
            'providerCommitAuthor' => $commitDetails['commitAuthor'] ?? '',
            'providerCommitMessage' => mb_strimwidth($commitDetails['commitMessage'] ?? '', 0, 255, '...'),
            'providerCommitUrl' => $commitDetails['commitUrl'] ?? '',
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $site->getAttribute('providerRootDirectory', ''),
            'search' => implode(' ', [$deploymentId]),
            'activate' => $activate,
        ]));

        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
        $domain = ID::unique() . "." . $sitesDomain;

        // TODO: @christyjacob remove once we migrate the rules in 1.7.x
        $ruleId = System::getEnv('_APP_RULES_FORMAT') === 'md5' ? md5($domain) : ID::unique();

        Authorization::skip(
            fn () => $dbForPlatform->createDocument('rules', new Document([
                '$id' => $ruleId,
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getInternalId(),
                'domain' => $domain,
                'trigger' => 'deployment',
                'type' => 'deployment',
                'deploymentId' => $deployment->getId(),
                'deploymentInternalId' => $deployment->getInternalId(),
                'deploymentResourceType' => 'site',
                'deploymentResourceId' => $site->getId(),
                'deploymentResourceInternalId' => $site->getInternalId(),
                'deploymentVcsProviderBranch' => $providerBranch,
                'status' => 'verified',
                'certificateId' => '',
                'search' => implode(' ', [$ruleId, $domain]),
            ]))
        );

        $queueForBuilds
            ->setType(BUILD_TYPE_DEPLOYMENT)
            ->setResource($site)
            ->setDeployment($deployment)
            ->setTemplate($template);

        return $deployment;
    }

    protected function listRules(Document $project, array $queries, Database $database, callable $callback): void
    {
        $limit = 100;
        $cursor = null;

        do {
            $queries = \array_merge([
                Query::limit($limit),
                Query::equal("projectInternalId", [$project->getInternalId()])
            ], $queries);

            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $results = $database->find('rules', $queries);

            $total = \count($results);
            if ($total > 0) {
                $cursor = $results[$total - 1];
            }

            if ($total < $limit) {
                $cursor = null;
            }

            foreach ($results as $document) {
                if (is_callable($callback)) {
                    $callback($document);
                }
            }
        } while (!\is_null($cursor));
    }
}
