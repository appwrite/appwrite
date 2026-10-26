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
 * commit, the deployed resource where the provider shows deployments and,
 * for pull-request deployments, the PR comment listing the build with its
 * console and preview links. Shared by both build backends; callers own
 * error handling — a failed report never fails a build.
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
            // Another deployment of the same commit keeps the resource running it
            if ($status === 'deleted' && !$dbForProject->findOne('deployments', [
                Query::equal('resourceInternalId', [$deployment->getAttribute('resourceInternalId', '')]),
                Query::equal('resourceType', [$deployment->getAttribute('resourceType', '')]),
                Query::equal('providerCommitHash', [$deployment->getAttribute('providerCommitHash', '')]),
            ])->isEmpty()) {
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

        // A deleted deployment leaves its check and comment as they were
        if ($status === 'deleted') {
            self::deployment($status, $vcs, $commitHash, $owner, $repositoryName, $project, $resource, $deployment, $dbForPlatform, $platform);
            return;
        }

        self::check($status, $vcs, $commitHash, $owner, $repositoryName, $project, $resource, $deployment, $dbForPlatform, $platform);
        self::deployment($status, $vcs, $commitHash, $owner, $repositoryName, $project, $resource, $deployment, $dbForPlatform, $platform);

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

        // A check is the resource, filed under its project; it answers whether
        // the build passed, so it links to the build's logs
        $vcs->updateCheck($owner, $repositoryName, $commitHash, self::state($status), $resource->getAttribute('name'), $title, '- ' . \implode("\n- ", $lines), $consoleUrl, $project->getAttribute('name'));
    }

    /**
     * The deployed resource alone, for a provider that shows deployments:
     * where it runs and where its build logs are, one entry per resource
     * of a project. A deleted deployment's resource is reported gone.
     *
     * @param array<string, mixed> $platform
     */
    public static function deployment(
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
        $deleted = $status === 'deleted';

        $vcs->updateDeployment(
            $owner,
            $repositoryName,
            $commitHash,
            key: "{$project->getId()}/{$resource->getId()}",
            name: $resource->getAttribute('name'),
            state: $deleted ? 'inactive' : self::state($status),
            // A production branch's build is the one that goes live
            environment: $deployment->getAttribute('activate', false) === true ? 'production' : 'preview',
            url: $isSite && !$deleted ? self::previewUrl(self::rule($project, $deployment, $dbForPlatform)) : '',
            logsUrl: ($platform['consoleUrl'] ?? '') . "/projects/{$project->getId()}/{$collection}/{$resource->getId()}/deployments/{$deployment->getId()}",
            type: $isSite ? 'site' : 'function',
            groupKey: $project->getId(),
            groupName: $project->getAttribute('name'),
        );
    }

    /**
     * The provider state of a deployment status.
     */
    private static function state(string $status): string
    {
        return match ($status) {
            'waiting' => 'queued',
            'processing', 'building' => 'pending',
            'ready' => 'success',
            'failed' => 'failure',
            'canceled' => 'cancelled',
            default => $status,
        };
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
