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
    protected const int NAME_MAX_LENGTH = 255;
    protected const int DESCRIPTION_MAX_LENGTH = 140;

    /**
     * Repositories that refused a check run. Owner, repository and commit are the same
     * for every resource a webhook fans out to, so a provider refusing access refuses
     * them all; a complaint about this particular report stays retryable. Keyed without
     * a provider, which holds only because this lives for one event with one adapter.
     *
     * @var array<string, true>
     */
    protected array $refused = [];

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
        if (empty($owner) || empty($repositoryName) || empty($commitHash)) {
            return;
        }

        $repository = "{$owner}/{$repositoryName}";

        if ($vcs instanceof GitHub && !isset($this->refused[$repository])) {
            try {
                $vcs->createCheckRun(
                    owner: $owner,
                    repositoryName: $repositoryName,
                    headSha: $commitHash,
                    name: \mb_strimwidth($name, 0, self::NAME_MAX_LENGTH, '...'),
                    status: 'completed',
                    conclusion: $conclusion,
                    title: $title,
                    summary: $summary,
                    detailsUrl: $detailsUrl,
                );

                return;
            } catch (\Throwable $error) {
                if (\in_array($error->getCode(), [401, 403, 404], true)) {
                    $this->refused[$repository] = true;
                }

                Console::warning("Failed to create check run on {$repository}: " . $error->getMessage());
            }
        }

        try {
            $vcs->updateCommitStatus($repositoryName, $commitHash, $owner, $state, \mb_strimwidth($summary, 0, self::DESCRIPTION_MAX_LENGTH, '...'), $detailsUrl, $name);
        } catch (\Throwable $error) {
            Console::warning("Failed to update commit status on {$repository}: " . $error->getMessage());
        }
    }
}
