<?php

use Appwrite\Event\Build;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Comment;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Http;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\RepositoryNotFound;

$createGitDeployments = function (GitHub $github, string $providerInstallationId, array $repositories, string $providerBranch, string $providerBranchUrl, string $providerRepositoryName, string $providerRepositoryUrl, string $providerRepositoryOwner, string $providerCommitHash, string $providerCommitAuthor, string $providerCommitAuthorUrl, string $providerCommitMessage, string $providerCommitUrl, string $providerPullRequestId, bool $external, Database $dbForPlatform, Authorization $authorization, Build $queueForBuilds, callable $getProjectDB, Request $request, array $platform) {
    $errors = [];
    foreach ($repositories as $repository) {
        try {
            $resourceType = $repository->getAttribute('resourceType');

            if ($resourceType !== "function" && $resourceType !== "site") {
                continue;
            }

            $projectId = $repository->getAttribute('projectId');
            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
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
                    $branchPrefix = substr($providerBranch, 0, 16);
                    if (strlen($providerBranch) > 16) {
                        $remainingChars = substr($providerBranch, 16);
                        $branchPrefix .= '-' . substr(hash('sha256', $remainingChars), 0, 7);
                    }
                    $resourceProjectHash = substr(hash('sha256', $resource->getId() . $project->getId()), 0, 7);
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

            $queueForBuilds
                ->setType(BUILD_TYPE_DEPLOYMENT)
                ->setResource($resource)
                ->setDeployment($deployment)
                ->setProject($project); // set the project because it won't be set for git deployments

            $queueForBuilds->trigger(); // must trigger here so that we create a build for each function/site

            //TODO: Add event?
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    $queueForBuilds->reset(); // prevent shutdown hook from triggering again

    if (!empty($errors)) {
        throw new Exception(Exception::GENERAL_UNKNOWN, \implode("\n", $errors));
    }
};

Http::post('/v1/vcs/github/events')
    ->desc('Create event')
    ->groups(['api', 'vcs'])
    ->label('scope', 'public')
    ->inject('gitHub')
    ->inject('request')
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('authorization')
    ->inject('getProjectDB')
    ->inject('queueForBuilds')
    ->inject('platform')
    ->action(
        function (GitHub $github, Request $request, Response $response, Database $dbForPlatform, Authorization $authorization, callable $getProjectDB, Build $queueForBuilds, array $platform) use ($createGitDeployments) {
            $payload = $request->getRawPayload();
            $signatureRemote = $request->getHeader('x-hub-signature-256', '');
            $signatureLocal = System::getEnv('_APP_VCS_GITHUB_WEBHOOK_SECRET', '');

            $valid = empty($signatureRemote) ? true : $github->validateWebhookEvent($payload, $signatureRemote, $signatureLocal);

            if (!$valid) {
                throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, "Invalid webhook payload signature. Please make sure the webhook secret has same value in your GitHub app and in the _APP_VCS_GITHUB_WEBHOOK_SECRET environment variable");
            }

            $event = $request->getHeader('x-github-event', '');
            $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
            $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
            $parsedPayload = $github->getEvent($event, $payload);

            if ($event == $github::EVENT_PUSH) {
                $providerBranchCreated = $parsedPayload["branchCreated"] ?? false;
                $providerBranchDeleted = $parsedPayload["branchDeleted"] ?? false;
                $providerBranch = $parsedPayload["branch"] ?? '';
                $providerBranchUrl = $parsedPayload["branchUrl"] ?? '';
                $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
                $providerRepositoryName = $parsedPayload["repositoryName"] ?? '';
                $providerInstallationId = $parsedPayload["installationId"] ?? '';
                $providerRepositoryUrl = $parsedPayload["repositoryUrl"] ?? '';
                $providerCommitHash = $parsedPayload["commitHash"] ?? '';
                $providerRepositoryOwner = $parsedPayload["owner"] ?? '';
                $providerCommitAuthorName = $parsedPayload["headCommitAuthorName"] ?? '';
                $providerCommitAuthorEmail = $parsedPayload["headCommitAuthorEmail"] ?? '';
                $providerCommitAuthorUrl = $parsedPayload["authorUrl"] ?? '';
                $providerCommitMessage = $parsedPayload["headCommitMessage"] ?? '';
                $providerCommitUrl = $parsedPayload["headCommitUrl"] ?? '';

                $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

                //find resourceId from relevant resources table
                $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                    Query::equal('providerRepositoryId', [$providerRepositoryId]),
                    Query::limit(100),
                ]));

                // create new deployment only on push (not committed by us) and not when branch is created or deleted
                if ($providerCommitAuthorEmail !== APP_VCS_GITHUB_EMAIL && !$providerBranchCreated && !$providerBranchDeleted) {
                    $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthorName, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, '', false, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $request, $platform);
                }
            } elseif ($event == $github::EVENT_INSTALLATION) {
                if ($parsedPayload["action"] == "deleted") {
                    // TODO: Use worker for this job instead (update function/site as well)
                    $providerInstallationId = $parsedPayload["installationId"];

                    $installations = $dbForPlatform->find('installations', [
                        Query::equal('providerInstallationId', [$providerInstallationId]),
                        Query::limit(1000)
                    ]);

                    foreach ($installations as $installation) {
                        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                            Query::equal('installationInternalId', [$installation->getSequence()]),
                            Query::limit(1000)
                        ]));

                        foreach ($repositories as $repository) {
                            $authorization->skip(fn () => $dbForPlatform->deleteDocument('repositories', $repository->getId()));
                        }

                        $authorization->skip(fn () => $dbForPlatform->deleteDocument('installations', $installation->getId()));
                    }
                }
            } elseif ($event == $github::EVENT_PULL_REQUEST) {
                if ($parsedPayload["action"] == "opened" || $parsedPayload["action"] == "reopened" || $parsedPayload["action"] == "synchronize") {
                    $providerBranch = $parsedPayload["branch"] ?? '';
                    $providerBranchUrl = $parsedPayload["branchUrl"] ?? '';
                    $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
                    $providerRepositoryName = $parsedPayload["repositoryName"] ?? '';
                    $providerInstallationId = $parsedPayload["installationId"] ?? '';
                    $providerRepositoryUrl = $parsedPayload["repositoryUrl"] ?? '';
                    $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
                    $providerCommitHash = $parsedPayload["commitHash"] ?? '';
                    $providerRepositoryOwner = $parsedPayload["owner"] ?? '';
                    $external = $parsedPayload["external"] ?? true;
                    $providerCommitUrl = $parsedPayload["headCommitUrl"] ?? '';
                    $providerCommitAuthorUrl = $parsedPayload["authorUrl"] ?? '';

                    // Ignore sync for non-external. We handle it in push webhook
                    if (!$external && $parsedPayload["action"] == "synchronize") {
                        return $response->json($parsedPayload);
                    }

                    $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

                    $commitDetails = $github->getCommit($providerRepositoryOwner, $providerRepositoryName, $providerCommitHash);
                    $providerCommitAuthor = $commitDetails["commitAuthor"] ?? '';
                    $providerCommitMessage = $commitDetails["commitMessage"] ?? '';

                    $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                        Query::equal('providerRepositoryId', [$providerRepositoryId]),
                        Query::orderDesc('$createdAt')
                    ]));

                    $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, $providerBranchUrl, $providerRepositoryName, $providerRepositoryUrl, $providerRepositoryOwner, $providerCommitHash, $providerCommitAuthor, $providerCommitAuthorUrl, $providerCommitMessage, $providerCommitUrl, $providerPullRequestId, $external, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $request, $platform);
                } elseif ($parsedPayload["action"] == "closed") {
                    // Allowed external contributions cleanup

                    $providerRepositoryId = $parsedPayload["repositoryId"] ?? '';
                    $providerPullRequestId = $parsedPayload["pullRequestNumber"] ?? '';
                    $external = $parsedPayload["external"] ?? true;

                    if ($external) {
                        $repositories = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                            Query::equal('providerRepositoryId', [$providerRepositoryId]),
                            Query::orderDesc('$createdAt')
                        ]));

                        foreach ($repositories as $repository) {
                            $providerPullRequestIds = $repository->getAttribute('providerPullRequestIds', []);

                            if (\in_array($providerPullRequestId, $providerPullRequestIds)) {
                                $providerPullRequestIds = \array_diff($providerPullRequestIds, [$providerPullRequestId]);
                                $repository = $repository->setAttribute('providerPullRequestIds', $providerPullRequestIds);
                                $repository = $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), $repository));
                            }
                        }
                    }
                }
            }

            $response->json($parsedPayload);
        }
    );

