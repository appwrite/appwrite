<?php

namespace Appwrite\Deployment;

use Appwrite\Vcs\Comment;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;

/**
 * Reports a deployment build state to the VCS provider: a check on the
 * commit and, for pull-request deployments, the PR comment listing the build
 * with its console and preview links. Shared by both build backends; callers
 * own error handling — a failed report never fails a build.
 */
final class GitAction
{
    /**
     * Reports through the installation the deployment's resource is linked
     * to, best-effort: a refused report is logged, never raised.
     *
     * @param array<string, mixed> $platform
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

        try {
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
        } catch (\Throwable $error) {
            // The provider report is what a reviewer sees, so a refused one is worth a line.
            Console::warning("Failed to report deployment '{$deployment->getId()}' as {$status}: " . $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $platform
     */
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

        self::check($status, $vcs, $commitHash, $owner, $repositoryName, $project, $resource, $deployment, $dbForPlatform, $platform);

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
            $isSite = $resource->getCollection() === 'sites';
            $previewUrl = $isSite ? self::previewUrl(self::rule($project, $deployment, $dbForPlatform)) : '';

            $comment = new Comment($platform, $vcs->supportsCommentImages());
            $comment->parseComment($vcs->getComment($owner, $repositoryName, $commentId));
            $comment->addBuild($project, $resource, $isSite ? 'site' : 'function', $status, $deployment->getId(), ['type' => 'logs'], $previewUrl);
            $vcs->updateComment($owner, $repositoryName, $commentId, $comment->generateComment());
        } finally {
            $dbForPlatform->deleteDocument('vcsCommentLocks', $commentId);
        }
    }

    /**
     * The check alone, for a deployment's first report when the caller has
     * just written its comment.
     *
     * @param array<string, mixed> $platform
     */
    public static function check(
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
        if ($commitHash === '' || $resource->getAttribute('providerSilentMode', false) === true) {
            return;
        }

        $isSite = $resource->getCollection() === 'sites';
        $collection = $isSite ? 'sites' : 'functions';
        $consoleUrl = ($platform['consoleUrl'] ?? '') . "/projects/{$project->getId()}/{$collection}/{$resource->getId()}/deployments/{$deployment->getId()}";
        $previewUrl = $isSite ? self::previewUrl(self::rule($project, $deployment, $dbForPlatform)) : '';

        $title = match ($status) {
            'waiting' => 'Starting...',
            'processing' => 'Building...',
            'ready' => 'Build succeeded.',
            'failed' => 'Build failed.',
            'canceled' => 'Build canceled.',
            default => $status,
        };
        $state = match ($status) {
            'waiting' => 'queued',
            'processing' => 'pending',
            'ready' => 'success',
            'failed' => 'failure',
            'canceled' => 'cancelled',
            default => $status,
        };

        $lines = [
            ($isSite ? 'Site' : 'Function') . ' ' . $resource->getAttribute('name') . ' in project ' . $project->getAttribute('name'),
            "Deployment {$deployment->getId()}: {$consoleUrl}",
        ];

        $branch = $deployment->getAttribute('providerBranch', '');
        $message = \trim(\explode("\n", $deployment->getAttribute('providerCommitMessage', ''))[0]);
        $lines[] = 'Commit ' . \substr($commitHash, 0, 7) . ($branch !== '' ? " on {$branch}" : '') . ($message !== '' ? ": {$message}" : '');

        $duration = (int) $deployment->getAttribute('buildDuration', 0);
        if ($duration > 0) {
            $lines[] = match ($status) {
                'ready' => "Built in {$duration}s",
                'failed' => "Failed after {$duration}s",
                'canceled' => "Canceled after {$duration}s",
                default => "Running for {$duration}s",
            };
        }

        if ($previewUrl !== '') {
            $lines[] = "Preview: {$previewUrl}";
        }

        $name = $resource->getAttribute('name') . ' (' . $project->getAttribute('name') . ')';
        $vcs->updateCheck($owner, $repositoryName, $commitHash, $state, $name, $title, '- ' . \implode("\n- ", $lines), $previewUrl !== '' ? $previewUrl : $consoleUrl);
    }

    /**
     * The address a deployment rule serves. The router serves it, so it is
     * HTTPS when either the API or the router forces HTTPS.
     */
    public static function previewUrl(Document $rule): string
    {
        if ($rule->isEmpty()) {
            return '';
        }

        $https = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') !== 'disabled' || System::getEnv('_APP_OPTIONS_ROUTER_FORCE_HTTPS', 'disabled') === 'enabled';

        return ($https ? 'https' : 'http') . '://' . $rule->getAttribute('domain', '');
    }

    /**
     * The deployment's own preview rule, when a site deployment has one.
     */
    private static function rule(Document $project, Document $deployment, Database $dbForPlatform): Document
    {
        return $dbForPlatform->findOne('rules', [
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::equal('type', ['deployment']),
            Query::equal('deploymentInternalId', [$deployment->getSequence()]),
        ]);
    }
}
