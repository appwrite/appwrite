<?php

namespace Appwrite\Platform\Modules\Compute;

use Appwrite\Event\Build;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Compute\Validator\Specification as SpecificationValidator;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Swoole\Request;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\RepositoryNotFound;

class Base extends Action
{
    /**
     * Get default specification based on plan and available specifications.
     *
     * @param array $plan The billing plan configuration
     * @return string The appropriate default specification
     */
    protected function getDefaultSpecification(array $plan): string
    {
        $specifications = Config::getParam('specifications', []);

        if (empty($specifications)) {
            return APP_COMPUTE_SPECIFICATION_DEFAULT;
        }

        $specificationValidator = new SpecificationValidator(
            $plan,
            $specifications,
            System::getEnv('_APP_COMPUTE_CPUS', 0),
            System::getEnv('_APP_COMPUTE_MEMORY', 0)
        );
        $allowedSpecifications = $specificationValidator->getAllowedSpecifications();

        // If there is no plan use the highest specification
        if (empty($plan)) {
            return end($allowedSpecifications) ?? APP_COMPUTE_SPECIFICATION_DEFAULT;
        }

        // Otherwise, use the lowest specification available in the plan
        return $allowedSpecifications[0] ?? APP_COMPUTE_SPECIFICATION_DEFAULT;
    }

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

        $commitDetails = [];
        $branchUrl = "";
        $providerBranch = "";

        // TODO: Support tag in future
        if ($referenceType === 'branch') {
            $providerBranch = empty($reference) ? $function->getAttribute('providerBranch', 'main') : $reference;
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

        $repositoryUrl = "https://github.com/$owner/$repositoryName";

        $deployment = $dbForProject->createDocument('deployments', new Document([
            '$id' => $deploymentId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceId' => $function->getId(),
            'resourceInternalId' => $function->getSequence(),
            'resourceType' => 'functions',
            'entrypoint' => $entrypoint,
            'buildCommands' => $function->getAttribute('commands', ''),
            'type' => 'vcs',
            'installationId' => $installation->getId(),
            'installationInternalId' => $installation->getSequence(),
            'providerRepositoryId' => $providerRepositoryId,
            'repositoryId' => $function->getAttribute('repositoryId', ''),
            'repositoryInternalId' => $function->getAttribute('repositoryInternalId', ''),
            'providerBranchUrl' => $branchUrl,
            'providerRepositoryName' => $repositoryName,
            'providerRepositoryOwner' => $owner,
            'providerRepositoryUrl' => $repositoryUrl,
            'providerCommitHash' => $commitDetails['commitHash'] ?? '',
            'providerCommitAuthorUrl' => $commitDetails['commitAuthorUrl'] ?? '',
            'providerCommitAuthor' => $commitDetails['commitAuthor'] ?? '',
            'providerCommitMessage' => mb_strimwidth($commitDetails['commitMessage'] ?? '', 0, 255, '...'),
            'providerCommitUrl' => $commitDetails['commitUrl'] ?? '',
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $function->getAttribute('providerRootDirectory', ''),
            'activate' => $activate,
        ]));

        $function = $function
            ->setAttribute('latestDeploymentId', $deployment->getId())
            ->setAttribute('latestDeploymentInternalId', $deployment->getSequence())
            ->setAttribute('latestDeploymentCreatedAt', $deployment->getCreatedAt())
            ->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
        $dbForProject->updateDocument('functions', $function->getId(), $function);

        $queueForBuilds
            ->setType(BUILD_TYPE_DEPLOYMENT)
            ->setResource($function)
            ->setDeployment($deployment)
            ->setTemplate($template);

        return $deployment;
    }