Http::patch('/v1/vcs/github/installations/:installationId/repositories/:repositoryId')
    ->desc('Update external deployment (authorize)')
    ->groups(['api', 'vcs'])
    ->label('scope', 'vcs.write')
    ->label('sdk', new Method(
        namespace: 'vcs',
        group: 'repositories',
        name: 'updateExternalDeployments',
        description: '/docs/references/vcs/update-external-deployments.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ]
    ))
    ->param('installationId', '', new Text(256), 'Installation Id')
    ->param('repositoryId', '', new Text(256), 'VCS Repository Id')
    ->param('providerPullRequestId', '', new Text(256), 'GitHub Pull Request Id')
    ->inject('gitHub')
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->inject('authorization')
    ->inject('getProjectDB')
    ->inject('queueForBuilds')
    ->inject('platform')
    ->action(function (string $installationId, string $repositoryId, string $providerPullRequestId, GitHub $github, Request $request, Response $response, Document $project, Database $dbForPlatform, Authorization $authorization, callable $getProjectDB, Build $queueForBuilds, array $platform) use ($createGitDeployments) {
        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if ($installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        $repository = $authorization->skip(fn () => $dbForPlatform->findOne('repositories', [
            Query::equal('$id', [$repositoryId]),
            Query::equal('projectInternalId', [$project->getSequence()])
        ]));

        if ($repository->isEmpty()) {
            throw new Exception(Exception::REPOSITORY_NOT_FOUND);
        }

        if (\in_array($providerPullRequestId, $repository->getAttribute('providerPullRequestIds', []))) {
            throw new Exception(Exception::PROVIDER_CONTRIBUTION_CONFLICT);
        }

        $providerPullRequestIds = \array_unique(\array_merge($repository->getAttribute('providerPullRequestIds', []), [$providerPullRequestId]));
        $repository = $repository->setAttribute('providerPullRequestIds', $providerPullRequestIds);

        // TODO: Delete from array when PR is closed

        $repository = $authorization->skip(fn () => $dbForPlatform->updateDocument('repositories', $repository->getId(), $repository));

        $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
        $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
        $providerInstallationId = $installation->getAttribute('providerInstallationId');
        $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);

        $repositories = [$repository];
        $providerRepositoryId = $repository->getAttribute('providerRepositoryId');

        $owner = $github->getOwnerName($providerInstallationId);
        try {
            $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
            if (empty($repositoryName)) {
                throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
            }
        } catch (RepositoryNotFound $e) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }
        $pullRequestResponse = $github->getPullRequest($owner, $repositoryName, $providerPullRequestId);

        $providerBranch = \explode(':', $pullRequestResponse['head']['label'])[1] ?? '';
        $providerCommitHash = $pullRequestResponse['head']['sha'] ?? '';
        $providerBranchUrl = $pullRequestResponse['head']['repo']['html_url'] ?? '';
        $providerRepositoryName = $pullRequestResponse['head']['repo']['name'] ?? '';
        $providerRepositoryUrl = $pullRequestResponse['head']['repo']['html_url'] ?? '';
        $providerRepositoryOwner = $pullRequestResponse['head']['repo']['owner']['login'] ?? '';
        $providerCommitAuthor = $pullRequestResponse['head']['user']['login'] ?? '';
        $providerCommitAuthorUrl = $pullRequestResponse['head']['user']['html_url'] ?? '';
        $providerCommitMessage = $pullRequestResponse['title'] ?? '';
        $providerCommitUrl = $pullRequestResponse['html_url'] ?? '';

        $createGitDeployments($github, $providerInstallationId, $repositories, $providerBranch, '', '', '', '', $providerCommitHash, '', '', '', '', $providerPullRequestId, true, $dbForPlatform, $authorization, $queueForBuilds, $getProjectDB, $request, $platform);

        $response->noContent();
    });
