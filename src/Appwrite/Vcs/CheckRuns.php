<?php

namespace Appwrite\Vcs;

use Utopia\Console;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

/**
 * Reports a verdict on a commit as a check run, falling back to a commit status
 * where check runs are unavailable — only GitHub implements them.
 */
class CheckRuns
{
    public const string CONCLUSION_NEUTRAL = 'neutral';
    public const string CONCLUSION_ACTION_REQUIRED = 'action_required';

    protected const int NAME_LIMIT = 255;

    /**
     * Repositories that refused a report, so a fan-out pays one rejected call, not one each.
     *
     * @var array<string, true>
     */
    protected array $refused = [];

    /**
     * Never throws: a failed report must not fail the event that produced it.
     */
    public function report(
        Git $vcs,
        string $owner,
        string $repositoryName,
        string $commitHash,
        string $name,
        string $conclusion,
        string $state,
        string $title,
        string $summary,
        string $detailsUrl = '',
    ): void {
        if ($this->conclude($vcs, $owner, $repositoryName, $commitHash, $name, $conclusion, $title, $summary, $detailsUrl)) {
            return;
        }

        if (!$this->reportable($owner, $repositoryName, $commitHash)) {
            return;
        }

        try {
            $vcs->updateCommitStatus($repositoryName, $commitHash, $owner, $state, $summary, $detailsUrl, $name);
        } catch (\Throwable $error) {
            $this->remember($owner, $repositoryName, $error);
            Console::warning("Failed to report on {$owner}/{$repositoryName}: " . $error->getMessage());
        }
    }

    protected function conclude(
        Git $vcs,
        string $owner,
        string $repositoryName,
        string $commitHash,
        string $name,
        string $conclusion,
        string $title,
        string $summary,
        string $detailsUrl,
    ): bool {
        if (!$vcs instanceof GitHub || !$this->reportable($owner, $repositoryName, $commitHash)) {
            return false;
        }

        try {
            $vcs->createCheckRun(
                owner: $owner,
                repositoryName: $repositoryName,
                headSha: $commitHash,
                name: \mb_strimwidth($name, 0, self::NAME_LIMIT, '...'),
                status: 'completed',
                conclusion: $conclusion,
                title: $title,
                summary: $summary,
                detailsUrl: $detailsUrl,
            );

            return true;
        } catch (\Throwable $error) {
            $this->remember($owner, $repositoryName, $error);
            Console::warning("Failed to create check run on {$owner}/{$repositoryName}: " . $error->getMessage());

            return false;
        }
    }

    protected function reportable(string $owner, string $repositoryName, string $commitHash): bool
    {
        if (empty($owner) || empty($repositoryName) || empty($commitHash)) {
            return false;
        }

        return !isset($this->refused["{$owner}/{$repositoryName}"]);
    }

    /**
     * Owner, repository and commit are identical for every resource in a fan-out, so a
     * provider rejecting those will reject them all. Only the context differs, so a
     * complaint about the report itself must stay retryable.
     */
    protected function remember(string $owner, string $repositoryName, \Throwable $error): void
    {
        if (\in_array($error->getCode(), [401, 403, 404], true)) {
            $this->refused["{$owner}/{$repositoryName}"] = true;
        }
    }
}
