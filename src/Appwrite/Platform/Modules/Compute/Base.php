<?php

namespace Appwrite\Platform\Modules\Compute;

use Appwrite\Event\Build;
use Appwrite\Extend\Exception;
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
    public function redeployVcsFunction(Request $request, Document $function, Document $project, Document $installation, Database $dbForProject, Build $queueForBuilds, Document $template, GitHub $github)
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
        $providerBranch = $function->getAttribute('providerBranch', 'main');
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
            'commands' => $function->getAttribute('commands', ''),
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
            'providerCommitAuthorAvatar' => $commitDetails['commitAuthorAvatar'] ?? '',
            'providerCommitAuthor' => $commitDetails['commitAuthor'] ?? '',
            'providerCommitMessage' => $commitDetails['commitMessage'] ?? '',
            'providerCommitUrl' => $commitDetails['commitUrl'] ?? '',
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $function->getAttribute('providerRootDirectory', ''),
            'search' => implode(' ', [$deploymentId, $entrypoint]),
            'activate' => true,
        ]));

        $queueForBuilds
            ->setType(BUILD_TYPE_DEPLOYMENT)
            ->setResource($function)
            ->setDeployment($deployment)
            ->setTemplate($template);
    }

    public function redeployVcsSite(Request $request, Document $site, Document $project, Document $installation, Database $dbForProject, Database $dbForConsole, Build $queueForBuilds, Document $template, GitHub $github)
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

        $providerBranch = $site->getAttribute('providerBranch', 'main');
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
            'resourceId' => $site->getId(),
            'resourceInternalId' => $site->getInternalId(),
            'resourceType' => 'sites',
            'buildCommand' => $site->getAttribute('buildCommand', ''),
            'installCommand' => $site->getAttribute('installCommand', ''),
            'outputDirectory' => $site->getAttribute('outputDirectory', ''),
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
            'providerCommitAuthorAvatar' => $commitDetails['commitAuthorAvatar'] ?? '',
            'providerCommitAuthor' => $commitDetails['commitAuthor'] ?? '',
            'providerCommitMessage' => $commitDetails['commitMessage'] ?? '',
            'providerCommitUrl' => $commitDetails['commitUrl'] ?? '',
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $site->getAttribute('providerRootDirectory', ''),
            'search' => implode(' ', [$deploymentId]),
            'activate' => true,
        ]));

        // Preview deployments for sites
        $projectId = $project->getId();

        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
        $domain = "{$deploymentId}-{$projectId}.{$sitesDomain}";
        $ruleId = md5($domain);

        $rule = Authorization::skip(
            fn () => $dbForConsole->createDocument('rules', new Document([
                '$id' => $ruleId,
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getInternalId(),
                'domain' => $domain,
                'resourceType' => 'deployment',
                'resourceId' => $deploymentId,
                'resourceInternalId' => $deployment->getInternalId(),
                'status' => 'verified',
                'certificateId' => '',
            ]))
        );

        $queueForBuilds
            ->setType(BUILD_TYPE_DEPLOYMENT)
            ->setResource($site)
            ->setDeployment($deployment)
            ->setTemplate($template);
    }
}
