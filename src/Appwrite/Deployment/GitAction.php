<?php

namespace Appwrite\Deployment;

use Appwrite\Vcs\CheckRuns;
use Appwrite\Vcs\Comment;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * Reports a deployment build state to the VCS provider: a commit status and,
 * for pull-request deployments, the PR comment listing the build with its
 * console and preview links. Shared by both build backends; callers own
 * error handling — a failed report never fails a build.
 */
final class GitAction
{
    /**
     * Resolve the provider for a deployment and report a build state to it.
     * Best-effort: a deployment that never came from git, or whose installation
     * is gone, reports nothing.
     */
    public static function report(
        string $status,
        Document $deployment,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        VcsFactory $vcsFactory,
        array $platform,
    ): void {
        if ($deployment->getAttribute('providerCommitHash', '') === '' && $deployment->getAttribute('providerCommentId', '') === '') {
            return;
        }

        $resource = $dbForProject->getDocument($deployment->getAttribute('resourceType', 'functions'), $deployment->getAttribute('resourceId'));
        $installation = $dbForPlatform->getDocument('installations', $resource->getAttribute('installationId', ''));

        if ($resource->isEmpty() || $installation->getAttribute('providerInstallationId', '') === '') {
            return;
        }

        self::run(
            $status,
            $vcsFactory->fromInstallation($installation),
            $deployment->getAttribute('providerCommitHash', ''),
            $deployment->getAttribute('providerRepositoryOwner', ''),
            $deployment->getAttribute('providerRepositoryName', ''),
            $project,
            $resource,
            $deployment,
            $dbForPlatform,
            $platform,
        );
    }

    public static function run(
        string $status,
        Git $vcs,
        string $commitHash,
        string $owner,
        string $repositoryName,
        Document $project,
        Document $resource,
        Document $deployment,
        Database $dbForPlatform,
        array $platform,
    ): void {
        $checkRunId = (int) $deployment->getAttribute('providerCheckRunId', 0);
        $silent = $resource->getAttribute('providerSilentMode', false) === true;

        // A run Appwrite opened is always closed. Silent mode is force-set when a
        // resource is disconnected from git, which would otherwise leave the check
        // spinning on the commit for good.
        if ($silent && $checkRunId <= 0) {
            return;
        }

        $isSite = $resource->getCollection() === 'sites';
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';

        if (!empty($commitHash)) {
            $message = match ($status) {
                'ready' => 'Build succeeded.',
                'failed' => 'Build failed.',
                'processing' => 'Building...',
                'canceled' => 'Build canceled.',
                default => $status
            };
            $state = match ($status) {
                'ready' => 'success',
                'failed' => 'failure',
                'processing' => 'pending',
                // A commit status has no canceled state, and reporting one as
                // failed would misrepresent it. Only a check run says this.
                'canceled' => '',
                default => $status
            };

            $hostname = System::getEnv('_APP_CONSOLE_DOMAIN', System::getEnv('_APP_DOMAIN', ''));
            $region = $project->getAttribute('region', 'default');
            $segment = $isSite ? "sites/site-{$resource->getId()}" : "functions/function-{$resource->getId()}";
            $targetUrl = "{$protocol}://{$hostname}/console/project-{$region}-{$project->getId()}/{$segment}";
            $name = $resource->getAttribute('name') . ' (' . $project->getAttribute('name') . ')';

            $conclusion = match ($status) {
                'ready' => CheckRuns::CONCLUSION_SUCCESS,
                'failed' => CheckRuns::CONCLUSION_FAILURE,
                'canceled' => CheckRuns::CONCLUSION_CANCELLED,
                default => ''
            };

            if ($checkRunId > 0) {
                // 'processing' leaves the run as it is — it was opened in progress.
                if (!empty($conclusion)) {
                    $title = match ($status) {
                        'ready' => 'Deployment ready',
                        'failed' => 'Deployment failed',
                        default => 'Deployment canceled'
                    };

                    (new CheckRuns())->close($vcs, $owner, $repositoryName, $checkRunId, $conclusion, $title, $message, $targetUrl);
                }
            } elseif (!empty($state)) {
                $vcs->updateCommitStatus($repositoryName, $commitHash, $owner, $state, $message, $targetUrl, $name);
            }
        }

        if ($silent) {
            return;
        }

        $commentId = $deployment->getAttribute('providerCommentId', '');
        if (empty($commentId)) {
            return;
        }

        // Serialize comment updates across concurrent builds via a lock document.
        $retries = 0;
        while (true) {
            try {
                $dbForPlatform->createDocument('vcsCommentLocks', new Document(['$id' => $commentId]));
                break;
            } catch (\Throwable $err) {
                if (++$retries >= 9) {
                    throw $err;
                }
                \sleep(1);
            }
        }

        try {
            $rule = $dbForPlatform->findOne('rules', [
                Query::equal('projectInternalId', [$project->getSequence()]),
                Query::equal('type', ['deployment']),
                Query::equal('deploymentInternalId', [$deployment->getSequence()]),
            ]);
            $previewUrl = $isSite && !$rule->isEmpty() ? "{$protocol}://" . $rule->getAttribute('domain', '') : '';

            $comment = new Comment($platform);
            $comment->parseComment($vcs->getComment($owner, $repositoryName, $commentId));
            $comment->addBuild($project, $resource, $isSite ? 'site' : 'function', $status, $deployment->getId(), ['type' => 'logs'], $previewUrl);
            $vcs->updateComment($owner, $repositoryName, $commentId, $comment->generateComment());
        } finally {
            $dbForPlatform->deleteDocument('vcsCommentLocks', $commentId);
        }
    }
}
