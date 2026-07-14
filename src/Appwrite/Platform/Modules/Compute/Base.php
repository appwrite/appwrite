<?php

namespace Appwrite\Platform\Modules\Compute;

use Appwrite\Compute\Job;
use Appwrite\Event\Message\Build as BuildMessage;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Filter\BranchDomain as BranchDomainFilter;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Compute\Validator\Specification as SpecificationValidator;
use Appwrite\Platform\Permission as AppwritePermission;
use OpenRuntimes\Orchestrator\Jobs;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\RepositoryNotFound;

class Base extends Action
{
    use AppwritePermission;

    /**
     * Get default specification based on plan and available specifications.
     *
     * @param array $plan The billing plan configuration
     * @return string The appropriate default specification
     */
    protected function getDefaultSpecification(array $plan, string $planKey = 'runtimeSpecifications', string $fallback = APP_COMPUTE_SPECIFICATION_DEFAULT, bool $preferFallback = false): string
    {
        $specifications = Config::getParam('specifications', []);

        if (empty($specifications)) {
            return $fallback;
        }

        $specificationValidator = new SpecificationValidator(
            $plan,
            $specifications,
            System::getEnv('_APP_COMPUTE_CPUS', 0),
            System::getEnv('_APP_COMPUTE_MEMORY', 0),
            $planKey
        );
        $allowedSpecifications = $specificationValidator->getAllowedSpecifications();

        if (empty($allowedSpecifications)) {
            return $fallback;
        }

        if ($preferFallback && !array_key_exists($planKey, $plan) && \in_array($fallback, $allowedSpecifications)) {
            return $fallback;
        }

        // If there is no plan use the highest specification
        if (empty($plan)) {
            return end($allowedSpecifications);
        }

        // Otherwise, use the lowest specification available in the plan
        return $allowedSpecifications[0];
    }

    public function redeployVcsFunction(Request $request, Document $function, Document $project, Document $installation, Database $dbForProject, BuildPublisher $publisherForBuilds, Document $template, Git $vcs, bool $activate, array $platform = [], string $referenceType = 'branch', string $reference = '', ?Jobs $jobs = null): Document
    {
        $deploymentId = ID::unique();
        $entrypoint = $function->getAttribute('entrypoint', '');
        $providerInstallationId = $installation->getAttribute('providerInstallationId', '');
        if (empty($providerInstallationId)) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }
        $owner = $vcs->getOwnerName($providerInstallationId);
        $providerRepositoryId = $function->getAttribute('providerRepositoryId', '');
        try {
            $repositoryName = $vcs->getRepositoryName($providerRepositoryId);
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
            $branchUrl = $vcs->getBranchUrl($owner, $repositoryName, $providerBranch);
            try {
                $commitDetails = $vcs->getLatestCommit($owner, $repositoryName, $providerBranch);
            } catch (\Throwable $error) {
                // Ignore; deployment can continue
            }
        } elseif ($referenceType === 'commit') {
            try {
                $commitDetails = $vcs->getCommit($owner, $repositoryName, $reference);
            } catch (\Throwable $error) {
                // Ignore; deployment can continue
            }
        } else {
            // Fallback till we have tag support here
            // Goal is to set providerBranch, so build worker knows what to clone as base
            // Without this, clone command would be cloning empty branch, and failing
            $providerBranch = $function->getAttribute('providerBranch', 'main');
            $branchUrl = $vcs->getBranchUrl($owner, $repositoryName, $providerBranch);
        }

        $repositoryUrl = $vcs->getRepositoryUrl($owner, $repositoryName);