    public function redeployVcsSite(Request $request, Document $site, Document $project, Document $installation, Database $dbForProject, Database $dbForPlatform, Build $queueForBuilds, Document $template, GitHub $github, bool $activate, Authorization $authorization, string $referenceType = 'branch', string $reference = ''): Document
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
            'resourceInternalId' => $site->getSequence(),
            'resourceType' => 'sites',
            'buildCommands' => implode(' && ', $commands),
            'buildOutput' => $site->getAttribute('outputDirectory', ''),
            'adapter' => $site->getAttribute('adapter', ''),
            'fallbackFile' => $site->getAttribute('fallbackFile', ''),
            'type' => 'vcs',
            'installationId' => $installation->getId(),
            'installationInternalId' => $installation->getSequence(),
            'providerRepositoryId' => $providerRepositoryId,
            'repositoryId' => $site->getAttribute('repositoryId', ''),
            'repositoryInternalId' => $site->getAttribute('repositoryInternalId', ''),
            'providerBranchUrl' => $branchUrl,
            'providerRepositoryName' => $repositoryName,
            'providerRepositoryOwner' => $owner,
            'providerRepositoryUrl' => $repositoryUrl,
            'providerCommitHash' => $commitDetails['commitHash'] ?? '',
            'providerCommitAuthorUrl' => $commitDetails['commitAuthorUrl'] ?? '',
            'providerCommitAuthor' => $commitDetails['commitAuthor'] ?? '',
            'providerCommitMessage' => mb_strimwidth($commitDetails['commitMessage'] ?? '', 0, 255, '...'),
            'providerCommitUrl' => $commitDetails['commitUrl'] ?? '',
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $site->getAttribute('providerRootDirectory', ''),
            'activate' => $activate,
        ]));

        $site = $site
            ->setAttribute('latestDeploymentId', $deployment->getId())
            ->setAttribute('latestDeploymentInternalId', $deployment->getSequence())
            ->setAttribute('latestDeploymentCreatedAt', $deployment->getCreatedAt())
            ->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
        $dbForProject->updateDocument('sites', $site->getId(), $site);

        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
        $domain = ID::unique() . "." . $sitesDomain;

        // TODO: (@Meldiron) Remove after 1.7.x migration
        $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
        $ruleId = $isMd5 ? md5($domain) : ID::unique();

        $authorization->skip(
            fn () => $dbForPlatform->createDocument('rules', new Document([
                '$id' => $ruleId,
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getSequence(),
                'domain' => $domain,
                'trigger' => 'deployment',
                'type' => 'deployment',
                'deploymentId' => $deployment->getId(),
                'deploymentInternalId' => $deployment->getSequence(),
                'deploymentResourceType' => 'site',
                'deploymentResourceId' => $site->getId(),
                'deploymentResourceInternalId' => $site->getSequence(),
                'deploymentVcsProviderBranch' => $providerBranch,
                'status' => 'verified',
                'certificateId' => '',
                'search' => implode(' ', [$ruleId, $domain]),
                'owner' => 'Appwrite',
                'region' => $project->getAttribute('region')
            ]))
        );

        if (!empty($commitDetails['commitHash'])) {
            $domain = "commit-" . substr($commitDetails['commitHash'], 0, 16) . ".{$sitesDomain}";
            $ruleId = md5($domain);
            try {
                $authorization->skip(
                    fn () => $dbForPlatform->createDocument('rules', new Document([
                        '$id' => $ruleId,
                        'projectId' => $project->getId(),
                        'projectInternalId' => $project->getSequence(),
                        'domain' => $domain,
                        'type' => 'deployment',
                        'trigger' => 'deployment',
                        'deploymentId' => $deployment->getId(),
                        'deploymentInternalId' => $deployment->getSequence(),
                        'deploymentResourceType' => 'site',
                        'deploymentResourceId' => $site->getId(),
                        'deploymentResourceInternalId' => $site->getSequence(),
                        'deploymentVcsProviderBranch' => $providerBranch,
                        'status' => 'verified',
                        'certificateId' => '',
                        'search' => implode(' ', [$ruleId, $domain]),
                        'owner' => 'Appwrite',
                        'region' => $project->getAttribute('region')
                    ]))
                );
            } catch (Duplicate $err) {
                // Ignore, rule already exists; will be updated by builds worker
            }
        }

        // VCS branch preview
        if (!empty($providerBranch)) {
            $branchPrefix = substr($providerBranch, 0, 16);
            if (strlen($providerBranch) > 16) {
                $remainingChars = substr($providerBranch, 16);
                $branchPrefix .= '-' . substr(hash('sha256', $remainingChars), 0, 7);
            }
            $resourceProjectHash = substr(hash('sha256', $site->getId() . $project->getId()), 0, 7);
            $domain = "branch-{$branchPrefix}-{$resourceProjectHash}.{$sitesDomain}";
            $ruleId = md5($domain);
            try {
                $authorization->skip(
                    fn () => $dbForPlatform->createDocument('rules', new Document([
                        '$id' => $ruleId,
                        'projectId' => $project->getId(),
                        'projectInternalId' => $project->getSequence(),
                        'domain' => $domain,
                        'type' => 'deployment',
                        'trigger' => 'deployment',
                        'deploymentId' => $deployment->getId(),
                        'deploymentInternalId' => $deployment->getSequence(),
                        'deploymentResourceType' => 'site',
                        'deploymentResourceId' => $site->getId(),
                        'deploymentResourceInternalId' => $site->getSequence(),
                        'deploymentVcsProviderBranch' => $providerBranch,
                        'status' => 'verified',
                        'certificateId' => '',
                        'search' => implode(' ', [$ruleId, $domain]),
                        'owner' => 'Appwrite',
                        'region' => $project->getAttribute('region')
                    ]))
                );
            } catch (Duplicate $err) {
                // Ignore, rule already exists; will be updated by builds worker
            }
        }

        $this->updateEmptyManualRule($project, $site, $deployment, $dbForPlatform, $authorization);

        $queueForBuilds
            ->setType(BUILD_TYPE_DEPLOYMENT)
            ->setResource($site)
            ->setDeployment($deployment)
            ->setTemplate($template);

        return $deployment;
    }

    /**
     * Update empty manual rule for deployment.
     * In case of first deployment, deployment ID will be empty in the rules, so we need to update it here.
     *
     * @param \Utopia\Database\Document $project
     * @param \Utopia\Database\Document $resource
     * @param \Utopia\Database\Document $deployment
     * @param \Utopia\Database\Database $dbForPlatform
     * @return void
     */
    public static function updateEmptyManualRule(Document $project, Document $resource, Document $deployment, Database $dbForPlatform, Authorization $authorization)
    {
        $resourceType = $resource->getCollection() === 'sites' ? 'site' : 'function';

        $queries = [
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::equal('deploymentResourceInternalId', [$resource->getSequence()]),
            Query::equal('deploymentResourceType', [$resourceType]),
            Query::equal('deploymentId', ['']),
            Query::equal('type', ['deployment']),
            Query::equal('trigger', ['manual']),
        ];
        $dbForPlatform->forEach('rules', function (Document $rule) use ($deployment, $dbForPlatform, $authorization) {
            $authorization->skip(fn () => $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                'deploymentId' => $deployment->getId(),
                'deploymentInternalId' => $deployment->getSequence(),
            ])));
        }, $queries);
    }
}
