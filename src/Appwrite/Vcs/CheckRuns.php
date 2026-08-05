<?php

namespace Appwrite\Vcs;

use Utopia\Console;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

/**
 * Reports a deployment that will not build as a provider check run, which
 * carries conclusions a commit status cannot: deliberately skipped, and waiting
 * on authorization. Only GitHub implements them, so a false return means fall
 * back to the commit status.
 */
class CheckRuns
{
    public const string CONCLUSION_NEUTRAL = 'neutral';
    public const string CONCLUSION_ACTION_REQUIRED = 'action_required';

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
     * Report a deployment that reached a terminal state without building.
     *
     * @return bool Whether the run was created.
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
    ): bool {
        // A malformed hash is a 422, and without one there is nothing to report against.
        if (!\preg_match('/^[0-9a-f]{7,40}$/i', $commitHash)) {
            return false;
        }

        if (!$this->supports($vcs) || empty($owner) || empty($repositoryName) || isset($this->refused[$owner])) {
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
            // An installation predating the check run permission answers 403 to
            // every call, so stop asking for the rest of this event.
            if ($error->getCode() === 403) {
                $this->refused[$owner] = true;
            }

            Console::warning("Failed to create check run on {$owner}/{$repositoryName}: " . $error->getMessage());

            return false;
        }
    }
}
