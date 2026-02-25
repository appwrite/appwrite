<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub;

use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Filter\BranchDomain as BranchDomainFilter;
use Appwrite\Vcs\Comment;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\RepositoryNotFound;

trait Deployments
{
    protected function createGitDeployments(
        GitHub $github,
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
        bool $external,
        Database $dbForPlatform,
        Authorization $authorization,
        Build $queueForBuilds,
        callable $getProjectDB,
        array $platform,
    ) {
        $errors = [];
        foreach ($repositories as $repository) {
            try {
                $repositoryId = $repository->getId();
                $resourceType = $repository->getAttribute('resourceType');

                $logBase = "vcs.github.event.{$repositoryId}";
                Span::add("{$logBase}.resourceType", $resourceType);
                Span::add("{$logBase}.projectId", $projectId);

                if ($resourceType !== "function" && $resourceType !== "site") {
                    continue;
                }

                $projectId = $repository->getAttribute('projectId');
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

                if ($project->isEmpty()) {
                    throw new Exception(Exception::PROJECT_NOT_FOUND, 'Repository references non-existent project');
                }

                if (!$this->validateDB($project)) {
                    continue;
                }

                $dbForProject = $getProjectDB($project);
                $resourceCollection = $resourceType === "function" ? 'functions' : 'sites';
                $resourceId = $repository->getAttribute('resourceId');
                $resource = $authorization->skip(fn () => $dbForProject->getDocument($resourceCollection, $resourceId));
                $resourceInternalId = $resource->getSequence();

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

                $owner = $github->getOwnerName($providerInstallationId) ?? '';
                try {
                    $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
                    if (empty($repositoryName)) {
                        throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                    }
                } catch (RepositoryNotFound $e) {
                    throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                }

                if (empty($repositoryName)) {
                    throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                }

                $isAuthorized = !$external;

                if (!$isAuthorized && !empty($providerPullRequestId)) {
                    if (\in_array($providerPullRequestId, $repository->getAttribute('providerPullRequestIds', []))) {
                        $isAuthorized = true;
                    }
                }

                Span::add("{$logBase}.authorized", $isAuthorized);

                $commentStatus = $isAuthorized ? 'waiting' : 'failed';
                $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
                $hostname = $platform['consoleHostname'] ?? '';

                $authorizeUrl = $protocol . '://' . $hostname . "/console/git/authorize-contributor?projectId={$projectId}&installationId={$installationId}&repositoryId={$repositoryId}&providerPullRequestId={$providerPullRequestId}";

                $action = $isAuthorized ? ['type' => 'logs'] : ['type' => 'authorize', 'url' => $authorizeUrl];

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
                                $comment->parseComment($github->getComment($owner, $repositoryName, $latestCommentId));
                                $comment->addBuild($project, $resource, $resourceType, $commentStatus, $deploymentId, $action, '');

                                $latestCommentId = \strval($github->updateComment($owner, $repositoryName, $latestCommentId, $comment->generateComment()));
                            } finally {
                                $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $latestCommentId));
                            }
                        }
                    } else {
                        $comment = new Comment($platform);
                        $comment->addBuild($project, $resource, $resourceType, $commentStatus, $deploymentId, $action, '');
                        $latestCommentId = \strval($github->createComment($owner, $repositoryName, $providerPullRequestId, $comment->generateComment()));

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
                                $comment->parseComment($github->getComment($owner, $repositoryName, $latestCommentId));
                                $comment->addBuild($project, $resource, $resourceType, $commentStatus, $deploymentId, $action, '');

                                $latestCommentId = \strval($github->updateComment($owner, $repositoryName, $latestCommentId, $comment->generateComment()));
                            } finally {
                                $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $latestCommentId));
                            }
                        }
                    }
                }

                if (!$isAuthorized) {
                    $resourceName = $resource->getAttribute('name');
                    $projectName = $project->getAttribute('name');
                    $name = "{$resourceName} ({$projectName})";
                    $message = 'Authorization required for external contributor.';

                    $providerRepositoryId = $repository->getAttribute('providerRepositoryId');
                    try {
                        $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
                        if (empty($repositoryName)) {
                            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                        }
                    } catch (RepositoryNotFound $e) {
                        throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                    }
                    $owner = $github->getOwnerName($providerInstallationId);
                    $github->updateCommitStatus($repositoryName, $providerCommitHash, $owner, 'failure', $message, $authorizeUrl, $name);
                    continue;
                }

                if ($external) {
                    $pullRequestResponse = $github->getPullRequest($owner, $repositoryName, $providerPullRequestId);
                    $providerRepositoryName = $pullRequestResponse['head']['repo']['owner']['login'];
                    $providerRepositoryOwner = $pullRequestResponse['head']['repo']['name'];
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

                $deployment = $authorization->skip(fn () => $dbForProject->createDocument('deployments', new Document([
                    '$id' => $deploymentId,
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
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
                ])));

                $resource = $resource
                    ->setAttribute('latestDeploymentId', $deployment->getId())
                    ->setAttribute('latestDeploymentInternalId', $deployment->getSequence())
                    ->setAttribute('latestDeploymentCreatedAt', $deployment->getCreatedAt())
                    ->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
                $authorization->skip(fn () => $dbForProject->updateDocument($resource->getCollection(), $resource->getId(), $resource));

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

                if ($resource->getCollection() === 'sites' && !empty($latestCommentId) && !empty($previewRuleId)) {
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
                            $previewUrl = !empty($rule) ? ("{$protocol}://" . $rule->getAttribute('domain', '')) : '';

                            if (!empty($previewUrl)) {
                                $comment = new Comment($platform);
                                $comment->parseComment($github->getComment($owner, $repositoryName, $latestCommentId));
                                $comment->addBuild($project, $resource, $resourceType, $commentStatus, $deploymentId, $action, $previewUrl);
                                $github->updateComment($owner, $repositoryName, $latestCommentId, $comment->generateComment());
                            }
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

                    $providerRepositoryId = $repository->getAttribute('providerRepositoryId');
                    try {
                        $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
                        if (empty($repositoryName)) {
                            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                        }
                    } catch (RepositoryNotFound $e) {
                        throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
                    }
                    $owner = $github->getOwnerName($providerInstallationId);

                    $providerTargetUrl = $protocol . '://' . $hostname . "/console/project-$region-$projectId/$resourceCollection/$resourceType-$resourceId";
                    $github->updateCommitStatus($repositoryName, $providerCommitHash, $owner, 'pending', $message, $providerTargetUrl, $name);
                }

                $queueName = $this->getBuildQueueName($project, $dbForPlatform, $authorization);

                $queueForBuilds
                    ->setQueue($queueName)
                    ->setType(BUILD_TYPE_DEPLOYMENT)
                    ->setResource($resource)
                    ->setDeployment($deployment)
                    ->setProject($project); // set the project because it won't be set for git deployments

                $queueForBuilds->trigger(); // must trigger here so that we create a build for each function/site

                Span::add("{$logBase}.build.triggered", 'true');
                //TODO: Add event?
            } catch (\Throwable $e) {
                Span::add("{$logBase}.error", $e->getMessage());
                $errors[] = $e->getMessage();
            }
        }

        $queueForBuilds->reset(); // prevent shutdown hook from triggering again

        if (!empty($errors)) {
            throw new Exception(Exception::GENERAL_UNKNOWN, \implode("\n", $errors));
        }
    }

    protected function validateDB(Document $project): bool
    {
        return true;
    }

    protected function getBuildQueueName(Document $project, Database $dbForPlatform, Authorization $authorization): string
    {
        return System::getEnv('_APP_BUILDS_QUEUE_NAME', Event::BUILDS_QUEUE_NAME);
    }
}
