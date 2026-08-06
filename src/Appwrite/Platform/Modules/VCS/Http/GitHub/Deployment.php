<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub;

use Appwrite\Extend\Exception;
use Appwrite\Filter\BranchDomain as BranchDomainFilter;
use Appwrite\Vcs\CheckRuns;
use Appwrite\Vcs\Comment;
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
use Utopia\DSN\DSN;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Validator\Contains;
use Utopia\Validator\Globstar;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\RepositoryNotFound;

trait Deployment
{
    protected function createGitDeployments(
        Git $vcs,
        string $providerInstallationId,
        array $repositories,
        string $providerBranch,
        string $providerBranchUrl,
        string $providerRepositoryName,
        string $providerRepositoryUrl,
        string $providerRepositoryOwner,
        string $providerCommitHash,
        string $providerCommitAuthor,
        string $providerCommitAuthorUrl,
        string $providerCommitMessage,
        string $providerCommitUrl,
        string $providerPullRequestId,
        array $providerAffectedFiles,
        bool $external,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        array $platform,
        callable $deploymentsFactory,
    ) {
        $errors = [];
        $provider = $vcs->getName();

        $resolved = [];
        $resolveOwnerAndName = function (string $providerRepositoryId) use ($vcs, $providerInstallationId, &$resolved): array {
            if (isset($resolved[$providerRepositoryId])) {
                return $resolved[$providerRepositoryId];
            }

            // Owner first: getRepositoryName reports an unreadable response as
            // RepositoryNotFound, which the loop skips as a 404 instead of retrying.
            $owner = $vcs->getOwnerName($providerInstallationId, (int) $providerRepositoryId);

            try {
                $repositoryName = $vcs->getRepositoryName($providerRepositoryId);
            } catch (RepositoryNotFound $e) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }

            return $resolved[$providerRepositoryId] = [$owner, $repositoryName];
        };

        $checkRuns = new CheckRuns();

        $reportSkip = function (Document $resource, Document $project, Document $repository, Database $dbForProject, string $resourceCollection, string $reason) use ($checkRuns, $vcs, $resolveOwnerAndName, $authorization, $providerCommitHash, $providerBranch, $providerPullRequestId, $external, $platform): void {
            if (empty($providerCommitHash) || $resource->getAttribute('providerSilentMode', false) === true) {
                return;
            }

            // A push event already reported this skip; a fork raises no push.
            if (!empty($providerPullRequestId) && !$external) {
                return;
            }

            // Only refs/heads is stripped from the payload, so a tag arrives whole.
            // It never matches a branch trigger, and saying so would be nonsense.
            if (\str_starts_with($providerBranch, 'refs/')) {
                return;
            }

            // This reports under the same context a build does, and a provider keeps
            // only the latest per context — so never speak for a commit that already
            // built, or pushing it to a second branch would overwrite its verdict.
            $built = $authorization->skip(fn () => $dbForProject->findOne('deployments', [
                Query::equal('resourceInternalId', [$resource->getSequence()]),
                Query::equal('resourceType', [$resourceCollection]),
                Query::equal('providerCommitHash', [$providerCommitHash]),
            ]));

            if (!$built->isEmpty()) {
                return;
            }

            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
            $hostname = $platform['consoleHostname'] ?? '';
            $region = $project->getAttribute('region', 'default');
            $collection = $resource->getCollection();
            $type = $collection === 'sites' ? 'site' : 'function';
            $targetUrl = "{$protocol}://{$hostname}/console/project-{$region}-{$project->getId()}/{$collection}/{$type}-{$resource->getId()}";
            $name = $resource->getAttribute('name') . ' (' . $project->getAttribute('name') . ')';

            try {
                [$owner, $repositoryName] = $resolveOwnerAndName($repository->getAttribute('providerRepositoryId'));

                // Neutral is in branch protection's passing set; failure is not.
                if ($checkRuns->conclude($vcs, $owner, $repositoryName, $providerCommitHash, $name, CheckRuns::CONCLUSION_NEUTRAL, 'Deployment skipped', $reason, $targetUrl)) {
                    return;
                }

                $vcs->updateCommitStatus($repositoryName, $providerCommitHash, $owner, 'success', $reason, $targetUrl, $name);
            } catch (\Throwable $e) {
                Console::warning("Failed to report a skipped deployment on repository '{$repository->getId()}': " . $e->getMessage());
            }
        };