        // Build a plain (non-template) VCS function deployment on the jobs-service
        // when the caller opts in ($jobs) and _APP_BUILDS_BACKEND=orchestrator.
        // Sites stay on the executor; template-into-repo pushes go through the
        // Builds worker (which does the git write, then hands the build to the
        // jobs-service itself when on orchestrator). When on jobs the deployment
        // is pre-declared 'waiting' with its buildPath so build.sh writes output
        // onto the mounted volume.
        $useJobs = $jobs !== null
            && $function->getCollection() === 'functions'
            && $template->isEmpty()
            && System::getEnv('_APP_BUILDS_BACKEND', 'executor') === 'orchestrator';
        $buildFields = $useJobs
            ? ['status' => 'waiting', 'buildPath' => Job::buildPath($project->getId(), $deploymentId)]
            : [];

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
            'startCommand' => $function->getAttribute('startCommand', ''),
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
            ...$buildFields,
        ]));

        if ($useJobs) {
            $ref = $deployment->getAttribute('providerCommitHash') ?: $deployment->getAttribute('providerBranch');
            $presignedUrl = $vcs->getRepositoryPresignedUrl($owner, $repositoryName, $ref);
            $source = [
                'url' => $presignedUrl,
                'subdir' => Job::sourceSubdirectory($vcs, $repositoryName, $function->getAttribute('providerRootDirectory', '')),
            ];

            // TODO: Temporary diagnostic for the intermittent Gitea "No source
            // code found" CI failure -- verifies the sidecar's exact source URL
            // resolves at submission time, since the sidecar's own logs aren't
            // captured by `docker compose logs`. Remove once root-caused.
            $this->probeSourceUrl($presignedUrl, $deployment->getId());

            $jobs->create(...Job::build($project, $function, $deployment, $platform, $source));
        } else {
            $publisherForBuilds->enqueue(new BuildMessage(
                project: $project,
                resource: $function,
                deployment: $deployment,
                type: BUILD_TYPE_DEPLOYMENT,
                template: $template,
                platform: $platform,
            ));
        }

        return $deployment;
    }

    /**
     * TODO: Temporary diagnostic for the intermittent Gitea "No source code
     * found" CI failure. Fetches the presigned source URL with the same GET
     * the sidecar performs and logs the outcome, so a failing CI run's
     * "Failure Logs" step (docker compose logs) surfaces the real HTTP
     * status/size instead of relying on the sidecar's own unretrievable logs.
     * Never throws -- purely observational. Remove once root-caused.
     */
    private function probeSourceUrl(string $url, string $deploymentId): void
    {
        try {
            $redactedUrl = \preg_replace('/([?&]token=)[^&]+/', '$1REDACTED', $url);

            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $body = \curl_exec($ch);
            $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = \curl_error($ch);
            \curl_close($ch);

            $size = $body === false ? 0 : \strlen($body);
            Console::warning("[vcs-source-probe] deployment={$deploymentId} url={$redactedUrl} status={$statusCode} bytes={$size} curlError=" . ($error ?: 'none'));
        } catch (\Throwable $error) {
            Console::warning("[vcs-source-probe] deployment={$deploymentId} probe threw: " . $error->getMessage());
        }
    }

    public function redeployVcsSite(Request $request, Document $site, Document $project, Document $installation, Database $dbForProject, Database $dbForPlatform, BuildPublisher $publisherForBuilds, Document $template, Git $vcs, bool $activate, Authorization $authorization, array $platform, string $referenceType = 'branch', string $reference = ''): Document
    {
        $deploymentId = ID::unique();
        $providerInstallationId = $installation->getAttribute('providerInstallationId', '');
        if (empty($providerInstallationId)) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }
        $owner = $vcs->getOwnerName($providerInstallationId);
        $providerRepositoryId = $site->getAttribute('providerRepositoryId', '');
        try {
            $repositoryName = $vcs->getRepositoryName($providerRepositoryId);
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
            $branchUrl = $vcs->getBranchUrl($owner, $repositoryName, $providerBranch);
            try {
                $commitDetails = $vcs->getLatestCommit($owner, $repositoryName, $providerBranch);
            } catch (\Throwable $error) {
                // Ignore; deployment can continue
            }
        } elseif ($referenceType === 'commit') {
            try {
                $commitDetails = $vcs->getCommit($owner, $repositoryName, $reference);
            } catch (\Throwable $error) {
                // Ignore; deployment can continue
            }
        } else {
            // Fallback till we have tag support here
            // Goal is to set providerBranch, so build worker knows what to clone as base
            // Without this, clone command would be cloning empty branch, and failing
            $providerBranch = $site->getAttribute('providerBranch', 'main');
            $branchUrl = $vcs->getBranchUrl($owner, $repositoryName, $providerBranch);
        }

        $repositoryUrl = $vcs->getRepositoryUrl($owner, $repositoryName);

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
            'startCommand' => $site->getAttribute('startCommand', ''),
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

        $sitesDomain = $platform['sitesDomain'];
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
            $domain = (new BranchDomainFilter())->apply([
                'branch' => $providerBranch,
                'resourceId' => $site->getId(),
                'projectId' => $project->getId(),
                'sitesDomain' => $sitesDomain,
            ]);
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

        $publisherForBuilds->enqueue(new BuildMessage(
            project: $project,
            resource: $site,
            deployment: $deployment,
            type: BUILD_TYPE_DEPLOYMENT,
            template: $template,
            platform: $platform,
        ));

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
