<?php

namespace Appwrite\Vcs;

use Utopia\Console;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

/**
 * Reports a verdict on a commit as a check run, falling back to a commit status
 * where check runs are unavailable. Never throws: a failed report must not fail
 * the event that produced it.
 */
class CheckRuns
{
    protected const int NAME_LIMIT = 255;
    protected const int DESCRIPTION_LIMIT = 140;

    /**
     * @var array<string, true> Repositories that refused a check run, so a fan-out pays one call, not one each.
     */
    protected array $refusedCheckRuns = [];

    /**
     * Writing check runs and writing statuses are granted separately, so a refusal
     * of one says nothing about the other.
     *
     * @var array<string, true>
     */
    protected array $refusedStatuses = [];

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

        if (!$this->addressable($owner, $repositoryName, $commitHash) || isset($this->refusedStatuses["{$owner}/{$repositoryName}"])) {
            return;
        }

        try {
            $vcs->updateCommitStatus($repositoryName, $commitHash, $owner, $state, \mb_strimwidth($summary, 0, self::DESCRIPTION_LIMIT, '...'), $detailsUrl, $name);
        } catch (\Throwable $error) {
            $this->remember($this->refusedStatuses, $owner, $repositoryName, $error);
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
        if (!$vcs instanceof GitHub || !$this->addressable($owner, $repositoryName, $commitHash)) {
            return false;
        }

        if (isset($this->refusedCheckRuns["{$owner}/{$repositoryName}"])) {
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
            $this->remember($this->refusedCheckRuns, $owner, $repositoryName, $error);
            Console::warning("Failed to create check run on {$owner}/{$repositoryName}: " . $error->getMessage());

            return false;
        }
    }

    protected function addressable(string $owner, string $repositoryName, string $commitHash): bool
    {
        return !empty($owner) && !empty($repositoryName) && !empty($commitHash);
    }

    /**
     * Owner, repository and commit are the same for every resource in a fan-out, so a
     * provider refusing access refuses them all. A complaint about this particular
     * report stays retryable.
     *
     * @param array<string, true> $refused
     */
    protected function remember(array &$refused, string $owner, string $repositoryName, \Throwable $error): void
    {
        if (\in_array($error->getCode(), [401, 403, 404], true)) {
            $refused["{$owner}/{$repositoryName}"] = true;
        }
    }
}