        foreach ($repositories as $repository) {
            $logBase = "vcs.{$provider}.event.repo.unknown";

            try {
                $repositoryId = $repository->getId();
                $projectId = $repository->getAttribute('projectId');
                $resourceId = $repository->getAttribute('resourceId');
                $resourceType = $repository->getAttribute('resourceType');

                $logBase = "vcs.{$provider}.event.repo.{$repositoryId}";
                Span::add('project.id', $projectId);
                Span::add("{$logBase}.resource.id", $resourceId);
                Span::add("{$logBase}.resource.type", $resourceType);

                if ($resourceType !== "function" && $resourceType !== "site") {
                    continue;
                }

                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

                if ($project->isEmpty()) {
                    throw new Exception(Exception::PROJECT_NOT_FOUND, 'Repository references non-existent project');
                }

                $this->beforeCreateGitDeployment($project, $repository, $dbForPlatform, $authorization);

                try {
                    $dsn = new DSN($project->getAttribute('database'));
                    $databaseName = $dsn->getHost();
                } catch (\InvalidArgumentException) {
                    $databaseName = $project->getAttribute('database');
                }

                $databases = Config::getParam('pools-database', []);
                $index = in_array($databaseName, $databases);

                if ($index === false) {
                    Console::error("Database: '{$databaseName}' is not part of region: " . System::getEnv('_APP_REGION'));
                    continue;
                }

                $dbForProject = $getProjectDB($project);
                $resourceCollection = $resourceType === "function" ? 'functions' : 'sites';
                $resource = $authorization->skip(fn () => $dbForProject->getDocument($resourceCollection, $resourceId));

                if ($resource->isEmpty()) {
                    throw new Exception($resourceType === 'function' ? Exception::FUNCTION_NOT_FOUND : Exception::SITE_NOT_FOUND, 'Repository references non-existent ' . $resourceType);
                }

                $resourceInternalId = $resource->getSequence();

                $validator = new Contains(VCS_DEPLOYMENT_SKIP_PATTERNS);
                if ($validator->isValid($providerCommitMessage)) {
                    Span::add("{$logBase}.build.skipped.reason", $validator->getDescription());
                    Span::add("{$logBase}.build.skipped", 'true');
                    $reportSkip($resource, $project, $repository, $dbForProject, $resourceCollection, 'Skipped: the commit message contains ' . \implode(' or ', VCS_DEPLOYMENT_SKIP_PATTERNS) . '.');
                    continue;
                }

                // Skip deployments when the branch or affected files do not match configured build triggers.
                $branchTrigger = new Globstar($resource->getAttribute('providerBranches', []));
                if (!$branchTrigger->isValid($providerBranch)) {
                    Span::add("{$logBase}.build.skipped.reason", 'branch');
                    Span::add("{$logBase}.build.skipped", 'true');
                    $reportSkip($resource, $project, $repository, $dbForProject, $resourceCollection, "Skipped: branch '" . \mb_strimwidth($providerBranch, 0, 60, '...') . "' does not match the configured branch triggers.");
                    continue;
                }

                $providerPaths = $resource->getAttribute('providerPaths', []);
                if (!empty($providerPaths) && !empty($providerAffectedFiles)) {
                    $pathTrigger = new Globstar($providerPaths);
                    $pathMatched = false;
                    foreach ($providerAffectedFiles as $file) {
                        if ($pathTrigger->isValid($file)) {
                            $pathMatched = true;
                            break;
                        }
                    }

                    if (!$pathMatched) {
                        Span::add("{$logBase}.build.skipped.reason", 'path');
                        Span::add("{$logBase}.build.skipped", 'true');
                        $reportSkip($resource, $project, $repository, $dbForProject, $resourceCollection, 'Skipped: no changed file matches the configured path filters.');
                        continue;
                    }
                }

                $deploymentId = ID::unique();
                $repositoryId = $repository->getId();
                $repositoryInternalId = $repository->getSequence();
                $providerRepositoryId = $repository->getAttribute('providerRepositoryId');
                $installationId = $repository->getAttribute('installationId');
                $installationInternalId = $repository->getAttribute('installationInternalId');
                $productionBranch = $resource->getAttribute('providerBranch');
                $activate = false;

                if ($providerBranch == $productionBranch && $external === false) {
                    $activate = true;
                }

                [$owner, $repositoryName] = $resolveOwnerAndName($providerRepositoryId);

                $isAuthorized = !$external;

                if (!$isAuthorized && !empty($providerPullRequestId)) {
                    if (\in_array($providerPullRequestId, $repository->getAttribute('providerPullRequestIds', []))) {
                        $isAuthorized = true;
                    }
                }

                Span::add("{$logBase}.authorized", $isAuthorized);

                $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
                $hostname = $platform['consoleHostname'] ?? '';

                $authorizeUrl = $protocol . '://' . $hostname . "/console/git/authorize-contributor?projectId={$projectId}&installationId={$installationId}&repositoryId={$repositoryId}&providerPullRequestId={$providerPullRequestId}";

                $action = $isAuthorized ? ['type' => 'logs'] : ['type' => 'authorize', 'url' => $authorizeUrl];

                $commentStatus = 'waiting';
                $commentPreviewUrl = '';

                // If this action was triggered by pull request, use most up to date details in comment
                if (!empty($providerPullRequestId)) {
                    $existingDeployment = $authorization->skip(fn () => $dbForProject->findOne('deployments', [
                        Query::equal('resourceInternalId', [$resource->getSequence()]),
                        Query::equal('resourceType', [$resourceCollection]),
                        Query::equal('providerCommitHash', [$providerCommitHash]),
                        Query::equal('providerBranch', [$providerBranch]),
                        Query::orderDesc('$createdAt')
                    ]));

                    $commentStatus = $existingDeployment->getAttribute('status', 'waiting');

                    if ($resource->getCollection() === 'sites') {
                        $previewRule = $authorization->skip(fn () => $dbForPlatform->findOne('rules', [
                            Query::equal('projectInternalId', [$project->getSequence()]),
                            Query::equal('type', ['deployment']), // Not redirect
                            Query::equal('trigger', ['deployment']), // Preview - Not manual
                            Query::equal('deploymentResourceType', ['site']), // Not function
                            Query::equal('deploymentInternalId', [$existingDeployment->getSequence()]),
                        ]));

                        $commentPreviewUrl = !$previewRule->isEmpty() ? ("{$protocol}://" . $previewRule->getAttribute('domain', '')) : '';
                    }
                }

                $latestCommentId = '';

                if (!empty($providerPullRequestId) && $resource->getAttribute('providerSilentMode', false) === false) {
                    $latestComment = $authorization->skip(fn () => $dbForPlatform->findOne('vcsComments', [
                        Query::equal('providerRepositoryId', [$providerRepositoryId]),
                        Query::equal('providerPullRequestId', [$providerPullRequestId]),
                        Query::orderDesc('$createdAt'),
                    ]));

                    if (!$latestComment->isEmpty()) {
                        $latestCommentId = $latestComment->getAttribute('providerCommentId', '');

                        $retries = 0;
                        $lockAcquired = false;

                        while ($retries < 9) {
                            $retries++;

                            try {
                                $dbForPlatform->createDocument('vcsCommentLocks', new Document([
                                    '$id' => $latestCommentId
                                ]));
                                $lockAcquired = true;
                                break;
                            } catch (\Throwable $err) {
                                if ($retries >= 9) {
                                    Console::warning("Error creating vcs comment lock for " . $latestCommentId . ": " . $err->getMessage());
                                }

                                \sleep(1);
                            }
                        }

                        if ($lockAcquired) {
                            // Wrap in try/finally to ensure lock file gets deleted
                            try {
                                $comment = new Comment($platform);
                                $comment->parseComment($vcs->getComment($owner, $repositoryName, $latestCommentId));
                                $comment->addBuild($project, $resource, $resourceType, $commentStatus, $deploymentId, $action, $commentPreviewUrl);

                                $latestCommentId = \strval($vcs->updateComment($owner, $repositoryName, $latestCommentId, $comment->generateComment()));
                            } catch (\Throwable $e) {
                                Console::warning("Failed to update PR comment '{$latestCommentId}': " . $e->getMessage());
                            } finally {
                                $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $latestCommentId));
                            }
                        }
                    } else {
                        $comment = new Comment($platform);
                        $comment->addBuild($project, $resource, $resourceType, $commentStatus, $deploymentId, $action, $commentPreviewUrl);
                        $latestCommentId = \strval($vcs->createComment($owner, $repositoryName, $providerPullRequestId, $comment->generateComment()));

                        if (!empty($latestCommentId)) {
                            $teamId = $project->getAttribute('teamId', '');

                            $latestComment = $authorization->skip(fn () => $dbForPlatform->createDocument('vcsComments', new Document([
                                '$id' => ID::unique(),
                                '$permissions' => [
                                    Permission::read(Role::team(ID::custom($teamId))),
                                    Permission::update(Role::team(ID::custom($teamId), 'owner')),
                                    Permission::update(Role::team(ID::custom($teamId), 'developer')),
                                    Permission::delete(Role::team(ID::custom($teamId), 'owner')),
                                    Permission::delete(Role::team(ID::custom($teamId), 'developer')),
                                ],
                                'installationInternalId' => $installationInternalId,
                                'installationId' => $installationId,
                                'projectInternalId' => $project->getSequence(),
                                'projectId' => $project->getId(),
                                'providerRepositoryId' => $providerRepositoryId,
                                'providerBranch' => $providerBranch,
                                'providerPullRequestId' => $providerPullRequestId,
                                'providerCommentId' => $latestCommentId
                            ])));
                        }
                    }
                } elseif (!empty($providerBranch)) {
                    $latestComments = $authorization->skip(fn () => $dbForPlatform->find('vcsComments', [
                        Query::equal('providerRepositoryId', [$providerRepositoryId]),
                        Query::equal('providerBranch', [$providerBranch]),
                        Query::orderDesc('$createdAt'),
                    ]));

                    foreach ($latestComments as $comment) {
                        $latestCommentId = $comment->getAttribute('providerCommentId', '');

                        $retries = 0;
                        $lockAcquired = false;

                        while ($retries < 9) {
                            $retries++;

                            try {
                                $dbForPlatform->createDocument('vcsCommentLocks', new Document([
                                    '$id' => $latestCommentId
                                ]));
                                $lockAcquired = true;
                                break;
                            } catch (\Throwable $err) {
                                if ($retries >= 9) {
                                    Console::warning("Error creating vcs comment lock for " . $latestCommentId . ": " . $err->getMessage());
                                }

                                \sleep(1);
                            }
                        }

                        if ($lockAcquired) {
                            // Wrap in try/finally to ensure lock file gets deleted
                            try {
                                $comment = new Comment($platform);
                                $comment->parseComment($vcs->getComment($owner, $repositoryName, $latestCommentId));
                                $comment->addBuild($project, $resource, $resourceType, $commentStatus, $deploymentId, $action, '');

                                $latestCommentId = \strval($vcs->updateComment($owner, $repositoryName, $latestCommentId, $comment->generateComment()));
                            } catch (\Throwable $e) {
                                Console::warning("Failed to update PR comment '{$latestCommentId}': " . $e->getMessage());
                            } finally {
                                $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $latestCommentId));
                            }
                        }
                    }
                }

                if (!$isAuthorized) {
                    if (!empty($providerCommitHash) && $resource->getAttribute('providerSilentMode', false) === false) {
                        $resourceName = $resource->getAttribute('name');
                        $projectName = $project->getAttribute('name');
                        $name = "{$resourceName} ({$projectName})";
                        $message = 'Authorization required: a maintainer must approve this external contribution.';

                        try {
                            if (!$checkRuns->conclude($vcs, $owner, $repositoryName, $providerCommitHash, $name, CheckRuns::CONCLUSION_ACTION_REQUIRED, 'Authorization required', $message, $authorizeUrl)) {
                                $vcs->updateCommitStatus($repositoryName, $providerCommitHash, $owner, 'failure', $message, $authorizeUrl, $name);
                            }
                        } catch (\Throwable $e) {
                            Console::warning("Failed to report required authorization on repository '{$repository->getId()}': " . $e->getMessage());
                        }
                    }

                    continue;
                }

                if (!empty($providerPullRequestId)) {
                    // Update comment ID so running build can update comment
                    $authorization->skip(fn () => $dbForProject->updateDocuments('deployments', new Document([
                        'providerCommentId' => \strval($latestCommentId)
                    ]), [
                        Query::equal('resourceInternalId', [$resourceInternalId]),
                        Query::equal('resourceType', [$resourceCollection]),
                        Query::equal('providerCommitHash', [$providerCommitHash]),
                        Query::equal('providerBranch', [$providerBranch]),
                    ]));

                    // Skip rest - prevent double deployments (previous one was made by push)
                    continue;
                }

                $commands = [];
                if (!empty($resource->getAttribute('installCommand', ''))) {
                    $commands[] = $resource->getAttribute('installCommand', '');
                }
                if (!empty($resource->getAttribute('buildCommand', ''))) {
                    $commands[] = $resource->getAttribute('buildCommand', '');
                }
                if (!empty($resource->getAttribute('commands', ''))) {
                    $commands[] = $resource->getAttribute('commands', '');
                }

                $deployment = new Document([
                    '$id' => $deploymentId,
                    'resourceId' => $resourceId,
                    'resourceInternalId' => $resourceInternalId,
                    'resourceType' => $resourceCollection,
                    'entrypoint' => $resource->getAttribute('entrypoint', ''),
                    'buildCommands' => \implode(' && ', $commands),
                    'startCommand' => $resource->getAttribute('startCommand', ''),
                    'buildOutput' => $resource->getAttribute('outputDirectory', ''),
                    'adapter' => $resource->getAttribute('adapter', ''),
                    'fallbackFile' => $resource->getAttribute('fallbackFile', ''),
                    'type' => 'vcs',
                    'installationId' => $installationId,
                    'installationInternalId' => $installationInternalId,
                    'providerRepositoryId' => $providerRepositoryId,
                    'repositoryId' => $repositoryId,
                    'repositoryInternalId' => $repositoryInternalId,
                    'providerBranchUrl' => $providerBranchUrl,
                    'providerRepositoryName' => $providerRepositoryName,
                    'providerRepositoryOwner' => $providerRepositoryOwner,
                    'providerRepositoryUrl' => $providerRepositoryUrl,
                    'providerCommitHash' => $providerCommitHash,
                    'providerCommitAuthorUrl' => $providerCommitAuthorUrl,
                    'providerCommitAuthor' => $providerCommitAuthor,
                    'providerCommitMessage' => mb_strimwidth($providerCommitMessage, 0, 255, '...'),
                    'providerCommitUrl' => $providerCommitUrl,
                    'providerCommentId' => \strval($latestCommentId),
                    'providerBranch' => $providerBranch,
                    'activate' => $activate,
                ]);

                // The Deployments service is built per repository: a webhook fans out to
                // many tenant projects, each with its own database.
                $deployment = $authorization->skip(fn () => $deploymentsFactory($dbForProject, $project)
                    ->createFromUrl(
                        $resource,
                        $deployment,
                        $vcs->getRepositoryPresignedUrl($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash),
                        $resource->getAttribute('providerRootDirectory', ''),
                    ));

                if ($resource->getCollection() === 'sites') {
                    $projectId = $project->getId();

                    // Deployment preview
                    $sitesDomain = $platform['sitesDomain'];
                    $domain = ID::unique() . "." . $sitesDomain;
                    $ruleId = md5($domain);
                    $previewRuleId = $ruleId;
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
                            'deploymentResourceId' => $resourceId,
                            'deploymentResourceInternalId' => $resourceInternalId,
                            'deploymentVcsProviderBranch' => $providerBranch,
                            'status' => 'verified',
                            'certificateId' => '',
                            'search' => implode(' ', [$ruleId, $domain]),
                            'owner' => 'Appwrite',
                            'region' => $project->getAttribute('region')
                        ]))
                    );

                    // VCS branch preview
                    if (!empty($providerBranch)) {
                        $domain = (new BranchDomainFilter())->apply([
                            'branch' => $providerBranch,
                            'resourceId' => $resource->getId(),
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
                                    'deploymentResourceId' => $resourceId,
                                    'deploymentResourceInternalId' => $resourceInternalId,
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

                    // VCS commit preview
                    if (!empty($providerCommitHash)) {
                        $domain = "commit-" . substr($providerCommitHash, 0, 16) . ".{$sitesDomain}";
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
                                    'deploymentResourceId' => $resourceId,
                                    'deploymentResourceInternalId' => $resourceInternalId,
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
                }

                if ($resource->getCollection() === 'sites' && !empty($latestCommentId)) {
                    $retries = 0;
                    $lockAcquired = false;

                    while ($retries < 9) {
                        $retries++;

                        try {
                            $dbForPlatform->createDocument('vcsCommentLocks', new Document([
                                '$id' => $latestCommentId
                            ]));
                            $lockAcquired = true;
                            break;
                        } catch (\Throwable $err) {
                            if ($retries >= 9) {
                                Console::warning("Error creating vcs comment lock for " . $latestCommentId . ": " . $err->getMessage());
                            }

                            \sleep(1);
                        }
                    }

                    if ($lockAcquired) {
                        // Wrap in try/finally to ensure lock file gets deleted
                        try {
                            $rule = $authorization->skip(fn () => $dbForPlatform->getDocument('rules', $previewRuleId));

                            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
                            $previewUrl = !$rule->isEmpty() ? ("{$protocol}://" . $rule->getAttribute('domain', '')) : '';

                            if (!empty($previewUrl)) {
                                $comment = new Comment($platform);
                                $comment->parseComment($vcs->getComment($owner, $repositoryName, $latestCommentId));
                                $comment->addBuild($project, $resource, $resourceType, $commentStatus, $deploymentId, $action, $previewUrl);
                                $vcs->updateComment($owner, $repositoryName, $latestCommentId, $comment->generateComment());
                            }
                        } catch (\Throwable $e) {
                            Console::warning("Failed to update PR comment '{$latestCommentId}': " . $e->getMessage());
                        } finally {
                            $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $latestCommentId));
                        }
                    }
                }

                if (!empty($providerCommitHash) && $resource->getAttribute('providerSilentMode', false) === false) {
                    $resourceName = $resource->getAttribute('name');
                    $projectName = $project->getAttribute('name');
                    $region = $project->getAttribute('region', 'default');
                    $name = "{$resourceName} ({$projectName})";
                    $message = 'Starting...';

                    $providerTargetUrl = $protocol . '://' . $hostname . "/console/project-$region-$projectId/$resourceCollection/$resourceType-$resourceId";
                    $vcs->updateCommitStatus($repositoryName, $providerCommitHash, $owner, 'pending', $message, $providerTargetUrl, $name);
                }

                Span::add("{$logBase}.build.triggered", 'true');
                //TODO: Add event?
            } catch (Exception $e) {
                Span::add("{$logBase}.error", $e->getMessage());
                Span::add("{$logBase}.error.type", $e->getType());
                if ($e->getCode() < 500) {
                    Console::warning("Skipping repository '{$repository->getId()}' ({$e->getType()}): {$e->getMessage()}");
                    continue;
                }
                $errors[] = $e->getMessage();
            } catch (\Throwable $e) {
                Span::add("{$logBase}.error", $e->getMessage());
                $errors[] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            throw new Exception(Exception::GENERAL_UNKNOWN, \implode("\n", $errors));
        }
    }

    protected function beforeCreateGitDeployment(Document $project, Document $repository, Database $dbForPlatform, Authorization $authorization): void
    {
    }

}
