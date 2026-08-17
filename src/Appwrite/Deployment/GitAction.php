<?php

namespace Appwrite\Deployment;

use Appwrite\Vcs\Comment;
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
        if ($resource->getAttribute('providerSilentMode', false) === true) {
            return;
        }

        $isSite = $resource->getCollection() === 'sites';
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';

        if (!empty($commitHash)) {
            $message = match ($status) {
                'ready' => 'Build succeeded.',
                'failed' => 'Build failed.',
                'processing' => 'Building...',
                default => $status
            };
            $state = match ($status) {
                'ready' => 'success',
                'failed' => 'failure',
                'processing' => 'pending',
                default => $status
            };

            $hostname = System::getEnv('_APP_CONSOLE_DOMAIN', System::getEnv('_APP_DOMAIN', ''));
            $region = $project->getAttribute('region', 'default');
            $segment = $isSite ? "sites/site-{$resource->getId()}" : "functions/function-{$resource->getId()}";
            $targetUrl = "{$protocol}://{$hostname}/console/project-{$region}-{$project->getId()}/{$segment}";
            $name = $resource->getAttribute('name') . ' (' . $project->getAttribute('name') . ')';

            $vcs->updateCommitStatus($repositoryName, $commitHash, $owner, $state, $message, $targetUrl, $name);
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
