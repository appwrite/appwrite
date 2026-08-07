<?php

namespace Appwrite\Vcs;

use Utopia\Console;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

/**
 * Reports deployment state as provider check runs, which carry conclusions a
 * commit status cannot: skipped, and waiting on authorization. Only GitHub
 * implements them, so a zero return means fall back to the commit status.
 */
class CheckRuns
{
    public const string CONCLUSION_NEUTRAL = 'neutral';
    public const string CONCLUSION_ACTION_REQUIRED = 'action_required';
    public const string CONCLUSION_SUCCESS = 'success';
    public const string CONCLUSION_FAILURE = 'failure';
    public const string CONCLUSION_CANCELLED = 'cancelled';

    protected const int NAME_LIMIT = 255;

    /**
     * Owners whose installation refused a run, so an installation missing the
     * permission does not pay one rejected call per linked resource.
     *
     * @var array<string, true>
     */
    protected array $refused = [];

    public function supports(Git $vcs): bool
    {
        return $vcs instanceof GitHub;
    }

    /**
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
        // A malformed hash is a 422, and without one there is nothing to report against.
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
        if (!$this->supports($vcs) || empty($owner) || empty($repositoryName)) {
            return false;
        }

        return !isset($this->refused[$owner]);
    }

    protected function remember(string $owner, \Throwable $error): void
    {
        if ($error->getCode() === 403) {
            $this->refused[$owner] = true;
        }
    }
}
