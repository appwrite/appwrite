<?php

namespace Appwrite\Vcs;

use Utopia\Console;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

/**
 * Reports deployment state as provider check runs.
 *
 * A commit status can only say pending, success, failure or error, so a
 * deployment Appwrite deliberately skipped looked identical to one that never
 * ran, and one waiting on a maintainer's authorization sat pending forever. A
 * check run carries a conclusion for both.
 *
 * Only GitHub implements check runs; every other provider keeps the commit
 * status, so callers treat a zero return as "fall back". Reporting is
 * best-effort throughout — a provider that refuses the call must never fail
 * the deployment that triggered it.
 */
class CheckRuns
{
    public const string CONCLUSION_NEUTRAL = 'neutral';
    public const string CONCLUSION_ACTION_REQUIRED = 'action_required';
    public const string CONCLUSION_SUCCESS = 'success';
    public const string CONCLUSION_FAILURE = 'failure';
    public const string CONCLUSION_CANCELLED = 'cancelled';

    /**
     * GitHub rejects a name longer than this.
     */
    protected const int NAME_LIMIT = 255;

    /**
     * Owners whose installation has already refused a check run. A webhook fans
     * out to every resource linked to a repository, so without this an
     * installation that was never granted the permission pays one rejected
     * call per resource.
     *
     * @var array<string, true>
     */
    protected array $refused = [];

    /**
     * Whether this provider reports check runs at all.
     */
    public function supports(Git $vcs): bool
    {
        return $vcs instanceof GitHub;
    }

    /**
     * Open a run for a deployment that is about to build.
     *
     * @return int The run's id, or 0 when none was opened.
     */
    public function open(
        Git $vcs,
        string $owner,
        string $repositoryName,
        string $commitHash,
        string $name,
        string $summary,
        string $detailsUrl = '',
        string $externalId = '',
    ): int {
        return $this->create($vcs, $owner, $repositoryName, $commitHash, $name, '', 'Deployment queued', $summary, $detailsUrl, $externalId);
    }

    /**
     * Report a deployment that reached a terminal state without building.
     *
     * @return int The run's id, or 0 when none was opened.
     */
    public function conclude(
        Git $vcs,
        string $owner,
        string $repositoryName,
        string $commitHash,
        string $name,
        string $conclusion,
        string $title,
        string $summary,
        string $detailsUrl = '',
    ): int {
        return $this->create($vcs, $owner, $repositoryName, $commitHash, $name, $conclusion, $title, $summary, $detailsUrl);
    }

    /**
     * Close a run opened earlier.
     *
     * @return bool Whether the run was closed.
     */
    public function close(
        Git $vcs,
        string $owner,
        string $repositoryName,
        int $checkRunId,
        string $conclusion,
        string $title,
        string $summary,
        string $detailsUrl = '',
    ): bool {
        if ($checkRunId <= 0 || !$this->reportable($vcs, $owner, $repositoryName)) {
            return false;
        }

        try {
            $vcs->updateCheckRun(
                owner: $owner,
                repositoryName: $repositoryName,
                checkRunId: $checkRunId,
                conclusion: $conclusion,
                title: $title,
                summary: $summary,
                detailsUrl: $detailsUrl,
            );

            return true;
        } catch (\Throwable $error) {
            $this->remember($owner, $error);
            Console::warning("Failed to close check run {$checkRunId} on {$owner}/{$repositoryName}: " . $error->getMessage());

            return false;
        }
    }

    /**
     * @return int The run's id, or 0 when none was created.
     */
    protected function create(
        Git $vcs,
        string $owner,
        string $repositoryName,
        string $commitHash,
        string $name,
        string $conclusion,
        string $title,
        string $summary,
        string $detailsUrl,
        string $externalId = '',
    ): int {
        // An empty or malformed hash is a 422; a deployment without one has no
        // commit to report against in the first place.
        if (!\preg_match('/^[0-9a-f]{7,40}$/i', $commitHash)) {
            return 0;
        }

        if (!$this->reportable($vcs, $owner, $repositoryName)) {
            return 0;
        }

        try {
            $run = $vcs->createCheckRun(
                owner: $owner,
                repositoryName: $repositoryName,
                headSha: $commitHash,
                name: \mb_strimwidth($name, 0, self::NAME_LIMIT, '...'),
                status: empty($conclusion) ? 'in_progress' : 'completed',
                conclusion: $conclusion,
                title: $title,
                summary: $summary,
                detailsUrl: $detailsUrl,
                externalId: $externalId,
            );

            return (int) ($run['id'] ?? 0);
        } catch (\Throwable $error) {
            $this->remember($owner, $error);
            Console::warning("Failed to create check run on {$owner}/{$repositoryName}: " . $error->getMessage());

            return 0;
        }
    }

    protected function reportable(Git $vcs, string $owner, string $repositoryName): bool
    {
        if (!$this->supports($vcs)) {
            return false;
        }

        if (empty($owner) || empty($repositoryName)) {
            return false;
        }

        return !isset($this->refused[$owner]);
    }

    /**
     * An installation predating the check run permission answers 403 to every
     * call, so stop asking for the rest of this event.
     */
    protected function remember(string $owner, \Throwable $error): void
    {
        if ($error->getCode() === 403) {
            $this->refused[$owner] = true;
        }
    }
}
